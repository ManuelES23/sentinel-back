<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hoja de corte de la semana: guarda en una sola petición atómica las celdas
 * (etapa × día, en hectáreas) que cambiaron. Exige `plan-corte.capturar`.
 *
 * Solo toca las celdas enviadas: una celda en 0 o vacía borra el corte de esa
 * etapa y día, y si hubiera varios cortes viejos de la misma etapa y día se
 * juntan en uno. Los cortes sin etapa solo pueden bajar o borrarse.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class HojaCorteController extends Controller
{
    use PermisoPlaneacion;

    /** Tolerancia al comparar hectáreas ya redondeadas a 2 decimales. */
    private const TOLERANCIA = 0.004;

    public function guardar(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('plan-corte', 'capturar');

        $datos = $request->validate([
            'celdas' => ['sometimes', 'array', 'max:600'],
            'celdas.*' => ['array'],
            'celdas.*.temporada_id' => ['required', 'integer'],
            'celdas.*.etapa_id' => ['required', 'integer'],
            'celdas.*.dia' => ['required', 'integer', 'between:0,5'],
            'celdas.*.hectareas' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'sin_etapa' => ['sometimes', 'array', 'max:200'],
            'sin_etapa.*' => ['array'],
            'sin_etapa.*.corte_id' => ['required', 'integer'],
            'sin_etapa.*.hectareas' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $celdas = $datos['celdas'] ?? [];
        $sinEtapa = $datos['sin_etapa'] ?? [];

        DB::transaction(function () use ($programa, $celdas, $sinEtapa, $servicio, $registro) {
            // El bloqueo de las etapas DEBE ser la primera sentencia de la
            // transacción. MySQL corre en REPEATABLE READ: la «foto» se toma en
            // la primera lectura sin bloqueo, así que si cualquier lectura
            // (temporadas, etapas, suma de cortes) va antes, quien espera el
            // bloqueo obtiene la etapa pero sigue viendo la foto vieja, no ve
            // los cortes que la otra captura acaba de confirmar y las dos
            // rebasan la superficie. (SQLite en pruebas no puede ejercitarlo.)
            $etapaIds = array_values(array_unique(array_map(fn ($c) => (int) $c['etapa_id'], $celdas)));
            sort($etapaIds);
            if ($etapaIds !== []) {
                Etapa::whereIn('id', $etapaIds)->orderBy('id')->lockForUpdate()->pluck('id');
            }

            $cambios = $this->planearCeldas($programa, $celdas, $servicio);
            $bajas = $this->planearSinEtapa($programa, $sinEtapa);

            // Todo está validado: desde aquí solo se escribe.
            $hubo = $this->aplicarCeldas($programa, $cambios);
            $hubo = $this->aplicarSinEtapa($bajas) || $hubo;

            if ($hubo) {
                $registro->emitir($programa);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Hoja de corte guardada.',
            'data' => $servicio->semana($programa->fresh(), $this->permisosUsuario()),
        ]);
    }

    // ── Validación ──────────────────────────────────────

    /**
     * Valida las celdas y el tope de cada etapa con todos los cambios del
     * lote aplicados. Devuelve lo que hay que escribir; si algo falla lanza
     * un 422 y no se ha tocado nada.
     *
     * Una celda en 0 o vacía solo borra: no se valida temporada, etapa ni
     * variedad (un corte que quedó fuera de regla porque se cerró la
     * temporada o se desactivó el lote debe poder limpiarse) y no entra al tope.
     *
     * @param  list<array{temporada_id: int|string, etapa_id: int|string, dia: int|string, hectareas: mixed}>  $celdas
     * @return list<array{temporada_id: ?int, etapa_id: int, variedad_id: ?int, dia: int, hectareas: float}>
     */
    private function planearCeldas(ProgramaSemanal $programa, array $celdas, ProgramaSemanalService $servicio): array
    {
        if ($celdas === []) {
            return [];
        }

        $temporadas = $servicio->temporadasDeSemana($programa)->keyBy('id');
        $etapas = Etapa::whereIn('id', array_unique(array_map(fn ($c) => (int) $c['etapa_id'], $celdas)))
            ->get(['id', 'codigo', 'nombre', 'superficie', 'variedad_id'])
            ->keyBy('id');

        $vistas = [];
        $planeadas = [];
        foreach ($celdas as $i => $celda) {
            $temporadaId = (int) $celda['temporada_id'];
            $etapaId = (int) $celda['etapa_id'];
            $dia = (int) $celda['dia'];
            $hectareas = round((float) ($celda['hectareas'] ?? 0), 2);

            $llave = $etapaId.'-'.$dia;
            if (isset($vistas[$llave])) {
                throw ValidationException::withMessages(["celdas.$i.dia" => 'La etapa y el día están repetidos en la petición.']);
            }
            $vistas[$llave] = true;

            if ($hectareas <= 0) {
                $planeadas[$i] = ['temporada_id' => null, 'etapa_id' => $etapaId, 'variedad_id' => null, 'dia' => $dia, 'hectareas' => 0.0];

                continue;
            }

            $temporada = $temporadas->get($temporadaId);
            if (! $temporada) {
                throw ValidationException::withMessages(["celdas.$i.temporada_id" => 'La temporada no está abierta en esta semana.']);
            }
            $etapa = $etapas->get($etapaId);
            if (! $etapa || ! $servicio->etapaEnTemporada($etapaId, $temporadaId)) {
                throw ValidationException::withMessages(["celdas.$i.etapa_id" => 'La etapa no pertenece a un lote activo de esta temporada.']);
            }
            if ($etapa->variedad_id === null) {
                throw ValidationException::withMessages(["celdas.$i.etapa_id" => 'La etapa no tiene variedad: asígnale una en el plan de siembra.']);
            }
            if ($servicio->cultivoDeVariedad((int) $etapa->variedad_id) !== (int) $temporada->cultivo_id) {
                throw ValidationException::withMessages(["celdas.$i.etapa_id" => 'La variedad de la etapa no es del cultivo de la temporada.']);
            }

            $planeadas[$i] = [
                'temporada_id' => $temporadaId,
                'etapa_id' => $etapaId,
                'variedad_id' => (int) $etapa->variedad_id,
                'dia' => $dia,
                'hectareas' => $hectareas,
            ];
        }

        $this->exigirTope($programa, $planeadas, $etapas, $servicio);

        return array_values($planeadas);
    }

    /**
     * Ninguna etapa puede pasar de su superficie sumando sus cortes de todas
     * las semanas, con los cambios de este lote ya aplicados. Las etapas ya
     * están bloqueadas (ver `guardar`). El error se marca en la primera celda
     * que hace pasarse a la etapa.
     *
     * Bajar nunca hace que se pase más: si la superficie se redujo y la etapa
     * ya estaba rebasada, se acepta cualquier total que no suba respecto de lo
     * que las celdas del lote ya tenían guardado. Por eso el límite es
     * `max(libres, ya_guardado)`.
     *
     * @param  array<int, array{etapa_id: int, dia: int, hectareas: float}>  $planeadas  Con la posición original de cada celda (las de 0 también, solo cuentan para saber qué reemplazan).
     * @param  Collection<int, Etapa>  $etapas  Las etapas de las celdas con hectáreas.
     */
    private function exigirTope(ProgramaSemanal $programa, array $planeadas, Collection $etapas, ProgramaSemanalService $servicio): void
    {
        // Libre de la etapa sin contar los cortes que esta hoja reemplaza, y
        // lo que esos cortes tenían guardado.
        $limites = [];
        foreach ($etapas as $etapaId => $etapa) {
            $deLaEtapa = array_filter($planeadas, fn ($c) => $c['etapa_id'] === $etapaId);
            if (array_sum(array_column($deLaEtapa, 'hectareas')) <= 0) {
                continue; // solo borra: no entra al tope
            }
            $dias = array_column($deLaEtapa, 'dia');
            $reemplazados = ProgramaCorte::where('programa_id', $programa->id)
                ->where('etapa_id', $etapaId)
                ->whereIn('dia', $dias)
                ->get(['id', 'hectareas']);
            $libres = $servicio->haDisponibles($etapaId, $reemplazados->pluck('id')->all());
            $guardado = round((float) $reemplazados->sum('hectareas'), 2);

            $limites[$etapaId] = max($libres, $guardado);
        }

        $acumulado = [];
        foreach ($planeadas as $i => $celda) {
            $etapaId = $celda['etapa_id'];
            if ($celda['hectareas'] <= 0 || ! isset($limites[$etapaId])) {
                continue;
            }
            $acumulado[$etapaId] = round(($acumulado[$etapaId] ?? 0.0) + $celda['hectareas'], 2);

            if ($acumulado[$etapaId] - $limites[$etapaId] > self::TOLERANCIA) {
                $etapa = $etapas->get($etapaId);
                $exceso = round($acumulado[$etapaId] - $limites[$etapaId], 2);

                throw ValidationException::withMessages(["celdas.$i.hectareas" => sprintf(
                    '%s: pasa %s ha de las %s disponibles (superficie %s ha).',
                    $etapa->codigo ?: $etapa->nombre,
                    number_format($exceso, 2, '.', ''),
                    number_format(max(0.0, $limites[$etapaId]), 2, '.', ''),
                    number_format((float) $etapa->superficie, 2, '.', ''),
                )]);
            }
        }
    }

    /**
     * Los cortes sin etapa del programa solo pueden bajar o borrarse.
     *
     * @param  list<array{corte_id: int|string, hectareas: mixed}>  $filas
     * @return list<array{corte: ProgramaCorte, hectareas: float}>
     */
    private function planearSinEtapa(ProgramaSemanal $programa, array $filas): array
    {
        $vistos = [];
        $bajas = [];
        foreach ($filas as $i => $fila) {
            $corteId = (int) $fila['corte_id'];
            if (isset($vistos[$corteId])) {
                throw ValidationException::withMessages(["sin_etapa.$i.corte_id" => 'El corte está repetido en la petición.']);
            }
            $vistos[$corteId] = true;

            $corte = ProgramaCorte::where('programa_id', $programa->id)
                ->whereNull('etapa_id')
                ->whereKey($corteId)
                ->lockForUpdate()
                ->first();
            if (! $corte) {
                throw ValidationException::withMessages(["sin_etapa.$i.corte_id" => 'El corte no existe en esta semana o ya tiene etapa.']);
            }

            $nuevas = round((float) ($fila['hectareas'] ?? 0), 2);
            if ($nuevas - (float) $corte->hectareas > self::TOLERANCIA) {
                throw ValidationException::withMessages(["sin_etapa.$i.hectareas" => 'Un corte sin etapa solo puede bajar o borrarse (tiene '
                    .number_format((float) $corte->hectareas, 2, '.', '').' ha).']);
            }

            $bajas[] = ['corte' => $corte, 'hectareas' => $nuevas];
        }

        return $bajas;
    }

    // ── Escritura ───────────────────────────────────────

    /**
     * @param  list<array{temporada_id: int, etapa_id: int, variedad_id: int, dia: int, hectareas: float}>  $cambios
     */
    private function aplicarCeldas(ProgramaSemanal $programa, array $cambios): bool
    {
        $hubo = false;
        foreach ($cambios as $celda) {
            $viejos = ProgramaCorte::where('programa_id', $programa->id)
                ->where('etapa_id', $celda['etapa_id'])
                ->where('dia', $celda['dia'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // En cero (o vacía) no queda ningún corte de esa etapa y día.
            if ($celda['hectareas'] <= 0) {
                foreach ($viejos as $viejo) {
                    $viejo->delete();
                    $hubo = true;
                }

                continue;
            }

            // Se conserva el primero y los duplicados viejos se juntan en él.
            $corte = $viejos->shift();
            foreach ($viejos as $duplicado) {
                $duplicado->delete();
                $hubo = true;
            }

            if ($corte === null) {
                ProgramaCorte::create($celda + [
                    'programa_id' => $programa->id,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $hubo = true;

                continue;
            }

            $corte->fill(['temporada_id' => $celda['temporada_id'], 'variedad_id' => $celda['variedad_id'], 'hectareas' => $celda['hectareas']]);
            if ($corte->isDirty()) {
                $corte->updated_by = Auth::id();
                $corte->save();
                $hubo = true;
            }
        }

        return $hubo;
    }

    /**
     * @param  list<array{corte: ProgramaCorte, hectareas: float}>  $bajas
     */
    private function aplicarSinEtapa(array $bajas): bool
    {
        $hubo = false;
        foreach ($bajas as ['corte' => $corte, 'hectareas' => $hectareas]) {
            if ($hectareas <= 0) {
                $corte->delete();
                $hubo = true;

                continue;
            }

            $corte->hectareas = $hectareas;
            if ($corte->isDirty()) {
                $corte->updated_by = Auth::id();
                $corte->save();
                $hubo = true;
            }
        }

        return $hubo;
    }
}
