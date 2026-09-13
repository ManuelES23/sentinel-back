<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\EmpresaExternaUpdated;
use App\Models\CRM\CrmEmpresaExterna;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpresaExternaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const RELACIONES = [
        'contactos:id,entidad_type,entidad_id,nombre,cargo,email,telefono,es_principal',
    ];

    /**
     * GET /crm/empresas-externas
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $query = CrmEmpresaExterna::query()
            ->where('empresa_id', $empresaId)
            ->with(self::RELACIONES)
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('razon_social', 'like', "%{$search}%")
                        ->orWhere('rfc', 'like', "%{$search}%")
                        ->orWhere('industria', 'like', "%{$search}%");
                });
            })
            ->when($request->industria, fn ($q, $i) => $q->where('industria', $i))
            ->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 15);
        $paginated = $query->paginate($perPage);

        return $this->jsonPaginated($paginated);
    }

    /**
     * GET /crm/empresas-externas/{empresaExterna}
     */
    public function show(Request $request, CrmEmpresaExterna $empresaExterna): JsonResponse
    {
        $this->verificarEmpresa($request, $empresaExterna);
        $empresaExterna->load(self::RELACIONES);

        return $this->jsonSuccess($empresaExterna);
    }

    /**
     * POST /crm/empresas-externas
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'empresas-externas', 'empresas-externas', 'crear', 'No tienes permiso para crear empresas externas.');

        $validated = $request->validate([
            'razon_social' => 'required|string|max:255',
            'rfc'          => 'nullable|string|max:20',
            'industria'    => 'nullable|string|max:255',
            'sitio_web'    => 'nullable|string|max:255',
        ]);

        $validated['empresa_id'] = $empresaId;

        $empresaExterna = CrmEmpresaExterna::create($validated);
        $empresaExterna->load(self::RELACIONES);

        broadcast(new EmpresaExternaUpdated('created', $empresaExterna->toArray()));

        return $this->jsonSuccess($empresaExterna, 'Empresa externa creada correctamente', 201);
    }

    /**
     * PATCH /crm/empresas-externas/{empresaExterna}
     */
    public function update(Request $request, CrmEmpresaExterna $empresaExterna): JsonResponse
    {
        $this->verificarEmpresa($request, $empresaExterna);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'empresas-externas', 'empresas-externas', 'editar', 'No tienes permiso para editar empresas externas.');

        $validated = $request->validate([
            'razon_social' => 'sometimes|required|string|max:255',
            'rfc'          => 'sometimes|nullable|string|max:20',
            'industria'    => 'sometimes|nullable|string|max:255',
            'sitio_web'    => 'sometimes|nullable|string|max:255',
        ]);

        $empresaExterna->update($validated);
        $empresaExterna->load(self::RELACIONES);

        broadcast(new EmpresaExternaUpdated('updated', $empresaExterna->toArray()));

        return $this->jsonSuccess($empresaExterna, 'Empresa externa actualizada correctamente');
    }

    /**
     * DELETE /crm/empresas-externas/{empresaExterna}
     */
    public function destroy(Request $request, CrmEmpresaExterna $empresaExterna): JsonResponse
    {
        $this->verificarEmpresa($request, $empresaExterna);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'empresas-externas', 'empresas-externas', 'eliminar', 'No tienes permiso para eliminar empresas externas.');

        $data = $empresaExterna->toArray();
        $empresaExterna->contactos()->delete();
        $empresaExterna->delete();

        broadcast(new EmpresaExternaUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Empresa externa eliminada correctamente');
    }

    /**
     * Aborta con 404 si la entidad no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmEmpresaExterna $empresaExterna): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $empresaExterna->empresa_id !== (int) $empresaId) {
            abort(404, 'Empresa externa no encontrada');
        }
    }
}
