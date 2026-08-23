<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmConfiguracionImpuesto;
use App\Traits\CRM\FiltraPorEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfiguracionComercialController extends CrmBaseController
{
    use FiltraPorEmpresa;

    /** GET /crm/configuracion-comercial */
    public function show(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        $impuestos = CrmConfiguracionImpuesto::where('empresa_id', $empresaId)->activos()->get();

        return $this->jsonSuccess([
            'descuento_global_habilitado' => $config->descuento_global_habilitado,
            'impuestos' => $impuestos,
        ]);
    }

    /** PUT /crm/configuracion-comercial */
    public function update(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $validated = $request->validate([
            'descuento_global_habilitado' => 'required|boolean',
        ]);

        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        $config->update($validated);

        return $this->jsonSuccess($config, 'Configuración actualizada correctamente');
    }

    /** POST /crm/configuracion-comercial/impuestos */
    public function storeImpuesto(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $validated = $request->validate([
            'nombre' => 'required|string|max:50',
            'tasa' => 'required|numeric|min:0|max:100',
            'orden' => 'nullable|integer|min:1',
        ]);
        $validated['empresa_id'] = $empresaId;
        $validated['orden'] = $validated['orden'] ?? (CrmConfiguracionImpuesto::where('empresa_id', $empresaId)->max('orden') + 1);

        $impuesto = CrmConfiguracionImpuesto::create($validated);

        return $this->jsonSuccess($impuesto, 'Impuesto creado correctamente', 201);
    }

    /** PUT /crm/configuracion-comercial/impuestos/{impuesto} */
    public function updateImpuesto(Request $request, CrmConfiguracionImpuesto $impuesto): JsonResponse
    {
        $this->verificarEmpresa($impuesto);

        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:50',
            'tasa' => 'sometimes|required|numeric|min:0|max:100',
            'activo' => 'sometimes|boolean',
            'orden' => 'sometimes|integer|min:1',
        ]);
        $impuesto->update($validated);

        return $this->jsonSuccess($impuesto, 'Impuesto actualizado correctamente');
    }

    /** DELETE /crm/configuracion-comercial/impuestos/{impuesto} */
    public function destroyImpuesto(Request $request, CrmConfiguracionImpuesto $impuesto): JsonResponse
    {
        $this->verificarEmpresa($impuesto);
        $impuesto->delete();

        return $this->jsonSuccess(null, 'Impuesto eliminado correctamente');
    }

    protected function verificarEmpresa(CrmConfiguracionImpuesto $impuesto): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $impuesto->empresa_id !== (int) $empresaId) {
            abort(404, 'Impuesto no encontrado');
        }
    }
}
