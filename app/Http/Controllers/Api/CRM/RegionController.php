<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\RegionUpdated;
use App\Models\CRM\CrmRegion;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RegionController
 * Catálogo de regiones geográficas del CRM (primer nivel de la jerarquía
 * Región → Zona → Bodega). Multi-tenant por empresa_id.
 */
class RegionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * GET /crm/regiones
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $query = CrmRegion::query()
            ->where('empresa_id', $empresaId)
            ->withCount('zonas')
            ->when($request->search, fn ($q, $search) => $q->where('nombre', 'like', "%{$search}%"))
            ->orderBy('nombre');

        // Modo lista simple para selects (sin paginación).
        if ($request->boolean('all')) {
            return $this->jsonSuccess($query->get(['id', 'nombre']));
        }

        $perPage = (int) $request->query('per_page', 50);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /**
     * GET /crm/regiones/{region}
     */
    public function show(Request $request, CrmRegion $region): JsonResponse
    {
        $this->verificarEmpresa($request, $region);

        return $this->jsonSuccess($region->loadCount('zonas'));
    }

    /**
     * POST /crm/regiones
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'regiones', 'crear', 'No tienes permiso para crear regiones.');

        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
        ]);

        $region = CrmRegion::create([
            'empresa_id' => $empresaId,
            'nombre'     => $validated['nombre'],
        ]);

        broadcast(new RegionUpdated('created', $region->toArray()));

        return $this->jsonSuccess($region, 'Región creada correctamente', 201);
    }

    /**
     * PATCH /crm/regiones/{region}
     */
    public function update(Request $request, CrmRegion $region): JsonResponse
    {
        $this->verificarEmpresa($request, $region);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'regiones', 'editar', 'No tienes permiso para editar regiones.');

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
        ]);

        $region->update($validated);

        broadcast(new RegionUpdated('updated', $region->toArray()));

        return $this->jsonSuccess($region, 'Región actualizada correctamente');
    }

    /**
     * DELETE /crm/regiones/{region}
     */
    public function destroy(Request $request, CrmRegion $region): JsonResponse
    {
        $this->verificarEmpresa($request, $region);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'regiones', 'eliminar', 'No tienes permiso para eliminar regiones.');

        if ($region->zonas()->exists()) {
            return $this->jsonError('No se puede eliminar la región porque tiene zonas asociadas.', 409);
        }

        $data = $region->toArray();
        $region->delete();

        broadcast(new RegionUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Región eliminada correctamente');
    }

    /**
     * Aborta con 404 si la región no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmRegion $region): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $region->empresa_id !== (int) $empresaId) {
            abort(404, 'Región no encontrada');
        }
    }
}
