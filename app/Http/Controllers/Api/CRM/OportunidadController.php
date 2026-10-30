<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Services\CRM\DashboardResumenService;
use App\Services\CRM\EmbudoService;
use App\Services\CRM\OportunidadProductosService;
use App\Services\CRM\VisibilidadOportunidades;
use App\Support\CRM\RangoDashboard;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OportunidadController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const RELACIONES = CrmOportunidad::RELACIONES_API;

    public function __construct(
        private readonly VisibilidadOportunidades $visibilidad,
        private readonly OportunidadProductosService $productos,
    ) {}

    /** GET /crm/oportunidades */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->visibilidad->exigirVer($empresaId);

        $vendedorId = $this->visibilidad->vendedorVisible($empresaId, $request->integer('vendedor_id') ?: null);

        $query = CrmOportunidad::query()
            ->where('empresa_id', $empresaId)
            ->with(self::RELACIONES)
            ->when($request->etapa, fn ($q, $etapa) => $q->where('etapa', $etapa))
            ->when($vendedorId, fn ($q, $id) => $q->where('vendedor_id', $id))
            // Usados por la pestaña "Oportunidades" de la ficha de cliente y
            // de prospecto, para listar solo las de ese registro.
            ->when($request->cliente_id, fn ($q, $id) => $q->where('cliente_id', $id))
            ->when($request->prospecto_id, fn ($q, $id) => $q->where('prospecto_id', $id))
            ->orderByDesc('created_at');

        $perPage = $this->perPage($request, 100);
        $paginated = $query->paginate($perPage);
        // Las cotizaciones ya vienen cargadas (RELACIONES): sin consulta por fila.
        $paginated->getCollection()->each->conMontoOrigen();

        return $this->jsonPaginated($paginated);
    }

    /** GET /crm/oportunidades/resumen -- cifras de la cabecera del tablero (spec 2026-10-08 §6.4). */
    public function resumen(Request $request, EmbudoService $embudos, DashboardResumenService $metricas): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->visibilidad->exigirVer($empresaId);

        $validated = $request->validate([
            'vendedor_id' => ['nullable', 'integer', $this->existeEnEmpresa('crm_vendedores', $empresaId)],
        ]);
        $vendedorId = $this->visibilidad->vendedorVisible($empresaId, isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null);

        $porEtapa = $embudos->pipeline($empresaId, $vendedorId);
        $mes = RangoDashboard::desdePeriodo('mes_actual');

        return $this->jsonSuccess([
            'abiertas' => array_sum(array_column($porEtapa, 'cantidad')),
            'monto' => round(array_sum(array_column($porEtapa, 'monto')), 2),
            'ponderado' => round(array_sum(array_column($porEtapa, 'ponderado')), 2),
            'porEtapa' => $porEtapa,
            'cerradasMes' => $metricas->cierres($empresaId, $vendedorId, $mes->inicio, $mes->fin)['total'],
            'tasaCierreMes' => [
                'actual' => $metricas->tasaCierre($empresaId, $vendedorId, $mes->inicio, $mes->fin),
                'anterior' => $metricas->tasaCierre($empresaId, $vendedorId, $mes->inicioAnterior, $mes->finAnterior),
            ],
        ]);
    }

    /** GET /crm/oportunidades/{oportunidad} */
    public function show(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad);
        $oportunidad->load(self::RELACIONES)->conMontoOrigen();

        return $this->jsonSuccess($oportunidad);
    }

    /** POST /crm/oportunidades */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->exigirPermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'crear', 'No tienes permiso para crear oportunidades.');

        $conProductos = CrmConfiguracionComercial::productosEnOportunidad($empresaId);

        $validated = $request->validate([
            'prospecto_id' => ['nullable', 'integer', $this->existeEnEmpresa('crm_prospectos', $empresaId)],
            'cliente_id' => ['nullable', 'integer', $this->existeEnEmpresa('crm_clientes', $empresaId)],
            'vendedor_id' => ['required', 'integer', $this->existeEnEmpresa('crm_vendedores', $empresaId)],
            'nombre' => 'required|string|max:255',
            'monto_esperado' => 'nullable|numeric|min:0',
            'probabilidad' => 'nullable|integer|min:0|max:100',
            'fecha_cierre_esperada' => 'nullable|date',
            'notas' => 'nullable|string',
        ] + $this->reglasProductos($empresaId, $conProductos, 'required'));

        // Asignarla a otro vendedor es decisión de gerencia (misma regla que
        // update): sin equipo solo al CrmVendedor activo propio.
        if (! $this->visibilidad->veAlEquipo($empresaId)) {
            $propio = $this->visibilidad->vendedorPropioId($empresaId);
            abort_unless($propio !== null, 403, VisibilidadOportunidades::MENSAJE_SIN_VENDEDOR);
            abort_unless((int) $validated['vendedor_id'] === $propio, 403, 'No puedes asignar la oportunidad a otro vendedor.');
        }

        $error = $this->validarProspectoOCliente($validated);
        if ($error) {
            return $this->jsonError($error, 422);
        }

        if ($conProductos) {
            $this->exigirTotalDentroDeLimite($validated['productos']);
            $inactivo = $this->productoInactivoNuevo($empresaId, $validated['productos'], []);
            if ($inactivo) {
                return $this->jsonError("El producto «{$inactivo}» está inactivo.", 422);
            }
        }

        $lineas = $conProductos ? $validated['productos'] : null;
        unset($validated['productos']);
        if ($conProductos) {
            // El monto lo calcula el back a partir de las líneas.
            unset($validated['monto_esperado']);
        }
        $validated['empresa_id'] = $empresaId;

        $oportunidad = DB::transaction(function () use ($validated, $lineas) {
            $oportunidad = CrmOportunidad::create($validated);
            if ($lineas !== null) {
                $this->productos->reemplazar($oportunidad, $lineas);
            }

            return $oportunidad;
        });
        $oportunidad->load(self::RELACIONES)->conMontoOrigen();

        $this->difundir(new OportunidadUpdated('created', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Oportunidad creada correctamente', 201);
    }

    /** PUT /crm/oportunidades/{oportunidad} */
    public function update(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'oportunidades', 'oportunidades', 'editar', 'No tienes permiso para editar oportunidades.');
        $empresaId = (int) $oportunidad->empresa_id;
        $this->visibilidad->exigirVisible($empresaId, $oportunidad, VisibilidadOportunidades::MENSAJE_MODIFICAR_AJENA);

        $conProductos = CrmConfiguracionComercial::productosEnOportunidad($empresaId);

        $validated = $request->validate([
            'prospecto_id' => ['sometimes', 'nullable', 'integer', $this->existeEnEmpresa('crm_prospectos', $empresaId)],
            'cliente_id' => ['sometimes', 'nullable', 'integer', $this->existeEnEmpresa('crm_clientes', $empresaId)],
            'vendedor_id' => ['sometimes', 'required', 'integer', $this->existeEnEmpresa('crm_vendedores', $empresaId)],
            'nombre' => 'sometimes|required|string|max:255',
            'monto_esperado' => 'sometimes|nullable|numeric|min:0',
            'probabilidad' => 'sometimes|nullable|integer|min:0|max:100',
            'fecha_cierre_esperada' => 'sometimes|nullable|date',
            'notas' => 'sometimes|nullable|string',
        ] + $this->reglasProductos($empresaId, $conProductos, 'sometimes'));

        $lineas = null;
        if ($conProductos) {
            // El monto lo calcula el back (o queda congelado/guardado): el que
            // mande el cliente se ignora siempre, traiga o no productos.
            unset($validated['monto_esperado']);
            if ($oportunidad->estaCerrada()) {
                if (array_key_exists('productos', $validated)) {
                    return $this->jsonError('Esta oportunidad está cerrada; sus productos no se pueden cambiar.', 422);
                }
            } elseif (array_key_exists('productos', $validated)) {
                $this->exigirTotalDentroDeLimite($validated['productos']);
                $actuales = $oportunidad->productos()->pluck('producto_id')->all();
                $inactivo = $this->productoInactivoNuevo($empresaId, $validated['productos'], $actuales);
                if ($inactivo) {
                    return $this->jsonError("El producto «{$inactivo}» está inactivo.", 422);
                }
                $lineas = $validated['productos'];
            }
        }
        unset($validated['productos']);

        if (! $conProductos && array_key_exists('monto_esperado', $validated)) {
            if (round((float) $validated['monto_esperado'], 2) === round((float) $oportunidad->monto_esperado, 2)) {
                // Mismo valor al centavo: no es un cambio, no se escribe.
                unset($validated['monto_esperado']);
            } else {
                $this->exigirMontoEditable($oportunidad);
            }
        }

        // Reasignar a otro vendedor es decisión de gerencia: sin equipo solo
        // se acepta el vendedor propio (el actual, porque solo edita las suyas).
        abort_if(
            array_key_exists('vendedor_id', $validated)
                && (int) $validated['vendedor_id'] !== (int) $oportunidad->vendedor_id
                && ! $this->visibilidad->veAlEquipo($empresaId),
            403,
            'No puedes asignar la oportunidad a otro vendedor.',
        );

        if (array_key_exists('prospecto_id', $validated) || array_key_exists('cliente_id', $validated)) {
            $mezclado = [
                'prospecto_id' => $validated['prospecto_id'] ?? $oportunidad->prospecto_id,
                'cliente_id' => $validated['cliente_id'] ?? $oportunidad->cliente_id,
            ];
            $error = $this->validarProspectoOCliente($mezclado);
            if ($error) {
                return $this->jsonError($error, 422);
            }
        }

        DB::transaction(function () use ($oportunidad, $validated, $lineas) {
            $oportunidad->update($validated);
            if ($lineas !== null) {
                $this->productos->reemplazar($oportunidad, $lineas);
            }
        });
        $oportunidad->load(self::RELACIONES)->conMontoOrigen();

        $this->difundir(new OportunidadUpdated('updated', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Oportunidad actualizada correctamente');
    }

    /** DELETE /crm/oportunidades/{oportunidad} */
    public function destroy(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'oportunidades', 'oportunidades', 'eliminar', 'No tienes permiso para eliminar oportunidades.');
        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad, VisibilidadOportunidades::MENSAJE_MODIFICAR_AJENA);

        $data = $oportunidad->toArray();
        $oportunidad->delete();

        $this->difundir(new OportunidadUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Oportunidad eliminada correctamente');
    }

    /**
     * Reglas de las líneas de producto. Con el interruptor de la empresa
     * encendido son obligatorias en store ('required') y opcionales en update
     * ('sometimes'); apagado se prohíben, para que nada cambie en las demás empresas.
     *
     * @return array<string, mixed>
     */
    private function reglasProductos(int $empresaId, bool $conProductos, string $presencia): array
    {
        if (! $conProductos) {
            return ['productos' => 'prohibited'];
        }

        return [
            'productos' => [$presencia, 'array', 'min:1'],
            'productos.*.producto_id' => ['required', 'integer', 'distinct', $this->existeEnEmpresa('crm_productos', $empresaId)],
            'productos.*.cantidad' => 'required|numeric|gt:0|max:99999999',
            'productos.*.precio_unitario' => 'required|numeric|min:0|max:9999999999.99',
        ];
    }

    /**
     * El total calculado debe caber en monto_esperado (decimal(12,2)); si no,
     * MySQL estricto daría 500 en lugar de un 422 claro.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function exigirTotalDentroDeLimite(array $lineas): void
    {
        if ($this->productos->monto($lineas) > CrmOportunidad::MONTO_MAXIMO) {
            throw ValidationException::withMessages([
                'productos' => 'El total de la oportunidad excede el máximo permitido.',
            ]);
        }
    }

    /**
     * El monto se edita a mano solo en oportunidades abiertas cuyo monto no
     * viene de una cotización (spec 2026-10-30 §7); si no, 422 en `monto_esperado`.
     */
    private function exigirMontoEditable(CrmOportunidad $oportunidad): void
    {
        if ($oportunidad->estaCerrada()) {
            throw ValidationException::withMessages([
                'monto_esperado' => 'La oportunidad está cerrada; su monto ya no se puede cambiar.',
            ]);
        }

        $cotizacion = $oportunidad->cotizacionDelMonto();
        if ($cotizacion !== null) {
            throw ValidationException::withMessages([
                'monto_esperado' => "El monto viene de la cotización {$cotizacion->folio}; para cambiarlo edita o crea una cotización.",
            ]);
        }
    }

    /**
     * Nombre del primer producto inactivo que no estaba ya en las líneas
     * actuales de la oportunidad (un producto desactivado después de
     * agregarlo se conserva, pero no se agrega uno inactivo nuevo).
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @param  array<int, int|string>  $idsActuales
     */
    private function productoInactivoNuevo(int $empresaId, array $lineas, array $idsActuales): ?string
    {
        $ids = array_diff(
            array_map('intval', array_column($lineas, 'producto_id')),
            array_map('intval', $idsActuales),
        );
        if ($ids === []) {
            return null;
        }

        return CrmProducto::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ids)
            ->where('activo', false)
            ->orderBy('id')
            ->value('nombre');
    }

    /** Exactamente uno de prospecto_id/cliente_id debe estar presente. */
    private function validarProspectoOCliente(array $datos): ?string
    {
        $tieneProspecto = ! empty($datos['prospecto_id'] ?? null);
        $tieneCliente = ! empty($datos['cliente_id'] ?? null);

        if ($tieneProspecto === $tieneCliente) {
            return 'Debe indicar exactamente un prospecto o un cliente, no ambos ni ninguno.';
        }

        return null;
    }

    protected function verificarEmpresa(CrmOportunidad $oportunidad): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $oportunidad->empresa_id !== (int) $empresaId) {
            abort(404, 'Oportunidad no encontrada');
        }
    }

    /** PATCH /crm/oportunidades/{oportunidad}/cambiar-etapa */
    public function cambiarEtapa(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresa($oportunidad);

        // Con catálogo de rechazo (categorías activas con motivos activos) al
        // perder se elige un motivo del catálogo y el texto pasa a ser un
        // comentario opcional; sin catálogo el texto libre sigue siendo el motivo.
        $conCatalogo = $request->input('etapa') === 'cerrado_perdido'
            && CrmCategoriaRechazo::empresaTieneCatalogo((int) $oportunidad->empresa_id);

        $reglas = [
            'etapa' => 'required|in:prospecto,calificado,propuesta,negociacion,cerrado_ganado,cerrado_perdido',
            'motivo_perdida' => 'required_if:etapa,cerrado_perdido|nullable|string',
            'forzar' => 'sometimes|boolean',
        ];
        if ($conCatalogo) {
            $reglas['motivo_perdida'] = 'nullable|string|max:1000';
            $reglas['motivo_rechazo_id'] = [
                'required',
                'integer',
                Rule::exists('crm_motivos_rechazo', 'id')->where(fn ($q) => $q
                    ->where('activo', true)
                    ->whereIn('categoria_id', CrmCategoriaRechazo::query()
                        ->where('empresa_id', $oportunidad->empresa_id)
                        ->where('activo', true)
                        ->select('id'))),
            ];
        }

        $validated = $request->validate($reglas);

        $nuevaEtapa = $validated['etapa'];

        // Mover entre etapas activas es editar; llevarla a una etapa terminal
        // (ganada o perdida) es cerrarla y exige el permiso específico.
        if (in_array($nuevaEtapa, ['cerrado_ganado', 'cerrado_perdido'], true)) {
            $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'oportunidades', 'oportunidades', 'cerrar', 'No tienes permiso para cerrar oportunidades.');
        } else {
            $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'oportunidades', 'oportunidades', 'editar', 'No tienes permiso para editar oportunidades.');
        }

        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad, VisibilidadOportunidades::MENSAJE_MODIFICAR_AJENA);

        // Terminal es terminal: una oportunidad ya cerrada (ganada o perdida)
        // no se reabre ni se mueve a la otra etapa terminal. "forzar" solo
        // sirve para saltarse el requisito de cotización aprobada al cerrar
        // como ganada desde una etapa activa — nunca para reabrir un cierre.
        if ($oportunidad->estaCerrada()) {
            return $this->jsonError(
                'Esta oportunidad ya está cerrada y su etapa no se puede volver a cambiar.',
                422
            );
        }

        if (! $oportunidad->puedeAvanzarA($nuevaEtapa)) {
            return $this->jsonError('No se puede regresar una oportunidad a una etapa anterior.', 422);
        }

        if ($nuevaEtapa === 'cerrado_ganado' && ! ($validated['forzar'] ?? false)) {
            $tieneCotizacionAprobada = $oportunidad->cotizaciones()->where('estado', 'aprobado')->exists();
            if (! $tieneCotizacionAprobada) {
                return $this->jsonError(
                    'Esta oportunidad no tiene una cotización aprobada. Aprueba una cotización o fuerza el cierre manual con "forzar".',
                    422
                );
            }
        }

        $oportunidad->etapa = $nuevaEtapa;
        if ($nuevaEtapa === 'cerrado_perdido') {
            $oportunidad->motivo_perdida = $validated['motivo_perdida'] ?? null;
            if ($conCatalogo) {
                $oportunidad->motivo_rechazo_id = $validated['motivo_rechazo_id'];
            }
            $oportunidad->fecha_cierre_real = now();
        } elseif ($nuevaEtapa === 'cerrado_ganado') {
            $oportunidad->fecha_cierre_real = now();
        }
        $oportunidad->save();
        $oportunidad->load(self::RELACIONES)->conMontoOrigen();

        $this->difundir(new OportunidadUpdated('updated', $oportunidad->toArray()));

        return $this->jsonSuccess($oportunidad, 'Etapa actualizada correctamente');
    }
}
