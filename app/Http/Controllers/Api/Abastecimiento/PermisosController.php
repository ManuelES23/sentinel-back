<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermisosController extends AbastecimientoController
{
    /** Qué puede hacer el usuario en cada submódulo (para mostrar u ocultar botones). */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->ctx->resumen($request),
        ]);
    }
}
