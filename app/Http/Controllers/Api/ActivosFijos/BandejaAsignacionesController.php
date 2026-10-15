<?php
// sentinel-back/app/Http/Controllers/Api/ActivosFijos/BandejaAsignacionesController.php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Http\Controllers\Controller;
use App\Services\ActivosFijos\BandejaAsignaciones;
use App\Services\ActivosFijos\PermisosActivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración → Activos Fijos → Asignaciones, pantalla de bandeja: tarjetas,
 * cartas por firmar y bitácora de movimientos. Solo lectura con `asignaciones.view`;
 * las operaciones siguen en AsignacionActivoController.
 */
class BandejaAsignacionesController extends Controller
{
    private const SUBMODULO = 'asignaciones';

    public function __construct(
        private PermisosActivos $permisos,
        private BandejaAsignaciones $bandeja,
    ) {
    }

    public function resumen(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');

        return response()->json(['success' => true, 'data' => $this->bandeja->resumen($request)]);
    }

    public function cartasPendientes(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');

        return response()->json(['success' => true, 'data' => $this->bandeja->cartasPendientes($request)]);
    }

    public function movimientos(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');

        return response()->json(['success' => true, 'data' => $this->bandeja->movimientos($request)]);
    }
}
