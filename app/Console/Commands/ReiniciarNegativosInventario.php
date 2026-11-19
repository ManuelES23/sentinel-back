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

/**
 * Lleva a cero las existencias negativas (por defecto, de la categoría «Consumibles producto
 * terminado») con un Ajuste Positivo aprobado por almacén. Pensado para arrancar una temporada
 * desde cero antes del conteo físico.
 *
 * Cada renglón entra por lo que le falta para llegar a 0 y al costo de su capa de faltante, así el
 * PEPS cierra el faltante sin diferencia de valor; queda en el kardex como un movimiento normal y
 * borra «en negativo desde». Sin --confirmar solo muestra lo que haría.
 */
class ReiniciarNegativosInventario extends Command
{
    protected $signature = 'inventario:reiniciar-negativos
        {--categoria=* : Nombre de la categoría (se puede repetir). Por defecto «Consumibles producto terminado»}
        {--todas : Todas las categorías}
        {--entidad=* : Solo estos almacenes (id, se puede repetir)}
        {--nota= : Texto que se agrega a la descripción del ajuste}
        {--usuario= : Correo del usuario que queda como autor y aprobador}
        {--confirmar : Aplica los ajustes (sin esto solo muestra la lista)}';

    protected $description = 'Lleva a cero las existencias negativas con un Ajuste Positivo por almacén';

    public const CATEGORIA_POR_DEFECTO = 'Consumibles producto terminado';

    private const TOL = 0.00005;

    public function handle(AplicadorStock $aplicador): int
    {
        $tipo = MovementType::where('code', 'AJUSTE-POSITIVO')->first();
        if (! $tipo) {
            $this->error('No existe el tipo de movimiento AJUSTE-POSITIVO.');

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

        $filas = $this->negativos($categoriaIds, $this->option('entidad'));
        if ($filas->isEmpty()) {
            $this->info('No hay existencias negativas que reiniciar.');

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

        if (! $this->confirm('Se creará y aprobará un Ajuste Positivo en '.self::almacenes($filas->groupBy('entity_id')->count()).'. ¿Continuar?', false)) {
            $this->info('Cancelado; no se modificó nada.');

            return self::SUCCESS;
        }

        $nota = trim((string) $this->option('nota'));
        $descripcion = 'Reinicio de existencias negativas a cero'.($nota !== '' ? ": {$nota}" : '');

        foreach ($filas->groupBy('entity_id') as $entityId => $deEntidad) {
            $movimiento = DB::transaction(fn () => $this->ajustarAlmacen(
                $aplicador, $tipo, (int) $entityId, $deEntidad->pluck('id')->all(), $descripcion, $usuario,
            ));

            $nombre = $deEntidad->first()->entity?->name ?? "Almacén {$entityId}";
            if ($movimiento) {
                $this->info("{$nombre}: {$movimiento->document_number} con {$movimiento->details()->count()} renglones.");
            } else {
                $this->warn("{$nombre}: ya no tenía negativos; no se creó ajuste.");
            }
        }

        $this->line('');
        $this->info('Listo. Revisa las capas con: php artisan inventario:verificar-capas');

        return self::SUCCESS;
    }

    /** Filas con existencia negativa, con su producto y almacén. */
    private function negativos(?array $categoriaIds, array $entidades): Collection
    {
        return InventoryStock::query()
            ->with(['product:id,code,name,category_id,unit_id', 'entity:id,name'])
            // La columna tiene 4 decimales: el negativo más chico es -0.0001. Un umbral con más decimales
            // (p. ej. -0.00005) MySQL lo redondea contra el índice y deja pasar filas en 0.
            ->where('quantity', '<=', '-0.0001')
            ->when($categoriaIds !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->whereIn('category_id', $categoriaIds)))
            ->when($entidades !== [], fn ($q) => $q->whereIn('entity_id', array_map('intval', $entidades)))
            ->orderBy('entity_id')->orderBy('product_id')
            ->get();
    }

    private function mostrar(Collection $filas): void
    {
        $this->table(
            ['Almacén', 'Código', 'Producto', 'Lote', 'Existencia', 'Entra', 'Costo unitario'],
            $filas->map(fn (InventoryStock $s) => [
                $s->entity?->name ?? $s->entity_id,
                $s->product?->code,
                $s->product?->name,
                $s->lot_number ?: '-',
                number_format((float) $s->quantity, 4),
                number_format(abs((float) $s->quantity), 4),
                number_format($this->costoFaltante($s), 4),
            ])->all(),
        );

        $this->line($filas->count().' '.($filas->count() === 1 ? 'renglón' : 'renglones').' en '
            .self::almacenes($filas->groupBy('entity_id')->count()).'.');
    }

    /**
     * Crea y aprueba el ajuste de un almacén. Relee y bloquea las filas dentro de la transacción: lo
     * que se haya capturado mientras tanto cuenta, y una fila que ya no es negativa se omite.
     */
    private function ajustarAlmacen(AplicadorStock $aplicador, MovementType $tipo, int $entityId, array $ids,
        string $descripcion, ?User $usuario): ?InventoryMovement
    {
        $filas = InventoryStock::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (InventoryStock $s) => (float) $s->quantity < -self::TOL)
            ->load('product:id,unit_id');
        if ($filas->isEmpty()) {
            return null;
        }

        $empresaId = Entity::with('branch:id,enterprise_id')->find($entityId)?->branch?->enterprise_id;

        $movimiento = InventoryMovement::create([
            'document_number' => InventoryMovement::generateDocumentNumber($tipo->direction),
            'movement_type_id' => $tipo->id,
            'destination_entity_id' => $entityId,
            'destination_entity_type' => 'entity',
            'movement_date' => now(),
            'description' => $descripcion,
            'status' => 'pending',
            'created_by' => $usuario?->id,
            'metadata' => [
                'reinicio_negativos' => true,
                'requires_external_validation' => false,
                'destination_owner_enterprise_id' => $empresaId,
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
                'unit_cost' => $this->costoFaltante($fila),
                'lot_number' => $fila->lot_number,
                'expiry_date' => $fila->expiry_date,
                'notes' => 'Existencia antes del ajuste: '.round((float) $fila->quantity, 4),
            ]);
        }

        $movimiento->recalculateTotals();
        $movimiento->load('details');

        foreach ($movimiento->details as $detalle) {
            $aplicador->aumentar($detalle, $entityId, 'entity', $movimiento, CapaCosto::ORIGEN_AJUSTE);
        }

        $movimiento->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $usuario?->id,
        ]);

        return $movimiento;
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
