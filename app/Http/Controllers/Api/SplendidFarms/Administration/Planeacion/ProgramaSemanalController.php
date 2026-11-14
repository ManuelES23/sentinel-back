<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\ProgramaSemanalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Programa semanal: la semana completa que leen todos los submódulos de
 * Planeación (se crea la primera vez que se abre). La abre quien ve el
 * Tablero, el Plan de corte, las Necesidades o los Embarques.
 *
 * El servicio se inyecta por método y no por constructor: guarda cálculos en
 * memoria y el controlador puede quedar vivo entre peticiones (la ruta lo
 * conserva), así que cada petición necesita su propia instancia.
 */
class ProgramaSemanalController extends Controller
{
    use PermisoPlaneacion;

    public function show(Request $request, ProgramaSemanalService $servicio): JsonResponse
    {
        $this->exigirAlguno([
            ['tablero-semanal', 'view'],
            ['plan-corte', 'view'],
            ['necesidades-venta', 'view'],
            ['embarques', 'view'],
        ]);

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
