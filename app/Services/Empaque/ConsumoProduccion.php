<?php

namespace App\Services\Empaque;

use App\Models\ConsumoCapa;
use App\Models\Entity;
use App\Models\InventoryKardex;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\Inventory\CapasCosto;
use App\Services\NotificationService;
use App\Support\Inventario\Cubeta;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consumo de insumos por producción de Empaque: cada salida queda en un renglón de
 * `produccion_empaque_consumos` cuya referencia (`empaque_consumo:{id}`) enlaza sus porciones
 * PEPS, así la devolución regresa exactamente lo registrado (nunca se recalcula con la receta).
 *
 * Debe llamarse dentro de la transacción del llamador (bloquea filas de stock y porciones).
 * Orden de bloqueo global: consumir y devolver tocan los insumos por `product_id` ascendente (y
 * luego por renglón), así dos transacciones que se cruzan no se bloquean en orden inverso (1213).
 * Una instancia por petición (`Scoped`): junta los insumos que quedaron en negativo para el aviso
 * al capturista (`faltantes()`) y, tras confirmar la transacción, notifica al encargado del
 * almacén los que cruzaron de ≥ 0 a < 0 con esta petición (una vez por almacén+producto).
 */
#[Scoped]
class ConsumoProduccion
{
    private const TOL = 0.0001;

    /** @var array<string, float> "almacén:producto" => existencia al primer contacto en esta petición */
    private array $iniciales = [];

    /** @var array<string, array{entity_id:int, product_id:int, pallet:string}> insumos con salida en esta petición */
    private array $salidas = [];

    /** @var array<string, true> cruces ya avisados en esta petición */
    private array $notificados = [];

    private bool $avisoProgramado = false;

    public function __construct(
        private CalculoConsumoReceta $calculo,
        private CapasCosto $capas,
    ) {
    }

    // ---- consumir ----

    public function consumirPorCaja(ProduccionEmpaque $p, ?ProduccionEmpaqueDetalle $d, Recipe $r, int $cajas, array $alternativas): void
    {
        if ($cajas <= 0 || (int) $p->entity_id <= 0) {
            return;
        }

        $descripcion = "Producción empaque · pallet {$this->documento($p)}" . ($d ? " · entrada {$d->numero_entrada}" : '');
        $fecha = $this->fecha($d?->fecha_produccion ?? $p->fecha_produccion);

        foreach ($this->enOrden($this->calculo->porCaja($r, $cajas, $alternativas)) as $renglon) {
            $this->salida($p, $d?->id, $r, $renglon, RecipeItem::CONSUMO_POR_CAJA, $cajas, $fecha, $descripcion);
        }
    }

    /** Insumos de pallet (al armar / al cerrar). No hace nada si ya hay renglones vigentes de ese momento. */
    public function consumirPorPallet(ProduccionEmpaque $p, Recipe $r, string $momento, array $alternativas = []): void
    {
        if (! in_array($momento, [RecipeItem::CONSUMO_AL_ARMAR, RecipeItem::CONSUMO_AL_CERRAR], true)) {
            throw new \InvalidArgumentException("Momento de consumo por pallet inválido: {$momento}");
        }
        if ((int) $p->entity_id <= 0) {
            return;
        }

        $yaConsumido = ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->where('tipo', $momento)->vigentes()->lockForUpdate()->exists();
        if ($yaConsumido) {
            return;
        }

        $descripcion = "Producción empaque · pallet {$this->documento($p)}";
        $fecha = $this->fecha($p->fecha_produccion);

        foreach ($this->enOrden($this->calculo->porPallet($r, $momento, $alternativas)) as $renglon) {
            $this->salida($p, null, $r, $renglon, $momento, null, $fecha, $descripcion);
        }
    }

    // ---- devolver ----

    /** Devuelve los renglones por caja de una entrada; con $d = null, los del pallet sin entrada. */
    public function devolverEntrada(ProduccionEmpaque $p, ?ProduccionEmpaqueDetalle $d): void
    {
        $this->devolver($p, $this->renglones($p)
            ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)
            ->when($d, fn ($q) => $q->where('detalle_id', $d->id), fn ($q) => $q->whereNull('detalle_id')));
    }

    public function devolverMomento(ProduccionEmpaque $p, string $momento): void
    {
        $this->devolver($p, $this->renglones($p)->where('tipo', $momento));
    }

    public function devolverTodo(ProduccionEmpaque $p): void
    {
        $this->devolver($p, $this->renglones($p));
    }

    // ---- reasignar sin mover inventario ----

    /**
     * Las cajas pasan de $origen (o del pallet sin entrada) a $destino: lo consumido por caja va con
     * ellas, en proporción $cajas / $cajasOrigen, sin movimiento de inventario. Si se mueven todas,
     * los renglones se reasignan; si no, se parten (y sus porciones PEPS con partirReferencia).
     */
    public function moverPorCaja(?ProduccionEmpaqueDetalle $origen, ProduccionEmpaque $pOrigen, ProduccionEmpaqueDetalle $destino, int $cajas, int $cajasOrigen): void
    {
        if ($cajas <= 0 || $cajasOrigen <= 0) {
            return;
        }

        $renglones = $this->renglones($pOrigen)
            ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)
            ->when($origen, fn ($q) => $q->where('detalle_id', $origen->id), fn ($q) => $q->whereNull('detalle_id'))
            ->get();

        if ($cajas >= $cajasOrigen) {
            foreach ($renglones as $c) {
                $c->update(['produccion_id' => $destino->produccion_id, 'detalle_id' => $destino->id]);
            }

            return;
        }

        foreach ($renglones as $c) {
            $mover = round($c->vigente() * $cajas / $cajasOrigen, 4);
            if ($mover <= self::TOL) {
                continue;
            }

            $nuevo = ProduccionEmpaqueConsumo::create([
                'produccion_id' => $destino->produccion_id,
                'detalle_id' => $destino->id,
                'entity_id' => $c->entity_id,
                'product_id' => $c->product_id,
                'recipe_id' => $c->recipe_id,
                'recipe_item_id' => $c->recipe_item_id,
                'group_key' => $c->group_key,
                'tipo' => $c->tipo,
                'cajas' => $cajas,
                'cantidad' => $mover,
                'costo_total' => 0,
                'cantidad_devuelta' => 0,
            ]);
            $importe = $this->capas->partirReferencia($c->referencia(), $nuevo->referencia(), $mover);
            $nuevo->update(['costo_total' => round($importe, 4)]);

            $c->update([
                'cantidad' => round((float) $c->cantidad - $mover, 4),
                'cajas' => max(0, (int) $c->cajas - $cajas),
                'costo_total' => round((float) $c->costo_total - $importe, 4),
            ]);
        }
    }

    /** Los renglones por caja del pallet sin entrada pasan a su entrada base. */
    public function ligarBase(ProduccionEmpaque $p, ProduccionEmpaqueDetalle $base): void
    {
        ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->whereNull('detalle_id')
            ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)
            ->update(['detalle_id' => $base->id]);
    }

    // ---- consultas ----

    /** Receta de los insumos por pallet: la de la entrada con más cajas (empate: la primera); sin entradas, la del pallet. */
    public function recetaDePallet(ProduccionEmpaque $p): ?Recipe
    {
        $entrada = $p->detalles()->get()
            ->sortBy([['total_cajas', 'desc'], ['numero_entrada', 'asc']])
            ->first();

        $recetaId = $entrada?->recipe_id ?? $p->recipe_id;

        return $recetaId ? Recipe::with('items')->find($recetaId) : null;
    }

    /** Alternativas registradas (vigentes) de una entrada o del pallet sin entrada: [['grupo', 'product_id']]. */
    public function alternativasDe(ProduccionEmpaque $p, ?ProduccionEmpaqueDetalle $d): array
    {
        return ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->when($d, fn ($q) => $q->where('detalle_id', $d->id), fn ($q) => $q->whereNull('detalle_id'))
            ->whereNotNull('group_key')
            ->vigentes()
            ->orderBy('id')
            ->get()
            ->unique('group_key')
            ->map(fn (ProduccionEmpaqueConsumo $c) => ['grupo' => $c->group_key, 'product_id' => (int) $c->product_id])
            ->values()
            ->all();
    }

    /**
     * Insumos con salida en esta petición que quedaron en negativo, con su existencia actual.
     *
     * @return array<int, array{producto_id:int, producto:string, almacen_id:int, almacen:string, existencia:float}>
     */
    public function faltantes(): array
    {
        $faltantes = [];
        foreach ($this->salidas as $s) {
            $existencia = $this->existencia($s['entity_id'], $s['product_id']);
            if ($existencia >= 0) {
                continue;
            }

            $faltantes[] = [
                'producto_id' => $s['product_id'],
                'producto' => (string) (Product::whereKey($s['product_id'])->value('name') ?? "ID:{$s['product_id']}"),
                'almacen_id' => $s['entity_id'],
                'almacen' => (string) (Entity::whereKey($s['entity_id'])->value('name') ?? "ID:{$s['entity_id']}"),
                'existencia' => $existencia,
            ];
        }

        return $faltantes;
    }

    // ---- internos ----

    /** @param array{product_id:int, recipe_item_id:int, group_key:?string, cantidad:float} $renglon */
    private function salida(ProduccionEmpaque $p, ?int $detalleId, Recipe $r, array $renglon, string $tipo,
        ?int $cajas, string $fecha, string $descripcion): void
    {
        $entityId = (int) $p->entity_id;
        $productId = $renglon['product_id'];
        $cantidad = $renglon['cantidad'];

        $consumo = ProduccionEmpaqueConsumo::create([
            'produccion_id' => $p->id,
            'detalle_id' => $detalleId,
            'entity_id' => $entityId,
            'product_id' => $productId,
            'recipe_id' => $r->id,
            'recipe_item_id' => $renglon['recipe_item_id'],
            'group_key' => $renglon['group_key'],
            'tipo' => $tipo,
            'cajas' => $cajas,
            'cantidad' => $cantidad,
            'costo_total' => 0,
            'cantidad_devuelta' => 0,
        ]);

        $this->observar($entityId, $productId);
        InventoryStock::updateStock($productId, $entityId, null, -$cantidad, 0, null, null, null,
            allowNegative: true, referencia: $consumo->referencia());

        $importe = (float) ConsumoCapa::where('referencia', $consumo->referencia())->get()
            ->sum(fn (ConsumoCapa $k) => (float) $k->cantidad * (float) $k->unit_cost);
        $consumo->update(['costo_total' => round($importe, 4)]);

        InventoryKardex::registrarProduccion($productId, $entityId, 'decrease', $cantidad, $importe / $cantidad,
            $fecha, $this->documento($p), $descripcion);

        $this->salidas["{$entityId}:{$productId}"] = ['entity_id' => $entityId, 'product_id' => $productId, 'pallet' => $this->documento($p)];
        $this->programarAviso();
    }

    private function devolver(ProduccionEmpaque $p, Builder $consulta): void
    {
        // Mismo orden global que al consumir: por producto y luego por renglón.
        $renglones = $consulta->orderBy('product_id')->orderBy('id')->get();

        foreach ($renglones as $c) {
            $pendiente = $c->vigente();
            if ($pendiente <= self::TOL) {
                continue;
            }

            $this->observar((int) $c->entity_id, (int) $c->product_id);
            $r = InventoryStock::devolverPorReferenciaExacta((int) $c->product_id, (int) $c->entity_id, $pendiente, $c->referencia());

            if ($r->cantidad + self::TOL < $pendiente) {
                Log::warning('Devolución de producción de Empaque incompleta: las porciones PEPS no alcanzan.', [
                    'consumo_id' => $c->id, 'produccion_id' => $p->id, 'producto' => $c->product_id,
                    'almacen' => $c->entity_id, 'pendiente' => $pendiente, 'devuelto' => $r->cantidad,
                ]);
            }
            if ($r->cantidad <= self::TOL) {
                continue;
            }

            $c->update(['cantidad_devuelta' => round((float) $c->cantidad_devuelta + $r->cantidad, 4)]);
            InventoryKardex::registrarProduccion((int) $c->product_id, (int) $c->entity_id, 'increase', $r->cantidad,
                $r->costoUnitario(), now()->toDateString(), $this->documento($p),
                "Devolución por corrección · pallet {$this->documento($p)}");
        }
    }

    /**
     * Renglones calculados en el orden global de bloqueo (product_id ascendente, luego renglón de la
     * receta), sin importar el orden de la receta.
     *
     * @param array<int, array{product_id:int, recipe_item_id:int, group_key:?string, cantidad:float}> $renglones
     */
    private function enOrden(array $renglones): array
    {
        usort($renglones, fn (array $a, array $b) => [$a['product_id'], $a['recipe_item_id']] <=> [$b['product_id'], $b['recipe_item_id']]);

        return $renglones;
    }

    private function renglones(ProduccionEmpaque $p): Builder
    {
        return ProduccionEmpaqueConsumo::query()->where('produccion_id', $p->id)->vigentes()->lockForUpdate();
    }

    /** Bloquea la fila de stock y anota su existencia la primera vez que esta petición la toca. */
    private function observar(int $entityId, int $productId): void
    {
        $llave = "{$entityId}:{$productId}";
        if (! array_key_exists($llave, $this->iniciales)) {
            $this->iniciales[$llave] = $this->existencia($entityId, $productId);
        }
    }

    private function existencia(int $entityId, int $productId): float
    {
        return round((float) ((new Cubeta($productId, $entityId))
            ->filtrar(InventoryStock::query())->lockForUpdate()->value('quantity') ?? 0), 4);
    }

    private function programarAviso(): void
    {
        if ($this->avisoProgramado) {
            return;
        }
        $this->avisoProgramado = true;

        // Si la transacción se revierte, lo observado ya no vale: se olvida.
        DB::afterRollBack(function () {
            $this->avisoProgramado = false;
            $this->iniciales = [];
            $this->salidas = [];
        });

        DB::afterCommit(function () {
            $this->avisoProgramado = false;
            $this->notificarCruces();
        });
    }

    /** Avisa al encargado de cada insumo que en esta petición pasó de ≥ 0 a < 0. */
    private function notificarCruces(): void
    {
        foreach ($this->salidas as $llave => $s) {
            if (isset($this->notificados[$llave]) || ($this->iniciales[$llave] ?? 0) < 0) {
                continue;
            }

            // Todo tras confirmar: ninguna consulta ni aviso fallido convierte la producción en error.
            try {
                $existencia = $this->existencia($s['entity_id'], $s['product_id']);
                if ($existencia >= 0) {
                    continue;
                }
                $this->notificados[$llave] = true;

                $almacen = Entity::with('encargado')->find($s['entity_id']);
                $producto = Product::whereKey($s['product_id'])->value('name') ?? "ID:{$s['product_id']}";

                if (! $almacen?->encargado) {
                    Log::warning('Faltante de insumo sin encargado del almacén: no se notifica.', [
                        'almacen' => $s['entity_id'], 'producto' => $s['product_id'], 'existencia' => $existencia, 'pallet' => $s['pallet'],
                    ]);

                    continue;
                }

                NotificationService::toUser($almacen->encargado)
                    ->withAction("/splendidfarms/inventario/reportes/stock?entidad={$almacen->id}&faltantes=1", 'Ver faltantes')
                    ->withData(['tipo' => 'faltante_insumo', 'almacen_id' => $almacen->id, 'producto_id' => $s['product_id'], 'existencia' => $existencia])
                    ->alert(
                        "Faltante de insumo en {$almacen->name}",
                        "{$producto}: quedan {$this->num($existencia)}. Se descontó por el pallet {$s['pallet']}.",
                    );
            } catch (\Throwable $e) {
                // La producción ya está guardada: un aviso (o su consulta) fallido no la convierte en error.
                Log::warning('No se pudo notificar el faltante de insumo.', [
                    'almacen' => $s['entity_id'], 'producto' => $s['product_id'], 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function documento(ProduccionEmpaque $p): string
    {
        return (string) ($p->numero_pallet ?: $p->folio_produccion ?: "#{$p->id}");
    }

    private function fecha(mixed $fecha): string
    {
        return $fecha ? Carbon::parse($fecha)->toDateString() : now()->toDateString();
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
