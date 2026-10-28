<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Corte de la semana (hectáreas por etapa y día) y rendimiento elegido para
 * cada variedad. Todas las escrituras exigen `capturar-corte`, quedan en el
 * historial del programa (si ya hay junta) y avisan en tiempo real.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaCorteController extends Controller
{
    use PermisoProgramaSemanal;

    private const MODOS_RENDIMIENTO = ['minimo', 'medio', 'maximo', 'exacto'];

    /** Crea un renglón de corte en la semana. */
    public function store(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-corte');

        $datos = $this->validarCorte($request, $programa, $servicio);

        $corte = DB::transaction(function () use ($programa, $datos, $servicio, $registro) {
            $this->exigirHectareas($datos['etapa_id'], (float) $datos['hectareas'], $servicio);

            $corte = ProgramaCorte::create($datos + [
                'programa_id' => $programa->id,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $registro->registrar($programa, 'corte', $corte->id, 'creado', null, $this->instantanea($corte));

            return $corte;
        });

        return response()->json([
            'success' => true,
            'message' => 'Corte guardado.',
            'data' => $this->payload($corte),
        ], 201);
    }

    /** Cambia un renglón de corte; el programa es el del corte. */
    public function update(Request $request, ProgramaCorte $corte, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-corte');

        $programa = $corte->programa()->firstOrFail();
        $datos = $this->validarCorte($request, $programa, $servicio);

        DB::transaction(function () use ($corte, $programa, $datos, $servicio, $registro) {
            // Sus propias hectáreas no cuentan contra la etapa.
            $this->exigirHectareas($datos['etapa_id'], (float) $datos['hectareas'], $servicio, $corte->id);

            $antes = $this->instantanea($corte);
            $corte->update($datos + ['updated_by' => Auth::id()]);
            $registro->registrar($programa, 'corte', $corte->id, 'actualizado', $antes, $this->instantanea($corte));
        });

        return response()->json([
            'success' => true,
            'message' => 'Corte actualizado.',
            'data' => $this->payload($corte->fresh()),
        ]);
    }

    public function destroy(ProgramaCorte $corte, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-corte');

        $programa = $corte->programa()->firstOrFail();

        DB::transaction(function () use ($corte, $programa, $registro) {
            $antes = $this->instantanea($corte);
            $corte->delete();
            $registro->registrar($programa, 'corte', $corte->id, 'borrado', $antes, null);
        });

        return response()->json(['success' => true, 'message' => 'Corte eliminado.']);
    }

    /**
     * Reparte las hectáreas de una etapa en varios días: crea todos los
     * renglones o ninguno, y la suma del reparto debe caber en la etapa.
     */
    public function repartir(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-corte');

        $datos = $request->validate([
            'etapa_id' => ['required', 'integer', Rule::exists('etapas', 'id')],
            'temporada_id' => ['required', 'integer'],
            'reparto' => ['required', 'array', 'min:1', 'max:36'],
            'reparto.*.dia' => ['required', 'integer', 'between:0,5'],
            'reparto.*.hectareas' => $this->reglasHectareas(),
        ]);

        $temporada = $this->temporadaDeSemana($programa, (int) $datos['temporada_id'], $servicio);
        [$etapaId, $variedadId] = $this->etapaYVariedad($temporada, (int) $datos['etapa_id'], null, $servicio);
        $total = array_sum(array_map(fn ($r) => (float) $r['hectareas'], $datos['reparto']));

        $cortes = DB::transaction(function () use ($programa, $datos, $etapaId, $variedadId, $total, $servicio, $registro) {
            $this->exigirHectareas($etapaId, $total, $servicio);

            $creados = [];
            foreach ($datos['reparto'] as $parte) {
                $corte = ProgramaCorte::create([
                    'programa_id' => $programa->id,
                    'temporada_id' => (int) $datos['temporada_id'],
                    'etapa_id' => $etapaId,
                    'variedad_id' => $variedadId,
                    'dia' => (int) $parte['dia'],
                    'hectareas' => $parte['hectareas'],
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $registro->registrar($programa, 'corte', $corte->id, 'creado', null, $this->instantanea($corte));
                $creados[] = $corte;
            }

            return $creados;
        });

        return response()->json([
            'success' => true,
            'message' => 'Corte repartido.',
            'data' => array_map(fn ($c) => $this->payload($c), $cortes),
        ], 201);
    }

    /** Rendimiento elegido por (temporada, variedad) para esta semana; crea o actualiza cada fila. */
    public function rendimientos(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-corte');

        $datos = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:200'],
            'filas.*.temporada_id' => ['required', 'integer'],
            'filas.*.variedad_id' => ['required', 'integer'],
            'filas.*.modo' => ['required', Rule::in(self::MODOS_RENDIMIENTO)],
            'filas.*.valor_exacto' => ['nullable', 'required_if:filas.*.modo,exacto', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $temporadas = $servicio->temporadasDeSemana($programa)->keyBy('id');
        $vistas = [];
        foreach ($datos['filas'] as $i => $fila) {
            $temporada = $temporadas->get((int) $fila['temporada_id']);
            if (! $temporada) {
                throw ValidationException::withMessages(["filas.$i.temporada_id" => 'La temporada no está abierta en esta semana.']);
            }
            if ($servicio->cultivoDeVariedad((int) $fila['variedad_id']) !== (int) $temporada->cultivo_id) {
                throw ValidationException::withMessages(["filas.$i.variedad_id" => 'La variedad no es del cultivo de la temporada.']);
            }
            $llave = $fila['temporada_id'].'-'.$fila['variedad_id'];
            if (isset($vistas[$llave])) {
                throw ValidationException::withMessages(["filas.$i.variedad_id" => 'La variedad está repetida en la misma temporada.']);
            }
            $vistas[$llave] = true;
        }

        $guardadas = DB::transaction(function () use ($programa, $datos, $registro) {
            $salida = [];
            foreach ($datos['filas'] as $fila) {
                $llave = [
                    'programa_id' => $programa->id,
                    'temporada_id' => (int) $fila['temporada_id'],
                    'variedad_id' => (int) $fila['variedad_id'],
                ];
                // El valor exacto solo se guarda con el modo exacto.
                $valores = [
                    'modo' => $fila['modo'],
                    'valor_exacto' => $fila['modo'] === 'exacto' ? $fila['valor_exacto'] : null,
                ];

                $existente = ProgramaRendimiento::where($llave)->lockForUpdate()->first();
                $antes = $existente ? $this->instantaneaRendimiento($existente) : null;
                $rendimiento = $existente ?? new ProgramaRendimiento($llave);
                $rendimiento->fill($valores);

                // Sin cambio de valor no hay escritura ni historial.
                if ($existente && ! $rendimiento->isDirty()) {
                    $salida[] = $rendimiento;

                    continue;
                }

                $rendimiento->save();
                $registro->registrar(
                    $programa,
                    'rendimiento',
                    $rendimiento->id,
                    $existente ? 'actualizado' : 'creado',
                    $antes,
                    $this->instantaneaRendimiento($rendimiento),
                );
                $salida[] = $rendimiento;
            }

            return $salida;
        });

        return response()->json([
            'success' => true,
            'message' => 'Rendimientos guardados.',
            'data' => array_map(fn ($r) => $this->instantaneaRendimiento($r), $guardadas),
        ]);
    }

    // ── Validación ──────────────────────────────────────

    /** @return list<mixed> */
    private function reglasHectareas(): array
    {
        return ['required', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'];
    }

    /**
     * Campos del corte ya validados, con `variedad_id` resuelta (la de la
     * etapa, o la elegida si no hay etapa).
     *
     * @return array{temporada_id: int, etapa_id: ?int, variedad_id: int, dia: int, hectareas: string, notas: ?string}
     */
    private function validarCorte(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio): array
    {
        $datos = $request->validate([
            'temporada_id' => ['required', 'integer'],
            'etapa_id' => ['nullable', 'integer', Rule::exists('etapas', 'id')],
            'variedad_id' => ['nullable', 'integer'],
            'dia' => ['required', 'integer', 'between:0,5'],
            'hectareas' => $this->reglasHectareas(),
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $temporada = $this->temporadaDeSemana($programa, (int) $datos['temporada_id'], $servicio);
        [$etapaId, $variedadId] = $this->etapaYVariedad(
            $temporada,
            isset($datos['etapa_id']) ? (int) $datos['etapa_id'] : null,
            isset($datos['variedad_id']) ? (int) $datos['variedad_id'] : null,
            $servicio,
        );

        return [
            'temporada_id' => $temporada->id,
            'etapa_id' => $etapaId,
            'variedad_id' => $variedadId,
            'dia' => (int) $datos['dia'],
            'hectareas' => $datos['hectareas'],
            'notas' => $datos['notas'] ?? null,
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

    /**
     * Con etapa, la etapa debe ser de un lote activo de la temporada y la
     * variedad sale de ella (si no tiene, se usa la elegida). Sin etapa la
     * variedad es obligatoria. En ambos casos debe ser del cultivo de la temporada.
     *
     * @return array{0: ?int, 1: int}
     */
    private function etapaYVariedad(Temporada $temporada, ?int $etapaId, ?int $variedadId, ProgramaSemanalService $servicio): array
    {
        $campo = 'variedad_id';
        if ($etapaId !== null) {
            if (! $servicio->etapaEnTemporada($etapaId, $temporada->id)) {
                throw ValidationException::withMessages(['etapa_id' => 'La etapa no pertenece a un lote activo de esta temporada.']);
            }
            $deEtapa = Etapa::whereKey($etapaId)->value('variedad_id');
            if ($deEtapa !== null) {
                $variedadId = (int) $deEtapa;
                $campo = 'etapa_id';
            }
        }

        if ($variedadId === null) {
            throw ValidationException::withMessages([$campo => $etapaId !== null
                ? 'La etapa no tiene variedad: elige la variedad del corte.'
                : 'Elige la variedad del corte.']);
        }
        if ($servicio->cultivoDeVariedad($variedadId) !== (int) $temporada->cultivo_id) {
            throw ValidationException::withMessages([$campo => 'La variedad no es del cultivo de la temporada.']);
        }

        return [$etapaId, $variedadId];
    }

    /**
     * Las hectáreas pedidas deben caber en lo que le queda a la etapa (cortes
     * de todas las semanas y adelantos activos). Se bloquea la etapa para que
     * dos capturas a la vez no la rebasen. Sin etapa no hay tope.
     */
    private function exigirHectareas(?int $etapaId, float $hectareas, ProgramaSemanalService $servicio, ?int $ignorarCorteId = null): void
    {
        if ($etapaId === null) {
            return;
        }

        Etapa::whereKey($etapaId)->lockForUpdate()->value('id');
        $disponibles = $servicio->haDisponibles($etapaId, $ignorarCorteId);

        if (round($hectareas, 2) - $disponibles > 0.004) {
            throw ValidationException::withMessages([
                'hectareas' => 'La etapa tiene '.number_format(max(0.0, $disponibles), 1, '.', '').' ha disponibles',
            ]);
        }
    }

    // ── Salidas ─────────────────────────────────────────

    /** Valores del corte para el historial (mismas llaves antes y después). */
    private function instantanea(ProgramaCorte $corte): array
    {
        return [
            'temporada_id' => $corte->temporada_id,
            'etapa_id' => $corte->etapa_id,
            'variedad_id' => $corte->variedad_id,
            'dia' => $corte->dia,
            'hectareas' => (float) $corte->hectareas,
            'notas' => $corte->notas,
        ];
    }

    private function instantaneaRendimiento(ProgramaRendimiento $r): array
    {
        return [
            'temporada_id' => $r->temporada_id,
            'variedad_id' => $r->variedad_id,
            'modo' => $r->modo,
            'valor_exacto' => $r->valor_exacto !== null ? (float) $r->valor_exacto : null,
        ];
    }

    private function payload(ProgramaCorte $corte): array
    {
        return ['id' => $corte->id, 'programa_id' => $corte->programa_id, 'sin_etapa' => $corte->etapa_id === null]
            + $this->instantanea($corte);
    }
}
