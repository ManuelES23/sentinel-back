<?php

namespace App\Services\Planeacion;

use App\Models\Entity;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\ProduccionEmpaque;

/**
 * Plantas (entidades) cuyos pallets cuentan en el Avance de empaque.
 *
 * Se guardan en el parámetro `avance_plantas` de `plan_configuracion` como
 * JSON de ids enteros. Sin parámetro, cuentan las plantas propias
 * (`entities.is_external = 0`, no borradas) que tienen algún pallet no
 * borrado: así no salen oficinas ni bodegas (también son entidades propias) y
 * una planta propia nueva cuenta sola con su primer pallet. Una lista guardada vacía es
 * «ninguna planta» (el real queda en ceros). De lo guardado solo se usan los
 * ids enteros de entidades que existen (también borradas lógicamente: sus
 * pallets siguen en la BD y se pueden seguir contando o desmarcar).
 */
final class PlantasAvance
{
    public const CLAVE = 'avance_plantas';

    /** Si hay una lista guardada (aunque esté vacía). */
    public function porOmision(): bool
    {
        return $this->guardadas() === null;
    }

    /**
     * Ids que cuentan: la lista guardada o, sin ella, las propias.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $guardadas = $this->guardadas();
        if ($guardadas === null) {
            return Entity::query()->where('is_external', false)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('produccion_empaque')
                    ->whereColumn('produccion_empaque.entity_id', 'entities.id')
                    ->whereNull('produccion_empaque.deleted_at'))
                ->orderBy('id')->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
        }
        if ($guardadas === []) {
            return [];
        }

        return Entity::withTrashed()->whereIn('id', $guardadas)->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Guarda la lista (únicos, enteros, ordenados). Los ids ya se validaron
     * contra `entities`.
     *
     * @param  array<int|string>  $entityIds
     */
    public function guardar(array $entityIds): void
    {
        ConfiguracionPlaneacion::updateOrCreate(
            ['clave' => self::CLAVE],
            ['valor' => json_encode(self::normalizar($entityIds))],
        );
    }

    /**
     * Plantas para elegir: las que tienen algún pallet no borrado (las propias
     * con pallets, entre ellas) y las ya elegidas, ordenadas por nombre;
     * `pallets` = pallets no borrados de cada una (una sola consulta agrupada).
     *
     * @return list<array{id: int, nombre: string, externa: bool, pallets: int}>
     */
    public function opciones(): array
    {
        $conteos = ProduccionEmpaque::query()
            ->whereNotNull('entity_id')
            ->groupBy('entity_id')
            ->selectRaw('entity_id, COUNT(*) as pallets')
            ->pluck('pallets', 'entity_id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])
            ->all();
        $elegidas = $this->guardadas() ?? [];
        $ids = array_values(array_unique(array_merge(array_keys($conteos), $elegidas)));

        if ($ids === []) {
            return [];
        }

        // Las propias con pallets ya vienen en los conteos.
        return Entity::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'is_external'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'nombre' => (string) $e->name,
                'externa' => (bool) $e->is_external,
                'pallets' => $conteos[(int) $e->id] ?? 0,
            ])
            ->all();
    }

    /**
     * Las que cuentan, con su nombre, en orden de nombre.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, nombre: string}>
     */
    public static function conNombre(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Entity::withTrashed()->whereIn('id', $ids)->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => (string) $e->name])
            ->all();
    }

    /** Lo que ve la pestaña Configuración (`GET configuracion` y la respuesta del PUT). */
    public function paraConfiguracion(): array
    {
        return [
            'seleccionadas' => $this->ids(),
            'opciones' => $this->opciones(),
            'por_omision' => $this->porOmision(),
        ];
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    public static function normalizar(array $ids): array
    {
        $enteros = array_values(array_unique(array_map('intval', $ids)));
        sort($enteros);

        return $enteros;
    }

    /**
     * Lista guardada (enteros únicos) o null si no hay parámetro o no es una
     * lista JSON.
     *
     * @return list<int>|null
     */
    private function guardadas(): ?array
    {
        $valor = ConfiguracionPlaneacion::valor(self::CLAVE);
        if ($valor === null) {
            return null;
        }
        $lista = json_decode((string) $valor, true);
        if (! is_array($lista) || ! array_is_list($lista)) {
            return null;
        }

        return self::normalizar(array_filter($lista, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id))));
    }
}
