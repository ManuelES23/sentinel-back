<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\ProgramaSemanalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Programa semanal de corte, empaque, ventas y logística: la semana completa
 * (se crea la primera vez que se abre).
 *
 * El servicio se inyecta por método y no por constructor: guarda cálculos en
 * memoria y el controlador puede quedar vivo entre peticiones (la ruta lo
 * conserva), así que cada petición necesita su propia instancia.
 */
class ProgramaSemanalController extends Controller
{
    use PermisoProgramaSemanal;

    public function show(Request $request, ProgramaSemanalService $servicio): JsonResponse
    {
        $this->exigirPermiso('view');

        $datos = $request->validate([
            'lunes' => ['required', 'date_format:Y-m-d'],
        ]);

        $programa = $servicio->programaDe($datos['lunes']);

        return response()->json([
            'success' => true,
            'data' => $servicio->semana($programa, $this->permisosUsuario()),
        ]);
    }
}
