<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;

/**
 * Precio vigente de un convenio. La vigencia de un precio empieza en su
 * `vigencia_inicio` y termina donde empieza el siguiente del mismo tipo de carga.
 */
class PrecioVigente
{
    /** El precio del tipo de carga con la mayor `vigencia_inicio` menor o igual a la fecha. */
    public function para(Convenio $convenio, int $tipoCargaId, string $fecha): ?ConvenioPrecio
    {
        return $this->delTipo($convenio, $tipoCargaId)
            ->filter(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d') <= $fecha)
            ->last();
    }

    /**
     * El precio vigente a la fecha (por omisión, el inicio del convenio) del tipo
     * de carga principal; si ese tipo aún no tiene precio vigente, su primera vigencia.
     */
    public function principal(Convenio $convenio, ?string $fecha = null): ?ConvenioPrecio
    {
        $fecha ??= $convenio->fecha_inicio->format('Y-m-d');
        $precios = ConvenioPrecio::where('convenio_id', $convenio->id)->orderBy('id')->get();
        $tipo = ($precios->firstWhere('es_principal', true) ?? $precios->first())?->tipo_carga_id;

        if ($tipo === null) {
            return null;
        }

        return $this->para($convenio, $tipo, $fecha) ?? $this->delTipo($convenio, $tipo)->first();
    }

    /** Vigencias vivas del tipo, de la más antigua a la más reciente. */
    private function delTipo(Convenio $convenio, int $tipoCargaId)
    {
        return ConvenioPrecio::where('convenio_id', $convenio->id)
            ->where('tipo_carga_id', $tipoCargaId)
            ->get()
            ->sortBy(fn (ConvenioPrecio $p) => $p->vigencia_inicio->format('Y-m-d'))
            ->values();
    }
}
