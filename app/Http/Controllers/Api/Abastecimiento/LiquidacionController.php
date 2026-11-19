<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use App\Services\Abastecimiento\LiquidarConvenio;
use App\Services\Abastecimiento\PresentadorCuenta;
use App\Services\Abastecimiento\PresentadorLiquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Liquidación final de un convenio (submódulo `liquidaciones`, spec 5.4): la ficha (`view`), la vista previa y
 * «Liquidar total» (`liquidar`) y la cancelación (`delete`). Todo responde con dinero, así que cada acción exige
 * además `view`. Los números salen de `LiquidarConvenio`; la forma, de `PresentadorLiquidacion` y `PresentadorCuenta`.
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

    /** Cálculo de «Liquidar total» sin guardar nada. */
    public function previa(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'liquidar');
        $datos = $request->validate($this->reglasAjuste());
        $modelo = Convenio::deEmpresa($empresa->id)->findOrFail($convenio);

        $ajuste = isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null;

        return response()->json([
            'success' => true,
            'data' => app(LiquidarConvenio::class)->previa($modelo, $ajuste, $datos['motivo_ajuste'] ?? null),
        ]);
    }

    /** Liquida el convenio completo (y, si se pide, registra el pago del restante). Responde 201 con la cuenta. */
    public function liquidar(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'liquidar');
        $soloSiPaga = 'exclude_unless:registrar_pago,true';
        $datos = $request->validate($this->reglasAjuste() + [
            'notas' => ['nullable', 'string', 'max:5000'],
            'registrar_pago' => ['required', 'boolean'],
            // El pago solo se exige si hay restante: lo decide el servicio con la cuenta ya bloqueada.
            'pago' => [$soloSiPaga, 'nullable', 'array'],
            'pago.fecha' => [$soloSiPaga, 'required_with:pago', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pago.metodo_pago' => [$soloSiPaga, 'required_with:pago', Rule::in(Abono::METODOS_PAGO)],
            'pago.referencia' => [$soloSiPaga, 'nullable', 'string', 'max:120'],
            'pago.notas' => [$soloSiPaga, 'nullable', 'string', 'max:5000'],
        ]);
        $datos['registrar_pago'] = $request->boolean('registrar_pago');

        $liquidacion = app(LiquidarConvenio::class)->liquidar($empresa, $request->user(), $convenio, $datos);

        return $this->respuesta($request, $empresa, $liquidacion, 'created', 'Convenio liquidado.', 201);
    }

    /** Cancela la liquidación con su motivo; la cuenta vuelve a abierta. */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'delete');
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:2000'],
        ]);

        $liquidacion = app(LiquidarConvenio::class)->cancelar($empresa, $request->user(), $id, $datos['motivo']);

        return $this->respuesta($request, $empresa, $liquidacion, 'updated', 'Liquidación cancelada.', 200);
    }

    /** Monto ajustado opcional (≥ 0, 2 decimales, dentro de `decimal(14,2)`) con su motivo. */
    private function reglasAjuste(): array
    {
        return [
            'monto_ajustado' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:999999999999.99'],
            'motivo_ajuste' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Ya confirmada la transacción: emite los eventos recortados (la liquidación y cada pago `liquidacion` que se
     * creó o canceló; una falla del websocket no rompe la respuesta) y responde con la cuenta recién leída.
     */
    private function respuesta(Request $request, Enterprise $empresa, Liquidacion $liquidacion, string $accion, string $mensaje, int $status): JsonResponse
    {
        $this->difundir('liquidacion', $accion, PresentadorLiquidacion::evento($liquidacion), $empresa);
        foreach ($liquidacion->pagos as $pago) {
            $this->difundir('pago', $accion, PresentadorCuenta::eventoPago($pago), $empresa);
        }

        $convenio = Convenio::deEmpresa($empresa->id)->findOrFail($liquidacion->convenio_id);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => app(PresentadorCuenta::class)->completa($convenio, $empresa, $request->user()),
        ], $status);
    }
}
