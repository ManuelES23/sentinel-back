<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmVendedor;
use App\Services\CRM\DashboardResumenService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DashboardController
 * 7 endpoints de solo lectura con métricas ejecutivas del CRM (kpis,
 * pipeline, cotizaciones, funnel de conversión, actividad, cumplimiento de
 * metas, ranking de vendedores). Toda la agregación vive en
 * DashboardResumenService; este controller solo resuelve permisos/alcance.
 */
class DashboardController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const PERIODOS_VALIDOS = ['mes_actual', 'mes_anterior', 'trimestre', 'anio'];

    public function __construct(private readonly DashboardResumenService $resumen) {}

    public function kpis(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->kpis($empresaId, $vendedorId, $periodo));
    }

    public function pipeline(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->pipeline($empresaId, $vendedorId, $periodo));
    }

    public function cotizaciones(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cotizaciones($empresaId, $vendedorId, $periodo));
    }

    public function funnelConversion(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->funnelConversion($empresaId, $vendedorId, $periodo));
    }

    public function actividad(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->actividad($empresaId, $vendedorId, $periodo));
    }

    public function cumplimientoMetas(Request $request): JsonResponse
    {
        [$empresaId, $vendedorId, $periodo] = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cumplimientoMetas($empresaId, $vendedorId, $periodo));
    }

    /** GET /crm/dashboard/ranking-vendedores -- único endpoint que exige 'ejecutivo', sin vendedorId (siempre agregado de equipo). */
    public function rankingVendedores(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ejecutivo'),
            403,
            'No tienes permiso para ver el ranking de vendedores.',
        );

        $periodo = $this->resolverPeriodo($request);

        return $this->jsonSuccess($this->resumen->rankingVendedores($empresaId, $periodo));
    }

    /**
     * Resuelve (empresaId, vendedorId, periodo) para los 6 endpoints
     * regulares. vendedorId es null cuando el usuario tiene 'ejecutivo'
     * (agregado de equipo, sin filtro); si solo tiene 'ver', se resuelve a
     * su propio CrmVendedor -- o a 0 si no tiene ninguno (0 nunca es un id
     * real, así que el service devuelve ceros de forma natural sin una
     * rama especial por método).
     *
     * @return array{0: int, 1: ?int, 2: string}
     */
    private function contexto(Request $request): array
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ver'),
            403,
            'No tienes permiso para ver el dashboard.',
        );

        $periodo = $this->resolverPeriodo($request);

        if ($this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ejecutivo')) {
            return [$empresaId, null, $periodo];
        }

        $vendedorId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        return [$empresaId, $vendedorId !== null ? (int) $vendedorId : 0, $periodo];
    }

    private function resolverPeriodo(Request $request): string
    {
        $validated = $request->validate([
            'periodo' => 'nullable|in:'.implode(',', self::PERIODOS_VALIDOS),
        ]);

        return $validated['periodo'] ?? 'mes_actual';
    }
}
