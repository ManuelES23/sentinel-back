<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DomingoPrograma;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Interruptor «Se trabaja el domingo» de la semana. Exige
 * `tablero-semanal.editar-notas`.
 *
 * Apagarlo con datos en el día 6 (cortes con hectáreas, necesidades o
 * sobrantes con cajas, embarques con cargas) responde 422 y no cambia nada.
 * El mismo valor es un 200 sin escritura ni aviso.
 */
class DomingoProgramaController extends Controller
{
    use PermisoPlaneacion;

    public function guardar(Request $request, ProgramaSemanal $programa, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('tablero-semanal', 'editar-notas');

        $datos = $request->validate([
            'trabaja_domingo' => ['required', 'boolean'],
        ]);
        $nuevo = (bool) $datos['trabaja_domingo'];

        DB::transaction(function () use ($programa, $nuevo, $registro) {
            // El bloqueo de la fila del programa DEBE ser la primera sentencia
            // de la transacción: MySQL (REPEATABLE READ) toma la «foto» en la
            // primera lectura sin bloqueo. Las hojas releen el interruptor con
            // esta misma fila bloqueada antes de guardar algo en el domingo, así
            // que una captura simultánea espera a este cambio o este conteo ve
            // lo que esa captura ya confirmó. (SQLite en pruebas no puede ejercitarlo.)
            // Se escribe sobre la fila leída con el bloqueo y no sobre el modelo
            // ligado por la ruta: ese se cargó antes y puede estar viejo (con su
            // valor original igual al pedido, save() no escribiría nada).
            $fila = ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->firstOrFail();

            // Sin cambio de valor no hay escritura ni aviso.
            if ($fila->trabaja_domingo === $nuevo) {
                return;
            }

            if (! $nuevo) {
                $datosDomingo = DomingoPrograma::datosDelDomingo($fila);
                if ($datosDomingo !== null) {
                    throw ValidationException::withMessages(['trabaja_domingo' => $datosDomingo]);
                }
            }

            $fila->trabaja_domingo = $nuevo;
            $fila->save();
            $registro->emitir($fila);
        });

        return response()->json([
            'success' => true,
            'message' => $nuevo ? 'La semana ahora se trabaja el domingo.' : 'La semana ahora no se trabaja el domingo.',
            'data' => ['trabaja_domingo' => $nuevo],
        ]);
    }
}
