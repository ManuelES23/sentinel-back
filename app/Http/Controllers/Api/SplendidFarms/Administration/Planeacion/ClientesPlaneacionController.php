<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\ClientesPlaneacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sugerencias de clientes para las Necesidades de venta: clientes activos de
 * la lista propia de Planeación (`plan_clientes`), hasta 20, por nombre. Un
 * nombre que no está en la lista se registra solo al guardar la hoja.
 */
class ClientesPlaneacionController extends Controller
{
    use PermisoPlaneacion;

    public function index(Request $request, ClientesPlaneacion $clientes): JsonResponse
    {
        $this->exigirPermiso('necesidades-venta', 'view');

        $datos = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['success' => true, 'data' => $clientes->sugerir((string) ($datos['q'] ?? ''))]);
    }
}
