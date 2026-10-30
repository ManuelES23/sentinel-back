<?php

namespace App\Http\Controllers\Api\CRM;

use App\Exceptions\CRM\VendedorNoVinculadoException;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\MiDiaDetalleService;
use App\Services\CRM\MiDiaService;
use App\Services\CRM\MiMesService;
use App\Services\CRM\VendedorActualService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /crm/mi-dia y GET /crm/mi-dia/oportunidades/{oportunidad} — pantalla de inicio del vendedor. */
class MiDiaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public function __construct(
        private readonly VendedorActualService $vendedores,
        private readonly MiDiaService $miDia,
        private readonly MiMesService $miMes,
        private readonly MiDiaDetalleService $detalle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'ver', 'No tienes permiso para ver Mi día.');

        $validated = $request->validate(['vendedor_id' => 'nullable|integer']);
        $solicitado = isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null;

        $puedeVerEquipo = $this->vendedores->puedeVerEquipo($empresaId);
        $lista = $puedeVerEquipo
            ? CrmVendedor::where('empresa_id', $empresaId)->activo()->orderBy('nombre')->get(['id', 'nombre'])
            : [];

        try {
            $vendedor = $this->vendedores->resolver($empresaId, $request->user(), $solicitado);
        } catch (VendedorNoVinculadoException $e) {
            // Gerencia sin vendedor propio: puede elegir uno del selector.
            if (! $puedeVerEquipo) {
                throw $e;
            }

            return $this->jsonSuccess(
                ['vendedor' => null, 'puede_ver_equipo' => true, 'vendedores' => $lista, 'mi_mes' => null] + $this->miDia->vacio(),
            );
        }

        return $this->jsonSuccess(
            [
                'vendedor' => ['id' => $vendedor->id, 'nombre' => $vendedor->nombre],
                'puede_ver_equipo' => $puedeVerEquipo,
                'vendedores' => $lista,
                'mi_mes' => $this->miMes->para($empresaId, $vendedor),
            ] + $this->miDia->resumen($empresaId, $vendedor, CarbonImmutable::now()),
        );
    }

    /**
     * Detalle de una oportunidad para el panel de Mi día. Solo exige `mi-dia.ver`:
     * Mi día ya muestra las oportunidades propias sin `oportunidades.ver`.
     */
    public function oportunidad(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'ver', 'No tienes permiso para ver Mi día.');
        abort_unless((int) $oportunidad->empresa_id === (int) $empresaId, 404, 'Oportunidad no encontrada');

        $validated = $request->validate(['vendedor_id' => 'nullable|integer']);
        $solicitado = isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null;
        $vendedor = $this->vendedores->resolver($empresaId, $request->user(), $solicitado);

        return $this->jsonSuccess($this->detalle->para(
            $vendedor,
            $oportunidad,
            $this->tienePermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'ver'),
        ));
    }
}
