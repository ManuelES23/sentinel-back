<?php

namespace App\Services\ActivosFijos;

use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\FixedAsset;
use App\Models\FixedAssetReceiptUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Convierte unidades de compra pendientes en activos fijos (alta) o las descarta.
 * Todo ocurre en una transacción; las filas se bloquean por id ascendente para
 * que dos altas simultáneas no se bloqueen entre sí.
 */
class AltaActivosDesdeCompra
{
    public function __construct(
        private CreadorActivo $creador,
        private ValidadorRelacionesActivo $relaciones,
    ) {
    }

    /**
     * @param  array<int, array{id: int|string, serial_number?: ?string}>  $filas
     * @param  array{category_id: int, subcategory_id?: ?int, area_id?: ?int, brand_id?: ?int, model?: ?string}  $datos
     * @param  array<int>  $empresasVisibles
     * @return Collection<int, FixedAsset>
     */
    public function alta(array $filas, array $datos, array $empresasVisibles, User $user): Collection
    {
        $ids = $this->idsOrdenados(array_column($filas, 'id'));
        $series = collect($filas)->mapWithKeys(fn ($f) => [(int) $f['id'] => $this->texto($f['serial_number'] ?? null, 150)]);

        return DB::transaction(function () use ($ids, $series, $datos, $empresasVisibles, $user) {
            $unidades = $this->bloquear($ids, $empresasVisibles);
            $this->exigirPendientes($unidades);

            $empresas = $unidades->pluck('enterprise_id')->unique();
            if ($empresas->count() > 1) {
                throw ValidationException::withMessages(['units' => 'Las unidades deben ser de la misma empresa.']);
            }
            $empresa = Enterprise::findOrFail($empresas->first());

            $creados = collect();

            foreach ($unidades as $unidad) {
                $recepcion = $unidad->receipt;
                $entidad = Entity::find($recepcion->almacen_id);
                if (! $entidad) {
                    throw ValidationException::withMessages([
                        'units' => "El almacén de la recepción {$recepcion->receipt_number} ya no existe.",
                    ]);
                }

                $errores = $this->relaciones->errores($empresa, [
                    'branch_id' => $entidad->branch_id,
                    'entity_id' => $entidad->id,
                    'area_id' => $datos['area_id'] ?? null,
                    'brand_id' => $datos['brand_id'] ?? null,
                    'category_id' => $datos['category_id'],
                    'subcategory_id' => $datos['subcategory_id'] ?? null,
                ]);
                if ($errores) {
                    throw ValidationException::withMessages($errores);
                }

                $serie = $series[$unidad->id] ?? null;

                $activo = $this->creador->crear($empresa, [
                    'name' => Str::limit($unidad->product->name, 255, ''),
                    'category_id' => $datos['category_id'],
                    'subcategory_id' => $datos['subcategory_id'] ?? null,
                    'brand_id' => $datos['brand_id'] ?? null,
                    'model' => $this->texto($datos['model'] ?? null, 150),
                    'area_id' => $datos['area_id'] ?? null,
                    'branch_id' => $entidad->branch_id,
                    'entity_id' => $entidad->id,
                    'serial_number' => $serie,
                    'purchase_date' => $recepcion->receipt_date,
                    'invoice_number' => $this->texto($recepcion->supplier_document, 100),
                    'purchase_value' => $unidad->unit_cost,
                    'supplier_id' => $recepcion->supplier_id,
                    'purchase_receipt_id' => $recepcion->id,
                    'is_active' => true,
                ]);

                $unidad->update([
                    'status' => FixedAssetReceiptUnit::STATUS_REGISTERED,
                    'fixed_asset_id' => $activo->id,
                    'serial_number' => $serie,
                    'registered_by' => $user->id,
                    'registered_at' => now(),
                ]);

                $creados->push($activo);
            }

            return $creados;
        });
    }

    /**
     * @param  array<int|string>  $unitIds
     * @param  array<int>  $empresasVisibles
     * @return Collection<int, FixedAssetReceiptUnit>
     */
    public function descartar(array $unitIds, string $motivo, array $empresasVisibles, User $user): Collection
    {
        $ids = $this->idsOrdenados($unitIds);

        return DB::transaction(function () use ($ids, $motivo, $empresasVisibles, $user) {
            $unidades = $this->bloquear($ids, $empresasVisibles);
            $this->exigirPendientes($unidades);

            foreach ($unidades as $unidad) {
                $unidad->update([
                    'status' => FixedAssetReceiptUnit::STATUS_DISCARDED,
                    'discarded_reason' => $motivo,
                    'discarded_by' => $user->id,
                    'discarded_at' => now(),
                ]);
            }

            return $unidades;
        });
    }

    /** @return array<int> */
    private function idsOrdenados(array $ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * Bloquea las filas por id ascendente. Si falta alguna o es de una empresa que
     * la petición no puede ver, 404 (no se revela que existe).
     *
     * @return Collection<int, FixedAssetReceiptUnit>
     */
    private function bloquear(array $ids, array $empresasVisibles): Collection
    {
        $unidades = FixedAssetReceiptUnit::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->with(['receipt', 'product:id,name'])
            ->get();

        abort_unless(
            $unidades->count() === count($ids)
                && $unidades->every(fn ($u) => in_array((int) $u->enterprise_id, $empresasVisibles, true)),
            404,
        );

        return $unidades;
    }

    private function exigirPendientes(Collection $unidades): void
    {
        $noPendiente = $unidades->first(fn ($u) => $u->status !== FixedAssetReceiptUnit::STATUS_PENDING);

        if ($noPendiente) {
            throw ValidationException::withMessages([
                'units' => "La unidad #{$noPendiente->id} ya no está pendiente de alta.",
            ]);
        }
    }

    private function texto(?string $valor, int $maximo): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : Str::limit($valor, $maximo, '');
    }
}
