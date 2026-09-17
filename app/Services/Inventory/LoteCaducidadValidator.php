<?php

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use App\Models\MovementType;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Reglas de lote y caducidad para movimientos de inventario:
 * - Entradas (entrada o ajuste positivo) de artículos con lotes exigen lote;
 *   con caducidad exigen además la fecha. Un lote existente no puede llegar
 *   con otra fecha. Una compra no puede traer un lote ya vencido.
 * - Salidas (salida, transferencia o ajuste negativo) exigen un lote que
 *   exista en el almacén de origen y que no esté vencido, salvo merma y
 *   ajuste negativo (baja de producto vencido).
 */
class LoteCaducidadValidator
{
    public const SIN_LOTE = 'SIN-LOTE';
    public const TIPOS_BAJA = ['MERMA', 'AJUSTE-'];

    public static function esEntrada(MovementType $tipo): bool
    {
        return $tipo->direction === 'in'
            || ($tipo->direction === 'adjustment' && $tipo->effect === 'increase');
    }

    public static function esSalida(MovementType $tipo): bool
    {
        return in_array($tipo->direction, ['out', 'transfer'], true)
            || ($tipo->direction === 'adjustment' && $tipo->effect === 'decrease');
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, string>
     */
    public function validar(MovementType $tipo, ?int $origenId, ?int $destinoId, array $details, bool $validarSalida = true): array
    {
        $hoy = now()->startOfDay();
        $productos = Product::whereIn('id', collect($details)->pluck('product_id')->filter())
            ->get(['id', 'name', 'track_lots', 'track_expiry'])
            ->keyBy('id');

        $errores = [];

        foreach (array_values($details) as $i => $detalle) {
            $producto = $productos->get((int) ($detalle['product_id'] ?? 0));
            if (! $producto || ! ($producto->track_lots || $producto->track_expiry)) {
                continue;
            }

            $lote = trim((string) ($detalle['lot_number'] ?? ''));
            if ($lote === '') {
                $errores["details.$i.lot_number"] = "{$producto->name}: el lote es obligatorio.";
                continue;
            }

            if (self::esEntrada($tipo) && $destinoId) {
                $error = $this->errorEntrada($tipo, $producto, $destinoId, $lote, $detalle['expiry_date'] ?? null, $hoy);
                if ($error) {
                    $errores["details.$i." . $error[0]] = $error[1];
                }
            }

            if ($validarSalida && self::esSalida($tipo) && $origenId) {
                $error = $this->errorSalida($tipo, $producto, $origenId, $lote, $hoy);
                if ($error) {
                    $errores["details.$i.lot_number"] = $error;
                }
            }
        }

        return $errores;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<int, array<string, mixed>>
     */
    public function completarCaducidad(?int $origenId, array $details): array
    {
        if (! $origenId) {
            return $details;
        }

        return array_map(function (array $detalle) use ($origenId) {
            $lote = trim((string) ($detalle['lot_number'] ?? ''));
            if ($lote === '') {
                return $detalle;
            }

            $caducidad = InventoryStock::where('product_id', $detalle['product_id'])
                ->where('entity_id', $origenId)
                ->where('lot_number', $lote)
                ->value('expiry_date');

            if ($caducidad) {
                $detalle['expiry_date'] = Carbon::parse($caducidad)->toDateString();
            }

            return $detalle;
        }, $details);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function errorEntrada(MovementType $tipo, Product $producto, int $destinoId, string $lote, mixed $fecha, Carbon $hoy): ?array
    {
        if ($producto->track_expiry && empty($fecha)) {
            return ['expiry_date', "{$producto->name}: la fecha de caducidad es obligatoria."];
        }

        if (empty($fecha)) {
            return null;
        }

        $fecha = Carbon::parse($fecha)->startOfDay();

        $existente = InventoryStock::where('product_id', $producto->id)
            ->where('entity_id', $destinoId)
            ->where('lot_number', $lote)
            ->whereNotNull('expiry_date')
            ->value('expiry_date');

        if ($existente && ! Carbon::parse($existente)->isSameDay($fecha)) {
            return ['lot_number', "El lote {$lote} ya existe con caducidad " . Carbon::parse($existente)->format('d/m/Y') . '.'];
        }

        if ($tipo->direction === 'in' && $fecha->lt($hoy)) {
            return ['expiry_date', "{$producto->name}: no se puede dar entrada a un lote vencido; usa un ajuste."];
        }

        return null;
    }

    private function errorSalida(MovementType $tipo, Product $producto, int $origenId, string $lote, Carbon $hoy): ?string
    {
        $stock = InventoryStock::where('product_id', $producto->id)
            ->where('entity_id', $origenId)
            ->where('lot_number', $lote)
            ->first(['id', 'expiry_date']);

        if (! $stock) {
            return "El lote {$lote} de {$producto->name} no existe en el almacén de origen.";
        }

        if ($stock->expiry_date && $stock->expiry_date->lt($hoy) && ! in_array($tipo->code, self::TIPOS_BAJA, true)) {
            return "El lote {$lote} venció el " . $stock->expiry_date->format('d/m/Y') . '.';
        }

        return null;
    }
}
