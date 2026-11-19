<?php

namespace App\Console\Commands;

use App\Models\CapaCosto;
use App\Models\Entity;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Models\MovementType;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Inventory\AplicadorStock;
use App\Support\Inventario\Cubeta;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lleva a cero las existencias (por defecto, de la categoría «Consumibles producto terminado») para
 * arrancar una temporada limpia antes del conteo físico. Por almacén crea y aprueba:
 *  - un Ajuste Positivo con lo negativo: entra lo que falta para llegar a 0 al costo de su capa de
 *    faltante, así el PEPS cierra el faltante sin diferencia de valor y se borra «en negativo desde»;
 *  - un Ajuste Negativo con lo positivo: sale todo por PEPS (el costo real queda en el renglón).
 * Todo queda en Movimientos y en el kardex. Sin --confirmar solo muestra lo que haría.
 */
class ReiniciarExistenciasInventario extends Command
{
    protected $signature = 'inventario:reiniciar-existencias
        {--categoria=* : Nombre de la categoría (se puede repetir). Por defecto «Consumibles producto terminado»}
        {--todas : Todas las categorías}
        {--entidad=* : Solo estos almacenes (id, se puede repetir)}
        {--excluir-entidad=* : Excluir estos almacenes (id, se puede repetir)}
        {--solo-negativos : Solo lleva a cero lo negativo (lo positivo no se toca)}
        {--nota= : Texto que se agrega a la descripción de los ajustes}
        {--usuario= : Correo del usuario que queda como autor y aprobador}
        {--confirmar : Aplica los ajustes (sin esto solo muestra la lista)}';

    protected $description = 'Lleva a cero las existencias de una categoría con ajustes aprobados por almacén';

    public const CATEGORIA_POR_DEFECTO = 'Consumibles producto terminado';

    private const TOL = 0.00005;

    public function handle(AplicadorStock $aplicador): int
    {
        $tipos = MovementType::whereIn('code', ['AJUSTE-POSITIVO', 'AJUSTE-NEGATIVO'])->get()->keyBy('code');
        if ($tipos->count() < 2) {
            $this->error('Faltan los tipos de movimiento AJUSTE-POSITIVO y AJUSTE-NEGATIVO.');

            return self::FAILURE;
        }

        $usuario = null;
        if ($this->option('usuario')) {
            $usuario = User::where('email', $this->option('usuario'))->first();
            if (! $usuario) {
                $this->error("No existe el usuario {$this->option('usuario')}.");

                return self::FAILURE;
            }
        }

        $categoriaIds = null;
        if (! $this->option('todas')) {
            $nombres = collect($this->option('categoria') ?: [self::CATEGORIA_POR_DEFECTO])
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter()->values();
            $categoriaIds = ProductCategory::query()->get(['id', 'name'])
                ->filter(fn ($c) => $nombres->contains(mb_strtolower(trim((string) $c->name))))
                ->pluck('id')->all();
            if ($categoriaIds === []) {
                $this->error('No hay ninguna categoría con ese nombre: '.$nombres->implode(', ').'.');

                return self::FAILURE;
            }
        }

        $soloNegativos = (bool) $this->option('solo-negativos');
        $filas = $this->filas($categoriaIds, $this->option('entidad'), $this->option('excluir-entidad'), $soloNegativos);
        if ($filas->isEmpty()) {
            $this->info($soloNegativos ? 'No hay existencias negativas que reiniciar.' : 'No hay existencias que reiniciar.');

            return self::SUCCESS;
        }

        $conArea = $filas->filter(fn (InventoryStock $s) => $s->area_id !== null);
        $filas = $filas->reject(fn (InventoryStock $s) => $s->area_id !== null);

        $this->mostrar($filas);
        if ($conArea->isNotEmpty()) {
            $this->warn("Se omiten {$conArea->count()} renglones con área (ajústalos a mano): ids "
                .$conArea->pluck('id')->implode(', '));
        }

        if (! $this->option('confirmar')) {
            $this->line('');
            $this->info('Nada se modificó. Corre de nuevo con --confirmar para aplicar los ajustes.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Se dejarán en 0 estas existencias con ajustes aprobados en '
            .self::almacenes($filas->groupBy('entity_id')->count()).'. ¿Continuar?', false)) {
            $this->info('Cancelado; no se modificó nada.');

            return self::SUCCESS;
        }

        $nota = trim((string) $this->option('nota'));
        $descripcion = 'Reinicio de existencias a cero'.($nota !== '' ? ": {$nota}" : '');
        $fallas = 0;

        foreach ($filas->groupBy('entity_id') as $entityId => $deEntidad) {
            $nombre = $deEntidad->first()->entity?->name ?? "Almacén {$entityId}";
            try {
                $movimientos = DB::transaction(fn () => $this->ajustarAlmacen(
                    $aplicador, $tipos, (int) $entityId, $deEntidad->pluck('id')->all(), $descripcion, $usuario, $soloNegativos,
                ));
            } catch (ValidationException $e) {
                $fallas++;
                $this->error("{$nombre}: no se ajustó nada ({$e->getMessage()}).");

                continue;
            }

            if ($movimientos === []) {
                $this->warn("{$nombre}: ya estaba en 0; no se creó ajuste.");
            }
            foreach ($movimientos as $m) {
                $this->info("{$nombre}: {$m->document_number} ({$m->movementType->name}) con {$m->details()->count()} renglones.");
            }
        }

        $this->line('');
        $this->info('Listo. Revisa las capas con: php artisan inventario:verificar-capas');

        return $fallas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Filas a llevar a cero, con su producto y almacén. */
    private function filas(?array $categoriaIds, array $entidades, array $excluidas, bool $soloNegativos): Collection
    {
        return InventoryStock::query()
            ->with(['product:id,code,name,category_id,unit_id', 'entity:id,name'])
            // La columna tiene 4 decimales: lo más chico distinto de 0 es ±0.0001. Un umbral con más
            // decimales (p. ej. -0.00005) MySQL lo redondea contra el índice y deja pasar filas en 0.
            ->where(fn ($q) => $soloNegativos
                ? $q->where('quantity', '<=', '-0.0001')
                : $q->where('quantity', '<=', '-0.0001')->orWhere('quantity', '>=', '0.0001'))
            ->when($categoriaIds !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->whereIn('category_id', $categoriaIds)))
            ->when($entidades !== [], fn ($q) => $q->whereIn('entity_id', array_map('intval', $entidades)))
            ->when($excluidas !== [], fn ($q) => $q->whereNotIn('entity_id', array_map('intval', $excluidas)))
            ->orderBy('entity_id')->orderBy('product_id')
            ->get();
    }

    private function mostrar(Collection $filas): void
    {
        $this->table(
            ['Almacén', 'Código', 'Producto', 'Lote', 'Existencia', 'Ajuste', 'Costo unitario'],
            $filas->map(fn (InventoryStock $s) => [
                $s->entity?->name ?? $s->entity_id,
                $s->product?->code,
                $s->product?->name,
                $s->lot_number ?: '-',
                number_format((float) $s->quantity, 4),
                ((float) $s->quantity < 0 ? 'Entra ' : 'Sale ').number_format(abs((float) $s->quantity), 4),
                (float) $s->quantity < 0 ? number_format($this->costoFaltante($s), 4) : 'PEPS',
            ])->all(),
        );

        $negativos = $filas->filter(fn (InventoryStock $s) => (float) $s->quantity < 0)->count();
        $this->line($filas->count().' '.($filas->count() === 1 ? 'renglón' : 'renglones')
            ." ({$negativos} negativos, ".($filas->count() - $negativos).' positivos) en '
            .self::almacenes($filas->groupBy('entity_id')->count()).'.');
    }

    /**
     * Ajusta un almacén: un Ajuste Positivo con lo negativo y un Ajuste Negativo con lo positivo, ambos
     * aprobados. Relee y bloquea las filas dentro de la transacción: lo que se haya capturado mientras
     * tanto cuenta, y una fila que ya está en 0 se omite.
     *
     * @return InventoryMovement[]
     */
    private function ajustarAlmacen(AplicadorStock $aplicador, Collection $tipos, int $entityId, array $ids,
        string $descripcion, ?User $usuario, bool $soloNegativos): array
    {
        $filas = InventoryStock::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->load('product:id,unit_id');
        $negativos = $filas->filter(fn (InventoryStock $s) => (float) $s->quantity < -self::TOL);
        $positivos = $soloNegativos ? collect() : $filas->filter(fn (InventoryStock $s) => (float) $s->quantity > self::TOL);

        $empresaId = Entity::with('branch:id,enterprise_id')->find($entityId)?->branch?->enterprise_id;
        $movimientos = [];

        if ($negativos->isNotEmpty()) {
            $m = $this->crearMovimiento($tipos['AJUSTE-POSITIVO'], $entityId, $empresaId, $negativos, $descripcion, $usuario, true);
            foreach ($m->details as $detalle) {
                $aplicador->aumentar($detalle, $entityId, 'entity', $m, CapaCosto::ORIGEN_AJUSTE);
            }
            $movimientos[] = $this->aprobar($m, $usuario);
        }

        if ($positivos->isNotEmpty()) {
            $m = $this->crearMovimiento($tipos['AJUSTE-NEGATIVO'], $entityId, $empresaId, $positivos, $descripcion, $usuario, false);
            foreach ($m->details as $detalle) {
                $aplicador->disminuir($detalle, $entityId, 'entity', $m);
            }
            $m->recalculateTotals();
            $movimientos[] = $this->aprobar($m, $usuario);
        }

        return $movimientos;
    }

    private function crearMovimiento(MovementType $tipo, int $entityId, ?int $empresaId, Collection $filas,
        string $descripcion, ?User $usuario, bool $entra): InventoryMovement
    {
        $movimiento = InventoryMovement::create([
            'document_number' => InventoryMovement::generateDocumentNumber($tipo->direction),
            'movement_type_id' => $tipo->id,
            'source_entity_id' => $entra ? null : $entityId,
            'source_entity_type' => $entra ? null : 'entity',
            'destination_entity_id' => $entra ? $entityId : null,
            'destination_entity_type' => $entra ? 'entity' : null,
            'movement_date' => now(),
            'description' => $descripcion,
            'status' => 'pending',
            'created_by' => $usuario?->id,
            'metadata' => [
                'reinicio_existencias' => true,
                'requires_external_validation' => false,
                ($entra ? 'destination_owner_enterprise_id' : 'source_owner_enterprise_id') => $empresaId,
            ],
        ]);

        foreach ($filas as $fila) {
            $cantidad = round(abs((float) $fila->quantity), 4);
            InventoryMovementDetail::create([
                'movement_id' => $movimiento->id,
                'product_id' => $fila->product_id,
                'quantity' => $cantidad,
                'unit_id' => $fila->product?->unit_id,
                'conversion_factor' => 1,
                'base_quantity' => $cantidad,
                // La entrada cierra el faltante a su mismo costo; la salida toma el costo PEPS al aplicarse.
                'unit_cost' => $entra ? $this->costoFaltante($fila) : 0,
                'lot_number' => $fila->lot_number,
                'expiry_date' => $fila->expiry_date,
                'notes' => 'Existencia antes del ajuste: '.round((float) $fila->quantity, 4),
            ]);
        }

        $movimiento->recalculateTotals();

        return $movimiento->load('details', 'movementType');
    }

    private function aprobar(InventoryMovement $m, ?User $usuario): InventoryMovement
    {
        $m->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $usuario?->id]);

        return $m;
    }

    private static function almacenes(int $n): string
    {
        return $n.' '.($n === 1 ? 'almacén' : 'almacenes');
    }

    /** Costo de la capa de faltante abierta (si no hay, el de la fila). */
    private function costoFaltante(InventoryStock $fila): float
    {
        $costo = CapaCosto::deCubeta(Cubeta::deFila($fila))
            ->where('origen', CapaCosto::ORIGEN_FALTANTE)
            ->where('cantidad_restante', '<', 0)
            ->orderByDesc('id')
            ->value('unit_cost');

        return round((float) ($costo ?? $fila->unit_cost ?? 0), 4);
    }
}
