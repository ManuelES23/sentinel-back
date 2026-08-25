<?php
// app/Http/Controllers/Api/CRM/DialpadIntegracionController.php

namespace App\Http\Controllers\Api\CRM;

use App\Console\Commands\SincronizarDialpadCommand;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Listado/clasificación de llamadas importadas de Dialpad (CrmActividad con
 * fuente='dialpad') + disparo manual del comando de sincronización +
 * consulta de estado. Sin conexión por usuario -- una sola API key global
 * (ver SincronizarDialpadCommand) -- por eso estado() no depende de qué
 * usuario pregunta, solo de que tenga permiso 'ver' en la empresa actual.
 *
 * A diferencia de ActividadController::TIPOS, aquí solo se permite vincular
 * a cliente/prospecto/contacto (no oportunidad/empresa_externa) porque esas
 * son las únicas entidades contra las que el comando de sincronización
 * compara el teléfono del contacto (ver SincronizarDialpadCommand::resolverEntidad()).
 */
class DialpadIntegracionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    protected const TIPOS = [
        'cliente' => CrmCliente::class,
        'prospecto' => CrmProspecto::class,
        'contacto' => CrmContacto::class,
    ];

    /** GET /crm/integraciones/dialpad/llamadas?vendedor_id=&sin_clasificar=&desde=&hasta= */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'ver'),
            403,
            'No tienes permiso para ver las llamadas de Dialpad.',
        );

        $validated = $request->validate([
            'vendedor_id' => 'nullable|integer',
            'sin_clasificar' => 'nullable|boolean',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        $vendedorId = $this->resolverFiltroVendedorId(
            $empresaId,
            isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
        );

        $query = CrmActividad::with(['vendedor:id,nombre', 'entidad'])
            ->where('empresa_id', $empresaId)
            ->where('fuente', 'dialpad')
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->when($validated['sin_clasificar'] ?? null, fn ($q) => $q->whereNull('entidad_id'))
            ->when($validated['desde'] ?? null, fn ($q, $desde) => $q->where('fecha_actividad', '>=', $desde))
            ->when($validated['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha_actividad', '<=', $hasta))
            ->orderByDesc('fecha_actividad');

        $perPage = (int) $request->query('per_page', 25);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($a) {
            $a->entidad_tipo = array_search($a->entidad_type, self::TIPOS, true) ?: null;

            return $a;
        });

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data' => $items,
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /** PATCH /crm/integraciones/dialpad/llamadas/{actividad}/clasificar */
    public function clasificar(Request $request, CrmActividad $actividad): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'editar'),
            403,
            'No tienes permiso para clasificar llamadas de Dialpad.',
        );

        if ((int) $actividad->empresa_id !== $empresaId || $actividad->fuente !== 'dialpad') {
            abort(404, 'Llamada no encontrada.');
        }

        $validated = $request->validate([
            'entidad_tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'entidad_id' => 'nullable|integer',
            'resultado' => 'nullable|string',
        ]);

        $entidadType = null;
        $entidadId = null;

        if (! empty($validated['entidad_tipo'])) {
            abort_unless(! empty($validated['entidad_id']), 422, 'Falta entidad_id.');

            $modelClass = self::TIPOS[$validated['entidad_tipo']];
            $entidad = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
            abort_unless($entidad, 404, 'La entidad relacionada no existe o no pertenece a la empresa.');

            $entidadType = $modelClass;
            $entidadId = $validated['entidad_id'];
        }

        $actividad->update([
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadId,
            'resultado' => array_key_exists('resultado', $validated) ? $validated['resultado'] : $actividad->resultado,
        ]);

        $actividad->load(['vendedor:id,nombre', 'entidad']);
        $actividad->entidad_tipo = array_search($actividad->entidad_type, self::TIPOS, true) ?: null;

        return $this->jsonSuccess($actividad, 'Llamada clasificada correctamente');
    }

    /** POST /crm/integraciones/dialpad/sincronizar */
    public function sincronizar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'sync'),
            403,
            'No tienes permiso para sincronizar llamadas de Dialpad.',
        );

        Artisan::call('crm:sincronizar-dialpad');

        $resultado = Cache::get(SincronizarDialpadCommand::CACHE_ULTIMA_CORRIDA, ['sincronizadas' => 0, 'omitidas' => 0]);

        return $this->jsonSuccess($resultado);
    }

    /** GET /crm/integraciones/dialpad/estado */
    public function estado(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'ver'),
            403,
            'No tienes permiso para ver el estado de Dialpad.',
        );

        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        return $this->jsonSuccess([
            'ultimoSync' => $estado->ultimo_sync_at?->toIso8601String(),
            'ultimoError' => $estado->ultimo_error,
        ]);
    }

    /**
     * Precedencia por vendedor (mismo patrón que AgendaController /
     * PresupuestoController), adaptada a un filtro OPCIONAL sobre un
     * listado (no a "una sola fila obligatoria"): quien tiene sync o editar
     * es gerencia y puede filtrar por cualquier vendedor o ver todos (null
     * = sin filtro); quien solo tiene ver:
     *   - si no pide ningún vendedor_id, se le fuerza a su propio vendedor
     *     (o -1 si no tiene un vendedor propio vinculado -- un id que
     *     ninguna fila real tendrá nunca, para que la query devuelva vacío
     *     en vez de "todas" por accidente);
     *   - si pide explícitamente el vendedor_id de alguien más, 403.
     */
    private function resolverFiltroVendedorId(int $empresaId, ?int $vendedorIdSolicitado): ?int
    {
        $esGerencia = $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'sync')
            || $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'dialpad', 'editar');

        if ($esGerencia) {
            return $vendedorIdSolicitado;
        }

        $vendedorPropioId = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->value('id');

        if ($vendedorIdSolicitado !== null) {
            abort_unless(
                $vendedorPropioId !== null && (int) $vendedorPropioId === $vendedorIdSolicitado,
                403,
                'No puedes ver las llamadas de otro vendedor.',
            );
        }

        return $vendedorPropioId !== null ? (int) $vendedorPropioId : -1;
    }
}
