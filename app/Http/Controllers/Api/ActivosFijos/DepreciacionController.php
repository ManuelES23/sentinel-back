<?php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Http\Controllers\Controller;
use App\Models\DepreciationClose;
use App\Models\Enterprise;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\CierreDepreciacion;
use App\Services\ActivosFijos\PermisosActivos;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Depreciación mensual de Activos Fijos. Fuera de Grupo Espléndido la empresa es la de la
 * URL; en GE se elige una (`enterprise_id`) entre las visibles. El cierre siempre es por empresa.
 */
class DepreciacionController extends Controller
{
    /** Mes AAAA-MM; el límite inferior evita años absurdos (0001-01). */
    private const REGLA_MES = ['required', 'date_format:Y-m', 'after_or_equal:2000-01'];

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private CierreDepreciacion $cierres,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'depreciacion', 'view');
        $datos = $request->validate(['month' => self::REGLA_MES]);
        $empresa = $this->empresa($request);

        return response()->json([
            'success' => true,
            'data' => $this->cierres->estadoDelMes($empresa, $this->mes($datos['month'])),
        ]);
    }

    public function cierres(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'depreciacion', 'view');
        $empresa = $this->empresa($request);

        $lista = DepreciationClose::where('enterprise_id', $empresa->id)
            ->with('closedBy:id,name')
            ->orderByDesc('period')
            ->get()
            ->map(fn (DepreciationClose $c) => $this->cierres->aArreglo($c))
            ->values();

        return response()->json(['success' => true, 'data' => $lista]);
    }

    public function cerrar(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'depreciacion', 'create');
        $datos = $request->validate(['month' => self::REGLA_MES]);
        $empresa = $this->empresa($request);

        $cierre = $this->cierres->cerrar($empresa, $this->mes($datos['month']), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Mes cerrado correctamente',
            'data' => $this->cierres->aArreglo($cierre->load('closedBy:id,name')),
        ], 201);
    }

    /** 404 y no 403 si el cierre es de una empresa fuera del alcance: no se revela que existe. */
    public function reabrir(Request $request, DepreciationClose $cierre): JsonResponse
    {
        abort_unless(in_array($cierre->enterprise_id, $this->alcance->idsVisibles($request), true), 404);
        $this->permisos->autorizar($request, 'depreciacion', 'delete');
        $datos = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->cierres->reabrir($cierre, $datos['reason'], $request->user());

        return response()->json(['success' => true, 'message' => 'Mes reabierto correctamente']);
    }

    private function empresa(Request $request): Enterprise
    {
        if (! $this->alcance->esCorporativo($request)) {
            return $this->alcance->empresaActual($request);
        }

        $empresa = $this->alcance->empresasVisibles($request)->firstWhere('id', (int) $request->input('enterprise_id'));
        if (! $empresa) {
            throw ValidationException::withMessages(['enterprise_id' => 'Selecciona la empresa.']);
        }

        return $empresa;
    }

    private function mes(string $valor): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $valor)->startOfMonth();
    }
}
