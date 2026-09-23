<?php

namespace App\Services\Compras;

use App\Models\CosteoAgricola;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genera la OC (borrador) a partir de la cotización ganadora de una
 * requisición y registra el costeo agrícola de sus líneas.
 */
class GeneradorOrdenCompra
{
    public function desdeCotizacion(RequisicionCampo $req, array $datos, User $user): PurchaseOrder
    {
        $ganadora = $req->cotizacionGanadora()->with(['supplier', 'detalles.requisicionDetalle'])->first();
        if ($req->status !== RequisicionCampo::STATUS_COTIZADA || ! $ganadora) {
            throw ValidationException::withMessages(['requisicion' => 'Primero elige la cotización ganadora.']);
        }

        return DB::transaction(function () use ($req, $datos, $user, $ganadora) {
            $supplier = $ganadora->supplier;
            $esperada = $datos['expected_date'] ?? ($ganadora->dias_entrega !== null
                ? Carbon::parse($datos['order_date'])->addDays($ganadora->dias_entrega)->toDateString()
                : null);

            $oc = PurchaseOrder::create([
                'order_number' => PurchaseOrder::generateOrderNumber(),
                'enterprise_id' => $req->enterprise_id,
                'almacen_destino_id' => $req->almacen_id,
                'requisicion_campo_id' => $req->id,
                'cotizacion_id' => $ganadora->id,
                'supplier_id' => $supplier->id,
                'order_date' => $datos['order_date'],
                'expected_date' => $esperada,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'currency_code' => 'MXN',
                'payment_terms' => $supplier->has_credit ? ($supplier->payment_terms ?? 0) : 0,
                'payment_conditions' => $ganadora->condiciones_pago,
                'notes' => trim(($datos['notes'] ?? '') . "\nGenerada desde Requisición: {$req->numero_requisicion}"),
                'requested_by' => $req->solicitante?->name ?? '',
                'created_by' => $user->id,
                'metadata' => ['requisicion_campo_id' => $req->id, 'temporada_id' => $req->temporada_id],
            ]);

            $linea = 0;
            foreach ($ganadora->detalles as $det) {
                $renglon = $det->requisicionDetalle;
                if (! $det->disponible || (float) $det->cantidad <= 0 || ! $renglon?->product_id) {
                    continue;
                }
                $oc->details()->create([
                    'product_id' => $renglon->product_id,
                    'quantity_ordered' => $det->cantidad,
                    'unit_id' => $renglon->unit_id,
                    'unit_price' => $det->precio_unitario,
                    'tax_rate' => $det->tax_rate,
                    'line_number' => ++$linea,
                ]);
            }

            $oc->recalculateTotals();
            $req->update(['status' => RequisicionCampo::STATUS_ORDEN_GENERADA, 'purchase_order_id' => $oc->id]);
            $this->registrarCosteo($req, $oc, $user);

            return $oc->fresh(['supplier', 'details.product', 'details.unit']);
        });
    }

    /**
     * Costeo a partir de las líneas reales de la OC (no de la requisición:
     * un cambio de cantidades o precios dejaba el costeo desfasado).
     */
    private function registrarCosteo(RequisicionCampo $req, PurchaseOrder $oc, User $user): void
    {
        $oc->load(['details.product.category']);
        $detallesRequisicion = $req->detalles()->with('product.category')->get();

        // Renglones de la requisición por producto, en orden: se van
        // consumiendo para que dos partidas del mismo producto en etapas
        // distintas no acaben atribuidas a la misma etapa.
        $pendientesPorProducto = $detallesRequisicion->groupBy('product_id')
            ->map(fn ($grupo) => $grupo->values()->all())
            ->all();

        foreach ($oc->details as $linea) {
            $impuesto = 1 + ((float) ($linea->tax_rate ?? 0) / 100);
            $costoTotal = (float) $linea->quantity_ordered * (float) $linea->unit_price * $impuesto;
            if ($costoTotal <= 0) {
                continue;
            }

            $det = null;
            if (! empty($pendientesPorProducto[$linea->product_id])) {
                $det = array_shift($pendientesPorProducto[$linea->product_id]);
            }

            CosteoAgricola::create([
                'temporada_id' => $req->temporada_id,
                'lote_id' => $det?->lote_id,
                'etapa_id' => $det?->etapa_id,
                'tipo_fuente' => CosteoAgricola::TIPO_FUENTE_REQUISICION,
                'fuente_id' => $req->id,
                'product_id' => $linea->product_id,
                'descripcion' => $det?->nombre_producto ?? $linea->product?->name,
                'categoria' => $this->mapearCategoria($linea->product?->category?->name),
                'cantidad' => $linea->quantity_ordered,
                'unit_id' => $linea->unit_id ?? $det?->unit_id,
                'costo_unitario' => $linea->unit_price,
                'costo_total' => $costoTotal,
                'fecha' => $req->fecha_solicitud,
                'user_id' => $user->id,
                'notas' => "Requisición {$req->numero_requisicion} → OC {$oc->order_number}",
            ]);
        }
    }

    /**
     * Traduce el nombre de la categoría del producto a una clave de
     * CosteoAgricola::CATEGORIAS.
     */
    private function mapearCategoria(?string $nombreCategoria): string
    {
        if (! $nombreCategoria) {
            return 'otro';
        }

        $normalizado = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n'],
            strtolower(trim($nombreCategoria))
        );

        $equivalencias = [
            'fertilizante' => 'fertilizante',
            'agroquimico' => 'agroquimico',
            'semilla' => 'semilla',
            'mano de obra' => 'mano_de_obra',
            'maquinaria' => 'maquinaria',
            'riego' => 'riego',
            'transporte' => 'transporte',
            'empaque' => 'empaque',
        ];

        foreach ($equivalencias as $aguja => $clave) {
            if (str_contains($normalizado, $aguja)) {
                return $clave;
            }
        }

        return 'otro';
    }
}
