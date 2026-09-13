<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ZonaUpdated;
use App\Models\CRM\CrmRegion;
use App\Models\CRM\CrmZona;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZonaController
 * Catálogo de zonas del CRM (segundo nivel: Región → Zona → Bodega).
 * Cada zona pertenece a una región. Multi-tenant por empresa_id.
 */
class ZonaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * GET /crm/zonas?region_id=
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $request->validate([
            'region_id' => 'nullable|integer',
        ]);

        $query = CrmZona::query()
            ->where('empresa_id', $empresaId)
            ->with('region:id,nombre')
            ->withCount('bodegas')
            ->when($request->region_id, fn ($q, $id) => $q->where('region_id', $id))
            ->when($request->search, fn ($q, $search) => $q->where('nombre', 'like', "%{$search}%"))
            ->orderBy('nombre');

        // Modo lista simple para selects (sin paginación).
        if ($request->boolean('all')) {
            return $this->jsonSuccess($query->get(['id', 'region_id', 'nombre']));
        }

        $perPage = (int) $request->query('per_page', 50);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /**
     * GET /crm/zonas/{zona}
     */
    public function show(Request $request, CrmZona $zona): JsonResponse
    {
        $this->verificarEmpresa($request, $zona);

        return $this->jsonSuccess($zona->load('region:id,nombre')->loadCount('bodegas'));
    }

    /**
     * POST /crm/zonas
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'zonas', 'crear', 'No tienes permiso para crear zonas.');

        $validated = $request->validate([
            'region_id' => 'required|integer',
            'nombre'    => 'required|string|max:255',
        ]);

        // La región debe existir y pertenecer a la empresa.
        $region = CrmRegion::where('empresa_id', $empresaId)->find($validated['region_id']);
        if (!$region) {
            return $this->jsonError('La región indicada no existe o no pertenece a la empresa.', 422);
        }

        $zona = CrmZona::create([
            'empresa_id' => $empresaId,
            'region_id'  => $validated['region_id'],
            'nombre'     => $validated['nombre'],
        ]);

        $zona->load('region:id,nombre');

        broadcast(new ZonaUpdated('created', $zona->toArray()));

        return $this->jsonSuccess($zona, 'Zona creada correctamente', 201);
    }

    /**
     * PATCH /crm/zonas/{zona}
     */
    public function update(Request $request, CrmZona $zona): JsonResponse
    {
        $this->verificarEmpresa($request, $zona);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'zonas', 'editar', 'No tienes permiso para editar zonas.');

        $validated = $request->validate([
            'region_id' => 'sometimes|required|integer',
            'nombre'    => 'sometimes|required|string|max:255',
        ]);

        if (array_key_exists('region_id', $validated)) {
            $region = CrmRegion::where('empresa_id', $zona->empresa_id)->find($validated['region_id']);
            if (!$region) {
                return $this->jsonError('La región indicada no existe o no pertenece a la empresa.', 422);
            }
        }

        $zona->update($validated);
        $zona->load('region:id,nombre');

        broadcast(new ZonaUpdated('updated', $zona->toArray()));

        return $this->jsonSuccess($zona, 'Zona actualizada correctamente');
    }

    /**
     * DELETE /crm/zonas/{zona}
     */
    public function destroy(Request $request, CrmZona $zona): JsonResponse
    {
        $this->verificarEmpresa($request, $zona);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'zonas', 'eliminar', 'No tienes permiso para eliminar zonas.');

        if ($zona->bodegas()->exists()) {
            return $this->jsonError('No se puede eliminar la zona porque tiene bodegas asociadas.', 409);
        }

        $data = $zona->toArray();
        $zona->delete();

        broadcast(new ZonaUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Zona eliminada correctamente');
    }

    /**
     * Aborta con 404 si la zona no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmZona $zona): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $zona->empresa_id !== (int) $empresaId) {
            abort(404, 'Zona no encontrada');
        }
    }
}
