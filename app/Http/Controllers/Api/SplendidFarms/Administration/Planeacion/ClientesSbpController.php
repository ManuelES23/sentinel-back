<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda de clientes de Splendid by Porvenir (CRM) para los pedidos del
 * Programa semanal. Un cliente que no esté en el CRM se escribe como texto libre.
 */
class ClientesSbpController extends Controller
{
    use PermisoProgramaSemanal;

    private const LIMITE = 20;

    public function index(Request $request): JsonResponse
    {
        $this->exigirPermiso('view');

        $datos = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = trim($datos['q'] ?? '');

        $empresaId = Enterprise::where('slug', 'splendidbyporvenir')->value('id');

        $clientes = $empresaId
            ? CrmCliente::where('empresa_id', $empresaId)
                ->when($q !== '', fn ($query) => $query->where('nombre', 'like', "%{$q}%"))
                ->orderBy('nombre')
                ->limit(self::LIMITE)
                ->get(['id', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])
                ->all()
            : [];

        return response()->json(['success' => true, 'data' => $clientes]);
    }
}
