<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ClienteUpdated;
use App\Events\CRM\VendedorAsignado;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const RELACIONES = [
        'vendedor:id,nombre,email',
        'region:id,nombre',
        'prospecto:id,nombre',
    ];

    /**
     * GET /crm/clientes
     * Listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $query = CrmCliente::query()
            ->where('empresa_id', $empresaId)
            ->with(self::RELACIONES)
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nombre', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%")
                        ->orWhere('rfc', 'like', "%{$search}%");
                });
            })
            ->when($request->vendedor_id, fn ($q, $v) => $q->where('vendedor_id', $v))
            ->when($request->region_id, fn ($q, $r) => $q->where('region_id', $r))
            ->when($request->estatus, fn ($q, $e) => $q->where('estatus', $e))
            ->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 15);
        $paginated = $query->paginate($perPage);

        return $this->jsonPaginated($paginated);
    }

    /**
     * GET /crm/clientes/{cliente}
     */
    public function show(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        $cliente->load(array_merge(self::RELACIONES, ['contactos', 'oportunidades']));

        return $this->jsonSuccess($cliente, 'Cliente encontrado');
    }

    /**
     * GET /crm/clientes/{cliente}/resumen
     * Devuelve datos agregados: contactos, oportunidades activas, total facturado (placeholder).
     */
    public function resumen(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);

        $cliente->loadCount([
            'contactos',
            'oportunidades',
            'actividades',
        ]);

        $oportunidades = $cliente->oportunidades()
            ->select('id', 'cliente_id', 'etapa', 'monto_esperado', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $actividadesRecientes = $cliente->actividades()
            ->select('id', 'entidad_id', 'entidad_type', 'tipo', 'descripcion', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $resumen = [
            'cliente' => $cliente->only([
                'id', 'nombre', 'rfc', 'email', 'telefono', 'estatus',
                'vendedor_id', 'region_id', 'created_at',
            ]),
            'metricas' => [
                'contactos'    => $cliente->contactos_count,
                'oportunidades' => $cliente->oportunidades_count,
                'actividades'   => $cliente->actividades_count,
                'valor_pipeline' => (float) $cliente->oportunidades()
                    ->whereNotIn('etapa', ['cerrado_ganado', 'cerrado_perdido'])
                    ->sum('monto_esperado'),
            ],
            'oportunidades' => $oportunidades,
            'actividades'   => $actividadesRecientes,
        ];

        return $this->jsonSuccess($resumen, 'Resumen del cliente');
    }

    /**
     * POST /crm/clientes
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'crear', 'No tienes permiso para crear clientes.');

        $validated = $request->validate([
            'prospecto_id' => 'nullable|exists:crm_prospectos,id',
            'nombre'       => 'required|string|max:255',
            'rfc'          => 'nullable|string|max:20',
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('crm_clientes', 'email')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->whereNull('deleted_at'),
            ],
            'telefono'    => 'nullable|string|max:50',
            'estatus'     => 'nullable|in:activo,inactivo,suspendido',
            'vendedor_id' => 'nullable|exists:crm_vendedores,id',
            'region_id'   => 'nullable|exists:crm_regiones,id',
            'notas'       => 'nullable|string',
        ]);

        $validated['empresa_id'] = $empresaId;
        $validated['estatus']   = $validated['estatus'] ?? 'activo';

        $cliente = CrmCliente::create($validated);
        $cliente->load(self::RELACIONES);

        broadcast(new ClienteUpdated('created', $cliente->toArray()));

        return $this->jsonSuccess($cliente, 'Cliente creado', 201);
    }

    /**
     * PUT /crm/clientes/{cliente}
     */
    public function update(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'clientes', 'clientes', 'editar', 'No tienes permiso para editar clientes.');
        $empresaId = $this->getEmpresaId($request);

        $validated = $request->validate([
            'prospecto_id' => 'sometimes|nullable|exists:crm_prospectos,id',
            'nombre'       => 'sometimes|required|string|max:255',
            'rfc'          => 'sometimes|nullable|string|max:20',
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('crm_clientes', 'email')
                    ->ignore($cliente->id)
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->whereNull('deleted_at'),
            ],
            'telefono'    => 'sometimes|nullable|string|max:50',
            'estatus'     => 'sometimes|in:activo,inactivo,suspendido',
            'vendedor_id' => 'sometimes|nullable|exists:crm_vendedores,id',
            'region_id'   => 'sometimes|nullable|exists:crm_regiones,id',
            'notas'       => 'sometimes|nullable|string',
        ]);

        $cliente->update($validated);
        $cliente->load(self::RELACIONES);

        broadcast(new ClienteUpdated('updated', $cliente->toArray()));

        return $this->jsonSuccess($cliente, 'Cliente actualizado');
    }

    /**
     * DELETE /crm/clientes/{cliente}
     */
    public function destroy(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'clientes', 'clientes', 'eliminar', 'No tienes permiso para eliminar clientes.');

        $id = $cliente->id;
        $cliente->delete();

        broadcast(new ClienteUpdated('deleted', ['id' => $id]));

        return $this->jsonSuccess(['id' => $id], 'Cliente eliminado');
    }

    /**
     * PATCH /crm/clientes/{cliente}/asignar-vendedor
     */
    public function asignarVendedor(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'clientes', 'clientes', 'asignar_vendedor', 'No tienes permiso para asignar vendedor a clientes.');

        $validated = $request->validate([
            'vendedor_id' => [
                'required',
                Rule::exists('crm_vendedores', 'id')->where(fn ($q) => $q->where('empresa_id', $cliente->empresa_id)),
            ],
        ]);

        $cliente->update(['vendedor_id' => $validated['vendedor_id']]);
        $cliente->load(self::RELACIONES);

        // Registrar actividad automática
        CrmActividad::create([
            'empresa_id'      => $cliente->empresa_id,
            'tipo'            => 'nota',
            'entidad_type'    => CrmCliente::class,
            'entidad_id'      => $cliente->id,
            'vendedor_id'     => $cliente->vendedor_id,
            'descripcion'     => "Vendedor asignado: {$cliente->vendedor?->nombre}",
            'fecha_actividad' => now(),
            'fuente'          => 'sistema',
        ]);

        broadcast(new ClienteUpdated('updated', $cliente->toArray()));

        broadcast(new VendedorAsignado(
            (int) $cliente->empresa_id,
            'cliente',
            (int) $cliente->id,
            $cliente->vendedor?->nombre,
            $request->user()?->name,
        ));

        return $this->jsonSuccess($cliente, 'Vendedor asignado');
    }

    /**
     * Aborta si el cliente no pertenece a la empresa del contexto.
     */
    private function verificarEmpresa(Request $request, CrmCliente $cliente): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $cliente->empresa_id !== (int) $empresaId) {
            abort(404, 'Cliente no encontrado');
        }
    }
}
