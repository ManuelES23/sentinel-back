<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Services\CRM\AjustesOportunidadService;
use App\Services\CRM\VentaNoRegistrableException;
use App\Services\CRM\VisibilidadOportunidades;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Venta de una oportunidad y sus ajustes posteriores (solo SBP, spec
 * 2026-11-25 §3, §6, §7). Orden de chequeos: empresa (404) → permiso (403) →
 * visibilidad de la oportunidad (403) → bandera, estado y validación (422).
 */
class VentaOportunidadController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MENSAJE_SIN_BANDERA = 'Esta empresa no registra lo vendido por renglón.';

    public function __construct(
        private readonly AjustesOportunidadService $ajustes,
        private readonly VisibilidadOportunidades $visibilidad,
    ) {}

    /** GET /crm/oportunidades/{oportunidad}/venta */
    public function show(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad);

        if (! CrmConfiguracionComercial::cotizacionPorCaja((int) $oportunidad->empresa_id)) {
            return $this->jsonError(self::MENSAJE_SIN_BANDERA, 422);
        }

        return $this->jsonSuccess($this->ajustes->resumen($oportunidad));
    }

    /** POST /crm/oportunidades/{oportunidad}/ajustes */
    public function store(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->exigirEditar($oportunidad);

        if (! CrmConfiguracionComercial::cotizacionPorCaja((int) $oportunidad->empresa_id)) {
            return $this->jsonError(self::MENSAJE_SIN_BANDERA, 422);
        }

        try {
            $resultado = $this->ajustes->registrar($oportunidad, $request->only([
                'venta_renglon_id', 'tipo', 'cajas', 'precio_nuevo', 'motivo_rechazo_id', 'fecha', 'nota',
            ]), (int) Auth::id());
        } catch (VentaNoRegistrableException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $this->difundirResultado($resultado);

        return $this->jsonSuccess(
            $this->respuesta($resultado),
            $resultado['ajuste']->tipo === 'rechazo' ? 'Rechazo registrado' : 'Ajuste de precio registrado',
            201
        );
    }

    /** POST /crm/oportunidades/{oportunidad}/ajustes/{ajuste}/anular */
    public function anular(Request $request, CrmOportunidad $oportunidad, CrmOportunidadAjuste $ajuste): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->exigirEditar($oportunidad);
        // Un ajuste de otra oportunidad responde como si no existiera (ya con permiso y visibilidad).
        abort_unless((int) $ajuste->oportunidad_id === (int) $oportunidad->id, 404, 'Ajuste no encontrado');

        if (! CrmConfiguracionComercial::cotizacionPorCaja((int) $oportunidad->empresa_id)) {
            return $this->jsonError(self::MENSAJE_SIN_BANDERA, 422);
        }

        $motivo = $request->input('motivo');

        try {
            $resultado = $this->ajustes->anular($oportunidad, $ajuste, is_string($motivo) ? $motivo : '', (int) Auth::id());
        } catch (VentaNoRegistrableException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $this->difundirResultado($resultado);

        return $this->jsonSuccess($this->respuesta($resultado), 'Ajuste anulado');
    }

    /** oportunidades.editar sobre una oportunidad visible para el usuario. */
    private function exigirEditar(CrmOportunidad $oportunidad): void
    {
        $this->exigirPermisoSubmodulo(
            $this->getEmpresaId(), 'oportunidades', 'oportunidades', 'editar', 'No tienes permiso para editar oportunidades.'
        );
        $this->visibilidad->exigirVisible(
            (int) $oportunidad->empresa_id, $oportunidad, VisibilidadOportunidades::MENSAJE_MODIFICAR_AJENA
        );
    }

    /**
     * @param  array{oportunidad: CrmOportunidad, ajuste: CrmOportunidadAjuste, actividad: \App\Models\CRM\CrmActividad}  $resultado
     * @return array<string, mixed>
     */
    private function respuesta(array $resultado): array
    {
        $oportunidad = $resultado['oportunidad'];

        return [
            'ajuste' => $resultado['ajuste'],
            'oportunidad' => $oportunidad->load(CrmOportunidad::RELACIONES_API)->conMontoOrigen(),
        ] + $this->ajustes->resumen($oportunidad);
    }

    /** Todo ya está confirmado: ahora sí se avisa en tiempo real. */
    private function difundirResultado(array $resultado): void
    {
        $oportunidad = $resultado['oportunidad'];
        $this->difundir(new OportunidadUpdated(
            'updated',
            $oportunidad->load(CrmOportunidad::RELACIONES_API)->conMontoOrigen()->toArray()
        ));

        $actividad = $resultado['actividad'];
        $actividad->load(ActividadController::RELACIONES);
        $actividad->entidad_tipo = 'oportunidad';
        $this->difundir(new ActividadUpdated('created', $actividad->toArray()));
    }

    protected function verificarEmpresa(CrmOportunidad $oportunidad): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $oportunidad->empresa_id !== (int) $empresaId) {
            abort(404, 'Oportunidad no encontrada');
        }
    }
}
