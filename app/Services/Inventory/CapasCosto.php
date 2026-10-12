<?php

namespace App\Services\Inventory;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\Entity;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Services\ActivosFijos\UnidadesVigentes;
use App\Support\Inventario\Cubeta;
use App\Support\Inventario\ResultadoMovimiento;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Motor PEPS: cada entrada crea una capa con su costo; cada salida toma de las
 * capas más viejas y anota las porciones para poder devolverlas exactas.
 * Debe llamarse dentro de una transacción; bloquea las capas que toca.
 * Después de cada operación el llamador ajusta la cantidad con
 * InventoryStock::ajustarCantidad(), que sincroniza el costo de la fila.
 */
class CapasCosto
{
    private const TOL = 0.0001;

    public function conciliar(Cubeta $c): void
    {
        $fila = $this->fila($c);
        $cantidadFila = round((float) ($fila?->quantity ?? 0), 4);
        $capas = $this->capas($c);
        $enCapas = round((float) $capas->sum(fn ($k) => (float) $k->cantidad_restante), 4);
        $diferencia = round($cantidadFila - $enCapas, 4);

        if (abs($diferencia) <= self::TOL) {
            return;
        }

        if ($diferencia > 0) {
            // Si hay faltante abierto, primero se cierra.
            $diferencia = $this->cubrirFaltante($capas, $diferencia);
            if ($diferencia > self::TOL) {
                $this->crearCapa($c, $diferencia, (float) ($fila->unit_cost ?? 0),
                    $fila->last_movement_at ?? $fila->updated_at ?? now(), CapaCosto::ORIGEN_INICIAL);
            }

            return;
        }

        // La fila tiene menos que las capas: se recortan las más viejas.
        $sobra = -$diferencia;
        foreach ($capas->filter(fn ($k) => (float) $k->cantidad_restante > self::TOL) as $capa) {
            $quita = min($sobra, (float) $capa->cantidad_restante);
            $capa->update(['cantidad_restante' => round((float) $capa->cantidad_restante - $quita, 4)]);
            $sobra = round($sobra - $quita, 4);
            if ($sobra <= self::TOL) {
                break;
            }
        }
        if ($sobra > self::TOL) {
            $this->faltante($c, $capas)->decrement('cantidad_restante', $sobra);
        }
        Log::info('Capas de costo conciliadas por diferencia con la fila de stock.', ['cubeta' => (array) $c, 'diferencia' => $diferencia]);
    }

    public function entrar(Cubeta $c, float $cantidad, float $costo, \DateTimeInterface $fecha, string $origen,
        ?int $movimientoId = null, ?int $detalleId = null, ?int $capaOrigenId = null): ResultadoMovimiento
    {
        $cantidad = round($cantidad, 4);
        if ($cantidad <= self::TOL) {
            return ResultadoMovimiento::vacio();
        }

        $this->conciliar($c);
        if ($costo <= 0) {
            $costo = $this->ultimoCosto($c);
        }

        $resto = $this->cubrirFaltante($this->capas($c), $cantidad);
        if ($resto > self::TOL) {
            $this->crearCapa($c, $resto, $costo, $fecha, $origen, $movimientoId, $detalleId, $capaOrigenId);
        }

        return new ResultadoMovimiento($cantidad, $cantidad * $costo);
    }

    public function salir(Cubeta $c, float $cantidad, ?int $movimientoId = null, ?int $detalleId = null,
        ?string $referencia = null, bool $permitirNegativo = false): ResultadoMovimiento
    {
        $cantidad = round($cantidad, 4);
        if ($cantidad <= self::TOL) {
            return ResultadoMovimiento::vacio();
        }

        app(UnidadesVigentes::class)->exigirRespaldo($c->productId, $c->entityId, $c->lote, -$cantidad);
        $this->conciliar($c);

        $capas = $this->capas($c);
        $vivas = $capas->filter(fn ($k) => (float) $k->cantidad_restante > self::TOL);
        $disponible = round((float) $vivas->sum(fn ($k) => (float) $k->cantidad_restante), 4);

        if ($disponible + self::TOL < $cantidad && ! $permitirNegativo) {
            $producto = Product::whereKey($c->productId)->value('name') ?? "ID:{$c->productId}";
            $almacen = Entity::whereKey($c->entityId)->value('name') ?? "ID:{$c->entityId}";
            throw ValidationException::withMessages([
                'stock' => "Stock insuficiente de {$producto} en {$almacen}: hay " . $this->num($disponible) . ', se piden ' . $this->num($cantidad) . '.',
            ]);
        }

        $restante = $cantidad;
        $importe = 0.0;
        foreach ($vivas as $capa) {
            $toma = round(min($restante, (float) $capa->cantidad_restante), 4);
            $capa->update(['cantidad_restante' => round((float) $capa->cantidad_restante - $toma, 4)]);
            $this->porcion($capa, $toma, $movimientoId, $detalleId, $referencia);
            $importe += $toma * (float) $capa->unit_cost;
            $restante = round($restante - $toma, 4);
            if ($restante <= self::TOL) {
                break;
            }
        }

        if ($restante > self::TOL) {
            $faltante = $this->faltante($c, $capas);
            $faltante->update(['cantidad_restante' => round((float) $faltante->cantidad_restante - $restante, 4)]);
            $this->porcion($faltante, $restante, $movimientoId, $detalleId, $referencia);
            $importe += $restante * (float) $faltante->unit_cost;
        }

        return new ResultadoMovimiento($cantidad, $importe);
    }

    public function sincronizarFila(Cubeta $c): void
    {
        $fila = $this->fila($c);
        if (! $fila) {
            return;
        }

        $valor = (float) $this->capas($c)->sum(fn ($k) => (float) $k->cantidad_restante * (float) $k->unit_cost);
        $cantidad = (float) $fila->quantity;

        $fila->forceFill([
            'total_cost' => round($valor, 4),
            'unit_cost' => abs($cantidad) > self::TOL ? round($valor / $cantidad, 4) : round($this->ultimoCosto($c), 4),
        ])->save();
    }

    public function ultimoCosto(Cubeta $c): float
    {
        // Las capas de faltante nunca fijan el último costo: su costo es derivado de éste.
        $costo = CapaCosto::deCubeta($c)->where('origen', '!=', CapaCosto::ORIGEN_FALTANTE)
            ->where('unit_cost', '>', 0)->orderByDesc('fecha_entrada')->orderByDesc('id')->value('unit_cost');
        if ($costo !== null) {
            return (float) $costo;
        }

        $costo = CapaCosto::where('product_id', $c->productId)->where('entity_id', $c->entityId)
            ->where('origen', '!=', CapaCosto::ORIGEN_FALTANTE)
            ->where('unit_cost', '>', 0)->orderByDesc('fecha_entrada')->orderByDesc('id')->value('unit_cost');

        return (float) ($costo ?? $this->fila($c)?->unit_cost ?? 0);
    }

    public function fechaMasVieja(Cubeta $c): ?CarbonInterface
    {
        $this->conciliar($c);
        $fecha = CapaCosto::deCubeta($c)->where('cantidad_restante', '>', self::TOL)->min('fecha_entrada');

        return $fecha ? Carbon::parse($fecha) : null;
    }

    // ---- internos ----

    /** @return Collection<int, CapaCosto> capas de la cubeta, bloqueadas, en orden PEPS */
    private function capas(Cubeta $c): Collection
    {
        return CapaCosto::deCubeta($c)->ordenPeps()->lockForUpdate()->get();
    }

    private function fila(Cubeta $c): ?InventoryStock
    {
        return $c->filtrar(InventoryStock::query())->lockForUpdate()->first();
    }

    private function crearCapa(Cubeta $c, float $cantidad, float $costo, \DateTimeInterface $fecha, string $origen,
        ?int $movimientoId = null, ?int $detalleId = null, ?int $capaOrigenId = null): CapaCosto
    {
        return CapaCosto::create([
            'product_id' => $c->productId,
            'entity_id' => $c->entityId,
            'area_id' => $c->areaId,
            'lot_number' => $c->lote,
            'fecha_entrada' => $fecha,
            'unit_cost' => round($costo, 6),
            'cantidad_inicial' => round($cantidad, 4),
            'cantidad_restante' => round($cantidad, 4),
            'origen' => $origen,
            'movement_id' => $movimientoId,
            'movement_detail_id' => $detalleId,
            'capa_origen_id' => $capaOrigenId,
        ]);
    }

    /** Suma $cantidad a un faltante abierto (restante < 0); devuelve lo que sobra. */
    private function cubrirFaltante(Collection $capas, float $cantidad): float
    {
        $faltante = $capas->first(fn ($k) => $k->origen === CapaCosto::ORIGEN_FALTANTE && (float) $k->cantidad_restante < -self::TOL);
        if (! $faltante) {
            return $cantidad;
        }

        $cubre = min($cantidad, -(float) $faltante->cantidad_restante);
        $faltante->update(['cantidad_restante' => round((float) $faltante->cantidad_restante + $cubre, 4)]);

        return round($cantidad - $cubre, 4);
    }

    private function faltante(Cubeta $c, Collection $capas): CapaCosto
    {
        $faltante = $capas->first(fn ($k) => $k->origen === CapaCosto::ORIGEN_FALTANTE);
        if (! $faltante) {
            return $this->crearCapa($c, 0, $this->ultimoCosto($c), now(), CapaCosto::ORIGEN_FALTANTE);
        }

        // Un faltante cerrado se reutiliza, pero a valor de hoy: el nuevo faltante
        // se costea al último costo conocido, no al que tenía cuando se abrió el anterior.
        if ((float) $faltante->cantidad_restante >= -self::TOL) {
            $faltante->update(['unit_cost' => round($this->ultimoCosto($c), 6), 'fecha_entrada' => now()]);
        }

        return $faltante;
    }

    private function porcion(CapaCosto $capa, float $cantidad, ?int $movimientoId, ?int $detalleId, ?string $referencia): void
    {
        ConsumoCapa::create([
            'layer_id' => $capa->id,
            'movement_id' => $movimientoId,
            'movement_detail_id' => $detalleId,
            'referencia' => $referencia,
            'cantidad' => $cantidad,
            'unit_cost' => $capa->unit_cost,
        ]);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
