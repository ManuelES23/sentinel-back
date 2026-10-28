<?php

namespace App\Services\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaViaje;
use App\Models\Planeacion\TipoEmpaque;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Arma el Programa semanal: el payload completo de `GET planeacion/programa`
 * (corte, necesidades, empaque, logística, balance por grupo, opciones de
 * falta, cambios y clientes previos) y el balance de cualquier semana, con el
 * inventario inicial arrastrado en cadena desde las semanas anteriores.
 *
 * Una instancia vive lo que dura la petición: los catálogos y los balances
 * ya calculados se guardan en memoria.
 */
class ProgramaSemanalService
{
    /** Las opciones de falta miran etapas del domingo de la semana a 14 días después. */
    private const DIAS_OPCIONES = 14;

    private readonly EtapasPlaneacion $etapas;

    private readonly SeccionesSemana $secciones;

    /** @var array<int, array{plan: PlanSemana, balance: array}> programa_id => semana calculada */
    private array $calculadas = [];

    /** @var array<string, int>|null lunes AAAA-MM-DD => programa_id, ordenado por lunes */
    private ?array $programasPorLunes = null;

    public function __construct(private readonly CatalogoPlaneacion $catalogo)
    {
        $this->etapas = new EtapasPlaneacion($catalogo);
        $this->secciones = new SeccionesSemana($catalogo);
    }

    /**
     * Olvida lo calculado y los catálogos leídos en esta petición. Lo llama
     * quien escribe datos del programa (o de la configuración) antes de volver
     * a pedir semana() o balanceDe() en la misma petición.
     */
    public function olvidar(): void
    {
        $this->calculadas = [];
        $this->programasPorLunes = null;
        $this->catalogo->olvidar();
    }

    /**
     * Programa de la semana que empieza en $lunes (AAAA-MM-DD); se crea la
     * primera vez que se abre. 422 si la fecha no es un lunes válido.
     */
    public function programaDe(string $lunes): ProgramaSemanal
    {
        try {
            $fecha = Carbon::createFromFormat('!Y-m-d', $lunes);
        } catch (InvalidFormatException) {
            $fecha = null;
        }
        if (! $fecha || $fecha->format('Y-m-d') !== $lunes || ! $fecha->isMonday()) {
            throw ValidationException::withMessages(['lunes' => 'La semana se identifica por su lunes (AAAA-MM-DD).']);
        }

        $programa = ProgramaSemanal::whereDate('lunes', $lunes)->first();
        if (! $programa) {
            try {
                $programa = ProgramaSemanal::create(['lunes' => $lunes]);
            } catch (UniqueConstraintViolationException) {
                // Otra petición lo creó al mismo tiempo.
                $programa = ProgramaSemanal::whereDate('lunes', $lunes)->firstOrFail();
            }
            $this->programasPorLunes = null;
        }

        return $programa;
    }

    public function grupoDeVariedad(int $variedadId): string
    {
        return $this->catalogo->grupoDeVariedad($variedadId);
    }

    public function haDisponibles(int $etapaId, ?int $ignorarCorteId = null, ?int $ignorarAcuerdoId = null): float
    {
        return $this->etapas->haDisponibles($etapaId, $ignorarCorteId, $ignorarAcuerdoId);
    }

    /** Etapa activa de un lote activo de la temporada (para validar cortes). */
    public function etapaEnTemporada(int $etapaId, int $temporadaId): bool
    {
        return $this->etapas->enTemporada($etapaId, $temporadaId);
    }

    /** Temporadas abiertas que tocan la semana del programa (lunes a sábado). */
    public function temporadasDeSemana(ProgramaSemanal $programa): Collection
    {
        $lunes = $programa->lunes->toDateString();

        return $this->catalogo->temporadasQueTocan($lunes, $this->masDias($lunes, 5));
    }

    /** Cultivo de una variedad que existe (no borrada); null si no existe. */
    public function cultivoDeVariedad(int $variedadId): ?int
    {
        $variedad = $this->catalogo->variedades()->get($variedadId);

        return $variedad && $variedad->deleted_at === null ? (int) $variedad->cultivo_id : null;
    }

    /**
     * Balance efectivo (con acuerdos) por grupo de la semana, tal como lo da
     * CalculadoraBalance más `final_sin_acuerdos`.
     */
    public function balanceDe(ProgramaSemanal $programa): array
    {
        return $this->calcular($programa)['balance'];
    }

    /**
     * Payload completo de la semana (contrato de `GET planeacion/programa`).
     *
     * @param  array<string, bool>  $permisos
     */
    public function semana(ProgramaSemanal $programa, array $permisos): array
    {
        ['plan' => $plan, 'balance' => $balance] = $this->calcular($programa);

        $lunes = $plan->lunes;
        $sabado = $this->masDias($lunes, 5);
        $domingo = $this->masDias($lunes, 6);
        $temporadas = $this->catalogo->temporadasQueTocan($lunes, $sabado);
        $viajes = ProgramaViaje::where('programa_id', $programa->id)->orderBy('dia')->orderBy('id')->get();
        $necesidades = $plan->necesidades(true);

        $cultivoIds = array_values(array_unique(array_merge(
            $temporadas->pluck('cultivo_id')->map(fn ($id) => (int) $id)->all(),
            $plan->cultivosCitados(),
            $viajes->pluck('cultivo_id')->all(),
        )));
        $grupos = $this->catalogo->gruposDeCultivos($cultivoIds);

        // Etapas de esta semana (sugerencias) y de las dos siguientes (opciones de falta) en una sola lectura.
        $hasta = $this->masDias($domingo, self::DIAS_OPCIONES);
        $candidatas = $this->etapas->candidatas($this->catalogo->temporadasQueTocan($lunes, $hasta), $lunes, $hasta);
        $temporadasSemana = $temporadas->pluck('id')->flip();

        return [
            'programa' => $this->programaPayload($programa, $lunes),
            'permisos' => $permisos,
            'temporadas' => $temporadas->map(fn ($t) => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'cultivo_id' => (int) $t->cultivo_id,
                'locacion' => $t->locacion,
            ])->values()->all(),
            'cultivos' => Cultivo::whereIn('id', $cultivoIds)->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->all(),
            'variedades' => $this->catalogo->variedades()
                ->filter(fn ($v) => $v->deleted_at === null && in_array((int) $v->cultivo_id, $cultivoIds, true))
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'cultivo_id' => (int) $v->cultivo_id,
                    'nombre' => $v->nombre,
                    'grupo_id' => CatalogoPlaneacion::idPublico($this->grupoDeVariedad($v->id)),
                ])->values()->all(),
            'grupos' => array_map(fn ($g) => array_diff_key($g, ['clave' => true]), $grupos),
            'catalogos' => $this->catalogos(),
            'rendimientos' => $this->rendimientos($plan, $temporadas),
            'cortes' => $plan->cortes->map(fn ($c) => [
                'id' => $c->id,
                'temporada_id' => $c->temporada_id,
                'etapa_id' => $c->etapa_id,
                'etapa_nombre' => $c->etapa?->nombre,
                'lote_nombre' => $c->etapa?->lote?->nombre,
                'variedad_id' => $c->variedad_id,
                'dia' => $c->dia,
                'hectareas' => (float) $c->hectareas,
                'cajas' => $plan->cajasDeCorte($c),
                'sin_etapa' => $c->etapa_id === null,
                'notas' => $c->notas,
            ])->values()->all(),
            'sugerencias_etapas' => array_values(array_filter($candidatas, fn ($e) => $e['fecha_cosecha'] <= $sabado
                && $e['ha_disponibles'] > 0
                && $temporadasSemana->has($e['temporada_id']))),
            'necesidades' => array_map(fn ($n) => $this->necesidadPayload($n), $necesidades),
            'viajes' => $viajes->map(fn (ProgramaViaje $v) => [
                'id' => $v->id,
                'dia' => $v->dia,
                'bodega_id' => $v->bodega_id,
                'cultivo_id' => $v->cultivo_id,
                'tipo' => $v->tipo,
                'cajas_cajas' => $v->tipo === 'full' ? [(int) $v->cajas_caja_1, (int) $v->cajas_caja_2] : [(int) $v->cajas_caja_1],
                'notas' => $v->notas,
            ])->values()->all(),
            'acuerdos' => $plan->acuerdos->map(fn ($a) => $this->acuerdoPayload($a, $plan))->values()->all(),
            'balance' => $balance,
            'empaque' => $this->secciones->empaque($necesidades),
            'logistica' => $this->secciones->logistica($necesidades, $viajes),
            'opciones_falta' => $this->secciones->opcionesFalta($balance, $necesidades, $candidatas, $domingo, $hasta),
            'cambios' => $this->secciones->cambios($programa),
            'clientes_previos' => $this->secciones->clientesPrevios($lunes, $necesidades),
        ];
    }

    // ── Balance en cadena ───────────────────────────────

    /**
     * Calcula la semana y, antes, las semanas anteriores que tengan programa:
     * el inicial de cada una es el final del programa existente más cercano
     * antes de ella, a cualquier distancia (una semana sin programa no tiene
     * datos y deja pasar el inventario igual). Iterativo y en memoria: cada
     * programa se calcula una sola vez por petición.
     *
     * @return array{plan: PlanSemana, balance: array}
     */
    private function calcular(ProgramaSemanal $programa): array
    {
        if (isset($this->calculadas[$programa->id])) {
            return $this->calculadas[$programa->id];
        }

        // Programas previos, del más reciente al más antiguo, hasta el primero ya calculado.
        $pendientes = [];
        $base = null;
        foreach ($this->anteriores($programa->lunes->toDateString()) as $id) {
            if (isset($this->calculadas[$id])) {
                $base = $id;
                break;
            }
            $pendientes[] = $id;
        }

        // Todas las semanas de la cadena se cargan juntas, de la más antigua a esta.
        $programas = $pendientes
            ? ProgramaSemanal::whereIn('id', $pendientes)->orderBy('lunes')->get()->push($programa)
            : new EloquentCollection([$programa]);
        $planes = PlanSemana::cargarVarios($programas, $this->catalogo);
        $anteriorId = $base;
        foreach ($programas as $p) {
            $this->resolver($planes[$p->id], $anteriorId);
            $anteriorId = $p->id;
        }

        return $this->calculadas[$programa->id];
    }

    /**
     * @param  int|null  $anteriorId  Programa existente más cercano antes de esta semana (ya calculado).
     * @return array{plan: PlanSemana, balance: array}
     */
    private function resolver(PlanSemana $plan, ?int $anteriorId): array
    {
        $programa = $plan->programa;
        $lunes = $plan->lunes;

        $inicial = [];
        if ($anteriorId !== null && isset($this->calculadas[$anteriorId])) {
            foreach ($this->calculadas[$anteriorId]['balance'] as $clave => $g) {
                $inicial[$clave] = $g['final'];
            }
        }
        foreach ($plan->inventariosManuales as $clave => $cajas) {
            $inicial[$clave] = $cajas;
        }

        // Grupos explícitos de los cultivos de la semana, más los que traen inventario.
        $cultivoIds = array_values(array_unique(array_merge(
            $this->catalogo->temporadasQueTocan($lunes, $this->masDias($lunes, 5))
                ->pluck('cultivo_id')->map(fn ($id) => (int) $id)->all(),
            $plan->cultivosCitados(),
        )));
        $grupos = [];
        foreach ($this->catalogo->gruposDeCultivos($cultivoIds) as $g) {
            if (! $g['implicito']) {
                $grupos[$g['clave']] = ['cultivo_id' => $g['cultivo_id']];
            }
        }
        foreach ($inicial as $clave => $cajas) {
            if (! isset($grupos[$clave]) && ($cajas !== 0 || array_key_exists((string) $clave, $plan->inventariosManuales))) {
                $grupos[$clave] = ['cultivo_id' => $this->catalogo->cultivoDeGrupo((string) $clave)];
            }
        }

        $balance = CalculadoraBalance::calcular(
            $grupos,
            $plan->produccion(true),
            PlanSemana::renglonesNecesidad($plan->necesidades(true)),
            $inicial,
        );
        $sinAcuerdos = CalculadoraBalance::calcular(
            $grupos,
            $plan->produccion(false),
            PlanSemana::renglonesNecesidad($plan->necesidades(false)),
            $inicial,
        );
        foreach ($balance as $clave => $g) {
            $balance[$clave]['final_sin_acuerdos'] = $sinAcuerdos[$clave]['final'] ?? $g['inicial'];
        }

        return $this->calculadas[$programa->id] = ['plan' => $plan, 'balance' => $balance];
    }

    /** @return array<string, int> */
    private function programasPorLunes(): array
    {
        if ($this->programasPorLunes === null) {
            $this->programasPorLunes = [];
            foreach (ProgramaSemanal::query()->toBase()->orderBy('lunes')->get(['id', 'lunes']) as $fila) {
                $this->programasPorLunes[substr((string) $fila->lunes, 0, 10)] = (int) $fila->id;
            }
        }

        return $this->programasPorLunes;
    }

    /**
     * Programas que existen antes de $lunes, del más reciente al más antiguo.
     *
     * @return list<int>
     */
    private function anteriores(string $lunes): array
    {
        $ids = [];
        foreach (array_reverse($this->programasPorLunes(), true) as $fecha => $id) {
            if ($fecha < $lunes) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // ── Secciones del payload ───────────────────────────

    private function programaPayload(ProgramaSemanal $programa, string $lunes): array
    {
        $programa->loadMissing('juntaRegistradaPor:id,name');

        return [
            'id' => $programa->id,
            'lunes' => $lunes,
            'semana_iso' => (int) $programa->lunes->isoWeek(),
            'dias' => array_map(fn ($d) => $this->masDias($lunes, $d), range(0, CalculadoraBalance::DIAS - 1)),
            'junta_registrada_at' => $programa->junta_registrada_at?->toIso8601String(),
            'junta_registrada_por' => $programa->junta_registrada_por,
            'junta_registrada_por_nombre' => $programa->juntaRegistradaPor?->name,
        ];
    }

    private function catalogos(): array
    {
        // Se incluyen los inactivos (is_active) porque los renglones viejos los citan.
        $fila = fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'orden' => $m->orden, 'is_active' => $m->is_active];

        return [
            'bodegas' => BodegaDestino::orderBy('orden')->orderBy('id')->get()->map($fila)->all(),
            'presentaciones' => Presentacion::orderBy('cultivo_id')->orderBy('orden')->orderBy('id')->get()
                ->map(fn ($m) => $fila($m) + ['cultivo_id' => $m->cultivo_id])->all(),
            'tipos_empaque' => TipoEmpaque::orderBy('orden')->orderBy('id')->get()->map($fila)->all(),
            'cajas_por_carga' => ConfiguracionPlaneacion::valor('cajas_por_carga', 1040),
        ];
    }

    /**
     * Un renglón por (temporada abierta, variedad de su cultivo), más los pares
     * que citan cortes o adelantos de otra temporada.
     */
    private function rendimientos(PlanSemana $plan, Collection $temporadas): array
    {
        $pares = [];
        foreach ($temporadas as $t) {
            foreach ($this->catalogo->variedades() as $v) {
                if ($v->deleted_at === null && (int) $v->cultivo_id === (int) $t->cultivo_id) {
                    $pares[$t->id.'-'.$v->id] = [$t->id, $v->id];
                }
            }
        }
        foreach ($plan->cortes as $c) {
            $pares[$c->temporada_id.'-'.$c->variedad_id] ??= [$c->temporada_id, $c->variedad_id];
        }
        foreach ($plan->acuerdos as $a) {
            if ($a->tipo === 'adelantar_etapa' && $a->temporada_id && $a->variedad_id) {
                $pares[$a->temporada_id.'-'.$a->variedad_id] ??= [$a->temporada_id, $a->variedad_id];
            }
        }

        $salida = [];
        foreach ($pares as [$temporadaId, $variedadId]) {
            $rango = $this->catalogo->rango($temporadaId, $variedadId);
            $eleccion = $plan->eleccion($temporadaId, $variedadId);
            $salida[] = [
                'temporada_id' => $temporadaId,
                'variedad_id' => $variedadId,
                'minimo' => $rango[0] ?? null,
                'maximo' => $rango[1] ?? null,
                'modo' => $eleccion?->modo ?? 'medio',
                'valor_exacto' => $eleccion?->valor_exacto !== null ? (float) $eleccion->valor_exacto : null,
                'valor_usado' => $plan->valorUsado($temporadaId, $variedadId),
            ];
        }

        return $salida;
    }

    private function necesidadPayload(array $renglon): array
    {
        $n = $renglon['necesidad'];
        $receta = $this->secciones->receta($n);

        return [
            'id' => $renglon['id'],
            'bodega_id' => $n->bodega_id,
            'cliente_id' => $n->cliente_id,
            'cliente_nombre' => $n->cliente_nombre,
            'en_crm' => $n->cliente_id !== null,
            'cultivo_id' => $n->cultivo_id,
            'presentacion_id' => $n->presentacion_id,
            'tipo_empaque_id' => $n->tipo_empaque_id,
            'variedad_ids' => $n->variedades->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'grupo_id' => $renglon['grupo'] !== null ? CatalogoPlaneacion::idPublico($renglon['grupo']) : null,
            'receta_id' => $receta['receta']['id'] ?? null,
            'receta_nombre' => $receta['receta']['nombre'] ?? null,
            'estado_receta' => $receta['estado'],
            'cajas' => $renglon['base'],
            'cajas_efectivas' => $renglon['cajas'],
            'movida_desde' => $renglon['movida_desde'],
        ];
    }

    private function acuerdoPayload(ProgramaAcuerdo $a, PlanSemana $plan): array
    {
        $campos = match ($a->tipo) {
            'adelantar_etapa' => [
                'etapa_id' => $a->etapa_id,
                'temporada_id' => $a->temporada_id,
                'variedad_id' => $a->variedad_id,
                'dia' => $a->dia,
                'hectareas' => $a->hectareas !== null ? (float) $a->hectareas : null,
            ],
            'mover_pedido' => [
                'necesidad_id' => $a->necesidad_id,
                'dia_origen' => $a->dia_origen,
                'destino_lunes' => $a->destino_lunes?->toDateString(),
                'dia_destino' => $a->dia_destino,
                'cajas' => $a->cajas,
            ],
            default => [
                'temporada_id' => $a->temporada_id,
                'variedad_id' => $a->variedad_id,
                'dia' => $a->dia,
                'cajas' => $a->cajas,
                'origen_tipo' => $a->origen_tipo,
                'origen_texto' => $a->origen_texto,
            ],
        };

        // Efecto en esta semana: cajas que suma (adelantar/conseguir, en su día)
        // o que se quitan del día de origen (mover pedido).
        if ($a->tipo === 'mover_pedido') {
            $necesidad = $plan->necesidades->firstWhere('id', $a->necesidad_id);
            $grupo = $necesidad ? $plan->grupoDeNecesidad($necesidad) : null;
            $efecto = ['grupo_id' => $grupo, 'dia' => $a->dia_origen, 'cajas' => $necesidad ? $plan->cajasMovidas($a) : 0];
        } elseif ($plan->sumaProduccion($a)) {
            $efecto = ['grupo_id' => $this->grupoDeVariedad($a->variedad_id), 'dia' => $a->dia, 'cajas' => $plan->cajasDeAcuerdo($a)];
        } else {
            // Sin variedad o sin día no suma producción; el efecto lo dice igual.
            $efecto = ['grupo_id' => null, 'dia' => $a->dia, 'cajas' => 0];
        }
        $efecto['grupo_id'] = $efecto['grupo_id'] !== null ? CatalogoPlaneacion::idPublico($efecto['grupo_id']) : null;

        return [
            'id' => $a->id,
            'tipo' => $a->tipo,
            'activo' => $a->activo,
            'descripcion' => $a->descripcion,
            ...$campos,
            'efecto' => $efecto,
            'creado_por' => $a->creador?->name,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function masDias(string $fecha, int $dias): string
    {
        return Carbon::parse($fecha)->addDays($dias)->toDateString();
    }
}
