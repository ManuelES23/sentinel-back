<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\ProspectoUpdated;
use App\Events\CRM\VendedorAsignado;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmProspecto;
use App\Services\CRM\SeguimientoService;
use App\Services\CRM\VendedorActualService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProspectoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const RELACIONES = [
        'vendedor:id,nombre,email',
        'region:id,nombre',
        'zona:id,nombre',
        'bodega:id,nombre',
    ];

    public function __construct(
        private readonly SeguimientoService $seguimientos,
        private readonly VendedorActualService $vendedores,
    ) {}

    /**
     * GET /crm/prospectos
     * Listado paginado con búsqueda y filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $query = CrmProspecto::query()
            ->where('empresa_id', $empresaId)
            ->with(self::RELACIONES);

        // Búsqueda por nombre / email / rfc
        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->string('search');
            $q->where(function ($sub) use ($search) {
                $sub->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%");
            });
        });

        // Filtros
        $query->when($request->filled('vendedor_id'), fn ($q) => $q->where('vendedor_id', $request->integer('vendedor_id')));
        $query->when($request->filled('region_id'),   fn ($q) => $q->where('region_id',   $request->integer('region_id')));
        $query->when($request->filled('estatus'),     fn ($q) => $q->where('estatus',     $request->string('estatus')));

        // Solo prospectos activos (excluye descartados) si se pide
        $query->when($request->boolean('activos'), fn ($q) => $q->activos());

        $paginated = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return $this->jsonPaginated($paginated);
    }

    /**
     * GET /crm/prospectos/{prospecto}
     */
    public function show(CrmProspecto $prospecto): JsonResponse
    {
        $this->verificarEmpresa($prospecto);

        $prospecto->load(array_merge(self::RELACIONES, [
            'contactos',
            'oportunidades' => fn ($q) => $q->orderByDesc('created_at'),
            'oportunidades.vendedor:id,nombre',
        ]));

        return $this->jsonSuccess($prospecto);
    }

    /**
     * POST /crm/prospectos
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->exigirPermisoSubmodulo($empresaId, 'prospectos', 'prospectos', 'crear', 'No tienes permiso para crear prospectos.');

        $validated = $request->validate([
            'nombre'      => 'required|string|max:255',
            'rfc'         => 'nullable|string|max:20',
            'email'       => [
                'nullable', 'email', 'max:255',
                Rule::unique('crm_prospectos', 'email')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->whereNull('deleted_at'),
            ],
            'telefono'    => 'nullable|string|max:50',
            'estatus'     => 'nullable|in:nuevo,contactado,calificado,descartado',
            'vendedor_id' => 'nullable|exists:crm_vendedores,id',
            'region_id'   => 'nullable|exists:crm_regiones,id',
            'zona_id'     => 'nullable|exists:crm_zonas,id',
            'bodega_id'   => 'nullable|exists:crm_bodegas,id',
            'notas'       => 'nullable|string',
        ]);

        $validated['empresa_id'] = $empresaId;
        $validated['estatus'] ??= 'nuevo';

        $prospecto = CrmProspecto::create($validated);
        $prospecto->load(self::RELACIONES);

        broadcast(new ProspectoUpdated('created', $prospecto->toArray()));

        return $this->jsonSuccess($prospecto, 'Prospecto creado exitosamente', 201);
    }

    /**
     * POST /crm/prospectos/rapido
     * Captura rápida (CRM fase 2): solo el nombre es obligatorio, se asigna
     * al vendedor del usuario y opcionalmente programa el primer contacto.
     */
    public function storeRapido(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->exigirPermisoSubmodulo($empresaId, 'prospectos', 'prospectos', 'crear', 'No tienes permiso para crear prospectos.');

        $validated = $request->validate(array_merge([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('crm_prospectos', 'email')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->whereNull('deleted_at'),
            ],
            'notas' => 'nullable|string|max:2000',
            'vendedor_id' => 'nullable|integer',
        ], $this->seguimientos->reglasSiguiente('primer_contacto')), array_merge(
            ['email.unique' => 'Ya existe un prospecto con este correo.'],
            $this->seguimientos->mensajesSiguiente('primer_contacto'),
        ));

        $primerContacto = $validated['primer_contacto'] ?? null;
        if (! empty($primerContacto)) {
            $this->exigirPermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear', 'No tienes permiso para programar eventos de agenda.');
        }

        $vendedor = $this->vendedores->resolver(
            $empresaId,
            $request->user(),
            isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
        );

        [$prospecto, $evento] = DB::transaction(function () use ($empresaId, $validated, $vendedor, $primerContacto) {
            $prospecto = CrmProspecto::create([
                'empresa_id' => $empresaId,
                'nombre' => $validated['nombre'],
                'telefono' => $validated['telefono'] ?? null,
                'email' => $validated['email'] ?? null,
                'notas' => $validated['notas'] ?? null,
                'estatus' => 'nuevo',
                'vendedor_id' => $vendedor->id,
            ]);

            $evento = empty($primerContacto)
                ? null
                : $this->seguimientos->programar($empresaId, $vendedor->id, $prospecto, $primerContacto);

            return [$prospecto, $evento];
        });

        $prospecto->load(self::RELACIONES);
        $this->difundir(new ProspectoUpdated('created', $prospecto->toArray(), null, 'crm', 'prospectos'));
        if ($evento) {
            $this->difundir(new AgendaUpdated('created', $evento->load('vendedor:id,nombre')->toArray(), null, 'crm', 'agenda'));
        }

        return $this->jsonSuccess(['prospecto' => $prospecto, 'evento' => $evento], 'Prospecto creado exitosamente', 201);
    }

    /**
     * PUT /crm/prospectos/{prospecto}
     */
    public function update(Request $request, CrmProspecto $prospecto): JsonResponse
    {
        $this->verificarEmpresa($prospecto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'prospectos', 'prospectos', 'editar', 'No tienes permiso para editar prospectos.');

        $validated = $request->validate([
            'nombre'      => 'sometimes|string|max:255',
            'rfc'         => 'nullable|string|max:20',
            'email'       => [
                'nullable', 'email', 'max:255',
                Rule::unique('crm_prospectos', 'email')
                    ->ignore($prospecto->id)
                    ->where(fn ($q) => $q->where('empresa_id', $prospecto->empresa_id))
                    ->whereNull('deleted_at'),
            ],
            'telefono'    => 'nullable|string|max:50',
            'estatus'     => 'sometimes|in:nuevo,contactado,calificado,descartado',
            'vendedor_id' => 'nullable|exists:crm_vendedores,id',
            'region_id'   => 'nullable|exists:crm_regiones,id',
            'zona_id'     => 'nullable|exists:crm_zonas,id',
            'bodega_id'   => 'nullable|exists:crm_bodegas,id',
            'notas'       => 'nullable|string',
        ]);

        $prospecto->update($validated);
        $prospecto->load(self::RELACIONES);

        broadcast(new ProspectoUpdated('updated', $prospecto->toArray()));

        return $this->jsonSuccess($prospecto, 'Prospecto actualizado exitosamente');
    }

    /**
     * DELETE /crm/prospectos/{prospecto}
     */
    public function destroy(CrmProspecto $prospecto): JsonResponse
    {
        $this->verificarEmpresa($prospecto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'prospectos', 'prospectos', 'eliminar', 'No tienes permiso para eliminar prospectos.');

        // Soft-delete impide perder trazabilidad; la validación de dependencias
        // (oportunidades abiertas) puede activarse cuando se implemente en E4.
        $data = $prospecto->toArray();
        $prospecto->delete();

        broadcast(new ProspectoUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Prospecto eliminado exitosamente');
    }

    /**
     * POST /crm/prospectos/{prospecto}/convertir-cliente
     * Crea un CrmCliente heredando vendedor, región y datos base.
     */
    public function convertirCliente(CrmProspecto $prospecto): JsonResponse
    {
        $this->verificarEmpresa($prospecto);
        // Convertir crea un cliente: se exige el permiso de crear clientes.
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'clientes', 'clientes', 'crear', 'No tienes permiso para convertir prospectos en clientes.');

        return DB::transaction(function () use ($prospecto) {
            $cliente = CrmCliente::create([
                'empresa_id'   => $prospecto->empresa_id,
                'prospecto_id' => $prospecto->id,
                'nombre'       => $prospecto->nombre,
                'rfc'          => $prospecto->rfc,
                'email'        => $prospecto->email,
                'telefono'     => $prospecto->telefono,
                'estatus'      => 'activo',
                'vendedor_id'  => $prospecto->vendedor_id,
                'region_id'    => $prospecto->region_id,
                'notas'        => $prospecto->notas,
            ]);

            // Marca el prospecto como calificado (no descartar por trazabilidad)
            $prospecto->update(['estatus' => 'calificado']);

            // Actividad de auditoría
            CrmActividad::create([
                'empresa_id'      => $prospecto->empresa_id,
                'tipo'            => 'nota',
                'entidad_type'    => CrmCliente::class,
                'entidad_id'      => $cliente->id,
                'vendedor_id'     => $prospecto->vendedor_id,
                'descripcion'     => "Cliente creado a partir del prospecto #{$prospecto->id}: {$prospecto->nombre}",
                'fecha_actividad' => now(),
                'fuente'          => 'sistema',
            ]);

            $cliente->load(['vendedor:id,nombre', 'region:id,nombre', 'prospecto:id,nombre']);

            broadcast(new ProspectoUpdated('updated', $prospecto->fresh()->toArray()));

            return $this->jsonSuccess($cliente, 'Prospecto convertido a cliente exitosamente', 201);
        });
    }

    /**
     * PATCH /crm/prospectos/{prospecto}/asignar-vendedor
     * (E2.6) Reasigna vendedor + genera actividad nota + broadcast.
     */
    public function asignarVendedor(Request $request, CrmProspecto $prospecto): JsonResponse
    {
        $this->verificarEmpresa($prospecto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'prospectos', 'prospectos', 'asignar_vendedor', 'No tienes permiso para asignar vendedor a prospectos.');

        $validated = $request->validate([
            'vendedor_id' => [
                'required',
                Rule::exists('crm_vendedores', 'id')->where(fn ($q) => $q->where('empresa_id', $prospecto->empresa_id)),
            ],
        ]);

        $prospecto->update(['vendedor_id' => $validated['vendedor_id']]);
        $prospecto->load(self::RELACIONES);

        CrmActividad::create([
            'empresa_id'      => $prospecto->empresa_id,
            'tipo'            => 'nota',
            'entidad_type'    => CrmProspecto::class,
            'entidad_id'      => $prospecto->id,
            'vendedor_id'     => $prospecto->vendedor_id,
            'descripcion'     => "Vendedor asignado: {$prospecto->vendedor?->nombre}",
            'fecha_actividad' => now(),
            'fuente'          => 'sistema',
        ]);

        broadcast(new ProspectoUpdated('updated', $prospecto->toArray()));

        broadcast(new VendedorAsignado(
            (int) $prospecto->empresa_id,
            'prospecto',
            (int) $prospecto->id,
            $prospecto->vendedor?->nombre,
            $request->user()?->name,
        ));

        return $this->jsonSuccess($prospecto, 'Vendedor asignado exitosamente');
    }

    /**
     * Verifica que el prospecto pertenezca a la empresa del contexto.
     */
    private function verificarEmpresa(CrmProspecto $prospecto): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId && $prospecto->empresa_id === $empresaId, 404);
    }
}
