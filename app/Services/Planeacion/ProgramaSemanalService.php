<?php

namespace App\Services\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\TipoEmpaque;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Arma el Programa semanal: el payload completo de `GET planeacion/programa`
 * (corte, etapas, necesidades, empaque por destino, embarques, balance por
 * grupo y clientes previos) y el balance de cualquier semana, con el
 * inventario inicial arrastrado en cadena desde las semanas anteriores.
 *
 * Una instancia vive lo que dura la petición: los catálogos y los balances
 * ya calculados se guardan en memoria.
 */
class ProgramaSemanalService
{
    private readonly EtapasPlaneacion $etapas;

    private readonly SeccionesSemana $secciones;

    /** @var array<int, array{plan: PlanSemana, balance: array}> programa_id => semana calculada */
    private array $calculadas = [];

    /** @var array<string, int>|null lunes AAAA-MM-DD => programa_id, ordenado por lunes */
    private ?array $programasPorLunes = null;

    public function __construct(private readonly CatalogoPlaneacion $catalogo)
    {
        $this->etapas = new EtapasPlaneacion($catalogo);
        $this->secciones = new SeccionesSemana;
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

    /**
     * Hectáreas de la etapa sin asignar a cortes de ninguna semana.
     *
     * @param  int[]  $ignorarCorteIds
     */
    public function haDisponibles(int $etapaId, array $ignorarCorteIds = []): float
    {
        return $this->etapas->haDisponibles($etapaId, $ignorarCorteIds);
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

    /** Balance por grupo de la semana, tal como lo da CalculadoraBalance más el origen del inicial. */
    public function balanceDe(ProgramaSemanal $programa): array
    {
        return $this->calcular($programa)['balance'];
    }

    /**
     * Payload completo de la semana (contrato de `GET planeacion/programa`).
     *
     * @param  array<string, array<string, bool>>  $permisos  Forma `permisos` del contrato.
     */
    public function semana(ProgramaSemanal $programa, array $permisos): array
    {
        ['plan' => $plan, 'balance' => $balance] = $this->calcular($programa);

        $lunes = $plan->lunes;
        $temporadas = $this->catalogo->temporadasQueTocan($lunes, $this->masDias($lunes, 5));
        $embarques = ProgramaEmbarque::where('programa_id', $programa->id)
            ->orderBy('cultivo_id')->orderBy('bodega_id')->orderBy('tipo')->orderBy('dia')
            ->get(['cultivo_id', 'bodega_id', 'tipo', 'dia', 'cargas']);
        $necesidades = $plan->necesidades();

        $cultivoIds = array_values(array_unique(array_merge(
            $temporadas->pluck('cultivo_id')->map(fn ($id) => (int) $id)->all(),
            $plan->cultivosCitados(),
            $embarques->pluck('cultivo_id')->all(),
        )));
        $grupos = $this->catalogo->gruposDeCultivos($cultivoIds);

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
            'etapas' => $this->etapas->paraSemana($temporadas, $lunes),
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
            'necesidades' => array_map(fn ($n) => $this->necesidadPayload($n), $necesidades),
            'embarques' => $embarques->map(fn (ProgramaEmbarque $e) => [
                'cultivo_id' => $e->cultivo_id,
                'bodega_id' => $e->bodega_id,
                'tipo' => $e->tipo,
                'dia' => $e->dia,
                'cargas' => $e->cargas,
            ])->values()->all(),
            'balance' => $balance,
            'empaque' => $this->secciones->empaque($necesidades),
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
        $arrastrado = $inicial;
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
            $plan->produccion(),
            PlanSemana::renglonesNecesidad($plan->necesidades()),
            $inicial,
        );
        foreach ($balance as $clave => $g) {
            // Capturado a mano o arrastrado del programa anterior; con el valor
            // que se arrastraría para poder volver a él.
            $balance[$clave]['inicial_origen'] = array_key_exists((string) $clave, $plan->inventariosManuales) ? 'capturado' : 'arrastrado';
            $balance[$clave]['inicial_arrastrado'] = $arrastrado[$clave] ?? 0;
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
        return [
            'id' => $programa->id,
            'lunes' => $lunes,
            'semana_iso' => (int) $programa->lunes->isoWeek(),
            'dias' => array_map(fn ($d) => $this->masDias($lunes, $d), range(0, CalculadoraBalance::DIAS - 1)),
            'notas' => $programa->notas,
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
     * que citan cortes de otra temporada.
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
            'cajas' => $renglon['cajas'],
        ];
    }

    private function masDias(string $fecha, int $dias): string
    {
        return Carbon::parse($fecha)->addDays($dias)->toDateString();
    }
}
