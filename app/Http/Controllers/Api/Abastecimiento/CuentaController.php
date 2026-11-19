<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Services\Abastecimiento\CuentaConvenio;
use App\Services\Abastecimiento\PresentadorCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuentas por convenio (submódulo `liquidaciones`), solo lectura: la lista de la temporada con sus totales y la
 * cuenta de un convenio con sus recepciones, pagos y acciones. El dinero se ve únicamente con `liquidaciones.view`.
 * La forma del JSON es de `PresentadorCuenta` y los números de `CuentaConvenio`.
 */
class CuentaController extends AbastecimientoController
{
    private const SUBMODULO = 'liquidaciones';

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
}
