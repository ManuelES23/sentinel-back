<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notas libres de la semana (cuadro «Notas de la semana» del Tablero semanal).
 * Exige `tablero-semanal.editar-notas`. Vacío o solo espacios borra las notas.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class NotasProgramaController extends Controller
{
    use PermisoPlaneacion;

    public function guardar(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('tablero-semanal', 'editar-notas');

        $datos = $request->validate([
            'notas' => ['present', 'nullable', 'string', 'max:5000'],
        ]);

        $notas = isset($datos['notas']) ? trim($datos['notas']) : null;
        $notas = $notas === '' ? null : $notas;

        DB::transaction(function () use ($programa, $notas, $registro) {
            // Sin cambio de valor no hay escritura ni aviso.
            if ($programa->notas === $notas) {
                return;
            }

            $programa->notas = $notas;
            $programa->save();
            $registro->emitir($programa);
        });

        return response()->json([
            'success' => true,
            'message' => 'Notas guardadas.',
            'data' => $servicio->semana($programa->fresh(), $this->permisosUsuario()),
        ]);
    }
}
