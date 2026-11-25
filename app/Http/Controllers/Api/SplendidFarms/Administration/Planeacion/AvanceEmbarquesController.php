<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\ValidaLunes;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\AvanceEmbarques;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Avance de embarques (solo lectura): las cargas programadas de la semana contra
 * los embarques salidos, con el atraso y lo que toca hoy. No crea el programa de
 * la semana.
 */
class AvanceEmbarquesController extends Controller
{
    use PermisoPlaneacion;
    use ValidaLunes;

    public function show(Request $request, AvanceEmbarques $avance): JsonResponse
    {
        $this->exigirPermiso('avance-embarques', 'view');

        $lunes = $this->lunesValidado($request);
        $datos = $request->validate([
            'cultivo_id' => ['nullable', 'integer', 'exists:cultivos,id'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $avance->de(
                $lunes,
                isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : null,
                Carbon::now('America/Mazatlan'),
            ),
        ]);
    }
}
