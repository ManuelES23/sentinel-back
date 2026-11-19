<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Liquidacion;
use App\Services\Abastecimiento\PresentadorLiquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liquidación final de un convenio (submódulo `liquidaciones`). Por ahora solo la ficha; la vista previa y
 * «Liquidar total» llegan en una task posterior. La forma del JSON es de `PresentadorLiquidacion`.
 */
class LiquidacionController extends AbastecimientoController
{
    private const SUBMODULO = 'liquidaciones';

    public function show(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $liquidacion = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => app(PresentadorLiquidacion::class)->completa($liquidacion, $empresa, $request->user()),
        ]);
    }
}
