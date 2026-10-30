<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Services\CRM\SeguimientoService;
use App\Services\CRM\VisibilidadAgenda;
use App\Services\CRM\VisibilidadOportunidades;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AgendaController
 * CRUD de eventos/tareas planeadas por vendedor, opcionalmente ligados a
 * una entidad CRM. Al completar, genera una Actividad en el timeline de
 * la entidad relacionada (si tenía una) -- ver completar().
 *
 * Visibilidad (VisibilidadAgenda): sin agenda.equipo el usuario solo lee y
 * modifica los eventos de su propio CrmVendedor activo; con él, los de
 * cualquier vendedor de la empresa. Orden en las acciones por id: tenant
 * (404), permiso de la acción (403) y vendedor del evento (403).
 */
class AgendaController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /** Alias corto → clase del modelo padre (mismo mapa que ActividadController). */
    protected const TIPOS = [
        'prospecto'       => CrmProspecto::class,
        'cliente'         => CrmCliente::class,
        'oportunidad'     => CrmOportunidad::class,
        'empresa_externa' => CrmEmpresaExterna::class,
    ];

    protected const TIPOS_AGENDA = ['llamada', 'visita', 'reunion', 'tarea', 'correo', 'whatsapp'];

    /** Relaciones que salen con cada evento (también en el payload en tiempo real). */
    protected const RELACIONES = ['vendedor:id,nombre', 'etiqueta:id,nombre'];

    /** Tope de pendientes vencidos devueltos de una vez (los más antiguos primero). */
    protected const LIMITE_VENCIDOS = 100;

    /** Agenda tiene 'tarea'; ActividadController::TIPOS_ACTIVIDAD no -- se traduce a 'nota'. */
    protected const TIPO_ACTIVIDAD_PARA = [
        'llamada' => 'llamada',
        'visita' => 'visita',
        'reunion' => 'reunion',
        'correo' => 'correo',
        'whatsapp' => 'whatsapp',
        'tarea' => 'nota',
    ];

    public function __construct(
        private readonly SeguimientoService $seguimientos,
        private readonly VisibilidadAgenda $visibilidad,
        private readonly VisibilidadOportunidades $oportunidades,
    ) {}

    /** GET /crm/agenda?desde=&hasta=&vendedor_id=&tipo=&completado=&vencidos= */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'ver'),
            403,
            'No tienes permiso para ver la agenda.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'required|integer',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'tipo' => ['nullable', Rule::in(self::TIPOS_AGENDA)],
            'completado' => 'nullable|boolean',
            'vencidos' => 'nullable|boolean',
        ]);

        $vendedorId = $this->visibilidad->vendedorVisible($empresaId, (int) $validated['vendedor_id']);

        // Pendientes vencidos: eventos no completados cuya hora de fin ya
        // pasó, sin importar el rango. Sin esto, la vista de lista (que
        // arranca en "hoy") hacía desaparecer lo que no se hizo ayer.
        if ($request->boolean('vencidos')) {
            $vencidos = CrmAgenda::with(self::RELACIONES)
                ->where('empresa_id', $empresaId)
                ->where('vendedor_id', $vendedorId)
                ->where('completado', false)
                ->where('fecha_fin', '<', now())
                ->orderBy('fecha_inicio')
                ->limit(self::LIMITE_VENCIDOS)
                ->get();

            return $this->jsonSuccess($vencidos);
        }
        $desde = $validated['desde'] ?? now()->startOfDay()->toDateTimeString();
        $hasta = $validated['hasta'] ?? now()->addDays(30)->endOfDay()->toDateTimeString();

        $eventos = CrmAgenda::with(self::RELACIONES)
            ->where('empresa_id', $empresaId)
            ->where('vendedor_id', $vendedorId)
            ->where('fecha_inicio', '<=', $hasta)
            ->where('fecha_fin', '>=', $desde)
            ->when($validated['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when(array_key_exists('completado', $validated), fn ($q) => $q->where('completado', $validated['completado']))
            ->orderBy('fecha_inicio')
            ->get();

        return $this->jsonSuccess($eventos);
    }

    /** POST /crm/agenda */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear'),
            403,
            'No tienes permiso para crear eventos de agenda.',
        );

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(self::TIPOS_AGENDA)],
            'titulo' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'vendedor_id' => ['required', 'integer', $this->existeEnEmpresa('crm_vendedores', $empresaId)],
            'entidad_tipo' => ['nullable', 'required_with:entidad_id', Rule::in(array_keys(self::TIPOS))],
            'entidad_id' => 'nullable|integer|required_with:entidad_tipo',
            'recordatorio_at' => 'nullable|date|before_or_equal:fecha_inicio',
            // Obligatoria cuando el evento cuelga de una oportunidad; opcional (pero válida) en lo demás.
            'etiqueta_id' => [
                $request->input('entidad_tipo') === 'oportunidad' ? 'required' : 'nullable',
                ...$this->seguimientos->reglaEtiqueta($empresaId),
            ],
        ], [
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
            'recordatorio_at.before_or_equal' => 'El recordatorio debe ser antes del inicio del evento.',
        ] + $this->seguimientos->mensajesEtiqueta('etiqueta_id'));

        $vendedorId = $this->visibilidad->vendedorVisible($empresaId, (int) $validated['vendedor_id']);
        // Un evento nuevo no se liga directo a un cliente o prospecto: se elige o se abre una oportunidad.
        $this->seguimientos->exigirCapturaConOportunidad($validated['entidad_tipo'] ?? null);

        $entidadType = null;
        if (! empty($validated['entidad_tipo'])) {
            $modelClass = self::TIPOS[$validated['entidad_tipo']];
            // Una oportunidad que el usuario no puede ver responde igual que una inexistente.
            $existe = $this->oportunidades->entidadVisible(
                $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id'])
            );
            if (! $existe) {
                return $this->jsonError('La entidad relacionada no existe o no pertenece a la empresa.', 404);
            }
            $entidadType = $modelClass;
        }

        $evento = CrmAgenda::create([
            'empresa_id' => $empresaId,
            'vendedor_id' => $vendedorId,
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadType ? $validated['entidad_id'] : null,
            'tipo' => $validated['tipo'],
            'titulo' => $validated['titulo'],
            'descripcion' => $validated['descripcion'] ?? null,
            'fecha_inicio' => $validated['fecha_inicio'],
            'fecha_fin' => $validated['fecha_fin'],
            'recordatorio_at' => $validated['recordatorio_at'] ?? null,
            'etiqueta_id' => $validated['etiqueta_id'] ?? null,
        ]);

        // Se carga la relación antes de emitir el broadcast (mismo patrón
        // que ActividadController::store()): si no, el evento en tiempo
        // real que reciben las demás pestañas llegaría sin
        // vendedor.nombre hasta el siguiente refetch completo.
        $evento->load(self::RELACIONES);
        $this->difundir(new AgendaUpdated('created', $evento->toArray()));

        return $this->jsonSuccess($evento, 'Evento de agenda creado correctamente', 201);
    }

    /** PUT /crm/agenda/{agenda} */
    public function update(Request $request, CrmAgenda $agenda): JsonResponse
    {
        $this->verificarEmpresa($agenda);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'editar'),
            403,
            'No tienes permiso para editar eventos de agenda.',
        );
        $this->visibilidad->exigirEventoVisible($agenda);

        $validated = $request->validate([
            'tipo' => ['sometimes', 'required', Rule::in(self::TIPOS_AGENDA)],
            'titulo' => 'sometimes|required|string|max:255',
            'descripcion' => 'sometimes|nullable|string',
            // Si el evento cuelga de una oportunidad no puede quedarse sin etiqueta; uno viejo
            // sin etiqueta se edita sin tocarla porque aquí solo se valida si el campo viene.
            'etiqueta_id' => [
                'sometimes',
                $agenda->entidad_type === CrmOportunidad::class ? 'required' : 'nullable',
                ...$this->seguimientos->reglaEtiqueta($empresaId),
            ],
            // Nota (deviación puntual respecto al brief, los tres campos
            // siguientes): antes se usaba 'before_or_equal:fecha_fin' en
            // fecha_inicio (no estaba en el brief pero sería el equivalente
            // simétrico), 'after_or_equal:fecha_inicio' en fecha_fin y
            // 'before_or_equal:fecha_inicio' en recordatorio_at, pero
            // Laravel evalúa esas reglas contra el valor del OTRO campo EN
            // EL REQUEST, no contra el registro existente. Como update()
            // usa 'sometimes' en todos los campos, un PUT que solo manda
            // UNO de los tres no incluye el otro en el payload:
            // - before_or_equal (recordatorio_at) con el comparador ausente
            //   resuelve a 0 y FALLA siempre (incluso con fechas válidas).
            // - after_or_equal (fecha_fin, y por simetría fecha_inicio) con
            //   el comparador ausente también resuelve a 0, y como
            //   "$valor >= 0" es casi siempre true, la regla PASA
            //   silenciosamente para cualquier fecha -- permitiendo
            //   persistir fecha_inicio posterior a fecha_fin (o viceversa).
            // Los tres closures comparan contra el otro campo del payload
            // si vino, y si no contra el valor ya persistido en el modelo.
            'fecha_inicio' => [
                'sometimes',
                'required',
                'date',
                function (string $attribute, $value, $fail) use ($request, $agenda) {
                    $fechaFin = $request->input('fecha_fin') ?? optional($agenda->fecha_fin)->toDateTimeString();
                    if ($fechaFin && strtotime($value) > strtotime($fechaFin)) {
                        $fail('La fecha de fin debe ser posterior o igual a la fecha de inicio.');
                    }
                },
            ],
            'fecha_fin' => [
                'sometimes',
                'required',
                'date',
                function (string $attribute, $value, $fail) use ($request, $agenda) {
                    $fechaInicio = $request->input('fecha_inicio') ?? optional($agenda->fecha_inicio)->toDateTimeString();
                    if ($fechaInicio && strtotime($value) < strtotime($fechaInicio)) {
                        $fail('La fecha de fin debe ser posterior o igual a la fecha de inicio.');
                    }
                },
            ],
            'recordatorio_at' => [
                'sometimes',
                'nullable',
                'date',
                function (string $attribute, $value, $fail) use ($request, $agenda) {
                    $fechaInicio = $request->input('fecha_inicio') ?? optional($agenda->fecha_inicio)->toDateTimeString();
                    if ($fechaInicio && strtotime($value) > strtotime($fechaInicio)) {
                        $fail('El recordatorio debe ser antes del inicio del evento.');
                    }
                },
            ],
        ], $this->seguimientos->mensajesEtiqueta('etiqueta_id'));
        // El evento no cambia de entidad aquí (para eso está `ligar`), pero si el cuerpo
        // intenta ponerlo en un cliente o prospecto se rechaza igual que en la alta.
        $this->seguimientos->exigirCapturaConOportunidad($request->input('entidad_tipo'));

        // Si cambia el recordatorio, se resetea recordatorio_enviado_at para
        // que el nuevo valor sí se notifique en la siguiente corrida del
        // scheduler -- de lo contrario un recordatorio movido a una fecha
        // futura seguiría "ya enviado" para siempre.
        if (array_key_exists('recordatorio_at', $validated)) {
            // Comparación por instante (Carbon), no por string: el frontend
            // manda formatos distintos según el origen (p. ej. un
            // <input type="datetime-local"> produce 'YYYY-MM-DDTHH:mm', sin
            // segundos y con 'T' en vez de espacio) que casi nunca
            // coinciden byte a byte con el 'Y-m-d H:i:s' canónico del cast
            // del modelo, aunque representen el mismo instante -- eso
            // reseteaba recordatorio_enviado_at en casi cualquier edición
            // que reenviara recordatorio_at, re-notificando recordatorios
            // que ya se habían enviado.
            $nuevoValor = $validated['recordatorio_at'];
            $valorActual = $agenda->recordatorio_at;
            $cambio = is_null($nuevoValor) !== is_null($valorActual)
                || ($nuevoValor !== null && ! \Carbon\Carbon::parse($nuevoValor)->eq($valorActual));
            if ($cambio) {
                $validated['recordatorio_enviado_at'] = null;
            }
        }

        $agenda->update($validated);

        $agenda->load(self::RELACIONES);
        $this->difundir(new AgendaUpdated('updated', $agenda->toArray()));

        return $this->jsonSuccess($agenda, 'Evento de agenda actualizado correctamente');
    }

    /**
     * PATCH /crm/agenda/{agenda}/completar
     * Body opcional (fase 2): resultado, actividad_tipo y siguiente paso.
     * Sin body se comporta igual que antes.
     */
    public function completar(Request $request, CrmAgenda $agenda): JsonResponse
    {
        $this->verificarEmpresa($agenda);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'editar'),
            403,
            'No tienes permiso para editar eventos de agenda.',
        );

        $validated = $request->validate(array_merge([
            'resultado' => 'nullable|string|max:2000',
            'actividad_tipo' => ['nullable', Rule::in(SeguimientoService::TIPOS_ACTIVIDAD)],
        ], $this->seguimientos->reglasSiguiente(
            'siguiente',
            (int) $empresaId,
            $agenda->entidad_type === CrmOportunidad::class,
        )), $this->seguimientos->mensajesSiguiente('siguiente'));

        $siguiente = $validated['siguiente'] ?? null;

        if (! empty($siguiente)) {
            abort_unless(
                $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear'),
                403,
                'No tienes permiso para programar eventos de agenda.',
            );
        }

        $this->visibilidad->exigirEventoVisible($agenda);

        if ($agenda->completado) {
            return $this->jsonError('Este evento ya fue marcado como completado.', 422);
        }

        // Un evento viejo ligado a un cliente o prospecto se completa, pero su siguiente
        // paso necesita una oportunidad: primero se liga (POST agenda/{id}/ligar).
        if (! empty($siguiente) && in_array($agenda->entidad_type, [CrmCliente::class, CrmProspecto::class], true)) {
            throw ValidationException::withMessages([
                'siguiente' => 'Liga primero el evento a una oportunidad para programar el siguiente paso.',
            ]);
        }

        $siguienteEvento = DB::transaction(function () use ($agenda, $validated, $siguiente) {
            $agenda->update(['completado' => true]);

            if ($agenda->entidad_type && $agenda->entidad_id) {
                CrmActividad::create([
                    'empresa_id' => $agenda->empresa_id,
                    'entidad_type' => $agenda->entidad_type,
                    'entidad_id' => $agenda->entidad_id,
                    'tipo' => $validated['actividad_tipo'] ?? self::TIPO_ACTIVIDAD_PARA[$agenda->tipo],
                    'descripcion' => $agenda->titulo.($agenda->descripcion ? " — {$agenda->descripcion}" : ''),
                    'resultado' => $validated['resultado'] ?? null,
                    'fecha_actividad' => now(),
                    'vendedor_id' => $agenda->vendedor_id,
                    'fuente' => 'agenda',
                    // Hereda la etiqueta del evento tal cual: si ya se desactivó, igual se completa.
                    'etiqueta_id' => $agenda->etiqueta_id,
                ]);
            }

            return empty($siguiente)
                ? null
                : $this->seguimientos->programar($agenda->empresa_id, $agenda->vendedor_id, $agenda->entidad, $siguiente);
        });

        $agenda->load(self::RELACIONES);
        // Canal explícito de Agenda: la petición puede venir desde Mi día
        // (X-Module-Slug: mi-dia) y la vista de Agenda debe enterarse igual.
        $this->difundir(new AgendaUpdated('completed', $agenda->toArray()));

        if ($siguienteEvento) {
            $siguienteEvento->load(self::RELACIONES);
            $this->difundir(new AgendaUpdated('created', $siguienteEvento->toArray()));
        }

        return $this->jsonSuccess(
            $agenda->toArray() + ['siguiente_evento' => $siguienteEvento?->toArray()],
            'Evento marcado como completado',
        );
    }

    /**
     * POST /crm/agenda/{agenda}/ligar
     * Body: oportunidad_id. Pone un evento sin oportunidad (viejo, de un
     * cliente o prospecto, interno o de Outlook) dentro de una oportunidad
     * visible y no perdida (spec 2026-10-30 §7 «Eventos sin oportunidad»).
     */
    public function ligar(Request $request, CrmAgenda $agenda): JsonResponse
    {
        $this->verificarEmpresa($agenda);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'editar'),
            403,
            'No tienes permiso para editar eventos de agenda.',
        );
        $this->visibilidad->exigirEventoVisible($agenda);

        $validated = $request->validate(['oportunidad_id' => 'required|integer']);

        // Una oportunidad que el usuario no puede ver responde igual que una inexistente.
        $oportunidad = $this->oportunidades->entidadVisible(
            CrmOportunidad::where('empresa_id', $empresaId)->find($validated['oportunidad_id'])
        );
        if (! $oportunidad) {
            return $this->jsonError('La entidad relacionada no existe o no pertenece a la empresa.', 404);
        }

        $this->exigirLigable($agenda, $oportunidad);

        $agenda->update([
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $oportunidad->id,
        ]);

        $agenda->load(self::RELACIONES);
        $this->difundir(new AgendaUpdated('updated', $agenda->toArray()));

        return $this->jsonSuccess($agenda, 'Evento ligado a la oportunidad');
    }

    /** @throws ValidationException */
    private function exigirLigable(CrmAgenda $agenda, CrmOportunidad $oportunidad): void
    {
        $falla = match (true) {
            $oportunidad->etapa === 'cerrado_perdido' => 'La oportunidad está perdida; elige otra.',
            $agenda->entidad_type === CrmOportunidad::class => 'Este evento ya está ligado a una oportunidad.',
            $agenda->entidad_type === CrmEmpresaExterna::class => 'Un evento de una empresa externa no puede ligarse a una oportunidad.',
            default => $this->oportunidadAjenaAlEvento($agenda, $oportunidad),
        };

        if ($falla) {
            throw ValidationException::withMessages(['oportunidad_id' => $falla]);
        }
    }

    /**
     * Un evento de un cliente o prospecto solo se liga a una oportunidad de ese
     * mismo registro. Un evento de un prospecto ya convertido también puede ir a
     * las oportunidades de su cliente, que es a donde pasaron. Sin entidad
     * (interno u Outlook) no hay restricción.
     */
    private function oportunidadAjenaAlEvento(CrmAgenda $agenda, CrmOportunidad $oportunidad): ?string
    {
        if ($agenda->entidad_type === CrmCliente::class) {
            return (int) $oportunidad->cliente_id === (int) $agenda->entidad_id
                ? null
                : 'La oportunidad no es de este cliente.';
        }

        if ($agenda->entidad_type === CrmProspecto::class) {
            $esDelProspecto = (int) $oportunidad->prospecto_id === (int) $agenda->entidad_id;
            $esDelClienteConvertido = $oportunidad->cliente_id !== null
                && CrmCliente::where('empresa_id', $agenda->empresa_id)
                    ->where('prospecto_id', $agenda->entidad_id)
                    ->whereKey($oportunidad->cliente_id)
                    ->exists();

            return $esDelProspecto || $esDelClienteConvertido ? null : 'La oportunidad no es de este prospecto.';
        }

        return null;
    }

    /** DELETE /crm/agenda/{agenda} */
    public function destroy(Request $request, CrmAgenda $agenda): JsonResponse
    {
        $this->verificarEmpresa($agenda);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'eliminar'),
            403,
            'No tienes permiso para eliminar eventos de agenda.',
        );
        $this->visibilidad->exigirEventoVisible($agenda);

        $data = $agenda->toArray();
        $agenda->delete();

        $this->difundir(new AgendaUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Evento de agenda eliminado correctamente');
    }

    protected function verificarEmpresa(CrmAgenda $agenda): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $agenda->empresa_id !== (int) $empresaId) {
            abort(404, 'Evento de agenda no encontrado');
        }
    }
}
