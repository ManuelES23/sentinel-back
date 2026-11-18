<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use Illuminate\Support\Collection;

/**
 * Precio vigente de un convenio. La vigencia de un precio empieza en su
 * `vigencia_inicio` y termina donde empieza el siguiente del mismo tipo de carga.
 *
 * `para` y `principal` consultan la base por convenio; en listados se usan
 * `enColeccion` y `principalEnColeccion`, que trabajan sobre los precios ya
 * cargados (sin consultas) y son la única implementación de la regla.
 */
class PrecioVigente
{
    /**
     * El precio del tipo de carga con la mayor `vigencia_inicio` menor o igual a la fecha.
     * Con `$calibreId`, el especial de ese calibre o, si no tiene, el general (convenios por calibre).
     */
    public function para(Convenio $convenio, int $tipoCargaId, string $fecha, ?int $calibreId = null): ?ConvenioPrecio
    {
        return self::enColeccion($this->precios($convenio), $tipoCargaId, $fecha, $calibreId);
    }

    /**
     * El precio vigente a la fecha (por omisión, el inicio del convenio) del tipo
     * de carga principal; si ese tipo aún no tiene precio vigente, su primera vigencia.
     */
    public function principal(Convenio $convenio, ?string $fecha = null): ?ConvenioPrecio
    {
        $fecha ??= $convenio->fecha_inicio->format('Y-m-d');

        return self::principalEnColeccion($this->precios($convenio), $fecha);
    }

    /**
     * Versión pura de `para`: el precio vigente del tipo a la fecha entre los
     * precios dados (de un solo convenio), sin tocar la base.
     *
     * Con `$calibreId` nulo solo cuentan los precios sin calibre (convenios
     * clásicos, o el precio general de uno por calibre). Con calibre (2026-11-18):
     * el precio ESPECIAL de ese calibre vigente a la fecha y, si no tiene, el
     * GENERAL (sin calibre), que vale para todos los calibres del convenio. El
     * especial es un precio fijo propio: un cambio del general no lo mueve.
     *
     * @param  Collection<int, ConvenioPrecio>  $precios
     */
    public static function enColeccion(Collection $precios, int $tipoCargaId, string $fecha, ?int $calibreId = null): ?ConvenioPrecio
    {
        $vigente = fn (?int $calibre) => self::delTipo($precios, $tipoCargaId, $calibre)
            ->filter(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d') <= $fecha)
            ->last();

        if ($calibreId === null) {
            return $vigente(null);
        }

        return $vigente($calibreId) ?? $vigente(null);
    }

    /**
     * Versión pura de `principal`: el tipo principal es el primer renglón marcado
     * (o el primero por id) y su precio vigente a la fecha, o su primera vigencia.
     * El tipo principal es uno por convenio, sin importar el calibre. Con `$calibreId`
     * vale el especial del calibre o, sin él, el general (como en `enColeccion`); si
     * ninguno está vigente aún, la vigencia que empiece primero (el especial en empate).
     *
     * @param  Collection<int, ConvenioPrecio>  $precios
     */
    public static function principalEnColeccion(Collection $precios, string $fecha, ?int $calibreId = null): ?ConvenioPrecio
    {
        $porId = $precios->sortBy('id')->values();
        $tipo = ($porId->firstWhere('es_principal', true) ?? $porId->first())?->tipo_carga_id;

        if ($tipo === null) {
            return null;
        }

        $vigente = self::enColeccion($precios, $tipo, $fecha, $calibreId);
        if ($vigente !== null || $calibreId === null) {
            return $vigente ?? self::delTipo($precios, $tipo)->first();
        }

        $especial = self::delTipo($precios, $tipo, $calibreId)->first();
        $general = self::delTipo($precios, $tipo)->first();
        if ($especial === null || $general === null) {
            return $especial ?? $general;
        }

        return $general->vigencia_inicio->format('Y-m-d') < $especial->vigencia_inicio->format('Y-m-d') ? $general : $especial;
    }

    /** @return Collection<int, ConvenioPrecio> */
    protected function precios(Convenio $convenio): Collection
    {
        // Siempre consulta: la relación cargada puede estar vieja si se agregaron precios después.
        return ConvenioPrecio::where('convenio_id', $convenio->id)->get();
    }

    /** Vigencias vivas del tipo (y calibre; nulo = sin calibre), de la más antigua a la más reciente. */
    private static function delTipo(Collection $precios, int $tipoCargaId, ?int $calibreId = null): Collection
    {
        return $precios
            ->filter(fn (ConvenioPrecio $p) => (int) $p->tipo_carga_id === $tipoCargaId
                && ($p->calibre_id === null ? null : (int) $p->calibre_id) === $calibreId)
            ->sortBy(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d'))
            ->values();
    }
}
