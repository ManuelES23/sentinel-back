<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\AvanceEmpaque;
use App\Services\Planeacion\CatalogoPlaneacion;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RealEmpacado;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Avance de empaque (solo lectura): el programa de empaque de la semana contra
 * lo empacado de verdad, con el atraso y lo que toca hoy. A diferencia de
 * `GET programa`, no crea el programa de la semana.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y cada petición necesita su propia instancia.
 */
class AvanceEmpaqueController extends Controller
{
    use PermisoPlaneacion;

    public function show(Request $request, ProgramaSemanalService $programas, RealEmpacado $real): JsonResponse
    {
        $this->exigirPermiso('avance-empaque', 'view');

        $datos = $request->validate([
            'lunes' => ['required', 'date_format:Y-m-d'],
            'cultivo_id' => ['nullable', 'integer', 'exists:cultivos,id'],
        ]);

        try {
            $fecha = Carbon::createFromFormat('!Y-m-d', $datos['lunes']);
        } catch (InvalidFormatException) {
            $fecha = null;
        }
        if (! $fecha || $fecha->format('Y-m-d') !== $datos['lunes'] || ! $fecha->isMonday()) {
            throw ValidationException::withMessages(['lunes' => 'La semana se identifica por su lunes (AAAA-MM-DD).']);
        }

        $avance = new AvanceEmpaque($programas, $real, app(CatalogoPlaneacion::class));
        $cultivoId = isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : null;

        return response()->json([
            'success' => true,
            'data' => $avance->de($datos['lunes'], $cultivoId, Carbon::now('America/Mazatlan')),
        ]);
    }
}
