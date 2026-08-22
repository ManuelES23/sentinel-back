<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Services\CRM\CotizacionCalculoService;
use App\Traits\CRM\FiltraPorEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CotizacionController extends CrmBaseController
{
    use FiltraPorEmpresa;

    public function __construct(private readonly CotizacionCalculoService $calculo) {}

    /** GET /crm/oportunidades/{oportunidad}/cotizaciones */
    public function index(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresaOportunidad($oportunidad);

        $cotizaciones = $oportunidad->cotizaciones()->with('impuestos')->orderByDesc('created_at')->get();

        return $this->jsonSuccess($cotizaciones);
    }

    /** GET /crm/cotizaciones/{cotizacion} */
    public function show(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $cotizacion->load(['lineas.producto:id,nombre', 'impuestos']);

        return $this->jsonSuccess($cotizacion);
    }

    /** POST /crm/oportunidades/{oportunidad}/cotizaciones */
    public function store(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresaOportunidad($oportunidad);
        $empresaId = $this->getEmpresaId();

        $validated = $request->validate([
            'fecha_emision' => 'required|date',
            'vigencia_dias' => 'nullable|integer|min:1',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string',
            'lineas' => 'required|array|min:1',
            'lineas.*.producto_id' => 'required|integer|exists:crm_productos,id',
            'lineas.*.cantidad' => 'required|numeric|min:0.0001',
            'lineas.*.precio_unitario' => 'required|numeric|min:0',
        ]);

        $descuento = $validated['descuento_global_pct'] ?? 0;
        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        if ($descuento > 0 && ! $config->descuento_global_habilitado) {
            return $this->jsonError('Esta empresa no tiene habilitado el descuento global en cotizaciones.', 422);
        }

        $cotizacion = DB::transaction(function () use ($validated, $oportunidad, $empresaId, $descuento) {
            $cotizacion = CrmCotizacion::create([
                'empresa_id' => $empresaId,
                'oportunidad_id' => $oportunidad->id,
                'folio' => $this->siguienteFolio(),
                'estado' => 'borrador',
                'fecha_emision' => $validated['fecha_emision'],
                'vigencia_dias' => $validated['vigencia_dias'] ?? null,
                'descuento_global_pct' => $descuento,
                'notas' => $validated['notas'] ?? null,
            ]);

            foreach ($validated['lineas'] as $linea) {
                $producto = CrmProducto::find($linea['producto_id']);
                CrmOportunidadProducto::create([
                    'oportunidad_id' => $oportunidad->id,
                    'cotizacion_id' => $cotizacion->id,
                    'producto_id' => $linea['producto_id'],
                    'descripcion' => $producto?->nombre ?? '',
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                ]);
            }

            return $this->calculo->recalcular($cotizacion);
        });

        return $this->jsonSuccess($cotizacion, 'Cotización creada correctamente', 201);
    }

    /** PUT /crm/cotizaciones/{cotizacion} — solo permitido en borrador */
    public function update(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);

        if ($cotizacion->estado !== 'borrador') {
            return $this->jsonError('Solo se puede editar una cotización en borrador.', 422);
        }

        $empresaId = $this->getEmpresaId();
        $validated = $request->validate([
            'fecha_emision' => 'sometimes|required|date',
            'vigencia_dias' => 'nullable|integer|min:1',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string',
            'lineas' => 'sometimes|required|array|min:1',
            'lineas.*.producto_id' => 'required_with:lineas|integer|exists:crm_productos,id',
            'lineas.*.cantidad' => 'required_with:lineas|numeric|min:0.0001',
            'lineas.*.precio_unitario' => 'required_with:lineas|numeric|min:0',
        ]);

        $descuento = $validated['descuento_global_pct'] ?? $cotizacion->descuento_global_pct;
        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        if ($descuento > 0 && ! $config->descuento_global_habilitado) {
            return $this->jsonError('Esta empresa no tiene habilitado el descuento global en cotizaciones.', 422);
        }

        $cotizacion = DB::transaction(function () use ($validated, $cotizacion) {
            $cotizacion->update(collect($validated)->except('lineas')->all());

            if (isset($validated['lineas'])) {
                $cotizacion->lineas()->delete();
                foreach ($validated['lineas'] as $linea) {
                    $producto = CrmProducto::find($linea['producto_id']);
                    CrmOportunidadProducto::create([
                        'oportunidad_id' => $cotizacion->oportunidad_id,
                        'cotizacion_id' => $cotizacion->id,
                        'producto_id' => $linea['producto_id'],
                        'descripcion' => $producto?->nombre ?? '',
                        'cantidad' => $linea['cantidad'],
                        'precio_unitario' => $linea['precio_unitario'],
                    ]);
                }
            }

            return $this->calculo->recalcular($cotizacion);
        });

        return $this->jsonSuccess($cotizacion, 'Cotización actualizada correctamente');
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/enviar */
    public function enviar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        if ($cotizacion->estado !== 'borrador') {
            return $this->jsonError('Solo una cotización en borrador se puede enviar.', 422);
        }
        $cotizacion->update(['estado' => 'enviado']);

        return $this->jsonSuccess($cotizacion, 'Cotización marcada como enviada');
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/rechazar */
    public function rechazar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        if ($cotizacion->estado !== 'enviado') {
            return $this->jsonError('Solo una cotización enviada se puede rechazar.', 422);
        }
        $cotizacion->update(['estado' => 'rechazado']);

        return $this->jsonSuccess($cotizacion, 'Cotización rechazada');
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/aprobar */
    public function aprobar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        if ($cotizacion->estado !== 'enviado') {
            return $this->jsonError('Solo una cotización enviada se puede aprobar.', 422);
        }

        DB::transaction(function () use ($cotizacion) {
            // Bloquea la fila de la oportunidad para que un aprobar() concurrente
            // sobre una cotización hermana no pueda intercalar su propio
            // supersede+approve dentro de esta misma ventana de transacción.
            CrmOportunidad::whereKey($cotizacion->oportunidad_id)->lockForUpdate()->first();

            // Un solo aprobado a la vez por oportunidad: el que ya estaba aprobado pasa a superado.
            CrmCotizacion::where('oportunidad_id', $cotizacion->oportunidad_id)
                ->where('id', '!=', $cotizacion->id)
                ->where('estado', 'aprobado')
                ->update(['estado' => 'superado']);

            $cotizacion->update(['estado' => 'aprobado']);

            $oportunidad = $cotizacion->oportunidad;
            $oportunidad->etapa = 'cerrado_ganado';
            $oportunidad->fecha_cierre_real = now();
            $oportunidad->save();
        });

        return $this->jsonSuccess($cotizacion->fresh(), 'Cotización aprobada — la oportunidad se cerró como ganada');
    }

    private function siguienteFolio(): string
    {
        $ultimo = CrmCotizacion::withTrashed()->where('folio', 'like', 'COT-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 5) AS UNSIGNED) DESC')->value('folio');
        $siguiente = $ultimo ? ((int) substr($ultimo, 4)) + 1 : 1;

        return 'COT-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    private function verificarEmpresaOportunidad(CrmOportunidad $oportunidad): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $oportunidad->empresa_id !== (int) $empresaId) {
            abort(404, 'Oportunidad no encontrada');
        }
    }

    protected function verificarEmpresa(CrmCotizacion $cotizacion): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $cotizacion->empresa_id !== (int) $empresaId) {
            abort(404, 'Cotización no encontrada');
        }
    }
}
