<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmPresupuesto;
use App\Services\CRM\PresupuestoResumenService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PresupuestoController
 * CRUD de metas mensuales por vendedor (empresa_id + vendedor_id + mes +
 * anio único), más el resumen mensual y el comparativo anual que cruzan
 * la meta contra lo real (PresupuestoResumenService).
 */
class PresupuestoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public function __construct(private readonly PresupuestoResumenService $resumen) {}

    /** GET /crm/presupuestos?vendedor_id=&mes=&anio= */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'ver'),
            403,
            'No tienes permiso para ver presupuestos.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'required|integer',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
        ]);

        $presupuesto = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $validated['vendedor_id'])
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->first();

        return $this->jsonSuccess($presupuesto);
    }

    /** POST /crm/presupuestos */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'crear'),
            403,
            'No tienes permiso para crear presupuestos.',
        );

        $validated = $request->validate([
            'vendedor_id' => ['required', 'integer', $this->existeEnEmpresa('crm_vendedores', $empresaId)],
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
            'meta_monto' => 'nullable|numeric|min:0',
            'meta_clientes' => 'nullable|integer|min:0',
            'meta_actividades' => 'nullable|integer|min:0',
        ]);

        $existe = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $validated['vendedor_id'])
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->exists();

        if ($existe) {
            return $this->jsonError('Ya existe un presupuesto para este vendedor en este mes.', 422);
        }

        $presupuesto = CrmPresupuesto::create([...$validated, 'empresa_id' => $empresaId]);

        return $this->jsonSuccess($presupuesto, 'Presupuesto creado exitosamente', 201);
    }

    /** PUT /crm/presupuestos/{presupuesto} */
    public function update(Request $request, CrmPresupuesto $presupuesto): JsonResponse
    {
        $this->verificarEmpresa($presupuesto);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'editar'),
            403,
            'No tienes permiso para editar presupuestos.',
        );

        $validated = $request->validate([
            'meta_monto' => 'nullable|numeric|min:0',
            'meta_clientes' => 'nullable|integer|min:0',
            'meta_actividades' => 'nullable|integer|min:0',
        ]);

        $presupuesto->update($validated);

        return $this->jsonSuccess($presupuesto);
    }

    /** GET /crm/presupuestos/resumen?vendedor_id=&mes=&anio= */
    public function resumenMensual(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'ver'),
            403,
            'No tienes permiso para ver presupuestos.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'required|integer',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
        ]);

        $presupuesto = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $validated['vendedor_id'])
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->first();

        $reales = $this->resumen->resumenMensual($empresaId, $validated['vendedor_id'], $validated['mes'], $validated['anio']);

        return $this->jsonSuccess(array_merge([
            'metaMonto' => (float) ($presupuesto->meta_monto ?? 0),
            'metaClientes' => (int) ($presupuesto->meta_clientes ?? 0),
            'metaActividades' => (int) ($presupuesto->meta_actividades ?? 0),
        ], $reales));
    }

    /** GET /crm/presupuestos/comparativo-anual?vendedor_id=&anio= */
    public function comparativoAnual(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'ver'),
            403,
            'No tienes permiso para ver presupuestos.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'required|integer',
            'anio' => 'required|integer|min:2000|max:2100',
        ]);

        return $this->jsonSuccess(
            $this->resumen->comparativoAnual($empresaId, $validated['vendedor_id'], $validated['anio']),
        );
    }

    protected function verificarEmpresa(CrmPresupuesto $presupuesto): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $presupuesto->empresa_id !== (int) $empresaId) {
            abort(404, 'Presupuesto no encontrado');
        }
    }
}
