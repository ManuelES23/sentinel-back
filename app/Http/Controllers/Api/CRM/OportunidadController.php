<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmOportunidad;
use App\Traits\CRM\FiltraPorEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OportunidadController extends CrmBaseController
{
    use FiltraPorEmpresa;

    private const RELACIONES = ['prospecto:id,nombre', 'cliente:id,nombre', 'vendedor:id,nombre'];

    /** GET /crm/oportunidades */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $query = CrmOportunidad::query()
            ->where('empresa_id', $empresaId)
            ->with(self::RELACIONES)
            ->when($request->etapa, fn ($q, $etapa) => $q->where('etapa', $etapa))
            ->when($request->vendedor_id, fn ($q, $id) => $q->where('vendedor_id', $id))
            ->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 100);
        $paginated = $query->paginate($perPage);

        return $this->jsonPaginated($paginated);
    }

    /** GET /crm/oportunidades/{oportunidad} */
    public function show(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $oportunidad->load(self::RELACIONES);

        return $this->jsonSuccess($oportunidad);
    }

    /** POST /crm/oportunidades */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $validated = $request->validate([
            'prospecto_id' => 'nullable|integer|exists:crm_prospectos,id',
            'cliente_id' => 'nullable|integer|exists:crm_clientes,id',
            'vendedor_id' => 'required|integer|exists:crm_vendedores,id',
            'nombre' => 'required|string|max:255',
            'monto_esperado' => 'nullable|numeric|min:0',
            'probabilidad' => 'nullable|integer|min:0|max:100',
            'fecha_cierre_esperada' => 'nullable|date',
            'notas' => 'nullable|string',
        ]);

        $error = $this->validarProspectoOCliente($validated);
        if ($error) {
            return $this->jsonError($error, 422);
        }

        $validated['empresa_id'] = $empresaId;

        $oportunidad = CrmOportunidad::create($validated);
        $oportunidad->load(self::RELACIONES);

        broadcast(new OportunidadUpdated('created', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Oportunidad creada correctamente', 201);
    }

    /** PUT /crm/oportunidades/{oportunidad} */
    public function update(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);

        $validated = $request->validate([
            'prospecto_id' => 'sometimes|nullable|integer|exists:crm_prospectos,id',
            'cliente_id' => 'sometimes|nullable|integer|exists:crm_clientes,id',
            'vendedor_id' => 'sometimes|required|integer|exists:crm_vendedores,id',
            'nombre' => 'sometimes|required|string|max:255',
            'monto_esperado' => 'sometimes|nullable|numeric|min:0',
            'probabilidad' => 'sometimes|nullable|integer|min:0|max:100',
            'fecha_cierre_esperada' => 'sometimes|nullable|date',
            'notas' => 'sometimes|nullable|string',
        ]);

        if (array_key_exists('prospecto_id', $validated) || array_key_exists('cliente_id', $validated)) {
            $mezclado = [
                'prospecto_id' => $validated['prospecto_id'] ?? $oportunidad->prospecto_id,
                'cliente_id' => $validated['cliente_id'] ?? $oportunidad->cliente_id,
            ];
            $error = $this->validarProspectoOCliente($mezclado);
            if ($error) {
                return $this->jsonError($error, 422);
            }
        }

        $oportunidad->update($validated);
        $oportunidad->load(self::RELACIONES);

        broadcast(new OportunidadUpdated('updated', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Oportunidad actualizada correctamente');
    }

    /** DELETE /crm/oportunidades/{oportunidad} */
    public function destroy(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);

        $data = $oportunidad->toArray();
        $oportunidad->delete();

        broadcast(new OportunidadUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Oportunidad eliminada correctamente');
    }

    /** Exactamente uno de prospecto_id/cliente_id debe estar presente. */
    private function validarProspectoOCliente(array $datos): ?string
    {
        $tieneProspecto = ! empty($datos['prospecto_id'] ?? null);
        $tieneCliente = ! empty($datos['cliente_id'] ?? null);

        if ($tieneProspecto === $tieneCliente) {
            return 'Debe indicar exactamente un prospecto o un cliente, no ambos ni ninguno.';
        }

        return null;
    }

    protected function verificarEmpresa(CrmOportunidad $oportunidad): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $oportunidad->empresa_id !== (int) $empresaId) {
            abort(404, 'Oportunidad no encontrada');
        }
    }

    /** PATCH /crm/oportunidades/{oportunidad}/cambiar-etapa */
    public function cambiarEtapa(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);

        $validated = $request->validate([
            'etapa' => 'required|in:prospecto,calificado,propuesta,negociacion,cerrado_ganado,cerrado_perdido',
            'motivo_perdida' => 'required_if:etapa,cerrado_perdido|nullable|string',
            'forzar' => 'sometimes|boolean',
        ]);

        $nuevaEtapa = $validated['etapa'];

        if ($nuevaEtapa !== 'cerrado_perdido' && ! $oportunidad->puedeAvanzarA($nuevaEtapa)) {
            return $this->jsonError('No se puede regresar una oportunidad a una etapa anterior.', 422);
        }

        if ($nuevaEtapa === 'cerrado_ganado' && ! ($validated['forzar'] ?? false)) {
            $tieneCotizacionAprobada = $oportunidad->cotizaciones()->where('estado', 'aprobado')->exists();
            if (! $tieneCotizacionAprobada) {
                return $this->jsonError(
                    'Esta oportunidad no tiene una cotización aprobada. Aprueba una cotización o fuerza el cierre manual con "forzar".',
                    422
                );
            }
        }

        $oportunidad->etapa = $nuevaEtapa;
        if ($nuevaEtapa === 'cerrado_perdido') {
            $oportunidad->motivo_perdida = $validated['motivo_perdida'];
            $oportunidad->fecha_cierre_real = now();
        } elseif ($nuevaEtapa === 'cerrado_ganado') {
            $oportunidad->fecha_cierre_real = now();
        }
        $oportunidad->save();
        $oportunidad->load(self::RELACIONES);

        broadcast(new OportunidadUpdated('updated', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Etapa actualizada correctamente');
    }
}
