<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;

/**
 * Detalle de una oportunidad para el panel de Mi día (spec 2026-10-30 §5.2):
 * la oportunidad, sus cotizaciones, las actividades pendientes del vendedor y
 * el seguimiento (actividades de la oportunidad, de su cliente y de su prospecto).
 */
class MiDiaDetalleService
{
    public const LIMITE_SEGUIMIENTO = 30;

    public const MENSAJE_AJENA = 'Esta oportunidad no está en el día de ese vendedor.';

    private const ORIGEN_SEGUIMIENTO = [
        CrmOportunidad::class => 'oportunidad',
        CrmCliente::class => 'cliente',
        CrmProspecto::class => 'prospecto',
    ];

    public function __construct(private readonly MiDiaService $miDia) {}

    /**
     * La oportunidad es del vendedor, o él tiene un evento (en cualquier estado) sobre ella.
     */
    public function puedeAbrir(CrmVendedor $vendedor, CrmOportunidad $oportunidad): bool
    {
        if ((int) $oportunidad->vendedor_id === $vendedor->id) {
            return true;
        }

        return CrmAgenda::where('empresa_id', $oportunidad->empresa_id)
            ->where('vendedor_id', $vendedor->id)
            ->where('entidad_type', CrmOportunidad::class)
            ->where('entidad_id', $oportunidad->id)
            ->exists();
    }

    /**
     * @return array{oportunidad: array, cotizaciones: array, eventos: array, seguimiento: array}
     */
    public function para(CrmVendedor $vendedor, CrmOportunidad $oportunidad, bool $conCotizaciones): array
    {
        abort_unless($this->puedeAbrir($vendedor, $oportunidad), 403, self::MENSAJE_AJENA);

        $oportunidad->load(MiDiaService::CARGA_OPORTUNIDAD);

        return [
            'oportunidad' => $this->miDia->oportunidadMiDia($oportunidad),
            'cotizaciones' => $conCotizaciones ? $this->cotizaciones($oportunidad) : [],
            'eventos' => $this->eventos($vendedor, $oportunidad),
            'seguimiento' => $this->seguimiento($oportunidad),
        ];
    }

    private function cotizaciones(CrmOportunidad $oportunidad): array
    {
        return CrmCotizacion::where('empresa_id', $oportunidad->empresa_id)
            ->where('oportunidad_id', $oportunidad->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'folio', 'estado', 'total'])
            ->map(fn (CrmCotizacion $c) => [
                'id' => $c->id,
                'folio' => $c->folio,
                'estado' => $c->estado,
                'total' => (float) $c->total,
            ])
            ->all();
    }

    private function eventos(CrmVendedor $vendedor, CrmOportunidad $oportunidad): array
    {
        return CrmAgenda::with('etiqueta:id,nombre')
            ->where('empresa_id', $oportunidad->empresa_id)
            ->where('vendedor_id', $vendedor->id)
            ->where('entidad_type', CrmOportunidad::class)
            ->where('entidad_id', $oportunidad->id)
            ->where('completado', false)
            ->orderBy('fecha_inicio')
            ->orderBy('id')
            ->get()
            ->map(function (CrmAgenda $e) use ($oportunidad) {
                // La entidad del evento es esta misma oportunidad: sin consulta extra.
                $e->setRelation('entidad', $oportunidad);

                return $this->miDia->evento($e);
            })
            ->all();
    }

    private function seguimiento(CrmOportunidad $oportunidad): array
    {
        $fuentes = array_filter([
            CrmOportunidad::class => $oportunidad->id,
            CrmCliente::class => $oportunidad->cliente_id,
            CrmProspecto::class => $oportunidad->prospecto_id,
        ]);

        return CrmActividad::with('etiqueta:id,nombre')
            ->where('empresa_id', $oportunidad->empresa_id)
            ->where(function ($q) use ($fuentes) {
                foreach ($fuentes as $clase => $id) {
                    $q->orWhere(fn ($par) => $par->where('entidad_type', $clase)->where('entidad_id', $id));
                }
            })
            ->orderByDesc('fecha_actividad')
            ->orderByDesc('id')
            ->limit(self::LIMITE_SEGUIMIENTO)
            ->get()
            ->map(fn (CrmActividad $a) => [
                'id' => $a->id,
                'tipo' => $a->tipo,
                'descripcion' => $a->descripcion,
                'resultado' => $a->resultado,
                'fecha' => $a->fecha_actividad?->toIso8601String(),
                'etiqueta' => $a->etiqueta ? ['id' => $a->etiqueta->id, 'nombre' => $a->etiqueta->nombre] : null,
                'origen' => self::ORIGEN_SEGUIMIENTO[$a->entidad_type],
            ])
            ->all();
    }
}
