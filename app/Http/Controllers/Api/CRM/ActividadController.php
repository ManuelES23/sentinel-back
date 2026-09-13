<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ActividadController
 * Actividades comerciales polimórficas (timeline por entidad).
 * Se pueden asociar a: prospecto, cliente, oportunidad o empresa_externa.
 *
 * Payload standard:
 *  - entidad_tipo: 'prospecto' | 'cliente' | 'oportunidad' | 'empresa_externa' (alias corto)
 *  - entidad_id:   ID del recurso relacionado
 * Internamente se traduce a entidad_type (nombre completo del modelo).
 */
class ActividadController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * Alias corto → clase del modelo padre.
     */
    protected const TIPOS = [
        'prospecto'       => CrmProspecto::class,
        'cliente'         => CrmCliente::class,
        'oportunidad'     => CrmOportunidad::class,
        'empresa_externa' => CrmEmpresaExterna::class,
    ];

    /**
     * Tipos de actividad permitidos (coinciden con el enum de la migración).
     */
    protected const TIPOS_ACTIVIDAD = ['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota'];

    protected const RELACIONES = ['vendedor:id,nombre,email'];

    /**
     * GET /crm/actividades?entidad_tipo=&entidad_id=&tipo=&vendedor_id=&search=
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $request->validate([
            'entidad_tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'   => 'nullable|integer',
            'tipo'         => ['nullable', Rule::in(self::TIPOS_ACTIVIDAD)],
            'vendedor_id'  => 'nullable|integer',
        ]);

        $query = $this->construirQuery($empresaId, $request->only([
            'entidad_tipo', 'entidad_id', 'tipo', 'vendedor_id', 'search',
        ]));

        $perPage = (int) $request->query('per_page', 50);

        return $this->respuestaPaginada($query->paginate($perPage));
    }

    /**
     * GET /crm/{entidad_type}/{entidad_id}/historial
     * Atajo de solo lectura sobre index(): historial de actividades de UNA
     * entidad puntual (prospecto|cliente|oportunidad|empresa_externa),
     * ordenado por fecha descendente y paginado a 15 registros fijos.
     * Incluye también las notas automáticas del sistema (fuente = 'sistema'),
     * como las de asignación de vendedor, porque viven en la misma tabla.
     */
    public function historial(Request $request, string $entidad_type, int $entidad_id): JsonResponse
    {
        if (! array_key_exists($entidad_type, self::TIPOS)) {
            return $this->jsonError('Tipo de entidad no válido.', 422);
        }

        $empresaId = $this->getEmpresaId($request);

        $query = $this->construirQuery($empresaId, [
            'entidad_tipo' => $entidad_type,
            'entidad_id'   => $entidad_id,
        ]);

        return $this->respuestaPaginada($query->paginate(15));
    }

    /**
     * Query base compartida por index() e historial(): mismos filtros,
     * mismo orden. Evita mantener dos veces la misma cadena de when().
     */
    private function construirQuery(?int $empresaId, array $filtros)
    {
        return CrmActividad::query()
            ->with(self::RELACIONES)
            ->where('empresa_id', $empresaId)
            ->when($filtros['entidad_tipo'] ?? null, function ($q, $tipo) {
                $q->where('entidad_type', self::TIPOS[$tipo]);
            })
            ->when($filtros['entidad_id'] ?? null, fn ($q, $id) => $q->where('entidad_id', $id))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['vendedor_id'] ?? null, fn ($q, $id) => $q->where('vendedor_id', $id))
            ->when($filtros['search'] ?? null, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('descripcion', 'like', "%{$search}%")
                        ->orWhere('resultado', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('fecha_actividad')
            ->orderByDesc('id');
    }

    /**
     * Serializa un paginador de CrmActividad al formato estándar
     * { success, message, data, meta }, agregando el alias entidad_tipo.
     */
    private function respuestaPaginada($paginated): JsonResponse
    {
        $items = collect($paginated->items())->map(function ($a) {
            $a->entidad_tipo = $this->aliasEntidadTipo($a->entidad_type);
            return $a;
        });

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data'    => $items,
            'meta'    => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /crm/actividades/{actividad}
     */
    public function show(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);
        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = $this->aliasEntidadTipo($actividad->entidad_type);

        return $this->jsonSuccess($actividad);
    }

    /**
     * POST /crm/actividades
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'actividades', 'actividades', 'crear', 'No tienes permiso para registrar actividades.');

        $validated = $request->validate([
            'entidad_tipo'     => ['required', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'       => 'required|integer',
            'tipo'             => ['required', Rule::in(self::TIPOS_ACTIVIDAD)],
            'descripcion'      => 'required|string',
            'fecha_actividad'  => 'required|date',
            'vendedor_id'      => 'nullable|integer|exists:crm_vendedores,id',
            'duracion_minutos' => 'nullable|integer|min:0',
            'resultado'        => 'nullable|string',
        ]);

        $modelClass = self::TIPOS[$validated['entidad_tipo']];
        $entidad = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
        if (!$entidad) {
            return $this->jsonError('La entidad relacionada no existe o no pertenece a la empresa.', 404);
        }

        $actividad = CrmActividad::create([
            'empresa_id'       => $empresaId,
            'entidad_type'     => $modelClass,
            'entidad_id'       => $validated['entidad_id'],
            'tipo'             => $validated['tipo'],
            'descripcion'      => $validated['descripcion'],
            'fecha_actividad'  => $validated['fecha_actividad'],
            'vendedor_id'      => $validated['vendedor_id'] ?? null,
            'duracion_minutos' => $validated['duracion_minutos'] ?? null,
            'resultado'        => $validated['resultado'] ?? null,
            'fuente'           => 'manual',
        ]);

        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = $validated['entidad_tipo'];

        broadcast(new ActividadUpdated('created', $actividad->toArray()));

        return $this->jsonSuccess($actividad, 'Actividad registrada correctamente', 201);
    }

    /**
     * PATCH /crm/actividades/{actividad}
     */
    public function update(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'actividades', 'actividades', 'editar', 'No tienes permiso para editar actividades.');

        $validated = $request->validate([
            'tipo'             => ['sometimes', 'required', Rule::in(self::TIPOS_ACTIVIDAD)],
            'descripcion'      => 'sometimes|required|string',
            'fecha_actividad'  => 'sometimes|required|date',
            'vendedor_id'      => 'sometimes|nullable|integer|exists:crm_vendedores,id',
            'duracion_minutos' => 'sometimes|nullable|integer|min:0',
            'resultado'        => 'sometimes|nullable|string',
        ]);

        $actividad->update($validated);
        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = $this->aliasEntidadTipo($actividad->entidad_type);

        broadcast(new ActividadUpdated('updated', $actividad->toArray()));

        return $this->jsonSuccess($actividad, 'Actividad actualizada correctamente');
    }

    /**
     * DELETE /crm/actividades/{actividad}
     */
    public function destroy(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'actividades', 'actividades', 'eliminar', 'No tienes permiso para eliminar actividades.');

        $data = $actividad->toArray();
        $actividad->delete();

        broadcast(new ActividadUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Actividad eliminada correctamente');
    }

    /**
     * Traduce el FQCN guardado en entidad_type de vuelta al alias corto
     * ('prospecto', 'cliente', ...) que consume el frontend.
     */
    private function aliasEntidadTipo(?string $entidadType): ?string
    {
        return array_search($entidadType, self::TIPOS, true) ?: null;
    }

    /**
     * Aborta con 404 si la actividad no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmActividad $actividad): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $actividad->empresa_id !== (int) $empresaId) {
            abort(404, 'Actividad no encontrada');
        }
    }
}
