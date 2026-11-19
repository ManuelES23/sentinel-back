<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use App\Services\Abastecimiento\CuentaConvenio;
use App\Services\Abastecimiento\EstadoCuentaConvenio;
use App\Services\Abastecimiento\PagosCuenta;
use App\Services\Abastecimiento\PresentadorCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuentas por convenio (submódulo `liquidaciones`): la lista de la temporada con sus totales, la cuenta de un
 * convenio con sus recepciones, pagos y acciones, su estado de cuenta con saldo corrido y los pagos (registrar,
 * cancelar y saldo inicial, vía `PagosCuenta`). Leer exige `view`; cada escritura, `view` más su permiso (`create`, `cancelar`, `carga-inicial`).
 * La forma del JSON es de `PresentadorCuenta` y los números de `CuentaConvenio`.
 */
class CuentaController extends AbastecimientoController
{
    private const SUBMODULO = 'liquidaciones';

    /** Monto de dinero: > 0, hasta 2 decimales y dentro de `decimal(14,2)`. */
    private const MONTO = ['numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'];

    /**
     * Cuentas de los convenios de una temporada (no borradores, con recepciones o algún pago), por productor y
     * folio. Número de consultas constante: los totales de todos los convenios salen de `CuentaConvenio::totales`.
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('abs_temporadas', 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at')],
            'productor_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'con_saldo' => ['nullable', 'boolean'],
            'liquidadas' => ['nullable', 'boolean'],
        ]);

        $convenios = Convenio::deEmpresa($empresa->id)
            ->join('abs_productores', 'abs_productores.id', '=', 'abs_convenios.productor_id')
            ->where('abs_convenios.temporada_id', $filtros['temporada_id'])
            ->where('abs_convenios.status', '!=', 'borrador')
            ->where(fn ($q) => $q->whereHas('recepciones')->orWhereHas('pagos'))
            ->when(! empty($filtros['productor_id']), fn ($q) => $q->where('abs_convenios.productor_id', $filtros['productor_id']))
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                // '!' como carácter de escape: funciona igual en MySQL y SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->where(fn ($q) => $q->whereRaw("abs_convenios.folio LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$like]));
            })
            ->select('abs_convenios.*')
            ->with(PresentadorCuenta::carga())
            ->orderBy('abs_productores.nombre')->orderBy('abs_convenios.folio')
            ->get();

        $totales = CuentaConvenio::totales($convenios);
        $verLiquidadas = $request->boolean('liquidadas');
        $soloConSaldo = $request->boolean('con_saldo');

        $filas = $convenios->filter(function (Convenio $c) use ($totales, $verLiquidadas, $soloConSaldo) {
            $t = $totales[$c->id];
            if (! $verLiquidadas && $t['estado'] === 'liquidada' && $t['por_pagar'] <= 0) {
                return false;
            }

            return ! $soloConSaldo || $t['por_pagar'] > 0;
        })->values();

        $folios = $filas->isEmpty() ? [] : Liquidacion::whereIn('id', array_filter(array_column($totales, 'liquidacion_id')))
            ->pluck('folio', 'id')->all();
        $presentador = app(PresentadorCuenta::class);

        return response()->json([
            'success' => true,
            'data' => $filas->map(fn (Convenio $c) => $presentador->fila($totales[$c->id], $c, $folios))->all(),
        ]);
    }

    /** Cuenta de un convenio de la empresa (404 si es de otra). */
    public function show(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $modelo = Convenio::deEmpresa($empresa->id)->findOrFail($convenio);

        return response()->json([
            'success' => true,
            'data' => app(PresentadorCuenta::class)->completa($modelo, $empresa, $request->user()),
        ]);
    }

    /**
     * Estado de cuenta del convenio (para el PDF): movimientos con saldo corrido; el último saldo es el `por_pagar`
     * de la cuenta. Solo `view` (404 si el convenio es de otra empresa).
     */
    public function estadoCuenta(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $modelo = Convenio::deEmpresa($empresa->id)->findOrFail($convenio);

        return response()->json([
            'success' => true,
            'data' => EstadoCuentaConvenio::armar(app(PresentadorCuenta::class), $empresa, $modelo),
        ]);
    }

    /**
     * Registra un pago por recepción, abono o adelanto (`create`). La moneda es la del convenio: si el cliente
     * manda `moneda`, se ignora. Responde 201 con la cuenta completa.
     */
    public function registrarPago(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(PagosCuenta::TIPOS_REGISTRABLES)],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo_pago' => ['required', Rule::in(Abono::METODOS_PAGO)],
            'referencia' => ['nullable', 'string', 'max:120'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'monto' => ['exclude_if:tipo,recepcion', 'required', ...self::MONTO],
            'recepciones' => ['exclude_unless:tipo,recepcion', 'required', 'array', 'min:1', 'max:200'],
            'recepciones.*' => ['exclude_unless:tipo,recepcion', 'array'],
            'recepciones.*.recepcion_id' => ['exclude_unless:tipo,recepcion', 'required', 'integer'],
            'recepciones.*.monto' => ['exclude_unless:tipo,recepcion', 'required', ...self::MONTO],
        ]);

        $pago = app(PagosCuenta::class)->registrar($empresa, $request->user(), $convenio, $datos);

        return $this->respuestaPago($request, $empresa, $pago, 'created', 'Pago registrado.', 201);
    }

    /** Cancela un pago con su motivo (`cancelar`). Responde la cuenta completa. */
    public function cancelarPago(Request $request, int $id): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'cancelar');
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:2000'],
        ]);

        $pago = app(PagosCuenta::class)->cancelar($empresa, $request->user(), $id, $datos['motivo']);

        return $this->respuestaPago($request, $empresa, $pago, 'updated', 'Pago cancelado.', 200);
    }

    /** Saldo inicial (deuda sin recepciones) de la cuenta (`carga-inicial`). El motivo se guarda en las notas. */
    public function saldoInicial(Request $request, int $convenio): JsonResponse
    {
        $empresa = $this->autorizarConVer($request, self::SUBMODULO, 'carga-inicial');
        $datos = $request->validate([
            'monto' => ['required', ...self::MONTO],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'motivo' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $pago = app(PagosCuenta::class)->saldoInicial($empresa, $request->user(), $convenio, $datos);

        return $this->respuestaPago($request, $empresa, $pago, 'created', 'Saldo inicial registrado.', 201);
    }

    /**
     * Ya confirmada la transacción: emite el evento recortado (sin montos ni notas; una falla del websocket no rompe
     * la respuesta) y responde con la cuenta completa recién leída.
     */
    private function respuestaPago(Request $request, Enterprise $empresa, Abono $pago, string $accion, string $mensaje, int $status): JsonResponse
    {
        $this->difundir('pago', $accion, PresentadorCuenta::eventoPago($pago), $empresa);

        $convenio = Convenio::deEmpresa($empresa->id)->findOrFail($pago->convenio_id);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => app(PresentadorCuenta::class)->completa($convenio, $empresa, $request->user()),
        ], $status);
    }
}
