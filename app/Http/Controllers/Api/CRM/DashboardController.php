<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmVendedor;
use App\Services\CRM\DashboardResumenService;
use App\Services\CRM\EmbudoService;
use App\Support\CRM\RangoDashboard;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * DashboardController
 * Endpoints de solo lectura del Dashboard del CRM (spec 2026-10-08 §6).
 * Toda la agregación vive en DashboardResumenService y EmbudoService; aquí
 * solo se resuelven permisos, alcance del vendedor y filtros comunes.
 */
class DashboardController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MAX_DIAS_PERSONALIZADO = 366;

    public function __construct(
        private readonly DashboardResumenService $resumen,
        private readonly EmbudoService $embudos,
    ) {}

    public function kpis(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->kpis($c['empresaId'], $c['vendedorId'], $c['rango']));
    }

    public function embudo(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->embudos->pipeline($c['empresaId'], $c['vendedorId']));
    }

    public function embudoConversion(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->embudos->conversion($c['empresaId'], $c['vendedorId'], $c['rango']));
    }

    public function tendencia(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->tendencia($c['empresaId'], $c['vendedorId'], $c['rango'], $c['comparar']));
    }

    public function cumplimientoMetas(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cumplimientoMetas($c['empresaId'], $c['vendedorId'], $c['rango']));
    }

    public function cotizaciones(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->cotizaciones($c['empresaId'], $c['vendedorId'], $c['rango'], $c['comparar']));
    }

    public function actividad(Request $request): JsonResponse
    {
        $c = $this->contexto($request);

        return $this->jsonSuccess($this->resumen->actividad($c['empresaId'], $c['vendedorId'], $c['rango'], $c['comparar']));
    }

    /** GET /crm/dashboard/detalle?tipo=&etapa= -- lista para el cajón de detalle (§6.3). */
    public function detalle(Request $request): JsonResponse
    {
        $c = $this->contexto($request);
        $validated = $request->validate([
            'tipo' => 'required|in:abiertas,llegaron,perdidas,ganadas',
            'etapa' => 'required_unless:tipo,ganadas|nullable|in:prospecto,calificado,propuesta,negociacion,sin_registro',
        ]);

        // EmbudoService::detalle no valida la etapa: aquí se acota por tipo.
        $tipo = $validated['tipo'];
        $etapa = $validated['etapa'] ?? null;
        if ($tipo === 'ganadas' && $etapa !== null) {
            throw ValidationException::withMessages(['etapa' => 'Las oportunidades ganadas no se filtran por etapa.']);
        }
        if ($etapa === 'sin_registro' && $tipo !== 'perdidas') {
            throw ValidationException::withMessages(['etapa' => 'La etapa sin_registro solo aplica a las perdidas.']);
        }

        return $this->jsonSuccess($this->embudos->detalle($c['empresaId'], $c['vendedorId'], $c['rango'], $tipo, $etapa));
    }

    /** GET /crm/dashboard/ranking-vendedores -- exige 'ejecutivo' (no 'ver'); siempre de todo el equipo. */
    public function rankingVendedores(Request $request): JsonResponse
    {
        $empresaId = $this->empresaConPermiso('ejecutivo', 'No tienes permiso para ver el ranking de vendedores.');
        $filtros = $this->filtros($request, $empresaId, true);

        return $this->jsonSuccess($this->resumen->rankingVendedores($empresaId, $filtros['rango']));
    }

    /** @return array{empresaId: int, vendedorId: ?int, rango: RangoDashboard, comparar: bool} */
    private function contexto(Request $request): array
    {
        $empresaId = $this->empresaConPermiso('ver', 'No tienes permiso para ver el dashboard.');
        $ejecutivo = $this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', 'ejecutivo');

        return ['empresaId' => $empresaId] + $this->filtros($request, $empresaId, $ejecutivo);
    }

    private function empresaConPermiso(string $permiso, string $mensaje): int
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless($this->tienePermisoSubmodulo($empresaId, 'dashboard', 'dashboard', $permiso), 403, $mensaje);

        return $empresaId;
    }

    /** @return array{vendedorId: ?int, rango: RangoDashboard, comparar: bool} */
    private function filtros(Request $request, int $empresaId, bool $ejecutivo): array
    {
        $validated = $request->validate([
            'periodo' => 'nullable|in:'.implode(',', RangoDashboard::PERIODOS),
            'desde' => 'required_if:periodo,personalizado|nullable|date_format:Y-m-d',
            'hasta' => 'required_if:periodo,personalizado|nullable|date_format:Y-m-d|after_or_equal:desde',
            'vendedor_id' => 'nullable|integer',
            'comparar' => 'nullable|boolean',
        ]);

        $periodo = $validated['periodo'] ?? 'mes_actual';
        $rango = RangoDashboard::desdePeriodo($periodo, $validated['desde'] ?? null, $validated['hasta'] ?? null);
        if ($periodo === 'personalizado' && $rango->dias() > self::MAX_DIAS_PERSONALIZADO) {
            throw ValidationException::withMessages(['hasta' => 'El rango personalizado no puede pasar de 366 días.']);
        }

        $solicitado = isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null;

        return [
            'vendedorId' => $this->resolverVendedor($empresaId, $ejecutivo, $solicitado),
            'rango' => $rango,
            'comparar' => (bool) ($validated['comparar'] ?? false),
        ];
    }

    /**
     * Con 'ejecutivo': el vendedor pedido (debe ser de la empresa) o null
     * (todo el equipo). Sin 'ejecutivo': siempre el vendedor propio, o 0 si
     * no tiene ninguno (0 nunca es un id real y el servicio devuelve ceros).
     */
    private function resolverVendedor(int $empresaId, bool $ejecutivo, ?int $solicitado): ?int
    {
        if ($ejecutivo) {
            if ($solicitado === null) {
                return null;
            }
            if (! CrmVendedor::where('empresa_id', $empresaId)->whereKey($solicitado)->exists()) {
                throw ValidationException::withMessages(['vendedor_id' => 'El vendedor no pertenece a esta empresa.']);
            }

            return $solicitado;
        }

        $propio = CrmVendedor::where('empresa_id', $empresaId)->where('user_id', Auth::id())->value('id');

        return $propio !== null ? (int) $propio : 0;
    }
}
