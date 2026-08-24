<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\PresupuestoResumenService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $vendedorId = $this->resolverVendedorId($empresaId, (int) $validated['vendedor_id']);

        $presupuesto = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->first();

        return $this->jsonSuccess($this->serializarPresupuesto($presupuesto));
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

        $validated = $this->normalizarMetasNulas($validated);

        $existe = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $validated['vendedor_id'])
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->exists();

        if ($existe) {
            return $this->jsonError('Ya existe un presupuesto para este vendedor en este mes.', 422);
        }

        try {
            $presupuesto = CrmPresupuesto::create([...$validated, 'empresa_id' => $empresaId]);
        } catch (QueryException $e) {
            if ($this->esViolacionDeUnicidad($e)) {
                // Ventana de carrera: dos requests concurrentes pasaron el
                // exists() de arriba antes de que cualquiera insertara. El
                // constraint UNIQUE de la BD es la fuente de verdad final.
                return $this->jsonError('Ya existe un presupuesto para este vendedor en este mes.', 422);
            }

            throw $e;
        }

        return $this->jsonSuccess($this->serializarPresupuesto($presupuesto), 'Presupuesto creado exitosamente', 201);
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

        $validated = $this->normalizarMetasNulas($validated);

        $presupuesto->update($validated);

        return $this->jsonSuccess($this->serializarPresupuesto($presupuesto));
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

        $vendedorId = $this->resolverVendedorId($empresaId, (int) $validated['vendedor_id']);

        $presupuesto = CrmPresupuesto::where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('mes', $validated['mes'])
            ->where('anio', $validated['anio'])
            ->first();

        $reales = $this->resumen->resumenMensual($empresaId, $vendedorId, $validated['mes'], $validated['anio']);

        // Las metas van en null (no 0) cuando no existe presupuesto para ese
        // mes -- meta_monto/meta_clientes/meta_actividades son NOT NULL
        // DEFAULT 0 en la tabla, así que un 0 real sería indistinguible de
        // "no hay meta definida". El frontend usa esto para su empty-state.
        return $this->jsonSuccess(array_merge([
            'presupuestoId' => $presupuesto->id ?? null,
            'metaMonto' => $presupuesto ? (float) $presupuesto->meta_monto : null,
            'metaClientes' => $presupuesto ? (int) $presupuesto->meta_clientes : null,
            'metaActividades' => $presupuesto ? (int) $presupuesto->meta_actividades : null,
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

        $vendedorId = $this->resolverVendedorId($empresaId, (int) $validated['vendedor_id']);

        return $this->jsonSuccess(
            $this->resumen->comparativoAnual($empresaId, $vendedorId, $validated['anio']),
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

    /**
     * Resuelve qué vendedor_id puede consultarse en los endpoints de
     * lectura (index/resumen/comparativo-anual).
     *
     * Spec §5.3: quien tiene 'crear' o 'editar' es gerencia -- puede ver a
     * cualquier vendedor. Quien solo tiene 'ver' (un vendedor de campo) solo
     * puede consultar su propio presupuesto/resumen; el selector de
     * vendedor en el frontend se autoselecciona y se oculta para ese caso,
     * pero eso NO es enforcement real -- cualquiera podría editar la URL.
     * Aquí se cierra esa fuga del lado del servidor.
     */
    private function resolverVendedorId(int $empresaId, int $vendedorIdSolicitado): int
    {
        $esGerencia = $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'crear')
            || $this->tienePermisoSubmodulo($empresaId, 'presupuestos', 'presupuestos', 'editar');

        if ($esGerencia) {
            return $vendedorIdSolicitado;
        }

        $vendedorPropioId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        abort_unless(
            $vendedorPropioId !== null && (int) $vendedorPropioId === $vendedorIdSolicitado,
            403,
            'No puedes ver el presupuesto de otro vendedor.',
        );

        return $vendedorIdSolicitado;
    }

    /**
     * meta_monto/meta_clientes/meta_actividades son NOT NULL DEFAULT 0 en la
     * tabla. Un cliente que manda el campo explícitamente en null (la forma
     * natural de "limpiar" un input) no debe tumbar el INSERT/UPDATE con un
     * 500 -- se normaliza a 0 antes de tocar la BD. Los campos ausentes del
     * todo (partial update) no se tocan.
     */
    private function normalizarMetasNulas(array $validated): array
    {
        foreach (['meta_monto', 'meta_clientes', 'meta_actividades'] as $campo) {
            if (array_key_exists($campo, $validated) && $validated[$campo] === null) {
                $validated[$campo] = 0;
            }
        }

        return $validated;
    }

    /** Serializa un CrmPresupuesto con meta_monto como número (no el string del cast decimal:2), para que el tipo sea consistente con /resumen y /comparativo-anual. */
    private function serializarPresupuesto(?CrmPresupuesto $presupuesto): ?array
    {
        if (! $presupuesto) {
            return null;
        }

        return array_merge($presupuesto->toArray(), [
            'meta_monto' => (float) $presupuesto->meta_monto,
        ]);
    }

    private function esViolacionDeUnicidad(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }
}
