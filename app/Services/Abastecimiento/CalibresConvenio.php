<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\VariedadCalibre;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Lo que solo existe en un convenio por calibre: qué calibres se permiten, cómo se guardan, cómo se
 * cargan y se presentan, la validación de activación y la limpieza de metas al cambiar de modo o de
 * calibres. Sale de ConvenioController sin cambiar su comportamiento; no depende del `Request`.
 */
class CalibresConvenio
{
    /**
     * Ids de calibre permitidos para la variedad: los activos más los que el convenio ya pactaba
     * (aunque se hayan desactivado).
     *
     * @return array<int, int>
     */
    public static function permitidos(int $variedadId, ?Convenio $convenio = null): array
    {
        $pactados = $convenio?->calibres()->pluck('calibre_id')->all() ?? [];

        return VariedadCalibre::where('variedad_id', $variedadId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $pactados))
            ->pluck('id')->all();
    }

    /**
     * Calibres del cuerpo ya normalizados: el volumen vive en cada calibre (solo en volumen total).
     *
     * @param  array<int, array{calibre_id: mixed, volumen_total?: mixed}>  $calibres
     * @return array<int, array{calibre_id: int, volumen_total: mixed}>
     */
    public static function normalizar(array $calibres, string $modoVolumen): array
    {
        return array_map(fn (array $c) => [
            'calibre_id' => (int) $c['calibre_id'],
            'volumen_total' => $modoVolumen === 'total' ? ($c['volumen_total'] ?? null) : null,
        ], $calibres);
    }

    /**
     * Deja exactamente los calibres enviados (por `calibre_id`): actualiza los que ya estaban,
     * crea los nuevos y borra los que no vienen. Sin calibres, borra todos.
     *
     * @param  array<int, array{calibre_id: int, volumen_total: mixed}>  $calibres
     */
    public static function guardar(Convenio $convenio, array $calibres): void
    {
        $ids = array_map(fn (array $c) => (int) $c['calibre_id'], $calibres);
        $convenio->calibres()->whereNotIn('calibre_id', $ids)->delete();

        foreach ($calibres as $c) {
            ConvenioCalibre::updateOrCreate(
                ['convenio_id' => $convenio->id, 'calibre_id' => (int) $c['calibre_id']],
                ['volumen_total' => $c['volumen_total'] ?? null],
            );
        }
    }

    /**
     * Calibres de los convenios que los usan (los pactados y el de cada precio), en carga ansiosa y solo
     * para esos: a un convenio clásico se le deja la relación vacía, sin consultas de más. Los calibres
     * se cargan aun borrados del catálogo: el convenio los conserva y su nombre debe seguir saliendo.
     * Requiere `precios` ya cargados.
     *
     * @param  EloquentCollection<int, Convenio>|Convenio  $convenios
     */
    public static function cargar(EloquentCollection|Convenio $convenios): void
    {
        if ($convenios instanceof Convenio) {
            $convenios = new EloquentCollection([$convenios]);
        }

        $porCalibre = new EloquentCollection();
        foreach ($convenios as $c) {
            if ($c->usa_calibres) {
                $porCalibre->push($c);
            } else {
                $c->setRelation('calibres', new EloquentCollection());
            }
        }

        if ($porCalibre->isNotEmpty()) {
            $calibre = fn ($q) => $q->withTrashed()->select('id', 'nombre', 'orden');
            $porCalibre->loadMissing(['precios.calibre' => $calibre, 'calibres.calibre' => $calibre]);
        }
    }

    /**
     * Calibres pactados con su volumen y lo pactado (en volumen total, el reparto; en semanal, la suma de
     * sus metas). Salen todos los del convenio, aunque no tengan metas. Requiere `calibres.calibre` cargado.
     */
    public static function presentar(Convenio $c): array
    {
        if (! $c->usa_calibres) {
            return [];
        }

        $pactado = MetasConvenio::pactadoPorCalibre($c);

        return $c->calibres
            ->sortBy([fn ($a, $b) => ($a->calibre?->orden ?? 0) <=> ($b->calibre?->orden ?? 0), ['calibre_id', 'asc']])
            ->map(fn (ConvenioCalibre $fila) => [
                'calibre_id' => $fila->calibre_id,
                'nombre' => $fila->calibre?->nombre,
                'orden' => $fila->calibre?->orden,
                'volumen_total' => $fila->volumen_total,
                'pactado' => (float) ($pactado[$fila->calibre_id] ?? 0.0),
            ])->values()->all();
    }

    /**
     * Activar un convenio por calibre: cada calibre necesita precio del tipo de carga principal
     * y volumen pactado mayor a cero (spec C12).
     */
    public static function validarActivacion(Convenio $convenio): void
    {
        $calibres = $convenio->calibres()->pluck('calibre_id')->map(fn ($id) => (int) $id);
        $precios = $convenio->precios()->get();
        $inicio = $convenio->fecha_inicio->format('Y-m-d');

        $sinPrecio = $calibres->contains(fn (int $id) => PrecioVigente::principalEnColeccion($precios, $inicio, $id) === null);
        if ($sinPrecio) {
            throw ValidationException::withMessages(['status' => 'Agrega el precio del tipo de carga principal en cada calibre antes de activar el convenio.']);
        }

        // Sin calibres no hay volumen pactado.
        $pactado = MetasConvenio::pactadoPorCalibre($convenio);
        if ($calibres->isEmpty() || $calibres->contains(fn (int $id) => ($pactado[$id] ?? 0.0) <= 0)) {
            throw ValidationException::withMessages(['status' => 'Define el volumen pactado de cada calibre antes de activar el convenio.']);
        }
    }

    /**
     * Metas guardadas que cuentan en el modo final (cuando la petición no trae `metas`): sin calibre en un
     * clásico; en uno por calibre, las de los calibres que se quedan.
     *
     * @param  array<int, int>  $idsCalibres  calibres que se quedan
     * @return array<int, array{semana: string, cantidad: mixed, calibre_id: mixed}>
     */
    public static function metasDelModoFinal(Convenio $convenio, bool $usaCalibres, array $idsCalibres): array
    {
        return $convenio->metas
            ->filter(fn (ConvenioMeta $m) => $usaCalibres
                ? $m->calibre_id !== null && in_array((int) $m->calibre_id, $idsCalibres, true)
                : $m->calibre_id === null)
            ->map(fn (ConvenioMeta $m) => [
                'semana' => $m->semana->format('Y-m-d'),
                'cantidad' => $m->cantidad,
                'calibre_id' => $m->calibre_id,
            ])->values()->all();
    }

    /**
     * Conserva las metas del modo final; se van las del otro modo y las de calibres quitados.
     *
     * @param  array<int, int>  $idsCalibres  calibres que se quedan
     */
    public static function borrarMetasFueraDelModo(Convenio $convenio, bool $usaCalibres, array $idsCalibres): void
    {
        $convenio->metas()
            ->when(
                $usaCalibres,
                fn ($q) => $q->where(fn ($q) => $q->whereNull('calibre_id')->orWhereNotIn('calibre_id', $idsCalibres)),
                fn ($q) => $q->whereNotNull('calibre_id'),
            )
            ->delete();
    }
}
