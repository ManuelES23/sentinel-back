<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Services\Abastecimiento\TableroAbastecimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tablero de Abastecimiento de una temporada (submódulo `tablero`). Solo lectura. */
class TableroController extends AbastecimientoController
{
    private const SUBMODULO = 'tablero';

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('abs_temporadas', 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at')],
            'moneda' => ['nullable', Rule::in(Convenio::MONEDAS)],
        ]);

        $data = TableroAbastecimiento::armar($empresa, (int) $filtros['temporada_id'], $filtros['moneda'] ?? null);

        return response()->json(['success' => true, 'message' => 'Tablero de abastecimiento.', 'data' => $data]);
    }
}
