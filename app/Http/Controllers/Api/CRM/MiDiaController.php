<?php

namespace App\Http\Controllers\Api\CRM;

use App\Exceptions\CRM\VendedorNoVinculadoException;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\MiDiaService;
use App\Services\CRM\VendedorActualService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /crm/mi-dia — pantalla de inicio del vendedor (CRM fase 2). */
class MiDiaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public function __construct(
        private readonly VendedorActualService $vendedores,
        private readonly MiDiaService $miDia,
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
                ['vendedor' => null, 'puede_ver_equipo' => true, 'vendedores' => $lista] + $this->miDia->vacio(),
            );
        }

        return $this->jsonSuccess(
            [
                'vendedor' => ['id' => $vendedor->id, 'nombre' => $vendedor->nombre],
                'puede_ver_equipo' => $puedeVerEquipo,
                'vendedores' => $lista,
            ] + $this->miDia->resumen($empresaId, $vendedor, CarbonImmutable::now()),
        );
    }
}
