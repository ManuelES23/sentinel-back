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
    /** El precio del tipo de carga con la mayor `vigencia_inicio` menor o igual a la fecha. */
    public function para(Convenio $convenio, int $tipoCargaId, string $fecha): ?ConvenioPrecio
    {
        return self::enColeccion($this->precios($convenio), $tipoCargaId, $fecha);
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
     * @param  Collection<int, ConvenioPrecio>  $precios
     */
    public static function enColeccion(Collection $precios, int $tipoCargaId, string $fecha): ?ConvenioPrecio
    {
        return self::delTipo($precios, $tipoCargaId)
            ->filter(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d') <= $fecha)
            ->last();
    }

    /**
     * Versión pura de `principal`: el tipo principal es el primer renglón marcado
     * (o el primero por id) y su precio vigente a la fecha, o su primera vigencia.
     *
     * @param  Collection<int, ConvenioPrecio>  $precios
     */
    public static function principalEnColeccion(Collection $precios, string $fecha): ?ConvenioPrecio
    {
        $porId = $precios->sortBy('id')->values();
        $tipo = ($porId->firstWhere('es_principal', true) ?? $porId->first())?->tipo_carga_id;

        if ($tipo === null) {
            return null;
        }

        return self::enColeccion($precios, $tipo, $fecha) ?? self::delTipo($precios, $tipo)->first();
    }

    /** @return Collection<int, ConvenioPrecio> */
    protected function precios(Convenio $convenio): Collection
    {
        // Siempre consulta: la relación cargada puede estar vieja si se agregaron precios después.
        return ConvenioPrecio::where('convenio_id', $convenio->id)->get();
    }

    /** Vigencias vivas del tipo, de la más antigua a la más reciente. */
    private static function delTipo(Collection $precios, int $tipoCargaId): Collection
    {
        return $precios
            ->filter(fn (ConvenioPrecio $p) => (int) $p->tipo_carga_id === $tipoCargaId)
            ->sortBy(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d'))
            ->values();
    }
}
