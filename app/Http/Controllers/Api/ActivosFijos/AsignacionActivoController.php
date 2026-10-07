<?php
// sentinel-back/app/Http/Controllers/Api/ActivosFijos/AsignacionActivoController.php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Events\FixedAssetUpdated;
use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\AsignadorActivos;
use App\Services\ActivosFijos\PermisosActivos;
use App\Services\ActivosFijos\ResponsablesActivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Administración → Activos Fijos → Asignaciones. La empresa y el alcance los resuelve
 * AlcanceActivos (segmento de la URL); los permisos son los del submódulo `asignaciones`.
 * Las reglas de negocio viven en AsignadorActivos.
 */
class AsignacionActivoController extends Controller
{
    private const SUBMODULO = 'asignaciones';
    private const POR_PAGINA = 25;
    private const POR_PAGINA_MAX = 100;

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private AsignadorActivos $asignador,
        private ResponsablesActivo $responsables,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');
        $request->validate(['estado' => ['nullable', Rule::in(['activas', 'devueltas', 'todas'])]]);

        $query = $this->alcance->aplicar(FixedAssetAssignment::query(), $request)
            ->with(['asset:id,code,name,status,entity_id,enterprise_id', 'enterprise:id,name,slug', 'area:id,name,code']);

        match ($request->input('estado', 'activas')) {
            'activas' => $query->whereNull('returned_at'),
            'devueltas' => $query->whereNotNull('returned_at'),
            default => null,
        };

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->input('area_id'));
        }

        // Solo se filtra por una empresa que la petición pueda ver.
        if ($request->filled('enterprise_id') && in_array((int) $request->input('enterprise_id'), $this->alcance->idsVisibles($request), true)) {
            $query->where('enterprise_id', (int) $request->input('enterprise_id'));
        }

        if ($request->filled('search')) {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw("fixed_asset_assignments.assignee_name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('asset', fn ($a) => $a->where(function ($w) use ($term) {
                        $w->whereRaw("fixed_assets.code LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw("fixed_assets.name LIKE ? ESCAPE '!'", [$term]);
                    }));
            });
        }

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('assigned_at')->orderByDesc('id')->paginate($porPagina),
        ]);
    }

    public function historial(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');
        $this->alcance->autorizarActivo($asset, $request);

        $asignaciones = $asset->asignaciones()
            ->with(['area:id,name,code', 'entregadoPor:id,name', 'devueltoPor:id,name'])
            ->get();

        $areas = $asset->entity
            ? $asset->entity->areas()->where('areas.is_active', true)->wherePivot('is_active', true)
                ->orderBy('areas.name')->get(['areas.id', 'areas.name', 'areas.code'])
                ->map->only(['id', 'name', 'code'])->values()
            : collect();

        $motivo = $this->asignador->motivoBloqueo($asset);

        return response()->json([
            'success' => true,
            'data' => [
                'asignaciones' => $asignaciones,
                'areas' => $areas,
                'asignable' => $motivo === null,
                'motivo_bloqueo' => $motivo,
                'asignacion_activa_id' => $asignaciones->first(fn (FixedAssetAssignment $a) => $a->returned_at === null)?->id,
            ],
        ]);
    }

    public function responsables(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'create');
        $this->alcance->autorizarActivo($asset, $request);

        return response()->json([
            'success' => true,
            'data' => $this->responsables->buscar($asset->enterprise, (string) $request->input('q', '')),
        ]);
    }

    public function store(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'create');
        $this->alcance->autorizarActivo($asset, $request);

        $datos = $request->validate($this->reglasEntrega($asset));
        $asignacion = $this->asignador->asignar($asset, $datos, $request->user());
        $this->emitir($asset->enterprise, $asset->id);

        return response()->json([
            'success' => true,
            'message' => 'Activo asignado exitosamente',
            'data' => $this->conRelaciones($asignacion),
        ], 201);
    }

    public function reasignar(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'create');
        $this->alcance->autorizarActivo($asset, $request);

        $datos = $request->validate(array_merge(
            $this->reglasEntrega($asset, 'asignacion.'),
            $this->reglasDevolucion('devolucion.'),
            ['asignacion' => ['required', 'array'], 'devolucion' => ['required', 'array']],
        ));
        $nueva = $this->asignador->reasignar($asset, $datos['devolucion'], $datos['asignacion'], $request->user());
        $this->emitir($asset->enterprise, $asset->id);

        return response()->json([
            'success' => true,
            'message' => 'Activo reasignado exitosamente',
            'data' => $this->conRelaciones($nueva),
        ], 201);
    }

    public function devolver(Request $request, FixedAssetAssignment $asignacion): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'edit');
        $this->alcance->autorizarAsignacion($asignacion, $request);

        $datos = $request->validate($this->reglasDevolucion());
        $devuelta = $this->asignador->devolver($asignacion, $datos, $request->user());
        $this->emitir($asignacion->enterprise, $asignacion->fixed_asset_id);

        return response()->json([
            'success' => true,
            'message' => 'Activo devuelto exitosamente',
            'data' => $this->conRelaciones($devuelta),
        ]);
    }

    public function update(Request $request, FixedAssetAssignment $asignacion): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'edit');
        $this->alcance->autorizarAsignacion($asignacion, $request);

        $datos = $request->validate([
            'area_id' => ['nullable', 'integer', $this->reglaArea($asignacion->asset->entity_id)],
            'accessories' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'condition_out' => ['sometimes', Rule::in(FixedAssetAssignment::CONDICIONES)],
        ]);
        $corregida = $this->asignador->corregir($asignacion, $datos);
        $this->emitir($asignacion->enterprise, $asignacion->fixed_asset_id);

        return response()->json([
            'success' => true,
            'message' => 'Asignación actualizada exitosamente',
            'data' => $this->conRelaciones($corregida),
        ]);
    }

    /** Sube o reemplaza la carta firmada. Disco privado: se sirve solo con descargarCartaFirmada. */
    public function subirCartaFirmada(Request $request, FixedAssetAssignment $asignacion): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'edit');
        $this->alcance->autorizarAsignacion($asignacion, $request);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);

        $anterior = $asignacion->signed_document_path;
        $nueva = $request->file('file')->store("fixed-asset-assignments/{$asignacion->id}", 'local');

        try {
            DB::transaction(fn () => $asignacion->update([
                'signed_document_path' => $nueva,
                'signed_uploaded_at' => now(),
                'signed_uploaded_by' => $request->user()->id,
            ]));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($nueva);
            throw $e;
        }

        // El archivo viejo se borra solo cuando el cambio ya quedó guardado.
        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }
        $this->emitir($asignacion->enterprise, $asignacion->fixed_asset_id);

        return response()->json([
            'success' => true,
            'message' => 'Carta firmada guardada exitosamente',
            'data' => $this->conRelaciones($asignacion->refresh()),
        ]);
    }

    public function descargarCartaFirmada(Request $request, FixedAssetAssignment $asignacion)
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');
        $this->alcance->autorizarAsignacion($asignacion, $request);

        $ruta = $asignacion->signed_document_path;
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 404, 'Esta asignación no tiene carta firmada.');

        return Storage::disk('local')->response($ruta);
    }

    /** Datos para imprimir la carta responsiva (el front dibuja el PDF). El activo sale de la copia guardada. */
    public function carta(Request $request, FixedAssetAssignment $asignacion): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');
        $this->alcance->autorizarAsignacion($asignacion, $request);

        $asignacion->load(['enterprise', 'area:id,name', 'entregadoPor:id,name', 'devueltoPor:id,name']);
        $empresa = $asignacion->enterprise;
        $copia = $asignacion->asset_snapshot ?? [];

        // Número de entrega del activo: cuántas asignaciones suyas hay hasta esta (inclusive).
        $numero = FixedAssetAssignment::query()
            ->where('fixed_asset_id', $asignacion->fixed_asset_id)
            ->where('id', '<=', $asignacion->id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'folio' => sprintf('%s-R%02d', $copia['code'] ?? 'SIN-CODIGO', $numero),
                'empresa' => [
                    'nombre' => $empresa->name,
                    'razon_social' => $empresa->razon_social,
                    'rfc' => $empresa->rfc,
                    'direccion' => $empresa->direccion,
                    'ciudad' => $empresa->ciudad,
                    'telefono' => $empresa->telefono,
                    'logo' => $empresa->logo,
                ],
                'activo' => $copia,
                'responsable' => [
                    'tipo' => $asignacion->assignee_type,
                    'nombre' => $asignacion->assignee_name,
                    'puesto' => $asignacion->assignee_position,
                    'departamento' => $asignacion->assignee_department,
                    'area' => $asignacion->area?->name,
                ],
                'entrega' => [
                    'fecha' => $asignacion->assigned_at?->toDateString(),
                    'condicion' => $asignacion->condition_out,
                    'accesorios' => $asignacion->accessories,
                    'notas' => $asignacion->notes,
                    'entregado_por' => $asignacion->entregadoPor?->name,
                ],
                'devolucion' => $asignacion->returned_at ? [
                    'fecha' => $asignacion->returned_at->toDateString(),
                    'condicion' => $asignacion->condition_in,
                    'motivo' => $asignacion->return_reason,
                    'notas' => $asignacion->return_notes,
                    'devuelto_por' => $asignacion->devueltoPor?->name,
                ] : null,
            ],
        ]);
    }

    private function reglasEntrega(FixedAsset $asset, string $prefijo = ''): array
    {
        return [
            "{$prefijo}assignee_type" => ['required', Rule::in(FixedAssetAssignment::TIPOS)],
            "{$prefijo}assignee_id" => ['required', 'integer'],
            "{$prefijo}area_id" => ['nullable', 'integer', $this->reglaArea($asset->entity_id)],
            "{$prefijo}assigned_at" => ['nullable', 'date', 'before_or_equal:today'],
            "{$prefijo}condition_out" => ['required', Rule::in(FixedAssetAssignment::CONDICIONES)],
            "{$prefijo}accessories" => ['nullable', 'string', 'max:2000'],
            "{$prefijo}notes" => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function reglasDevolucion(string $prefijo = ''): array
    {
        return [
            "{$prefijo}returned_at" => ['nullable', 'date', 'before_or_equal:today'],
            "{$prefijo}condition_in" => ['required', Rule::in(FixedAssetAssignment::CONDICIONES)],
            "{$prefijo}return_reason" => ['required', Rule::in(FixedAssetAssignment::MOTIVOS)],
            "{$prefijo}return_notes" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** El área debe estar activa en la entidad del activo (misma regla que al editar el activo). */
    private function reglaArea(?int $entityId)
    {
        return Rule::exists('entity_area', 'area_id')->where('entity_id', $entityId)->where('is_active', true);
    }

    private function conRelaciones(FixedAssetAssignment $asignacion): FixedAssetAssignment
    {
        return $asignacion->load(['area:id,name,code', 'entregadoPor:id,name', 'devueltoPor:id,name']);
    }

    /** Un Reverb caído no debe convertir un guardado exitoso en un 500. */
    private function emitir(Enterprise $empresa, int $activoId): void
    {
        try {
            broadcast(new FixedAssetUpdated('assignment', ['id' => $activoId], $empresa->slug));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
