<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\RequisicionCampo;
use App\Models\RequisicionCotizacion;
use App\Services\Compras\AlcanceCompras;
use App\Services\Compras\CotizacionService;
use App\Services\Compras\PermisosCompras;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cotizaciones de proveedores sobre una requisición (solo Compras).
 */
class RequisicionCotizacionController extends Controller
{
    public function __construct(
        private AlmacenAccessService $almacenes,
        private AlcanceCompras $alcance,
        private PermisosCompras $permisos,
        private CotizacionService $cotizaciones,
    ) {
    }

    public function index(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $user = $request->user();
        abort_unless($this->alcance->puedeVer($user, $empresa, $requisicion, 'almacen_id'), 403, 'No tienes acceso a esta requisición');
        abort_unless(
            $this->permisos->puedeCotizar($user, $empresa) || $this->almacenes->puedeVerTodos($user, $empresa),
            403,
            'No tienes acceso a las cotizaciones'
        );

        $lista = $requisicion->cotizaciones()
            ->with(['supplier:id,code,business_name,trade_name', 'creador:id,name', 'detalles'])
            ->orderBy('total')
            ->get();

        return response()->json(['success' => true, 'data' => $lista]);
    }

    public function store(Request $request, RequisicionCampo $requisicion): JsonResponse
    {
        $this->autorizarEscritura($request, $requisicion);
        $datos = $this->validar($request, $requisicion);

        $cot = $this->cotizaciones->guardar($requisicion, $datos, $request->user());

        return response()->json(['success' => true, 'message' => 'Cotización registrada', 'data' => $cot], 201);
    }

    public function update(Request $request, RequisicionCampo $requisicion, RequisicionCotizacion $cotizacion): JsonResponse
    {
        $this->autorizarEscritura($request, $requisicion, $cotizacion);
        $datos = $this->validar($request, $requisicion);

        $cot = $this->cotizaciones->guardar($requisicion, $datos, $request->user(), $cotizacion);

        return response()->json(['success' => true, 'message' => 'Cotización actualizada', 'data' => $cot]);
    }

    public function destroy(Request $request, RequisicionCampo $requisicion, RequisicionCotizacion $cotizacion): JsonResponse
    {
        $this->autorizarEscritura($request, $requisicion, $cotizacion);
        $this->cotizaciones->eliminar($cotizacion);

        return response()->json(['success' => true, 'message' => 'Cotización eliminada']);
    }

    public function ganadora(Request $request, RequisicionCampo $requisicion, RequisicionCotizacion $cotizacion): JsonResponse
    {
        $this->autorizarEscritura($request, $requisicion, $cotizacion);
        $this->cotizaciones->marcarGanadora($cotizacion);

        return response()->json(['success' => true, 'message' => 'Cotización ganadora elegida', 'data' => $cotizacion->fresh(['supplier', 'detalles'])]);
    }

    public function archivo(Request $request, RequisicionCampo $requisicion, RequisicionCotizacion $cotizacion): JsonResponse
    {
        $this->autorizarEscritura($request, $requisicion, $cotizacion);
        $request->validate(['archivo' => 'required|file|mimes:pdf|max:10240']);

        if ($cotizacion->archivo_path) {
            Storage::disk('public')->delete($cotizacion->archivo_path);
        }
        $cotizacion->update(['archivo_path' => $request->file('archivo')->store('cotizaciones', 'public')]);

        return response()->json(['success' => true, 'message' => 'Archivo adjuntado', 'data' => $cotizacion->fresh()]);
    }

    private function autorizarEscritura(Request $request, RequisicionCampo $requisicion, ?RequisicionCotizacion $cotizacion = null): void
    {
        $empresa = $this->almacenes->resolverEmpresa($request);
        $user = $request->user();
        abort_unless($this->permisos->puedeCotizar($user, $empresa), 403, 'No tienes permiso para cotizar');
        abort_unless($this->alcance->puedeVer($user, $empresa, $requisicion, 'almacen_id'), 403, 'No tienes acceso a esta requisición');
        abort_if($cotizacion && (int) $cotizacion->requisicion_campo_id !== (int) $requisicion->id, 404);

        if (! in_array($requisicion->status, RequisicionCampo::STATUS_EN_COMPRAS, true)) {
            throw ValidationException::withMessages(['requisicion' => 'La requisición ya no admite cambios en sus cotizaciones.']);
        }
    }

    private function validar(Request $request, RequisicionCampo $requisicion): array
    {
        $datos = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'folio_proveedor' => 'nullable|string|max:60',
            'fecha' => 'required|date',
            'vigencia' => 'nullable|date|after_or_equal:fecha',
            'dias_entrega' => 'nullable|integer|min:0|max:365',
            'condiciones_pago' => 'nullable|string|max:150',
            'notas' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.requisicion_detalle_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('requisicion_campo_detalles', 'id')->where('requisicion_campo_id', $requisicion->id),
            ],
            'detalles.*.disponible' => 'boolean',
            'detalles.*.cantidad' => 'required|numeric|min:0',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
            'detalles.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ], [
            'supplier_id.exists' => 'El proveedor no existe o está inactivo.',
        ]);

        $cotizable = collect($datos['detalles'])->contains(fn ($d) => ($d['disponible'] ?? true) && (float) $d['cantidad'] > 0);
        if (! $cotizable) {
            throw ValidationException::withMessages(['detalles' => 'Cotiza al menos un producto disponible.']);
        }

        return $datos;
    }
}
