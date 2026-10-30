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
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    /** Días después de hoy que cubre `agenda.proximas` (el día de hoy+7 entra completo). */
    public const DIAS_PROXIMAS = 7;

    /** Columnas del tablero, en orden: las etapas abiertas y Ganada. */
    public const ETAPAS_TABLERO = ['prospecto', 'calificado', 'propuesta', 'negociacion', 'cerrado_ganado'];

    /** Lo que necesita OportunidadMiDia: entidad y cotizaciones (columnas mínimas) para el origen del monto. */
    public const CARGA_OPORTUNIDAD = ['cliente:id,nombre', 'prospecto:id,nombre', CrmOportunidad::CARGA_COTIZACIONES];

    private const CARGA_EVENTO = ['etiqueta:id,nombre', 'entidad'];

    private const ALIAS_ENTIDAD = [
        CrmProspecto::class => 'prospecto',
        CrmCliente::class => 'cliente',
        CrmOportunidad::class => 'oportunidad',
        CrmEmpresaExterna::class => 'empresa_externa',
    ];

    public function resumen(int $empresaId, CrmVendedor $vendedor, CarbonImmutable $ahora): array
    {
        $vencidos = CrmAgenda::with(self::CARGA_EVENTO)
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedor->id)
            ->where('completado', false)
            ->where('fecha_fin', '<', $ahora)
            ->orderBy('fecha_fin');

        $hoy = CrmAgenda::with(self::CARGA_EVENTO)
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedor->id)
            ->whereBetween('fecha_inicio', [$ahora->startOfDay(), $ahora->endOfDay()])
            ->orderBy('fecha_inicio');

        $tratos = $this->tratosDetenidos($empresaId, $vendedor->id, $ahora);
        $cotizaciones = $this->cotizacionesPorVencer($empresaId, $vendedor->id, $ahora);

        return [
            'oportunidad_eje' => true,
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
            'agenda' => $this->agenda($empresaId, $vendedor->id, $ahora),
        ] + $this->tablero($empresaId, $vendedor->id);
    }

    /** Estructura sin datos, para gerencia que aún no elige vendedor. */
    public function vacio(): array
    {
        return [
            'oportunidad_eje' => true,
            'umbrales' => $this->umbrales(),
            'contadores' => ['vencidos' => 0, 'hoy' => 0, 'tratos_detenidos' => 0, 'cotizaciones_por_vencer' => 0],
            'vencidos' => [],
            'hoy' => [],
            'tratos_detenidos' => [],
            'cotizaciones_por_vencer' => [],
            'agenda' => ['vencidas' => [], 'hoy' => [], 'proximas' => []],
            'tablero' => array_map(fn (string $etapa) => ['etapa' => $etapa, 'oportunidades' => []], self::ETAPAS_TABLERO),
            'por_cobrar' => ['cantidad' => 0, 'monto' => 0.0],
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
            ->map(fn (CrmAgenda $e) => $this->evento($e))
            ->all();
    }

    /**
     * Evento base de Mi día: lo clásico + `etiqueta` + `liga_pendiente`. Con
     * `$conOportunidad` agrega la oportunidad del evento (null si no cuelga de una).
     * Espera `etiqueta` y `entidad` cargadas.
     */
    public function evento(CrmAgenda $e, bool $conOportunidad = false): array
    {
        $item = [
            'id' => $e->id,
            'tipo' => $e->tipo,
            'titulo' => $e->titulo,
            'descripcion' => $e->descripcion,
            'fecha_inicio' => $e->fecha_inicio?->toIso8601String(),
            'fecha_fin' => $e->fecha_fin?->toIso8601String(),
            'completado' => (bool) $e->completado,
            'entidad' => $this->entidad($e->entidad),
            'etiqueta' => $e->etiqueta ? ['id' => $e->etiqueta->id, 'nombre' => $e->etiqueta->nombre] : null,
            // Se puede ligar a una oportunidad: cuelga de un cliente o prospecto, o de nada.
            'liga_pendiente' => in_array($e->entidad_type, [null, CrmCliente::class, CrmProspecto::class], true),
        ];

        if ($conOportunidad) {
            $item['oportunidad'] = $e->entidad instanceof CrmOportunidad ? $this->oportunidadMiDia($e->entidad) : null;
        }

        return $item;
    }

    /**
     * OportunidadMiDia. Espera `cliente`, `prospecto` y `cotizaciones` cargadas
     * (CARGA_OPORTUNIDAD) para no consultar por cada oportunidad.
     */
    public function oportunidadMiDia(CrmOportunidad $op): array
    {
        return [
            'id' => $op->id,
            'nombre' => $op->nombre,
            'etapa' => $op->etapa,
            'tipo_producto' => $op->tipo_producto,
            'monto_esperado' => (float) $op->monto_esperado,
            'monto_origen' => $op->montoOrigen(),
            'estado_cobro' => $op->estado_cobro,
            'entidad' => $this->entidad($op->cliente ?? $op->prospecto),
        ];
    }

    /**
     * Agenda de la pantalla única: pendientes del vendedor en tres grupos.
     * Un evento de hoy que ya terminó sale solo en `vencidas`.
     *
     * @return array{vencidas: array, hoy: array, proximas: array}
     */
    private function agenda(int $empresaId, int $vendedorId, CarbonImmutable $ahora): array
    {
        $pendientes = fn () => CrmAgenda::with([
            'etiqueta:id,nombre',
            'entidad' => fn (MorphTo $morph) => $morph->morphWith([CrmOportunidad::class => self::CARGA_OPORTUNIDAD]),
        ])
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('completado', false)
            ->orderBy('fecha_inicio')
            ->orderBy('id')
            ->limit(self::LIMITE_POR_BLOQUE);

        $mapear = fn (Builder $query) => $query->get()->map(fn (CrmAgenda $e) => $this->evento($e, true))->all();

        return [
            'vencidas' => $mapear($pendientes()->where('fecha_fin', '<', $ahora)),
            'hoy' => $mapear($pendientes()
                ->whereBetween('fecha_inicio', [$ahora->startOfDay(), $ahora->endOfDay()])
                ->where('fecha_fin', '>=', $ahora)),
            'proximas' => $mapear($pendientes()
                ->where('fecha_inicio', '>=', $ahora->addDay()->startOfDay())
                ->where('fecha_inicio', '<', $ahora->addDays(self::DIAS_PROXIMAS + 1)->startOfDay())),
        ];
    }

    /**
     * Tablero por etapa y cobro pendiente del vendedor. Abiertas: todas. Ganada:
     * solo con actividad pendiente o por cobrar. Nunca las perdidas.
     *
     * @return array{tablero: array, por_cobrar: array{cantidad: int, monto: float}}
     */
    private function tablero(int $empresaId, int $vendedorId): array
    {
        $conPendiente = fn ($q) => $q->selectRaw('1')
            ->from('crm_agenda')
            ->whereColumn('crm_agenda.entidad_id', 'crm_oportunidades.id')
            ->where('crm_agenda.entidad_type', CrmOportunidad::class)
            ->where('crm_agenda.vendedor_id', $vendedorId)
            ->where('crm_agenda.completado', false);

        $oportunidades = CrmOportunidad::with(self::CARGA_OPORTUNIDAD)
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where(function ($q) use ($conPendiente) {
                $q->activas()->orWhere(function ($ganada) use ($conPendiente) {
                    $ganada->where('etapa', 'cerrado_ganado')
                        ->where(fn ($cond) => $cond->where('estado_cobro', 'por_cobrar')->orWhereExists($conPendiente));
                });
            })
            ->get();

        // Siguiente actividad: la pendiente más próxima del propio vendedor, de cualquier fecha.
        $siguientes = CrmAgenda::with('etiqueta:id,nombre')
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('completado', false)
            ->where('entidad_type', CrmOportunidad::class)
            ->whereIn('entidad_id', $oportunidades->modelKeys())
            ->orderBy('fecha_inicio')
            ->orderBy('id')
            ->get()
            ->unique('entidad_id')
            ->keyBy('entidad_id');

        $tarjetas = $oportunidades->map(function (CrmOportunidad $op) use ($siguientes) {
            $siguiente = $siguientes->get($op->id);
            $siguiente?->setRelation('entidad', $op);

            return [
                'etapa' => $op->etapa,
                'orden' => $siguiente?->fecha_inicio?->getTimestamp(),
                'tarjeta' => $this->oportunidadMiDia($op) + [
                    'siguiente' => $siguiente ? $this->evento($siguiente) : null,
                ],
            ];
        });

        $tablero = array_map(fn (string $etapa) => [
            'etapa' => $etapa,
            // Con siguiente primero (la más próxima arriba); sin siguiente al final, por id.
            'oportunidades' => $tarjetas->where('etapa', $etapa)
                ->sort(fn (array $a, array $b) => [$a['orden'] === null, $a['orden'], $a['tarjeta']['id']]
                    <=> [$b['orden'] === null, $b['orden'], $b['tarjeta']['id']])
                ->take(self::LIMITE_POR_BLOQUE)
                ->pluck('tarjeta')
                ->values()
                ->all(),
        ], self::ETAPAS_TABLERO);

        $porCobrar = CrmOportunidad::query()
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->cerradasGanadas()
            ->where('estado_cobro', 'por_cobrar');

        return [
            'tablero' => $tablero,
            'por_cobrar' => [
                'cantidad' => (clone $porCobrar)->count(),
                'monto' => (float) (clone $porCobrar)->sum('monto_esperado'),
            ],
        ];
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
