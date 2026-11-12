<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hoja de embarques de la semana: guarda en una sola petición atómica las
 * celdas (cultivo · bodega · tipo · día, en cargas) que cambiaron. Exige
 * `embarques.capturar`.
 *
 * Solo toca las celdas enviadas: una celda con 0 o vacía borra la fila de esa
 * combinación. Con un error en cualquier celda no se guarda ninguna.
 *
 * Quitar o bajar cargas nunca depende del estado de los catálogos (temporada
 * cerrada, bodega desactivada): solo se validan temporada y bodega cuando la
 * celda es nueva o sube sus cargas, para que un embarque que quedó fuera de
 * regla pueda limpiarse y una hoja completa con celdas sin cambio no falle.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class HojaEmbarquesController extends Controller
{
    use PermisoPlaneacion;

    private const MENSAJE_TEMPORADA = 'El cultivo no tiene una temporada abierta en esta semana.';

    private const MENSAJE_BODEGA = 'La bodega no existe o está desactivada.';

    private const MAX_CARGAS = 99;

    public function guardar(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('embarques', 'capturar');

        $max = self::MAX_CARGAS;
        $datos = $request->validate([
            'celdas' => ['sometimes', 'array', 'max:600'],
            'celdas.*' => ['array'],
            'celdas.*.cultivo_id' => ['required', 'integer'],
            'celdas.*.bodega_id' => ['required', 'integer'],
            'celdas.*.tipo' => ['required', 'in:full,sencillo'],
            'celdas.*.dia' => ['required', 'integer', 'between:0,5'],
            'celdas.*.cargas' => ['present', 'nullable', 'integer', "between:0,$max"],
        ]);

        $celdas = array_values($datos['celdas'] ?? []);

        try {
            DB::transaction(function () use ($programa, $celdas, $servicio, $registro) {
                // El bloqueo de la fila del programa (la semana) DEBE ser la
                // primera sentencia de la transacción. Se bloquea el padre y no
                // las filas de embarque: con la semana vacía un `lockForUpdate`
                // sobre `programa_id` solo toma un bloqueo de hueco, y los
                // bloqueos de hueco no chocan entre sí: dos primeras capturas
                // simultáneas lo pasarían, verían la semana vacía y sus INSERT
                // se bloquearían mutuamente (deadlock 1213, un 500). Con el
                // padre bloqueado todas las escrituras de la semana se
                // serializan, haya o no filas. Además MySQL corre en REPEATABLE
                // READ: la «foto» se toma en la primera lectura sin bloqueo, así
                // que si cualquier otra lectura (embarques, bodegas, temporadas)
                // va antes, quien espera el bloqueo decide con la foto vieja: no
                // ve lo que la otra captura acaba de confirmar y la pisa.
                // (SQLite en pruebas no puede ejercitarlo.)
                if ($celdas !== []) {
                    ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->value('id');
                }

                $existentes = $celdas === []
                    ? collect()
                    : ProgramaEmbarque::where('programa_id', $programa->id)->get()
                        ->keyBy(fn (ProgramaEmbarque $e) => self::clave($e->cultivo_id, $e->bodega_id, $e->tipo, $e->dia));

                $plan = $this->planear($programa, $celdas, $existentes, $servicio);

                // Todo está validado: desde aquí solo se escribe.
                $hubo = false;
                foreach ($plan as $paso) {
                    $hubo = $this->aplicar($programa, $paso) || $hubo;
                }

                if ($hubo) {
                    $registro->emitir($programa);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Otra captura creó la misma celda entre nuestra lectura y la escritura.
            throw ValidationException::withMessages(['celdas' => 'Otra captura cambió esta hoja al mismo tiempo. Recarga la semana e inténtalo de nuevo.']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hoja de embarques guardada.',
            'data' => $servicio->semana($programa->fresh(), $this->permisosUsuario()),
        ]);
    }

    private static function clave(int|string $cultivoId, int|string $bodegaId, string $tipo, int|string $dia): string
    {
        return (int) $cultivoId.'|'.(int) $bodegaId.'|'.$tipo.'|'.(int) $dia;
    }

    /**
     * Valida las celdas y devuelve lo que hay que escribir; si algo falla
     * lanza un 422 y no se ha tocado nada.
     *
     * @param  list<array{cultivo_id: int|string, bodega_id: int|string, tipo: string, dia: int|string, cargas: int|string|null}>  $celdas
     * @param  Collection<string, ProgramaEmbarque>  $existentes
     * @return list<array{actual: ?ProgramaEmbarque, cultivo_id: int, bodega_id: int, tipo: string, dia: int, cargas: int}>
     */
    private function planear(ProgramaSemanal $programa, array $celdas, Collection $existentes, ProgramaSemanalService $servicio): array
    {
        if ($celdas === []) {
            return [];
        }

        $temporadas = $servicio->temporadasDeSemana($programa);
        $bodegasActivas = BodegaDestino::whereIn('id', array_values(array_unique(array_map(fn ($c) => (int) $c['bodega_id'], $celdas))))
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $vistas = [];
        $plan = [];
        foreach ($celdas as $i => $celda) {
            $clave = self::clave($celda['cultivo_id'], $celda['bodega_id'], $celda['tipo'], $celda['dia']);
            if (isset($vistas[$clave])) {
                throw ValidationException::withMessages(["celdas.$i.cargas" => 'La celda viene repetida en la misma petición.']);
            }
            $vistas[$clave] = true;

            $actual = $existentes->get($clave);
            $cargas = (int) ($celda['cargas'] ?? 0);
            $cultivoId = (int) $celda['cultivo_id'];
            $bodegaId = (int) $celda['bodega_id'];

            // Solo una celda nueva o que sube sus cargas se valida contra los
            // catálogos: borrar y bajar siempre se puede.
            if ($cargas > ($actual?->cargas ?? 0)) {
                if (! $temporadas->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
                    throw ValidationException::withMessages(["celdas.$i.cultivo_id" => self::MENSAJE_TEMPORADA]);
                }
                if (! in_array($bodegaId, $bodegasActivas, true)) {
                    throw ValidationException::withMessages(["celdas.$i.bodega_id" => self::MENSAJE_BODEGA]);
                }
            }

            $plan[] = [
                'actual' => $actual,
                'cultivo_id' => $cultivoId,
                'bodega_id' => $bodegaId,
                'tipo' => (string) $celda['tipo'],
                'dia' => (int) $celda['dia'],
                'cargas' => $cargas,
            ];
        }

        return $plan;
    }

    /**
     * Sin cambio de valor no hay escritura ni aviso.
     *
     * @param  array{actual: ?ProgramaEmbarque, cultivo_id: int, bodega_id: int, tipo: string, dia: int, cargas: int}  $paso
     */
    private function aplicar(ProgramaSemanal $programa, array $paso): bool
    {
        $actual = $paso['actual'];

        if ($paso['cargas'] === 0) {
            if ($actual === null) {
                return false;
            }
            $actual->delete();

            return true;
        }

        if ($actual === null) {
            ProgramaEmbarque::create([
                'programa_id' => $programa->id,
                'cultivo_id' => $paso['cultivo_id'],
                'bodega_id' => $paso['bodega_id'],
                'tipo' => $paso['tipo'],
                'dia' => $paso['dia'],
                'cargas' => $paso['cargas'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return true;
        }

        if ((int) $actual->cargas === $paso['cargas']) {
            return false;
        }
        $actual->cargas = $paso['cargas'];
        $actual->updated_by = Auth::id();
        $actual->save();

        return true;
    }
}
