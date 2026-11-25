<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\CotizacionUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmConfiguracionImpuesto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmProducto;
use App\Services\CRM\CotizacionCalculoService;
use App\Services\CRM\MontoOportunidadService;
use App\Services\CRM\VentaNoRegistrableException;
use App\Services\CRM\VentaOportunidadService;
use App\Services\CRM\VersionesCotizacion;
use App\Services\CRM\VisibilidadOportunidades;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones del CRM. Heredan la visibilidad de su oportunidad
 * (VisibilidadOportunidades): leer exige cotizaciones.ver y escribir su propio
 * permiso, y en ambos casos poder VER la oportunidad (sin oportunidades.equipo,
 * solo las del CrmVendedor activo propio). Orden: tenant (404), permiso (403)
 * y oportunidad visible (403), antes de cualquier validación de estado o
 * efecto. Una cotización cuya oportunidad está eliminada (soft delete) o no
 * existe solo la ve y la toca quien tiene oportunidades.equipo.
 */
class CotizacionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public const MENSAJE_LEER_AJENA = 'No puedes ver las cotizaciones de otro vendedor.';

    public const MENSAJE_MODIFICAR_AJENA = 'No puedes modificar las cotizaciones de otro vendedor.';

    private const MENSAJE_SIN_VER = 'No tienes permiso para ver cotizaciones.';

    private const MENSAJE_SOLO_BORRADOR = 'Solo se puede editar una cotización en borrador.';

    private const MENSAJE_CON_VENTA = 'La cotización ya tiene venta registrada; registra un ajuste en la oportunidad.';

    public function __construct(
        private readonly CotizacionCalculoService $calculo,
        private readonly MontoOportunidadService $monto,
        private readonly VisibilidadOportunidades $visibilidad,
        private readonly VersionesCotizacion $versiones,
        private readonly VentaOportunidadService $ventas,
    ) {}

    /** GET /crm/oportunidades/{oportunidad}/cotizaciones */
    public function index(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresaOportunidad($oportunidad);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'ver', self::MENSAJE_SIN_VER);
        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad, self::MENSAJE_LEER_AJENA);

        $cotizaciones = $oportunidad->cotizaciones()->with('impuestos')->orderByDesc('created_at')->get();

        return $this->jsonSuccess($cotizaciones);
    }

    /**
     * GET /crm/cotizaciones — listado global de la empresa (todas las
     * oportunidades), para la vista de nivel superior del submódulo
     * Cotizaciones. Distinto de index(): ahí se lista por UNA oportunidad;
     * aquí se cruzan todas, con los mismos filtros que OportunidadController::index.
     */
    public function indexEmpresa(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->exigirPermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'ver', self::MENSAJE_SIN_VER);
        // Mismo alcance que GET crm/oportunidades: sin oportunidades.ver 403;
        // sin equipo, forzado al vendedor propio (pedir otro o no tenerlo, 403).
        $this->visibilidad->exigirVer($empresaId);
        $vendedorId = $this->visibilidad->vendedorVisible($empresaId, $request->integer('vendedor_id') ?: null);

        $query = CrmCotizacion::query()
            ->where('empresa_id', $empresaId)
            ->with([
                'oportunidad:id,folio,nombre,prospecto_id,cliente_id,vendedor_id',
                'oportunidad.prospecto:id,nombre',
                'oportunidad.cliente:id,nombre',
                'oportunidad.vendedor:id,nombre',
            ])
            ->when($request->estado, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($vendedorId, fn ($q, $id) => $q->whereHas(
                'oportunidad', fn ($oq) => $oq->where('vendedor_id', $id)
            ))
            // El folio de la cotización (COT-…) o el de su oportunidad (OPP-…).
            ->when($request->folio, fn ($q, $folio) => $q->where(fn ($w) => $w
                ->where('folio', 'like', "%{$folio}%")
                ->orWhereHas('oportunidad', fn ($oq) => $oq->where('folio', 'like', "%{$folio}%"))))
            ->orderByDesc('created_at');

        $perPage = $this->perPage($request, 100);
        $paginated = $query->paginate($perPage);

        return $this->jsonPaginated($paginated);
    }

    /** GET /crm/cotizaciones/{cotizacion} */
    public function show(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'ver', self::MENSAJE_SIN_VER);
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_LEER_AJENA);
        // Para el detalle: commodity, nombres de cliente/prospecto/vendedor y datos de la empresa.
        // `oportunidad` queda en null si está eliminada (soft delete); la carga anidada lo tolera.
        $cotizacion->load([
            'lineas.producto:id,nombre,commodity,clave,marca_id,activo',
            'lineas.producto.marca:id,nombre',
            'impuestos',
            'oportunidad:id,folio,nombre,cliente_id,prospecto_id,vendedor_id',
            'oportunidad.cliente:id,nombre',
            'oportunidad.prospecto:id,nombre',
            'oportunidad.vendedor:id,nombre',
            'empresa:id,name,razon_social,direccion,ciudad,pais,telefono,logo',
        ]);

        return $this->jsonSuccess($cotizacion);
    }

    /** POST /crm/oportunidades/{oportunidad}/cotizaciones */
    public function store(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $this->verificarEmpresaOportunidad($oportunidad);
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'crear', 'No tienes permiso para crear cotizaciones.');
        $this->visibilidad->exigirVisible((int) $oportunidad->empresa_id, $oportunidad, self::MENSAJE_MODIFICAR_AJENA);

        // Una oportunidad perdida ya no admite cotizaciones nuevas: la única
        // razón para cotizar sería aprobarla, y aprobar sobre una perdida está
        // prohibido (reabriría el cierre). Sobre una cerrado_ganado sí se
        // permite: es como se arma la cotización que sustituye a la vigente
        // (flujo "superado" del spec).
        if ($oportunidad->estaPerdida()) {
            return $this->jsonError('No se puede crear una cotización sobre una oportunidad cerrada como perdida.', 422);
        }

        $this->normalizarTextosDeLineas($request);
        $validated = $request->validate([
            'fecha_emision' => 'required|date',
            'vigencia_dias' => 'nullable|integer|min:1',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string',
            'lineas' => 'required|array|min:1',
            'lineas.*.producto_id' => ['required', 'integer', $this->existeEnEmpresa('crm_productos', $empresaId)],
            'lineas.*.calibre' => 'nullable|string|max:100',
            'lineas.*.presentacion' => 'nullable|string|max:150',
            'lineas.*.cantidad' => 'required|numeric|min:0.0001',
            'lineas.*.precio_unitario' => 'required|numeric|min:0',
        ]);

        $descuento = $validated['descuento_global_pct'] ?? 0;
        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        if ($descuento > 0 && ! $config->descuento_global_habilitado) {
            return $this->jsonError('Esta empresa no tiene habilitado el descuento global en cotizaciones.', 422);
        }

        // Antes de escribir nada: lo que no cabe en las columnas responde 422, no 500.
        $excede = $this->mensajeSiExcedeElLimite($validated['lineas'], (float) $descuento, $empresaId);
        if ($excede !== null) {
            return $this->jsonError($excede, 422);
        }

        [$cotizacion, $oportunidad] = DB::transaction(function () use ($validated, $oportunidad, $empresaId, $descuento) {
            // Bloquea la oportunidad antes de leer o cambiar su monto.
            $oportunidad = CrmOportunidad::whereKey($oportunidad->id)->lockForUpdate()->firstOrFail();

            $cotizacion = CrmCotizacion::create([
                'empresa_id' => $empresaId,
                'oportunidad_id' => $oportunidad->id,
                'folio' => $this->siguienteFolio($empresaId),
                'estado' => 'borrador',
                'fecha_emision' => $validated['fecha_emision'],
                'vigencia_dias' => $validated['vigencia_dias'] ?? null,
                'descuento_global_pct' => $descuento,
                'notas' => $validated['notas'] ?? null,
            ]);

            $this->crearLineas($cotizacion, $validated['lineas'], $empresaId);

            $cotizacion = $this->calculo->recalcular($cotizacion);
            $this->monto->alGuardar($oportunidad, $cotizacion);

            return [$cotizacion, $oportunidad];
        });

        $this->difundirMontoSiCambio($oportunidad);
        $this->difundir(new CotizacionUpdated('created', $cotizacion->toArray()));

        return $this->jsonSuccess($cotizacion, 'Cotización creada correctamente', 201);
    }

    /**
     * PUT /crm/cotizaciones/{cotizacion} — solo en borrador. En SBP (cotización
     * por caja) también una enviada, con `motivo_cambio` obligatorio: cada
     * guardado crea la versión siguiente (spec 2026-11-25 §4).
     */
    public function update(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'editar', 'No tienes permiso para editar cotizaciones.');
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_MODIFICAR_AJENA);

        $empresaId = $this->getEmpresaId();
        $sbp = CrmConfiguracionComercial::cotizacionPorCaja($empresaId);

        $noEditable = $this->mensajeSiNoSeEdita($cotizacion, $sbp);
        if ($noEditable !== null) {
            return $this->jsonError($noEditable, 422);
        }

        // Una enviada (solo SBP) se edita con motivo y deja una versión nueva.
        $conVersion = $sbp && $cotizacion->estado === 'enviado';

        $this->normalizarTextosDeLineas($request);
        $validated = $request->validate([
            'fecha_emision' => 'sometimes|required|date',
            'vigencia_dias' => 'nullable|integer|min:1',
            'descuento_global_pct' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string',
            'lineas' => 'sometimes|required|array|min:1',
            'lineas.*.producto_id' => ['required_with:lineas', 'integer', $this->existeEnEmpresa('crm_productos', $empresaId)],
            'lineas.*.calibre' => 'nullable|string|max:100',
            'lineas.*.presentacion' => 'nullable|string|max:150',
            'lineas.*.cantidad' => 'required_with:lineas|numeric|min:0.0001',
            'lineas.*.precio_unitario' => 'required_with:lineas|numeric|min:0',
            'motivo_cambio' => $conVersion ? 'required|string|max:500' : 'nullable',
        ], [
            'motivo_cambio.required' => 'Escribe el motivo del cambio.',
        ]);
        $motivo = $conVersion ? trim((string) $validated['motivo_cambio']) : null;

        // `descuento_global_pct` es NOT NULL en BD y la regla es `nullable`, así que
        // un null explícito en el body debe resolverse al valor ya guardado (no escribirse).
        $descuentoActual = (float) $cotizacion->descuento_global_pct;
        $descuentoNuevo = (float) ($validated['descuento_global_pct'] ?? $descuentoActual);

        // Con el descuento global deshabilitado solo se bloquea SUBIRLO. Bajarlo o
        // dejarlo igual sigue permitido, para no dejar inedizable un borrador que ya
        // traía descuento de antes de que la empresa deshabilitara la opción.
        $config = CrmConfiguracionComercial::paraEmpresa($empresaId);
        if ($descuentoNuevo > $descuentoActual && ! $config->descuento_global_habilitado) {
            return $this->jsonError('Esta empresa no tiene habilitado el descuento global en cotizaciones.', 422);
        }

        $lineasFinales = $validated['lineas'] ?? $cotizacion->lineas()->get(['cantidad', 'precio_unitario'])->toArray();
        $excede = $this->mensajeSiExcedeElLimite($lineasFinales, $descuentoNuevo, $empresaId);
        if ($excede !== null) {
            return $this->jsonError($excede, 422);
        }

        $resultado = DB::transaction(function () use ($validated, $cotizacion, $empresaId, $descuentoNuevo, $sbp, $conVersion, $motivo) {
            // Bloquea la oportunidad (si sigue existiendo) antes de tocar su monto.
            $oportunidad = CrmOportunidad::whereKey($cotizacion->oportunidad_id)->lockForUpdate()->first();

            // En SBP el estado y la venta se revisan de nuevo ya con el bloqueo:
            // una venta o un rechazo concurrentes no pueden colarse entre medias.
            if ($sbp) {
                $actual = CrmCotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
                $mensaje = $this->mensajeSiNoSeEdita($actual, true);
                if ($mensaje !== null || $actual->estado !== $cotizacion->estado) {
                    return $mensaje ?? self::MENSAJE_SOLO_BORRADOR;
                }

                // Una enviada de antes de las versiones no tiene ninguna: se guarda
                // su contenido previo (sin motivo ni usuario) como versión 1 ANTES
                // de editarla, para no perder lo que tenía.
                if ($conVersion && ! $actual->versiones()->exists()) {
                    $this->versiones->registrar($actual, null, null);
                }
            }

            $datos = collect($validated)->except(['lineas', 'descuento_global_pct', 'motivo_cambio'])->all();
            $datos['descuento_global_pct'] = $descuentoNuevo;
            $cotizacion->update($datos);

            if (isset($validated['lineas'])) {
                $cotizacion->lineas()->delete();
                $this->crearLineas($cotizacion, $validated['lineas'], $empresaId);
            }

            $cotizacion = $this->calculo->recalcular($cotizacion);
            if ($conVersion) {
                $this->versiones->registrar($cotizacion, $motivo, Auth::id());
            }
            if ($oportunidad) {
                $this->monto->alGuardar($oportunidad, $cotizacion);
            }

            return [$cotizacion, $oportunidad];
        });

        if (is_string($resultado)) {
            return $this->jsonError($resultado, 422);
        }
        [$cotizacion, $oportunidad] = $resultado;

        $this->difundirMontoSiCambio($oportunidad);
        $this->difundir(new CotizacionUpdated('updated', $cotizacion->toArray()));

        return $this->jsonSuccess($cotizacion, 'Cotización actualizada correctamente');
    }

    /** GET /crm/cotizaciones/{cotizacion}/versiones — más nueva primero */
    public function versiones(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'ver', self::MENSAJE_SIN_VER);
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_LEER_AJENA);

        $versiones = $cotizacion->versiones()
            ->reorder()
            ->orderByDesc('numero')
            ->with('usuario:id,name')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'numero' => $v->numero,
                'renglones' => $v->renglones,
                'descuento_global_pct' => $v->descuento_global_pct,
                'subtotal' => $v->subtotal,
                'total' => $v->total,
                'motivo' => $v->motivo,
                'usuario' => $v->usuario ? ['id' => $v->usuario->id, 'name' => $v->usuario->name] : null,
                'created_at' => $v->created_at,
            ])
            ->values();

        return $this->jsonSuccess($versiones);
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/enviar */
    public function enviar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'editar', 'No tienes permiso para enviar cotizaciones.');
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_MODIFICAR_AJENA);
        if ($cotizacion->estado !== 'borrador') {
            return $this->jsonError('Solo una cotización en borrador se puede enviar.', 422);
        }

        if (CrmConfiguracionComercial::cotizacionPorCaja((int) $cotizacion->empresa_id)) {
            // SBP: el cambio de estado y la versión 1 van juntos. Se revisa el
            // estado de nuevo con la fila bloqueada para no duplicar la versión 1.
            $enviada = DB::transaction(function () use ($cotizacion) {
                $actual = CrmCotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
                if ($actual->estado !== 'borrador') {
                    return false;
                }
                $actual->update(['estado' => 'enviado']);
                $this->versiones->registrar($actual, null, Auth::id());

                return true;
            });
            if (! $enviada) {
                return $this->jsonError('Solo una cotización en borrador se puede enviar.', 422);
            }
            $cotizacion->refresh();
        } else {
            $cotizacion->update(['estado' => 'enviado']);
        }

        $this->difundir(new CotizacionUpdated('updated', $cotizacion->toArray()));

        return $this->jsonSuccess($cotizacion, 'Cotización marcada como enviada');
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/rechazar */
    public function rechazar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'rechazar', 'No tienes permiso para rechazar cotizaciones.');
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_MODIFICAR_AJENA);
        if ($cotizacion->estado !== 'enviado') {
            return $this->jsonError('Solo una cotización enviada se puede rechazar.', 422);
        }

        $resultado = DB::transaction(function () use ($cotizacion) {
            // Bloquea la oportunidad (si sigue existiendo) antes de tocar su monto.
            $oportunidad = CrmOportunidad::whereKey($cotizacion->oportunidad_id)->lockForUpdate()->first();

            // El estado se revisa de nuevo con el bloqueo: una venta o un cambio
            // concurrentes no pueden quedar pisados con «rechazado».
            $actual = CrmCotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            if ($actual->estado !== 'enviado') {
                return null;
            }

            $cotizacion->update(['estado' => 'rechazado']);
            if ($oportunidad) {
                $this->monto->alRechazar($oportunidad);
            }

            return [$oportunidad];
        });

        if ($resultado === null) {
            return $this->jsonError('Solo una cotización enviada se puede rechazar.', 422);
        }
        [$oportunidad] = $resultado;

        $this->difundirMontoSiCambio($oportunidad);
        $this->difundir(new CotizacionUpdated('updated', $cotizacion->toArray()));

        return $this->jsonSuccess($cotizacion, 'Cotización rechazada');
    }

    /** PATCH /crm/cotizaciones/{cotizacion}/aprobar */
    public function aprobar(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'cotizaciones', 'cotizaciones', 'aprobar', 'No tienes permiso para aprobar cotizaciones.');
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_MODIFICAR_AJENA);
        // En SBP se gana registrando lo vendido por renglón (venta()), no aprobando.
        if (CrmConfiguracionComercial::cotizacionPorCaja((int) $cotizacion->empresa_id)) {
            return $this->jsonError('En esta empresa se registra lo vendido de la cotización.', 422);
        }
        if ($cotizacion->estado !== 'enviado') {
            return $this->jsonError('Solo una cotización enviada se puede aprobar.', 422);
        }

        // Aprobar cierra la oportunidad como ganada. Sobre una cerrado_perdido
        // eso sería una reapertura encubierta (etapa perdido → ganado, con el
        // motivo_perdida quedando obsoleto): se rechaza. Sobre una
        // cerrado_ganado no hay cambio de etapa, es el flujo "superado".
        $oportunidadActual = $cotizacion->oportunidad;
        if (! $oportunidadActual || $oportunidadActual->estaPerdida()) {
            return $this->jsonError(
                'La oportunidad está cerrada como perdida; no se puede aprobar una cotización sobre ella.',
                422
            );
        }

        $oportunidad = DB::transaction(function () use ($cotizacion) {
            // Bloquea la fila de la oportunidad para que un aprobar() concurrente
            // sobre una cotización hermana no pueda intercalar su propio
            // supersede+approve dentro de esta misma ventana de transacción.
            $oportunidad = CrmOportunidad::whereKey($cotizacion->oportunidad_id)->lockForUpdate()->firstOrFail();

            // Revalidación bajo el lock: un cambiarEtapa concurrente pudo marcarla
            // como perdida entre el chequeo de arriba y la obtención del lock.
            if ($oportunidad->estaPerdida()) {
                return null;
            }

            // Un solo aprobado a la vez por oportunidad: el que ya estaba aprobado pasa a superado.
            CrmCotizacion::where('oportunidad_id', $cotizacion->oportunidad_id)
                ->where('id', '!=', $cotizacion->id)
                ->where('estado', 'aprobado')
                ->update(['estado' => 'superado']);

            $cotizacion->update(['estado' => 'aprobado']);

            // Si ya estaba ganada («superado») la venta conserva su fecha de
            // cierre, para que no cambie de mes; el estado de cobro tampoco se toca.
            $yaGanada = $oportunidad->etapa === 'cerrado_ganado';
            $oportunidad->etapa = 'cerrado_ganado';
            if (! $yaGanada) {
                $oportunidad->fecha_cierre_real = now();
            }
            $oportunidad->save();

            $this->monto->alAprobar($oportunidad, $cotizacion);

            return $oportunidad;
        });

        if (! $oportunidad) {
            return $this->jsonError(
                'La oportunidad está cerrada como perdida; no se puede aprobar una cotización sobre ella.',
                422
            );
        }

        // El tablero Kanban se alimenta de este canal: sin este broadcast la
        // tarjeta no se movería a "cerrado ganado" hasta un refresh manual.
        $this->difundirOportunidad($oportunidad);

        $this->difundir(new CotizacionUpdated('updated', $cotizacion->fresh()->toArray()));

        return $this->jsonSuccess($cotizacion->fresh(), 'Cotización aprobada — la oportunidad se cerró como ganada');
    }

    /**
     * POST /crm/cotizaciones/{cotizacion}/venta — registra lo vendido por
     * renglón (solo SBP). Gana la oportunidad con monto = lo vendido, o la
     * pierde si ningún renglón se vendió. No exige aprobar ni cerrar: basta
     * cotizaciones.editar y oportunidades.editar sobre una oportunidad visible.
     */
    public function venta(Request $request, CrmCotizacion $cotizacion): JsonResponse
    {
        $this->verificarEmpresa($cotizacion);
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'editar', 'No tienes permiso para editar cotizaciones.');
        $this->exigirPermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'editar', 'No tienes permiso para editar oportunidades.');
        $this->exigirOportunidadVisible($cotizacion, self::MENSAJE_MODIFICAR_AJENA);

        if (! CrmConfiguracionComercial::cotizacionPorCaja($empresaId)) {
            return $this->jsonError('Esta empresa no registra lo vendido por renglón.', 422);
        }

        try {
            $resultado = $this->ventas->registrar($cotizacion, $request->input('renglones'), (int) Auth::id());
        } catch (VentaNoRegistrableException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $oportunidad = $resultado['oportunidad'];
        $venta = $resultado['venta'];
        $cotizacion = $resultado['cotizacion'];
        $actividad = $resultado['actividad'];

        // Todo ya está confirmado: ahora sí se avisa en tiempo real.
        $this->difundirOportunidad($oportunidad);
        $this->difundir(new CotizacionUpdated('updated', $cotizacion->toArray()));
        foreach ($resultado['superadas'] as $superada) {
            $this->difundir(new CotizacionUpdated('updated', $superada->fresh()->toArray()));
        }
        $actividad->load(ActividadController::RELACIONES);
        $actividad->entidad_tipo = 'oportunidad';
        $this->difundir(new ActividadUpdated('created', $actividad->toArray()));
        $this->difundirEventos('completed', $resultado['eventos']);

        return $this->jsonSuccess([
            'oportunidad' => $oportunidad->load(CrmOportunidad::RELACIONES_API)->conMontoOrigen(),
            'cotizacion' => $cotizacion,
            'venta' => $venta,
        ], $oportunidad->estaPerdida() ? 'La oportunidad se cerró como perdida' : 'Venta registrada');
    }

    /**
     * Mensaje de 422 si la cotización ya no admite edición; null si se puede.
     * Sin SBP solo el borrador. En SBP también la enviada, salvo que ya tenga
     * venta registrada (los cambios son ajustes de la oportunidad) o esté
     * aprobada.
     */
    private function mensajeSiNoSeEdita(CrmCotizacion $cotizacion, bool $sbp): ?string
    {
        if (! $sbp) {
            return $cotizacion->estado === 'borrador' ? null : self::MENSAJE_SOLO_BORRADOR;
        }

        $conVenta = $cotizacion->estado === 'aprobado'
            || CrmOportunidadVenta::where('oportunidad_id', $cotizacion->oportunidad_id)
                ->where('cotizacion_id', $cotizacion->id)
                ->where('vigente', true)
                ->exists();
        if ($conVenta) {
            return self::MENSAJE_CON_VENTA;
        }

        return in_array($cotizacion->estado, ['borrador', 'enviado'], true) ? null : self::MENSAJE_SOLO_BORRADOR;
    }

    /** Difunde la oportunidad con su forma de API (incluye `monto_origen`). */
    private function difundirOportunidad(CrmOportunidad $oportunidad): void
    {
        $this->difundir(new OportunidadUpdated(
            'updated',
            $oportunidad->load(CrmOportunidad::RELACIONES_API)->conMontoOrigen()->toArray()
        ));
    }

    /** El tablero muestra el monto: si la cotización lo movió, se avisa. */
    private function difundirMontoSiCambio(?CrmOportunidad $oportunidad): void
    {
        if ($oportunidad?->wasChanged('monto_esperado')) {
            $this->difundirOportunidad($oportunidad);
        }
    }

    /**
     * Crea los renglones de la cotización. `descripcion` guarda el nombre actual
     * del producto; calibre y presentación son texto libre y opcionales (sin
     * catálogo obligatorio, para que una pestaña vieja siga funcionando).
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function crearLineas(CrmCotizacion $cotizacion, array $lineas, int $empresaId): void
    {
        foreach ($lineas as $linea) {
            $producto = CrmProducto::where('empresa_id', $empresaId)->find($linea['producto_id']);
            CrmOportunidadProducto::create([
                'oportunidad_id' => $cotizacion->oportunidad_id,
                'cotizacion_id' => $cotizacion->id,
                'producto_id' => $linea['producto_id'],
                'descripcion' => $producto?->nombre ?? '',
                'calibre' => $linea['calibre'] ?? null,
                'presentacion' => $linea['presentacion'] ?? null,
                'unidad' => $producto?->unidad_medida,
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $linea['precio_unitario'],
            ]);
        }
    }

    /**
     * Recorta calibre y presentación y convierte vacío en null, antes de
     * validar. Solo toca los textos: un valor que no es texto se deja como
     * viene para que la regla `string` responda 422 (no un 500).
     */
    private function normalizarTextosDeLineas(Request $request): void
    {
        $lineas = $request->input('lineas');
        if (! is_array($lineas)) {
            return;
        }

        foreach ($lineas as $i => $linea) {
            if (! is_array($linea)) {
                continue;
            }
            foreach (['calibre', 'presentacion'] as $campo) {
                if (isset($linea[$campo]) && is_string($linea[$campo])) {
                    $recortado = trim($linea[$campo]);
                    $lineas[$i][$campo] = $recortado === '' ? null : $recortado;
                }
            }
        }

        $request->merge(['lineas' => $lineas]);
    }

    /**
     * Mensaje de error si la cotización no cabe en las columnas decimal(12,2)
     * (subtotal, base o total con impuestos); null si cabe. Se evalúa antes de
     * escribir para responder 422 en lugar de un 500 de MySQL estricto.
     *
     * @param  iterable<int, array{cantidad: mixed, precio_unitario: mixed}>  $lineas
     */
    private function mensajeSiExcedeElLimite(iterable $lineas, float $descuento, int $empresaId): ?string
    {
        $subtotal = 0.0;
        foreach ($lineas as $linea) {
            $subtotal += round((float) $linea['cantidad'], 4) * round((float) $linea['precio_unitario'], 2);
        }
        $subtotal = round($subtotal, 2);
        $base = round($subtotal * (1 - $descuento / 100), 2);

        $tasas = (float) CrmConfiguracionImpuesto::where('empresa_id', $empresaId)->activos()->sum('tasa');
        $total = round($base * (1 + $tasas / 100), 2);

        if ($subtotal > CrmOportunidad::MONTO_MAXIMO || $total > CrmOportunidad::MONTO_MAXIMO) {
            return 'El total de la cotización excede el máximo permitido.';
        }

        return null;
    }

    /**
     * Folio consecutivo POR EMPRESA: el número de cotización de un tenant no
     * debe depender del volumen de otro (ni filtrarlo). La unicidad en BD es
     * el índice compuesto (empresa_id, folio).
     */
    private function siguienteFolio(int $empresaId): string
    {
        $ultimo = CrmCotizacion::withTrashed()
            ->where('empresa_id', $empresaId)
            ->where('folio', 'like', 'COT-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 5) AS UNSIGNED) DESC')->value('folio');
        $siguiente = $ultimo ? ((int) substr($ultimo, 4)) + 1 : 1;

        return 'COT-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    /**
     * 403 si el usuario no puede ver la oportunidad de la cotización. Sin
     * oportunidad (eliminada o inexistente) solo pasa quien tiene equipo.
     */
    private function exigirOportunidadVisible(CrmCotizacion $cotizacion, string $mensaje): void
    {
        $empresaId = (int) $cotizacion->empresa_id;
        $oportunidad = $cotizacion->oportunidad;

        if ($oportunidad === null) {
            $this->visibilidad->exigirVer($empresaId);
            abort_unless($this->visibilidad->veAlEquipo($empresaId), 403, $mensaje);

            return;
        }

        $this->visibilidad->exigirVisible($empresaId, $oportunidad, $mensaje);
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
