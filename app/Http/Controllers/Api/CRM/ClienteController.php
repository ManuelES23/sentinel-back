<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ClienteUpdated;
use App\Events\CRM\VendedorAsignado;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\ActividadesDeFicha;
use App\Services\CRM\VisibilidadOportunidades;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** Clientes por petición en la asignación en lote. */
    private const TOPE_LOTE = 500;

    /** Pendientes que devuelve el modal de asignación (de sobra para un catálogo). */
    private const TOPE_PENDIENTES = 5000;

    public function __construct(
        private readonly VisibilidadOportunidades $visibilidad,
        private readonly ActividadesDeFicha $fichas,
    ) {}

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
                        ->orWhere('rfc', 'like', "%{$search}%")
                        ->orWhere('clave', $search);
                });
            })
            // vendedor_id=sin → pendientes por asignar vendedor
            ->when($request->vendedor_id, fn ($q, $v) => $v === 'sin' ? $q->whereNull('vendedor_id') : $q->where('vendedor_id', $v))
            ->when($request->region_id, fn ($q, $r) => $q->where('region_id', $r))
            ->when($request->estatus, fn ($q, $e) => $q->where('estatus', $e))
            // Desempate por id: una importación crea cientos con el mismo created_at
            // y sin él MySQL puede repetir u omitir filas entre páginas.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $perPage = $this->perPage($request, 15);
        $paginated = $query->paginate($perPage);

        return $this->jsonPaginated($paginated);
    }

    /**
     * GET /crm/clientes/{cliente}
     */
    public function show(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        $alcance = $this->visibilidad->alcance((int) $cliente->empresa_id);

        // Solo las oportunidades que el usuario puede ver (sin
        // oportunidades.ver, ninguna; sin equipo, las de su vendedor).
        $cliente->load(array_merge(self::RELACIONES, [
            'contactos',
            'oportunidades' => fn ($q) => $alcance->aplicar($q),
        ]));

        return $this->jsonSuccess($cliente, 'Cliente encontrado');
    }

    /**
     * GET /crm/clientes/{cliente}/resumen
     * Devuelve datos agregados: contactos, oportunidades activas, total facturado (placeholder).
     */
    public function resumen(Request $request, CrmCliente $cliente): JsonResponse
    {
        $this->verificarEmpresa($request, $cliente);
        // Conteo, valor del pipeline y últimas oportunidades: solo lo visible.
        $alcance = $this->visibilidad->alcance((int) $cliente->empresa_id);

        $cliente->loadCount([
            'contactos',
            'oportunidades' => fn ($q) => $alcance->aplicar($q),
        ]);

        $oportunidades = $alcance->aplicar($cliente->oportunidades())
            ->select('id', 'cliente_id', 'etapa', 'monto_esperado', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Las del cliente y las de sus oportunidades visibles (spec 2026-10-30 §5.4).
        $actividadesDelCliente = fn () => $this->fichas->limitar(
            CrmActividad::query(), CrmCliente::class, (int) $cliente->id, $alcance,
        );

        $actividadesRecientes = $actividadesDelCliente()
            ->select('id', 'entidad_id', 'entidad_type', 'tipo', 'descripcion', 'created_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $this->fichas->adjuntarOportunidad($actividadesRecientes);

        $resumen = [
            'cliente' => $cliente->only([
                'id', 'nombre', 'rfc', 'email', 'telefono', 'estatus',
                'vendedor_id', 'region_id', 'created_at',
            ]),
            'metricas' => [
                'contactos'    => $cliente->contactos_count,
                'oportunidades' => $cliente->oportunidades_count,
                'actividades'   => $actividadesDelCliente()->count(),
                'valor_pipeline' => (float) $alcance->aplicar($cliente->oportunidades())
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
            'giro'        => 'nullable|string|max:150',
            'sitio_web'   => 'nullable|string|max:255',
            'ciudad'      => 'nullable|string|max:100',
            'estado'      => 'nullable|string|max:100',
        ]);

        $validated['empresa_id'] = $empresaId;
        $validated['estatus']   = $validated['estatus'] ?? 'activo';

        $cliente = CrmCliente::create($validated);
        $cliente->load(self::RELACIONES);

        $this->difundir(new ClienteUpdated('created', $cliente->toArray()));

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
            'giro'        => 'sometimes|nullable|string|max:150',
            'sitio_web'   => 'sometimes|nullable|string|max:255',
            'ciudad'      => 'sometimes|nullable|string|max:100',
            'estado'      => 'sometimes|nullable|string|max:100',
        ]);

        $cliente->update($validated);
        $cliente->load(self::RELACIONES);

        $this->difundir(new ClienteUpdated('updated', $cliente->toArray()));

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

        $this->difundir(new ClienteUpdated('deleted', ['id' => $id]));

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

        $this->difundir(new ClienteUpdated('updated', $cliente->toArray()));

        $this->difundir(new VendedorAsignado(
            (int) $cliente->empresa_id,
            'cliente',
            (int) $cliente->id,
            $cliente->vendedor?->nombre,
            $request->user()?->name,
        ));

        return $this->jsonSuccess($cliente, 'Vendedor asignado');
    }

    /**
     * GET /crm/clientes/pendientes-asignar
     * Todos los clientes sin vendedor de la empresa, ligeros (clave, nombre,
     * ciudad, estado), para el modal de asignación en lote: filtra en el navegador.
     */
    public function pendientesAsignar(Request $request): JsonResponse
    {
        $clientes = CrmCliente::query()
            ->where('empresa_id', $this->getEmpresaId($request))
            ->whereNull('vendedor_id')
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(self::TOPE_PENDIENTES)
            ->get(['id', 'clave', 'nombre', 'ciudad', 'estado']);

        return $this->jsonSuccess($clientes, 'Clientes pendientes por asignar');
    }

    /**
     * PATCH /crm/clientes/asignar-vendedor
     * Asigna un vendedor a varios clientes pendientes a la vez. Solo toca los que
     * siguen sin vendedor (otro pudo asignarlos mientras tanto): esos se omiten.
     * Cada cliente guarda su nota de auditoría, como en la asignación individual.
     */
    public function asignarVendedorLote(Request $request): JsonResponse
    {
        $empresaId = (int) $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'asignar_vendedor', 'No tienes permiso para asignar vendedor a clientes.');

        $validated = $request->validate([
            'cliente_ids' => ['required', 'array', 'min:1', 'max:'.self::TOPE_LOTE],
            'cliente_ids.*' => ['integer', 'distinct'],
            'vendedor_id' => [
                'required',
                Rule::exists('crm_vendedores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
        ], [
            'cliente_ids.max' => 'Asigna como máximo '.self::TOPE_LOTE.' clientes a la vez.',
            'vendedor_id.exists' => 'El vendedor no existe o no está activo.',
        ]);

        $vendedor = CrmVendedor::findOrFail($validated['vendedor_id']);

        $asignados = DB::transaction(function () use ($empresaId, $validated, $vendedor) {
            $ids = CrmCliente::query()
                ->where('empresa_id', $empresaId)
                ->whereNull('vendedor_id')
                ->whereIn('id', $validated['cliente_ids'])
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            CrmCliente::whereIn('id', $ids)->update(['vendedor_id' => $vendedor->id]);

            foreach ($ids as $id) {
                CrmActividad::create([
                    'empresa_id'      => $empresaId,
                    'tipo'            => 'nota',
                    'entidad_type'    => CrmCliente::class,
                    'entidad_id'      => $id,
                    'vendedor_id'     => $vendedor->id,
                    'descripcion'     => "Vendedor asignado: {$vendedor->nombre}",
                    'fecha_actividad' => now(),
                    'fuente'          => 'sistema',
                ]);
            }

            return $ids->count();
        });

        // Un solo aviso para todo el lote: las vistas abiertas recargan una vez
        // (entidad_id 0 = varios clientes).
        if ($asignados > 0) {
            $this->difundir(new VendedorAsignado(
                $empresaId,
                'cliente',
                0,
                $vendedor->nombre,
                $request->user()?->name,
            ));
        }

        return $this->jsonSuccess([
            'asignados' => $asignados,
            'omitidos' => count($validated['cliente_ids']) - $asignados,
            'vendedor' => ['id' => $vendedor->id, 'nombre' => $vendedor->nombre],
        ], $asignados === 1 ? 'Vendedor asignado a 1 cliente' : "Vendedor asignado a {$asignados} clientes");
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
