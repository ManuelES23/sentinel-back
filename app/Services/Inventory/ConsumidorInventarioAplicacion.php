<?php

namespace App\Services\Inventory;

use App\Models\Aplicacion;
use App\Models\AplicacionDetalle;
use App\Models\CosteoAgricola;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\MovementType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Descuenta del almacén el insumo de una aplicación de campo (dosis por
 * hectárea × superficie, convertida a la unidad de stock) y registra su costo
 * real. Todo en una transacción: o se aplica completo o no queda nada.
 */
class ConsumidorInventarioAplicacion
{
    private const TOLERANCIA = 0.0001;

    public function __construct(private AplicadorStock $stock)
    {
    }

    public function consumir(Aplicacion $aplicacion): void
    {
        DB::transaction(function () use ($aplicacion) {
            $this->bloquear($aplicacion);

            if ($aplicacion->inventory_movement_id) {
                throw ValidationException::withMessages(['movimiento' => 'La aplicación ya tiene un consumo de inventario aplicado.']);
            }

            $aplicacion->load('detalles.product.unit', 'detalles.unidadDosis');

            $tipo = MovementType::where('code', 'CONSUMO')->first()
                ?? throw ValidationException::withMessages(['movimiento' => 'No existe el tipo de movimiento CONSUMO.']);

            // Valida y reparte contra el stock ANTES de escribir nada.
            $planes = $this->planear($aplicacion);

            $movimiento = InventoryMovement::create([
                'document_number' => InventoryMovement::generateDocumentNumber($tipo->direction),
                'movement_type_id' => $tipo->id,
                'movement_date' => $aplicacion->fecha,
                'source_entity_id' => $aplicacion->almacen_id,
                'source_entity_type' => 'entity',
                'reference_type' => 'aplicacion',
                'reference_id' => $aplicacion->id,
                'reference_number' => $aplicacion->folio,
                'description' => 'Consumo por aplicación ' . ($aplicacion->folio ?? '#' . $aplicacion->id),
                'status' => 'approved',
                'created_by' => $aplicacion->created_by,
                'approved_by' => $aplicacion->created_by,
                'approved_at' => now(),
                'total_quantity' => 0,
                'total_amount' => 0,
            ]);

            $totalCantidad = 0.0;
            $totalImporte = 0.0;

            foreach ($planes as $plan) {
                [$cubierta, $importe] = $this->descontar($plan['porciones'], $plan['detalle'], $aplicacion, $movimiento);

                $plan['detalle']->update(['conversion_factor' => $plan['factor'], 'base_quantity' => $cubierta]);

                if ($cubierta <= self::TOLERANCIA) {
                    continue;
                }

                $this->registrarCosteo($aplicacion, $plan['detalle'], $cubierta, $importe);
                $totalCantidad += $cubierta;
                $totalImporte += $importe;
            }

            $movimiento->update(['total_quantity' => $totalCantidad, 'total_amount' => $totalImporte]);
            $aplicacion->update(['inventory_movement_id' => $movimiento->id]);
        });
    }

    public function revertir(Aplicacion $aplicacion): void
    {
        DB::transaction(function () use ($aplicacion) {
            $this->bloquear($aplicacion);

            if (! $aplicacion->inventory_movement_id) {
                return;
            }

            $movimiento = InventoryMovement::with('details')->find($aplicacion->inventory_movement_id);

            if (! $movimiento) {
                Log::warning('Al revertir el consumo, el movimiento de la aplicación ya no existe.', [
                    'aplicacion_id' => $aplicacion->id,
                    'inventory_movement_id' => $aplicacion->inventory_movement_id,
                ]);
            } else {
                // Se devuelve al almacén del movimiento, no al de la aplicación (en una
                // edición este ya puede venir cambiado). Es una entrada normal con el
                // costo original de cada porción: el promedio de la fila no se altera y
                // el saldo del kardex vuelve al anterior (esReversa lo desalinearía).
                foreach ($movimiento->details as $renglon) {
                    $this->stock->aumentar($renglon, (int) $movimiento->source_entity_id, $movimiento->source_entity_type, $movimiento);
                }

                $movimiento->delete();
            }

            CosteoAgricola::where('tipo_fuente', CosteoAgricola::TIPO_FUENTE_MOVIMIENTO)
                ->where('fuente_id', $aplicacion->id)
                ->delete();

            $aplicacion->update(['inventory_movement_id' => null]);
        });
    }

    public function reconsumir(Aplicacion $aplicacion): void
    {
        DB::transaction(function () use ($aplicacion) {
            $this->revertir($aplicacion);
            $this->consumir($aplicacion);
        });
    }

    /** Serializa los cambios sobre el puntero de la aplicación y lo relee de la BD. */
    private function bloquear(Aplicacion $aplicacion): void
    {
        Aplicacion::whereKey($aplicacion->id)->lockForUpdate()->first();
        $aplicacion->refresh();
    }

    /**
     * @return array<int, array{detalle: AplicacionDetalle, factor: float, porciones: array<int, array{stock: InventoryStock, cantidad: float}>}>
     */
    private function planear(Aplicacion $aplicacion): array
    {
        if (! $aplicacion->almacen_id) {
            throw ValidationException::withMessages(['almacen_id' => 'La aplicación necesita un almacén de origen.']);
        }

        $superficie = (float) $aplicacion->superficie_aplicada;
        if ($superficie <= 0) {
            throw ValidationException::withMessages(['superficie_aplicada' => 'La superficie aplicada es obligatoria para descontar inventario.']);
        }

        $errores = [];
        $planes = [];
        $tomado = []; // stock_id => cantidad ya asignada a renglones anteriores de esta aplicación

        foreach ($aplicacion->detalles as $i => $detalle) {
            $producto = $detalle->product;
            $unidadStock = $producto?->unit;
            $unidadDosis = $detalle->unidadDosis;

            if (! $unidadStock || (float) $unidadStock->conversion_factor <= 0) {
                $errores["productos.$i.product_id"] = 'El producto no tiene unidad de stock configurada.';
                continue;
            }
            if (! $unidadDosis) {
                $errores["productos.$i.unidad_dosis_id"] = 'Falta la unidad de la dosis.';
                continue;
            }
            if (! in_array($unidadDosis->type, ['weight', 'volume'], true) || $unidadDosis->type !== $unidadStock->type) {
                $errores["productos.$i.unidad_dosis_id"] = "La unidad de la dosis ({$unidadDosis->abbreviation}) no es compatible con la unidad de stock del producto ({$unidadStock->abbreviation}).";
                continue;
            }

            $factor = (float) $unidadDosis->conversion_factor / (float) $unidadStock->conversion_factor;
            $necesaria = round((float) $detalle->dosis * $superficie * $factor, 4);
            [$porciones, $faltante, $duplicado] = $this->repartir((int) $aplicacion->almacen_id, (int) $producto->id, $necesaria, $tomado);

            if ($duplicado) {
                $errores["productos.$i.dosis"] = "Inventario inconsistente: hay lotes duplicados de {$producto->name} en el almacén; corrígelo antes de registrar el consumo.";
                continue;
            }

            if ($faltante > self::TOLERANCIA) {
                $errores["productos.$i.dosis"] = "Stock insuficiente de {$producto->name} en el almacén: faltan " . round($faltante, 4) . " {$unidadStock->abbreviation}.";
                continue;
            }

            $planes[] = ['detalle' => $detalle, 'factor' => $factor, 'porciones' => $porciones];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $planes;
    }

    /**
     * Reparte la cantidad entre las filas de stock del producto en el almacén
     * (orden estable por id, con bloqueo). $tomado evita que dos renglones del
     * mismo producto se disputen la misma existencia.
     *
     * @param  array<int, float>  $tomado
     * @return array{0: array<int, array{stock: InventoryStock, cantidad: float}>, 1: float, 2: bool}  porciones, faltante y si hay lotes duplicados
     */
    private function repartir(int $almacenId, int $productoId, float $necesaria, array &$tomado): array
    {
        $filas = InventoryStock::where('entity_id', $almacenId)
            ->where('product_id', $productoId)
            ->whereNull('area_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // updateStock resuelve la fila por producto+almacén+área+lote: con lotes repetidos
        // descontaría siempre de la primera y stock, kardex y costo divergirían.
        if ($filas->count() !== $filas->unique(fn ($fila) => (string) $fila->lot_number)->count()) {
            return [[], 0.0, true];
        }

        $porciones = [];
        $restante = $necesaria;

        foreach ($filas as $fila) {
            if ($restante <= self::TOLERANCIA) {
                break;
            }

            $libre = (float) $fila->available_quantity - ($tomado[$fila->id] ?? 0.0);
            if ($libre <= self::TOLERANCIA) {
                continue;
            }

            $toma = round(min($restante, $libre), 4);
            $porciones[] = ['stock' => $fila, 'cantidad' => $toma];
            $tomado[$fila->id] = ($tomado[$fila->id] ?? 0.0) + $toma;
            $restante = round($restante - $toma, 4);
        }

        return [$porciones, max(0.0, $restante), false];
    }

    /**
     * @param  array<int, array{stock: InventoryStock, cantidad: float}>  $porciones
     * @return array{0: float, 1: float}  cantidad cubierta e importe consumido
     */
    private function descontar(array $porciones, AplicacionDetalle $detalle, Aplicacion $aplicacion, InventoryMovement $movimiento): array
    {
        $producto = $detalle->product;
        $cubierta = 0.0;
        $importe = 0.0;

        foreach ($porciones as $porcion) {
            $fila = $porcion['stock'];
            $cantidad = $porcion['cantidad'];
            $costo = (float) $fila->unit_cost;

            $renglon = $movimiento->details()->create([
                'product_id' => $producto->id,
                'quantity' => $cantidad,
                'unit_id' => $producto->unit_id,
                'conversion_factor' => 1,
                'base_quantity' => $cantidad,
                'lot_number' => $fila->lot_number,
                'expiry_date' => $fila->expiry_date,
                'unit_cost' => $costo,
                'total_cost' => $cantidad * $costo,
            ]);

            $this->stock->disminuir($renglon, (int) $aplicacion->almacen_id, 'entity', $movimiento);

            $cubierta += $cantidad;
            $importe += $cantidad * $costo;
        }

        return [$cubierta, $importe];
    }

    private function registrarCosteo(Aplicacion $aplicacion, AplicacionDetalle $detalle, float $cantidad, float $importe): void
    {
        $producto = $detalle->product;
        $folio = $aplicacion->folio ?? '#' . $aplicacion->id;

        CosteoAgricola::create([
            'temporada_id' => $aplicacion->temporada_id,
            'lote_id' => $aplicacion->lote_id,
            'etapa_id' => null,
            'tipo_fuente' => CosteoAgricola::TIPO_FUENTE_MOVIMIENTO,
            'fuente_id' => $aplicacion->id,
            'product_id' => $producto->id,
            'descripcion' => Str::limit("Consumo por aplicación {$folio} — {$producto->name}", 300, ''),
            'categoria' => $aplicacion->tipo_aplicacion,
            'cantidad' => $cantidad,
            'unit_id' => $producto->unit_id,
            'costo_unitario' => $importe / $cantidad,
            'costo_total' => $importe,
            'fecha' => $aplicacion->fecha,
            'user_id' => $aplicacion->created_by,
        ]);
    }
}
