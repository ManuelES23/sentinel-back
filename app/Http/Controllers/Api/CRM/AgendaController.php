<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * AgendaController
 * CRUD de eventos/tareas planeadas por vendedor, opcionalmente ligados a
 * una entidad CRM. Al completar, genera una Actividad en el timeline de
 * la entidad relacionada (si tenía una) -- ver completar().
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

    protected const TIPOS_AGENDA = ['llamada', 'visita', 'reunion', 'tarea', 'correo'];

    /** Agenda tiene 'tarea'; CrmActividad::TIPOS_ACTIVIDAD no -- se traduce a 'nota'. */
    protected const TIPO_ACTIVIDAD_PARA = [
        'llamada' => 'llamada',
        'visita' => 'visita',
        'reunion' => 'reunion',
        'correo' => 'correo',
        'tarea' => 'nota',
    ];

    /** GET /crm/agenda?desde=&hasta=&vendedor_id=&tipo=&completado= */
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
        ]);

        $vendedorId = $this->resolverVendedorId($empresaId, (int) $validated['vendedor_id']);
        $desde = $validated['desde'] ?? now()->startOfDay()->toDateTimeString();
        $hasta = $validated['hasta'] ?? now()->addDays(30)->endOfDay()->toDateTimeString();

        $eventos = CrmAgenda::with('vendedor:id,nombre')
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
            'vendedor_id' => 'required|integer',
            'entidad_tipo' => ['nullable', 'required_with:entidad_id', Rule::in(array_keys(self::TIPOS))],
            'entidad_id' => 'nullable|integer|required_with:entidad_tipo',
            'recordatorio_at' => 'nullable|date|before_or_equal:fecha_inicio',
        ], [
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
            'recordatorio_at.before_or_equal' => 'El recordatorio debe ser antes del inicio del evento.',
        ]);

        $vendedorId = $this->resolverVendedorId($empresaId, (int) $validated['vendedor_id']);

        $entidadType = null;
        if (! empty($validated['entidad_tipo'])) {
            $modelClass = self::TIPOS[$validated['entidad_tipo']];
            $existe = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
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
        ]);

        // Se carga la relación antes de emitir el broadcast (mismo patrón
        // que ActividadController::store()): si no, el evento en tiempo
        // real que reciben las demás pestañas llegaría sin
        // vendedor.nombre hasta el siguiente refetch completo.
        $evento->load('vendedor:id,nombre');
        broadcast(new AgendaUpdated('created', $evento->toArray()));

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

        $validated = $request->validate([
            'tipo' => ['sometimes', 'required', Rule::in(self::TIPOS_AGENDA)],
            'titulo' => 'sometimes|required|string|max:255',
            'descripcion' => 'sometimes|nullable|string',
            'fecha_inicio' => 'sometimes|required|date',
            'fecha_fin' => 'sometimes|required|date|after_or_equal:fecha_inicio',
            // Nota (deviación puntual respecto al brief): antes se usaba
            // 'before_or_equal:fecha_inicio', pero Laravel evalúa esa regla
            // contra el valor de fecha_inicio EN EL REQUEST, no contra el
            // registro existente. Como update() usa 'sometimes' en todos los
            // campos, un PUT que solo manda recordatorio_at (el caso de uso
            // real: reprogramar solo el recordatorio) no incluye fecha_inicio
            // en el payload, y la regla fallaba siempre con 422 aunque el
            // valor fuera válido. El closure compara contra fecha_inicio del
            // payload si vino, y si no contra $agenda->fecha_inicio actual.
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
        ], [
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ]);

        // Si cambia el recordatorio, se resetea recordatorio_enviado_at para
        // que el nuevo valor sí se notifique en la siguiente corrida del
        // scheduler -- de lo contrario un recordatorio movido a una fecha
        // futura seguiría "ya enviado" para siempre.
        if (array_key_exists('recordatorio_at', $validated)) {
            $nuevoValor = $validated['recordatorio_at'];
            $valorActual = $agenda->recordatorio_at?->toDateTimeString();
            if ($nuevoValor !== $valorActual) {
                $validated['recordatorio_enviado_at'] = null;
            }
        }

        $agenda->update($validated);

        $agenda->load('vendedor:id,nombre');
        broadcast(new AgendaUpdated('updated', $agenda->toArray()));

        return $this->jsonSuccess($agenda, 'Evento de agenda actualizado correctamente');
    }

    /** PATCH /crm/agenda/{agenda}/completar */
    public function completar(Request $request, CrmAgenda $agenda): JsonResponse
    {
        $this->verificarEmpresa($agenda);
        $empresaId = $this->getEmpresaId();
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'editar'),
            403,
            'No tienes permiso para editar eventos de agenda.',
        );

        if ($agenda->completado) {
            return $this->jsonError('Este evento ya fue marcado como completado.', 422);
        }

        $agenda->update(['completado' => true]);

        if ($agenda->entidad_type && $agenda->entidad_id) {
            CrmActividad::create([
                'empresa_id' => $agenda->empresa_id,
                'entidad_type' => $agenda->entidad_type,
                'entidad_id' => $agenda->entidad_id,
                'tipo' => self::TIPO_ACTIVIDAD_PARA[$agenda->tipo],
                'descripcion' => $agenda->titulo.($agenda->descripcion ? " — {$agenda->descripcion}" : ''),
                'fecha_actividad' => now(),
                'vendedor_id' => $agenda->vendedor_id,
                'fuente' => 'agenda',
            ]);
        }

        $agenda->load('vendedor:id,nombre');
        broadcast(new AgendaUpdated('completed', $agenda->toArray()));

        return $this->jsonSuccess($agenda, 'Evento marcado como completado');
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

        $data = $agenda->toArray();
        $agenda->delete();

        broadcast(new AgendaUpdated('deleted', $data));

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

    /**
     * Resuelve qué vendedor_id puede consultarse/asignarse. Mismo patrón
     * que PresupuestoController::resolverVendedorId() (spec §4, misma
     * regla de precedencia): 'crear'/'editar' = gerencia, puede elegir
     * cualquier vendedor; solo 'ver' = forzado al propio CrmVendedor.
     */
    private function resolverVendedorId(int $empresaId, int $vendedorIdSolicitado): int
    {
        $esGerencia = $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear')
            || $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'editar');

        if ($esGerencia) {
            return $vendedorIdSolicitado;
        }

        $vendedorPropioId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        abort_unless(
            $vendedorPropioId !== null && (int) $vendedorPropioId === $vendedorIdSolicitado,
            403,
            'No puedes ver o modificar la agenda de otro vendedor.',
        );

        return $vendedorIdSolicitado;
    }
}
