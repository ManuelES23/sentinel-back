<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\VendedorUpdated;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * VendedorController
 * Catálogo de vendedores del CRM. Cada vendedor enlaza (opcionalmente) a un
 * usuario del sistema. No puede existir más de un vendedor por usuario dentro
 * de la misma empresa. Multi-tenant por empresa_id.
 */
class VendedorController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * GET /crm/vendedores
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $query = CrmVendedor::query()
            ->where('empresa_id', $empresaId)
            ->with('user:id,name,email')
            ->when($request->has('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nombre', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%");
                });
            })
            ->orderBy('nombre');

        // Modo lista simple para selects (sin paginación): sólo activos.
        if ($request->boolean('all')) {
            return $this->jsonSuccess(
                $query->clone()->where('activo', true)->get(['id', 'nombre', 'email'])
            );
        }

        $perPage = (int) $request->query('per_page', 50);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /**
     * GET /crm/vendedores/usuarios-disponibles
     * Usuarios con acceso a la empresa que aún no están registrados como vendedor.
     */
    public function usuariosDisponibles(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        // Expone nombre/email de usuarios: solo para quien da de alta o edita vendedores.
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'catalogos', 'vendedores', 'crear')
                || $this->tienePermisoSubmodulo($empresaId, 'catalogos', 'vendedores', 'editar'),
            403,
            'No tienes permiso para administrar vendedores.',
        );

        $usuariosVendedores = CrmVendedor::where('empresa_id', $empresaId)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $userIds = UserEnterpriseAccess::where('enterprise_id', $empresaId)
            ->where('is_active', true)
            ->whereNotIn('user_id', $usuariosVendedores)
            ->pluck('user_id');

        $usuarios = User::whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return $this->jsonSuccess($usuarios);
    }

    /**
     * GET /crm/vendedores/{vendedor}
     */
    public function show(Request $request, CrmVendedor $vendedor): JsonResponse
    {
        $this->verificarEmpresa($request, $vendedor);

        return $this->jsonSuccess($vendedor->load('user:id,name,email'));
    }

    /**
     * POST /crm/vendedores
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'vendedores', 'crear', 'No tienes permiso para crear vendedores.');

        $validated = $request->validate([
            'user_id'  => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('crm_vendedores', 'user_id')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId)),
            ],
            'nombre'   => 'required|string|max:255',
            'email'    => 'nullable|email|max:255',
            'telefono' => 'nullable|string|max:50',
            'activo'   => 'nullable|boolean',
        ], [
            'user_id.unique' => 'El usuario seleccionado ya está registrado como vendedor.',
        ]);

        $vendedor = CrmVendedor::create([
            'empresa_id' => $empresaId,
            'user_id'    => $validated['user_id'] ?? null,
            'nombre'     => $validated['nombre'],
            'email'      => $validated['email'] ?? null,
            'telefono'   => $validated['telefono'] ?? null,
            'activo'     => $validated['activo'] ?? true,
        ]);

        $vendedor->load('user:id,name,email');

        broadcast(new VendedorUpdated('created', $vendedor->toArray()));

        return $this->jsonSuccess($vendedor, 'Vendedor creado correctamente', 201);
    }

    /**
     * PATCH /crm/vendedores/{vendedor}
     */
    public function update(Request $request, CrmVendedor $vendedor): JsonResponse
    {
        $this->verificarEmpresa($request, $vendedor);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'vendedores', 'editar', 'No tienes permiso para editar vendedores.');

        $validated = $request->validate([
            'user_id'  => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('crm_vendedores', 'user_id')
                    ->where(fn ($q) => $q->where('empresa_id', $vendedor->empresa_id))
                    ->ignore($vendedor->id),
            ],
            'nombre'   => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|nullable|email|max:255',
            'telefono' => 'sometimes|nullable|string|max:50',
            'activo'   => 'sometimes|boolean',
        ], [
            'user_id.unique' => 'El usuario seleccionado ya está registrado como vendedor.',
        ]);

        $vendedor->update($validated);
        $vendedor->load('user:id,name,email');

        broadcast(new VendedorUpdated('updated', $vendedor->toArray()));

        return $this->jsonSuccess($vendedor, 'Vendedor actualizado correctamente');
    }

    /**
     * PATCH /crm/vendedores/{vendedor}/toggle-activo
     */
    public function toggleActivo(Request $request, CrmVendedor $vendedor): JsonResponse
    {
        $this->verificarEmpresa($request, $vendedor);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'vendedores', 'editar', 'No tienes permiso para editar vendedores.');

        $vendedor->update(['activo' => ! $vendedor->activo]);
        $vendedor->load('user:id,name,email');

        broadcast(new VendedorUpdated('updated', $vendedor->toArray()));

        return $this->jsonSuccess($vendedor, 'Estado del vendedor actualizado correctamente');
    }

    /**
     * DELETE /crm/vendedores/{vendedor}
     */
    public function destroy(Request $request, CrmVendedor $vendedor): JsonResponse
    {
        $this->verificarEmpresa($request, $vendedor);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'vendedores', 'eliminar', 'No tienes permiso para eliminar vendedores.');

        if ($vendedor->prospectos()->exists() || $vendedor->clientes()->exists()) {
            return $this->jsonError('No se puede eliminar el vendedor porque tiene prospectos o clientes asignados.', 409);
        }

        $data = $vendedor->toArray();
        $vendedor->delete();

        broadcast(new VendedorUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Vendedor eliminado correctamente');
    }

    /**
     * Aborta con 404 si el vendedor no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmVendedor $vendedor): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $vendedor->empresa_id !== (int) $empresaId) {
            abort(404, 'Vendedor no encontrado');
        }
    }
}
