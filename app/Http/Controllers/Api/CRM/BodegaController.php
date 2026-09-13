<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\BodegaUpdated;
use App\Models\CRM\CrmBodega;
use App\Models\CRM\CrmZona;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BodegaController
 * Catálogo de bodegas del CRM (tercer nivel: Región → Zona → Bodega).
 * Cada bodega pertenece a una zona. Multi-tenant por empresa_id.
 */
class BodegaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * GET /crm/bodegas?zona_id=
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $request->validate([
            'zona_id' => 'nullable|integer',
        ]);

        $query = CrmBodega::query()
            ->where('empresa_id', $empresaId)
            ->with('zona:id,region_id,nombre')
            ->when($request->zona_id, fn ($q, $id) => $q->where('zona_id', $id))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nombre', 'like', "%{$search}%")
                        ->orWhere('direccion', 'like', "%{$search}%");
                });
            })
            ->orderBy('nombre');

        // Modo lista simple para selects (sin paginación).
        if ($request->boolean('all')) {
            return $this->jsonSuccess($query->get(['id', 'zona_id', 'nombre']));
        }

        $perPage = (int) $request->query('per_page', 50);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /**
     * GET /crm/bodegas/{bodega}
     */
    public function show(Request $request, CrmBodega $bodega): JsonResponse
    {
        $this->verificarEmpresa($request, $bodega);

        return $this->jsonSuccess($bodega->load('zona:id,region_id,nombre'));
    }

    /**
     * POST /crm/bodegas
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'bodegas', 'crear', 'No tienes permiso para crear bodegas.');

        $validated = $request->validate([
            'zona_id'   => 'required|integer',
            'nombre'    => 'required|string|max:255',
            'direccion' => 'nullable|string|max:500',
        ]);

        // La zona debe existir y pertenecer a la empresa.
        $zona = CrmZona::where('empresa_id', $empresaId)->find($validated['zona_id']);
        if (!$zona) {
            return $this->jsonError('La zona indicada no existe o no pertenece a la empresa.', 422);
        }

        $bodega = CrmBodega::create([
            'empresa_id' => $empresaId,
            'zona_id'    => $validated['zona_id'],
            'nombre'     => $validated['nombre'],
            'direccion'  => $validated['direccion'] ?? null,
        ]);

        $bodega->load('zona:id,region_id,nombre');

        broadcast(new BodegaUpdated('created', $bodega->toArray()));

        return $this->jsonSuccess($bodega, 'Bodega creada correctamente', 201);
    }

    /**
     * PATCH /crm/bodegas/{bodega}
     */
    public function update(Request $request, CrmBodega $bodega): JsonResponse
    {
        $this->verificarEmpresa($request, $bodega);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'bodegas', 'editar', 'No tienes permiso para editar bodegas.');

        $validated = $request->validate([
            'zona_id'   => 'sometimes|required|integer',
            'nombre'    => 'sometimes|required|string|max:255',
            'direccion' => 'sometimes|nullable|string|max:500',
        ]);

        if (array_key_exists('zona_id', $validated)) {
            $zona = CrmZona::where('empresa_id', $bodega->empresa_id)->find($validated['zona_id']);
            if (!$zona) {
                return $this->jsonError('La zona indicada no existe o no pertenece a la empresa.', 422);
            }
        }

        $bodega->update($validated);
        $bodega->load('zona:id,region_id,nombre');

        broadcast(new BodegaUpdated('updated', $bodega->toArray()));

        return $this->jsonSuccess($bodega, 'Bodega actualizada correctamente');
    }

    /**
     * DELETE /crm/bodegas/{bodega}
     */
    public function destroy(Request $request, CrmBodega $bodega): JsonResponse
    {
        $this->verificarEmpresa($request, $bodega);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'bodegas', 'eliminar', 'No tienes permiso para eliminar bodegas.');

        $data = $bodega->toArray();
        $bodega->delete();

        broadcast(new BodegaUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Bodega eliminada correctamente');
    }

    /**
     * Aborta con 404 si la bodega no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmBodega $bodega): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $bodega->empresa_id !== (int) $empresaId) {
            abort(404, 'Bodega no encontrada');
        }
    }
}
