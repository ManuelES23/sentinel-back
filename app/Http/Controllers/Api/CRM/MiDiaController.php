<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Events\CRM\ProspectoUpdated;
use App\Exceptions\CRM\VendedorNoVinculadoException;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\AperturaOportunidadService;
use App\Services\CRM\MiDiaDetalleService;
use App\Services\CRM\MiDiaService;
use App\Services\CRM\MiMesService;
use App\Services\CRM\NuevaActividadService;
use App\Services\CRM\SeguimientoService;
use App\Services\CRM\VendedorActualService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** GET /crm/mi-dia, GET /crm/mi-dia/oportunidades/{oportunidad} y POST /crm/mi-dia/actividades — pantalla de inicio del vendedor. */
class MiDiaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public function __construct(
        private readonly VendedorActualService $vendedores,
        private readonly MiDiaService $miDia,
        private readonly MiMesService $miMes,
        private readonly MiDiaDetalleService $detalle,
        private readonly NuevaActividadService $nuevaActividad,
        private readonly SeguimientoService $seguimientos,
        private readonly AperturaOportunidadService $apertura,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'ver', 'No tienes permiso para ver Mi día.');

        $validated = $request->validate(['vendedor_id' => 'nullable|integer']);
        $solicitado = isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null;

        $puedeVerEquipo = $this->vendedores->puedeVerEquipo($empresaId);
        $lista = $puedeVerEquipo
            ? CrmVendedor::where('empresa_id', $empresaId)->activo()->orderBy('nombre')->get(['id', 'nombre'])
            : [];

        try {
            $vendedor = $this->vendedores->resolver($empresaId, $request->user(), $solicitado);
        } catch (VendedorNoVinculadoException $e) {
            // Gerencia sin vendedor propio: puede elegir uno del selector.
            if (! $puedeVerEquipo) {
                throw $e;
            }

            return $this->jsonSuccess(
                ['vendedor' => null, 'puede_ver_equipo' => true, 'vendedores' => $lista, 'mi_mes' => null] + $this->miDia->vacio(),
            );
        }

        return $this->jsonSuccess(
            [
                'vendedor' => ['id' => $vendedor->id, 'nombre' => $vendedor->nombre],
                'puede_ver_equipo' => $puedeVerEquipo,
                'vendedores' => $lista,
                'mi_mes' => $this->miMes->para($empresaId, $vendedor),
            ] + $this->miDia->resumen($empresaId, $vendedor, CarbonImmutable::now()),
        );
    }

    /**
     * Detalle de una oportunidad para el panel de Mi día. Solo exige `mi-dia.ver`:
     * Mi día ya muestra las oportunidades propias sin `oportunidades.ver`.
     */
    public function oportunidad(Request $request, CrmOportunidad $oportunidad): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'ver', 'No tienes permiso para ver Mi día.');
        abort_unless((int) $oportunidad->empresa_id === (int) $empresaId, 404, 'Oportunidad no encontrada');

        $validated = $request->validate(['vendedor_id' => 'nullable|integer']);
        $solicitado = isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null;
        $vendedor = $this->vendedores->resolver($empresaId, $request->user(), $solicitado);

        return $this->jsonSuccess($this->detalle->para(
            $vendedor,
            $oportunidad,
            $this->tienePermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'ver'),
        ));
    }

    /**
     * POST /crm/mi-dia/actividades — «Nueva actividad» (spec 2026-10-30 §5.3):
     * prospecto (si es nuevo), oportunidad (si hace falta) y evento o actividad
     * en una sola transacción. Los eventos en tiempo real salen después de
     * confirmarla.
     */
    public function crearActividad(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        $this->exigirPermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'ver', 'No tienes permiso para ver Mi día.');

        $validated = $this->validarNuevaActividad($request, $empresaId);

        if (! empty($validated['evento'])) {
            $this->exigirPermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear', 'No tienes permiso para programar eventos de agenda.');
        }
        if (! empty($validated['actividad'])) {
            $this->exigirPermisoSubmodulo($empresaId, 'actividades', 'actividades', 'crear', 'No tienes permiso para registrar actividades.');
        }
        // Una oportunidad existente no se abre: solo lo nuevo exige crear.
        if (! isset($validated['oportunidad_id'])) {
            $this->exigirPermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'crear', 'No tienes permiso para crear oportunidades.');
        }
        if (! empty($validated['prospecto_nuevo'])) {
            $this->exigirPermisoSubmodulo($empresaId, 'prospectos', 'prospectos', 'crear', 'No tienes permiso para crear prospectos.');
        }

        $resultado = $this->nuevaActividad->crear($empresaId, $request->user(), $validated);

        $oportunidad = $resultado['oportunidad']->load(CrmOportunidad::RELACIONES_API)->conMontoOrigen();
        $prospecto = $resultado['prospecto']?->load(ProspectoController::RELACIONES);
        $evento = $resultado['evento']?->load(['vendedor:id,nombre', 'etiqueta:id,nombre']);
        $actividad = $resultado['actividad']?->load(ActividadController::RELACIONES);

        if ($prospecto) {
            $this->difundir(new ProspectoUpdated('created', $prospecto->toArray()));
        }
        if ($resultado['oportunidad_nueva']) {
            $this->difundir(new OportunidadUpdated('created', $oportunidad->toArray()));
        }
        if ($evento) {
            $this->difundir(new AgendaUpdated('created', $evento->toArray()));
        }
        if ($actividad) {
            $this->difundir(new ActividadUpdated('created', $actividad->toArray()));
        }

        return $this->jsonSuccess(
            ['oportunidad' => $oportunidad, 'evento' => $evento, 'actividad' => $actividad, 'prospecto' => $prospecto],
            'Actividad registrada correctamente',
            201,
        );
    }

    /**
     * Si el cuerpo trae el campo: un bloque (`evento`, `actividad`, `prospecto_nuevo`)
     * cuenta solo si no está vacío (`{}` es como no mandarlo) y un id si no es
     * nulo ni cadena vacía (un 0 cuenta, para que la regla `min:1` lo rechace).
     */
    private function dado(Request $request, string $campo): bool
    {
        $valor = $request->input($campo);

        return is_array($valor) ? $valor !== [] : filled($valor);
    }

    /**
     * Valida el cuerpo de crearActividad: exactamente un origen y exactamente un
     * registro. Las reglas del evento son las de `siguiente` de POST
     * crm/seguimientos y las de la actividad, las de su `actividad`.
     *
     * @return array<string, mixed>
     */
    private function validarNuevaActividad(Request $request, int $empresaId): array
    {
        $abreOportunidad = ! $this->dado($request, 'oportunidad_id');
        $conProductos = CrmConfiguracionComercial::productosEnOportunidad($empresaId);

        $reglas = [
            'vendedor_id' => 'nullable|integer',
            'prospecto_nuevo' => 'nullable|array',
            'prospecto_nuevo.nombre' => 'required_with:prospecto_nuevo|string|max:255',
            'prospecto_nuevo.telefono' => 'nullable|string|max:50',
            'prospecto_nuevo.email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('crm_prospectos', 'email')
                    ->where(fn ($q) => $q->where('empresa_id', $empresaId))
                    ->whereNull('deleted_at'),
            ],
            'cliente_id' => ['nullable', 'integer', $this->existeEnEmpresa('crm_clientes', $empresaId)->whereNull('deleted_at')],
            'prospecto_id' => ['nullable', 'integer', $this->existeEnEmpresa('crm_prospectos', $empresaId)->whereNull('deleted_at')],
            'oportunidad_id' => 'nullable|integer|min:1',
            // Solo se usan al abrir una oportunidad nueva.
            'monto_esperado' => 'nullable|numeric|min:0|max:'.CrmOportunidad::MONTO_MAXIMO,
            'tipo_producto' => $abreOportunidad
                ? $this->apertura->reglasTipoProducto($empresaId, $conProductos)
                : 'nullable|string|max:100',
            'actividad' => 'nullable|array',
            'actividad.tipo' => ['required_with:actividad', Rule::in(SeguimientoService::TIPOS_ACTIVIDAD)],
            'actividad.descripcion' => 'required_with:actividad|string|max:2000',
            'actividad.resultado' => 'nullable|string|max:2000',
            // Opcional: sin ella el servidor guarda la hora actual. Con ella, no puede ser futura.
            'actividad.fecha_actividad' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'actividad.etiqueta_id' => ['required_with:actividad', ...$this->seguimientos->reglaEtiqueta($empresaId)],
        ] + $this->seguimientos->reglasSiguiente('evento', $empresaId, true);

        $mensajes = array_merge(
            $this->seguimientos->mensajesSiguiente('evento'),
            $this->seguimientos->mensajesEtiqueta('actividad.etiqueta_id'),
            [
                'prospecto_nuevo.email.unique' => 'Ya existe un prospecto con este correo.',
                'cliente_id.exists' => 'El cliente no existe o fue eliminado.',
                'prospecto_id.exists' => 'El prospecto no existe o fue eliminado.',
                'evento.fecha_inicio.after' => 'El evento debe programarse en el futuro.',
                'actividad.fecha_actividad.before_or_equal' => 'La fecha de la actividad no puede ser futura.',
            ],
        );

        return Validator::make($request->all(), $reglas, $mensajes)
            ->after(function ($validador) use ($request) {
                $origenes = collect(['prospecto_nuevo', 'cliente_id', 'prospecto_id', 'oportunidad_id'])
                    ->filter(fn (string $campo) => $this->dado($request, $campo))
                    ->count();
                if ($origenes !== 1) {
                    $validador->errors()->add('origen', 'Elige un solo origen: prospecto nuevo, cliente, prospecto u oportunidad.');
                }

                $registros = collect(['evento', 'actividad'])
                    ->filter(fn (string $campo) => $this->dado($request, $campo))
                    ->count();
                if ($registros !== 1) {
                    $validador->errors()->add('registro', 'Programa un evento o registra una actividad ya hecha, no ambos ni ninguno.');
                }
            })
            ->validate();
    }
}
