<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Temporada;
use App\Services\Planeacion\ResumenTemporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Resumen de temporada: una fila por semana (hoja RESUMEN del Excel). Solo
 * lectura; exige `resumen-temporada.view`.
 *
 * El servicio se inyecta por método y no por constructor: guarda catálogos en
 * memoria y el controlador puede quedar vivo entre peticiones.
 */
class ResumenTemporadaController extends Controller
{
    use PermisoPlaneacion;

    public function show(Request $request, ResumenTemporada $resumen): JsonResponse
    {
        $this->exigirPermiso('resumen-temporada', 'view');

        $datos = $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('temporadas', 'id')->whereNull('deleted_at')],
            'cultivo_id' => ['nullable', 'integer', Rule::exists('cultivos', 'id')->whereNull('deleted_at')],
        ]);

        $temporada = Temporada::findOrFail($datos['temporada_id']);

        return response()->json([
            'success' => true,
            'data' => $resumen->de($temporada, isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : null),
        ]);
    }
}
