<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use App\Models\Variedad;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Acuerdos de la junta: capas activables sobre el plan base (adelantar
 * etapa, mover pedido y conseguir producto). Su efecto en el balance lo
 * calcula ProgramaSemanalService al armar la semana; aquí se validan, se
 * guardan, se activan o desactivan y se borran.
 *
 * Todas las escrituras exigen `acuerdos`, quedan en el historial del programa
 * (si ya hay junta) y avisan en tiempo real; un «mover pedido» a otra semana
 * avisa también a la semana destino.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaAcuerdoController extends Controller
{
    use PermisoProgramaSemanal;

    private const TIPOS = ['adelantar_etapa', 'mover_pedido', 'conseguir_producto'];

    private const ORIGENES = [
        'productor' => 'Compra a productor',
        'empresa_grupo' => 'Producto de empresa del grupo',
        'otro' => 'Producto conseguido',
    ];

    private const MAX_CAJAS = 999999;

    private const DIAS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const MENSAJE_ETAPA_DE_ESTA_SEMANA = 'La etapa ya es de esta semana; agrégala como corte';

    /** Crea un acuerdo (activo) en la semana; los campos dependen de `tipo`. */
    public function store(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('acuerdos');

        if (is_string($request->input('descripcion'))) {
            $texto = trim($request->input('descripcion'));
            $request->merge(['descripcion' => $texto === '' ? null : $texto]);
        }
        $comun = $request->validate([
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $destino = null;
        $datos = match ($comun['tipo']) {
            'adelantar_etapa' => $this->validarAdelanto($request, $programa, $servicio),
            'mover_pedido' => $this->validarMovimiento($request, $programa, $servicio, $destino),
            'conseguir_producto' => $this->validarCompra($request, $programa, $servicio),
        };
        $datos['descripcion'] = $comun['descripcion'] ?? $this->describir($comun['tipo'], $datos, $programa);

        $acuerdo = DB::transaction(function () use ($programa, $comun, $datos, $destino, $servicio, $registro) {
            if ($comun['tipo'] === 'adelantar_etapa') {
                $this->exigirHectareas($datos['etapa_id'], (float) $datos['hectareas'], $servicio);
            } elseif ($comun['tipo'] === 'mover_pedido') {
                $this->exigirCajasMovibles($datos['necesidad_id'], $datos['dia_origen'], $datos['cajas']);
            }

            $acuerdo = ProgramaAcuerdo::create($datos + [
                'programa_id' => $programa->id,
                'tipo' => $comun['tipo'],
                'activo' => true,
                'created_by' => Auth::id(),
            ]);
            $registro->registrar($programa, 'acuerdo', $acuerdo->id, 'creado', null, $this->instantanea($acuerdo));
            $this->avisarDestino($programa, $destino, $registro);

            return $acuerdo;
        });

        return response()->json([
            'success' => true,
            'message' => 'Acuerdo guardado.',
            'data' => $this->payload($acuerdo),
        ], 201);
    }

    /**
     * Activa o desactiva el acuerdo. Al activar se vuelve a validar que la
     * etapa tenga hectáreas o que el renglón tenga cajas para mover (422 si ya
     * no alcanza). Sin cambio de valor no hay historial ni aviso.
     */
    public function activo(Request $request, ProgramaAcuerdo $acuerdo, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('acuerdos');

        $activo = (bool) $request->validate(['activo' => ['required', 'boolean']])['activo'];
        $programa = $acuerdo->programa()->firstOrFail();
        $destino = $this->programaDestino($acuerdo, $programa, $servicio);

        DB::transaction(function () use ($acuerdo, $activo, $programa, $destino, $servicio, $registro) {
            // Al activar se bloquea primero la etapa o el renglón (mismo orden que al crear).
            if ($activo && $acuerdo->tipo === 'adelantar_etapa' && $acuerdo->etapa_id) {
                Etapa::whereKey($acuerdo->etapa_id)->lockForUpdate()->value('id');
            } elseif ($activo && $acuerdo->tipo === 'mover_pedido' && $acuerdo->necesidad_id) {
                ProgramaNecesidad::whereKey($acuerdo->necesidad_id)->lockForUpdate()->value('id');
            }

            $actual = ProgramaAcuerdo::whereKey($acuerdo->id)->lockForUpdate()->firstOrFail();
            if ($actual->activo !== $activo) {
                if ($activo && $actual->tipo === 'adelantar_etapa' && $actual->etapa_id) {
                    $this->exigirHectareas($actual->etapa_id, (float) $actual->hectareas, $servicio, $actual->id);
                } elseif ($activo && $actual->tipo === 'mover_pedido' && $actual->necesidad_id) {
                    $this->exigirCajasMovibles($actual->necesidad_id, (int) $actual->dia_origen, (int) $actual->cajas, $actual->id);
                }

                $antes = $this->instantanea($actual);
                $actual->activo = $activo;
                $actual->save();
                $registro->registrar($programa, 'acuerdo', $actual->id, $activo ? 'activado' : 'desactivado', $antes, $this->instantanea($actual));
                $this->avisarDestino($programa, $destino, $registro);
            }

            $acuerdo->setRawAttributes($actual->getAttributes(), true);
        });

        return response()->json([
            'success' => true,
            'message' => $activo ? 'Acuerdo activado.' : 'Acuerdo desactivado.',
            'data' => $this->payload($acuerdo),
        ]);
    }

    public function destroy(ProgramaAcuerdo $acuerdo, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('acuerdos');

        $programa = $acuerdo->programa()->firstOrFail();
        $destino = $this->programaDestino($acuerdo, $programa, $servicio);

        DB::transaction(function () use ($acuerdo, $programa, $destino, $registro) {
            $antes = $this->instantanea($acuerdo);
            $acuerdo->delete();
            $registro->registrar($programa, 'acuerdo', $acuerdo->id, 'borrado', $antes, null);
            $this->avisarDestino($programa, $destino, $registro);
        });

        return response()->json(['success' => true, 'message' => 'Acuerdo eliminado.']);
    }

    // ── Validación por tipo ─────────────────────────────

    /**
     * Adelantar etapa: la temporada debe estar abierta en la semana, la etapa
     * activa en ella y cosecharse después del sábado; la variedad sale de la
     * etapa. El tope de hectáreas se revisa dentro de la transacción.
     *
     * @return array{etapa_id: int, temporada_id: int, variedad_id: int, dia: int, hectareas: string}
     */
    private function validarAdelanto(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio): array
    {
        $datos = $request->validate([
            'etapa_id' => ['required', 'integer', Rule::exists('etapas', 'id')],
            'temporada_id' => ['required', 'integer'],
            'dia' => ['required', 'integer', 'between:0,5'],
            'hectareas' => ['required', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $temporada = $this->temporadaDeSemana($programa, (int) $datos['temporada_id'], $servicio);
        $etapaId = (int) $datos['etapa_id'];
        if (! $servicio->etapaEnTemporada($etapaId, $temporada->id)) {
            throw ValidationException::withMessages(['etapa_id' => 'La etapa no pertenece a un lote activo de esta temporada.']);
        }

        $etapa = Etapa::whereKey($etapaId)->firstOrFail(['id', 'variedad_id', 'fecha_cosecha_estimada', 'fecha_cosecha_proyectada']);
        $cosecha = ($etapa->fecha_cosecha_proyectada ?? $etapa->fecha_cosecha_estimada)?->toDateString();
        if ($cosecha === null) {
            throw ValidationException::withMessages(['etapa_id' => 'La etapa no tiene fecha de cosecha.']);
        }
        if ($cosecha <= $this->fechaDe($programa, 5)->toDateString()) {
            throw ValidationException::withMessages(['etapa_id' => self::MENSAJE_ETAPA_DE_ESTA_SEMANA]);
        }

        if ($etapa->variedad_id === null) {
            throw ValidationException::withMessages(['etapa_id' => 'La etapa no tiene variedad.']);
        }
        if ($servicio->cultivoDeVariedad((int) $etapa->variedad_id) !== (int) $temporada->cultivo_id) {
            throw ValidationException::withMessages(['etapa_id' => 'La variedad de la etapa no es del cultivo de la temporada.']);
        }

        return [
            'etapa_id' => $etapaId,
            'temporada_id' => $temporada->id,
            'variedad_id' => (int) $etapa->variedad_id,
            'dia' => (int) $datos['dia'],
            'hectareas' => $datos['hectareas'],
        ];
    }

    /**
     * Mover pedido: el renglón debe ser de esta semana y el destino un lunes;
     * en la misma semana solo se mueve a otro día. El programa destino se crea
     * aquí si no existe, para que las cajas movidas se vean allá. El tope de
     * cajas se revisa dentro de la transacción.
     *
     * @return array{necesidad_id: int, dia_origen: int, cajas: int, destino_lunes: string, dia_destino: int}
     */
    private function validarMovimiento(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, ?ProgramaSemanal &$destino): array
    {
        $datos = $request->validate([
            'necesidad_id' => ['required', 'integer'],
            'dia_origen' => ['required', 'integer', 'between:0,5'],
            'cajas' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CAJAS],
            'destino_lunes' => ['required', 'date_format:Y-m-d'],
            'dia_destino' => ['required', 'integer', 'between:0,5'],
        ]);

        $necesidadId = (int) $datos['necesidad_id'];
        if (! ProgramaNecesidad::whereKey($necesidadId)->where('programa_id', $programa->id)->exists()) {
            throw ValidationException::withMessages(['necesidad_id' => 'El renglón de necesidad no es de esta semana.']);
        }

        $lunes = $datos['destino_lunes'];
        if (! $this->esLunes($lunes)) {
            throw ValidationException::withMessages(['destino_lunes' => 'La semana destino se identifica por su lunes (AAAA-MM-DD).']);
        }
        if ($lunes === $programa->lunes->toDateString() && (int) $datos['dia_destino'] === (int) $datos['dia_origen']) {
            throw ValidationException::withMessages(['dia_destino' => 'En la misma semana el pedido se mueve a otro día.']);
        }

        $destino = $servicio->programaDe($lunes);

        return [
            'necesidad_id' => $necesidadId,
            'dia_origen' => (int) $datos['dia_origen'],
            'cajas' => (int) $datos['cajas'],
            'destino_lunes' => $lunes,
            'dia_destino' => (int) $datos['dia_destino'],
        ];
    }

    /**
     * Conseguir producto: la variedad debe ser del cultivo de una temporada
     * abierta en la semana (la indicada, si se indica).
     *
     * @return array{variedad_id: int, temporada_id: ?int, dia: int, cajas: int, origen_tipo: string, origen_texto: ?string}
     */
    private function validarCompra(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio): array
    {
        if (is_string($request->input('origen_texto'))) {
            $texto = trim($request->input('origen_texto'));
            $request->merge(['origen_texto' => $texto === '' ? null : $texto]);
        }

        $datos = $request->validate([
            'variedad_id' => ['required', 'integer'],
            'temporada_id' => ['nullable', 'integer'],
            'dia' => ['required', 'integer', 'between:0,5'],
            'cajas' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CAJAS],
            'origen_tipo' => ['required', Rule::in(array_keys(self::ORIGENES))],
            'origen_texto' => ['nullable', 'string', 'max:150'],
        ]);

        $variedadId = (int) $datos['variedad_id'];
        $cultivoId = $servicio->cultivoDeVariedad($variedadId);
        $temporadaId = isset($datos['temporada_id']) ? (int) $datos['temporada_id'] : null;

        if ($temporadaId !== null) {
            $temporada = $this->temporadaDeSemana($programa, $temporadaId, $servicio);
            if ($cultivoId !== (int) $temporada->cultivo_id) {
                throw ValidationException::withMessages(['variedad_id' => 'La variedad no es del cultivo de la temporada.']);
            }
        } elseif ($cultivoId === null || ! $servicio->temporadasDeSemana($programa)->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
            throw ValidationException::withMessages(['variedad_id' => 'La variedad no es de un cultivo con temporada abierta en esta semana.']);
        }

        return [
            'variedad_id' => $variedadId,
            'temporada_id' => $temporadaId,
            'dia' => (int) $datos['dia'],
            'cajas' => (int) $datos['cajas'],
            'origen_tipo' => $datos['origen_tipo'],
            'origen_texto' => $datos['origen_texto'] ?? null,
        ];
    }

    /** La temporada debe estar abierta y tocar la semana del programa. */
    private function temporadaDeSemana(ProgramaSemanal $programa, int $temporadaId, ProgramaSemanalService $servicio): Temporada
    {
        $temporada = $servicio->temporadasDeSemana($programa)->firstWhere('id', $temporadaId);
        if (! $temporada) {
            throw ValidationException::withMessages(['temporada_id' => 'La temporada no está abierta en esta semana.']);
        }

        return $temporada;
    }

    // ── Topes (dentro de la transacción) ────────────────

    /**
     * Las hectáreas deben caber en lo que le queda a la etapa (cortes de todas
     * las semanas y adelantos activos, sin contar el propio acuerdo). La etapa
     * se bloquea antes de leer para que dos capturas a la vez no la rebasen.
     */
    private function exigirHectareas(int $etapaId, float $hectareas, ProgramaSemanalService $servicio, ?int $ignorarAcuerdoId = null): void
    {
        Etapa::whereKey($etapaId)->lockForUpdate()->value('id');
        $disponibles = $servicio->haDisponibles($etapaId, null, $ignorarAcuerdoId);

        if (round($hectareas, 2) - $disponibles > 0.004) {
            throw ValidationException::withMessages([
                'hectareas' => 'La etapa tiene '.number_format(max(0.0, $disponibles), 2, '.', '').' ha disponibles',
            ]);
        }
    }

    /**
     * Las cajas no pueden pasar las del renglón ese día menos las que ya
     * mueven otros «mover pedido» activos del mismo renglón y día. El renglón
     * se bloquea antes de leer para que dos acuerdos a la vez no lo rebasen.
     */
    private function exigirCajasMovibles(int $necesidadId, int $dia, int $cajas, ?int $ignorarAcuerdoId = null): void
    {
        $necesidad = ProgramaNecesidad::whereKey($necesidadId)->lockForUpdate()->first();
        $enDia = $necesidad && $dia >= 0 && $dia <= 5 ? (int) $necesidad->{"cajas_{$dia}"} : 0;

        $yaMovidas = (int) ProgramaAcuerdo::where('tipo', 'mover_pedido')
            ->where('activo', true)
            ->where('necesidad_id', $necesidadId)
            ->where('dia_origen', $dia)
            ->when($ignorarAcuerdoId, fn ($q) => $q->whereKeyNot($ignorarAcuerdoId))
            ->sum('cajas');

        $disponibles = max(0, $enDia - $yaMovidas);
        if ($cajas > $disponibles) {
            throw ValidationException::withMessages([
                'cajas' => 'El renglón tiene '.number_format($disponibles, 0, '.', ',').' cajas para mover ese día',
            ]);
        }
    }

    // ── Semana destino ──────────────────────────────────

    /**
     * Programa destino de un «mover pedido» cuando es otra semana (se crea si
     * no existe, para que las cajas movidas se vean allá); null en otro caso.
     */
    private function programaDestino(ProgramaAcuerdo $acuerdo, ProgramaSemanal $programa, ProgramaSemanalService $servicio): ?ProgramaSemanal
    {
        if ($acuerdo->tipo !== 'mover_pedido' || $acuerdo->destino_lunes === null) {
            return null;
        }

        $lunes = $acuerdo->destino_lunes->toDateString();

        return $lunes === $programa->lunes->toDateString() ? null : $servicio->programaDe($lunes);
    }

    private function avisarDestino(ProgramaSemanal $programa, ?ProgramaSemanal $destino, RegistroCambiosPrograma $registro): void
    {
        if ($destino !== null && $destino->id !== $programa->id) {
            $registro->emitir($destino);
        }
    }

    // ── Descripción ─────────────────────────────────────

    /**
     * Descripción en español cuando no llega una, p. ej.
     * «Lote 12 · Etapa 1 · Blanco 4.0 ha al mié 25»,
     * «Vallarta · 1,000 cj del mié 25 a lun 30 nov» o
     * «Compra a productor · 1,200 cj Blanco el jue 26».
     *
     * @param  array<string, mixed>  $datos
     */
    private function describir(string $tipo, array $datos, ProgramaSemanal $programa): string
    {
        if ($tipo === 'mover_pedido') {
            $cliente = ProgramaNecesidad::whereKey($datos['necesidad_id'])->value('cliente_nombre') ?? 'Pedido';
            $destino = Carbon::parse($datos['destino_lunes'])->addDays($datos['dia_destino']);

            return sprintf(
                '%s · %s cj del %s a %s %s',
                $cliente,
                number_format($datos['cajas'], 0, '.', ','),
                $this->diaCorto($this->fechaDe($programa, $datos['dia_origen'])),
                $this->diaCorto($destino),
                self::MESES[$destino->month - 1],
            );
        }

        $variedad = Variedad::whereKey($datos['variedad_id'])->value('nombre') ?? 'Producto';
        $fecha = $this->diaCorto($this->fechaDe($programa, $datos['dia']));

        if ($tipo === 'adelantar_etapa') {
            $etapa = Etapa::with('lote:id,nombre')->whereKey($datos['etapa_id'])->first(['id', 'lote_id', 'nombre']);
            $partes = array_filter([$etapa?->lote?->nombre, $etapa?->nombre]);
            $partes[] = sprintf('%s %s ha al %s', $variedad, number_format((float) $datos['hectareas'], 1, '.', ','), $fecha);

            return implode(' · ', $partes);
        }

        $origen = self::ORIGENES[$datos['origen_tipo']];
        if ($datos['origen_texto'] !== null) {
            $origen .= " ({$datos['origen_texto']})";
        }

        return sprintf('%s · %s cj %s el %s', $origen, number_format($datos['cajas'], 0, '.', ','), $variedad, $fecha);
    }

    private function fechaDe(ProgramaSemanal $programa, int $dia): Carbon
    {
        return Carbon::parse($programa->lunes->toDateString())->addDays($dia);
    }

    /** «mié 25»: día de la semana abreviado y día del mes. */
    private function diaCorto(Carbon $fecha): string
    {
        return self::DIAS[$fecha->dayOfWeekIso - 1].' '.$fecha->day;
    }

    private function esLunes(string $fecha): bool
    {
        try {
            $carbon = Carbon::createFromFormat('!Y-m-d', $fecha);
        } catch (InvalidFormatException) {
            return false;
        }

        return $carbon && $carbon->format('Y-m-d') === $fecha && $carbon->isMonday();
    }

    // ── Salidas ─────────────────────────────────────────

    /** Valores del acuerdo para el historial (mismas llaves antes y después, en todos los tipos). */
    private function instantanea(ProgramaAcuerdo $a): array
    {
        return $a->instantaneaHistorial();
    }

    private function payload(ProgramaAcuerdo $a): array
    {
        return ['id' => $a->id, 'programa_id' => $a->programa_id] + $this->instantanea($a);
    }
}
