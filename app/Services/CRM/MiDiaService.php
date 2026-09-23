<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Arma "Mi día" del vendedor: pendientes vencidos, eventos de hoy, tratos
 * detenidos y cotizaciones por vencer. Umbrales fijos en esta fase (spec
 * CRM fase 2, §2); se centralizan aquí para volverlos configurables después.
 */
class MiDiaService
{
    public const DIAS_TRATO_DETENIDO = 7;

    public const DIAS_COTIZACION_POR_VENCER = 3;

    public const LIMITE_POR_BLOQUE = 50;

    private const ALIAS_ENTIDAD = [
        CrmProspecto::class => 'prospecto',
        CrmCliente::class => 'cliente',
        CrmOportunidad::class => 'oportunidad',
        CrmEmpresaExterna::class => 'empresa_externa',
    ];

    public function resumen(int $empresaId, CrmVendedor $vendedor, CarbonImmutable $ahora): array
    {
        $vencidos = CrmAgenda::with('entidad')
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedor->id)
            ->where('completado', false)
            ->where('fecha_fin', '<', $ahora)
            ->orderBy('fecha_fin');

        $hoy = CrmAgenda::with('entidad')
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedor->id)
            ->whereBetween('fecha_inicio', [$ahora->startOfDay(), $ahora->endOfDay()])
            ->orderBy('fecha_inicio');

        $tratos = $this->tratosDetenidos($empresaId, $vendedor->id, $ahora);
        $cotizaciones = $this->cotizacionesPorVencer($empresaId, $vendedor->id, $ahora);

        return [
            'umbrales' => $this->umbrales(),
            'contadores' => [
                'vencidos' => (clone $vencidos)->count(),
                'hoy' => (clone $hoy)->count(),
                'tratos_detenidos' => count($tratos),
                'cotizaciones_por_vencer' => count($cotizaciones),
            ],
            'vencidos' => $this->eventos($vencidos),
            'hoy' => $this->eventos($hoy),
            'tratos_detenidos' => array_slice($tratos, 0, self::LIMITE_POR_BLOQUE),
            'cotizaciones_por_vencer' => array_slice($cotizaciones, 0, self::LIMITE_POR_BLOQUE),
        ];
    }

    /** Estructura sin datos, para gerencia que aún no elige vendedor. */
    public function vacio(): array
    {
        return [
            'umbrales' => $this->umbrales(),
            'contadores' => ['vencidos' => 0, 'hoy' => 0, 'tratos_detenidos' => 0, 'cotizaciones_por_vencer' => 0],
            'vencidos' => [],
            'hoy' => [],
            'tratos_detenidos' => [],
            'cotizaciones_por_vencer' => [],
        ];
    }

    private function umbrales(): array
    {
        return [
            'dias_trato_detenido' => self::DIAS_TRATO_DETENIDO,
            'dias_cotizacion_por_vencer' => self::DIAS_COTIZACION_POR_VENCER,
        ];
    }

    private function eventos(Builder $query): array
    {
        return $query->limit(self::LIMITE_POR_BLOQUE)->get()
            ->map(fn (CrmAgenda $e) => [
                'id' => $e->id,
                'tipo' => $e->tipo,
                'titulo' => $e->titulo,
                'descripcion' => $e->descripcion,
                'fecha_inicio' => $e->fecha_inicio?->toIso8601String(),
                'fecha_fin' => $e->fecha_fin?->toIso8601String(),
                'completado' => (bool) $e->completado,
                'entidad' => $this->entidad($e->entidad),
            ])
            ->all();
    }

    private function tratosDetenidos(int $empresaId, int $vendedorId, CarbonImmutable $ahora): array
    {
        // MAX(fecha_actividad) de las actividades de la oportunidad, de su
        // cliente o de su prospecto: una llamada anotada en la ficha del
        // cliente también cuenta como seguimiento del trato.
        $ultimaActividad = fn (string $clase, string $columna) => CrmActividad::query()
            ->selectRaw('MAX(fecha_actividad)')
            ->where('crm_actividades.empresa_id', $empresaId)
            ->where('entidad_type', $clase)
            ->whereColumn('entidad_id', "crm_oportunidades.{$columna}");

        return CrmOportunidad::query()
            ->select('crm_oportunidades.*')
            ->selectSub($ultimaActividad(CrmOportunidad::class, 'id'), 'ultima_act_oportunidad')
            ->selectSub($ultimaActividad(CrmCliente::class, 'cliente_id'), 'ultima_act_cliente')
            ->selectSub($ultimaActividad(CrmProspecto::class, 'prospecto_id'), 'ultima_act_prospecto')
            ->with(['cliente:id,nombre', 'prospecto:id,nombre'])
            ->where('crm_oportunidades.empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->activas()
            ->get()
            ->map(function (CrmOportunidad $op) use ($ahora) {
                $fechas = array_filter([
                    $op->ultima_act_oportunidad,
                    $op->ultima_act_cliente,
                    $op->ultima_act_prospecto,
                ]);
                $ultima = $fechas ? CarbonImmutable::parse(max($fechas)) : null;
                $referencia = $ultima ?? CarbonImmutable::parse($op->created_at);

                return [
                    'id' => $op->id,
                    'nombre' => $op->nombre,
                    'etapa' => $op->etapa,
                    'monto_esperado' => (float) $op->monto_esperado,
                    'entidad' => $this->entidad($op->cliente ?? $op->prospecto),
                    'ultima_actividad_at' => $ultima?->toIso8601String(),
                    'dias_sin_actividad' => (int) floor($referencia->diffInDays($ahora, false)),
                ];
            })
            ->filter(fn (array $t) => $t['dias_sin_actividad'] >= self::DIAS_TRATO_DETENIDO)
            ->sortByDesc('dias_sin_actividad')
            ->values()
            ->all();
    }

    private function cotizacionesPorVencer(int $empresaId, int $vendedorId, CarbonImmutable $ahora): array
    {
        $hoy = $ahora->startOfDay();

        return CrmCotizacion::query()
            ->with(['oportunidad:id,nombre,cliente_id,prospecto_id', 'oportunidad.cliente:id,nombre', 'oportunidad.prospecto:id,nombre'])
            ->where('empresa_id', $empresaId)
            ->where('estado', 'enviado')
            ->whereNotNull('vigencia_dias')
            ->whereHas('oportunidad', fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->get()
            ->map(function (CrmCotizacion $c) use ($hoy) {
                $venceEl = CarbonImmutable::parse($c->fecha_emision)->startOfDay()->addDays((int) $c->vigencia_dias);

                return [
                    'id' => $c->id,
                    'folio' => $c->folio,
                    'total' => (float) $c->total,
                    'oportunidad' => ['id' => $c->oportunidad->id, 'nombre' => $c->oportunidad->nombre],
                    'entidad' => $this->entidad($c->oportunidad->cliente ?? $c->oportunidad->prospecto),
                    'vence_el' => $venceEl->toDateString(),
                    'dias_restantes' => (int) round($hoy->diffInDays($venceEl, false)),
                ];
            })
            ->filter(fn (array $c) => $c['dias_restantes'] <= self::DIAS_COTIZACION_POR_VENCER)
            ->sortBy('dias_restantes')
            ->values()
            ->all();
    }

    private function entidad(?Model $modelo): ?array
    {
        if (! $modelo) {
            return null;
        }

        return [
            'tipo' => self::ALIAS_ENTIDAD[$modelo::class] ?? null,
            'id' => $modelo->id,
            // CrmEmpresaExterna no tiene columna `nombre`, usa `razon_social`.
            'nombre' => $modelo->nombre ?? $modelo->razon_social ?? null,
        ];
    }
}
