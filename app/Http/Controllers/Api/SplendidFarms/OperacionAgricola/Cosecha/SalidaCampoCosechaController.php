<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Events\SalidaCampoUpdated;
use App\Http\Controllers\Controller;
use App\Models\CierreCosecha;
use App\Models\RecepcionEmpaque;
use App\Models\SalidaCampoCosecha;
use App\Models\ConvenioCompra;
use App\Models\Lote;
use App\Models\Etapa;
use App\Models\Submodule;
use App\Models\TipoCarga;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SalidaCampoCosechaController extends Controller
{
    private ?int $submoduleId = null;

    private array $eagerLoad = [
        'etapa:id,nombre,orden,lote_id,variedad_id,tipo_variedad_id',
        'etapa.variedad:id,nombre',
        'etapa.tipoVariedad:id,nombre',
        'variedad:id,nombre,cultivo_id',
        'convenioCompra:id,folio_convenio,modalidad,status',
        'lote:id,nombre,numero_lote,zona_cultivo_id,productor_id',
        'lote.zonaCultivo:id,nombre',
        'productor:id,nombre,apellido,tipo',
        'tipoCarga:id,nombre,peso_estimado_kg,cultivo_id',
        'tipoCarga.cultivo:id,nombre',
        'destinoEntity:id,name,code',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = SalidaCampoCosecha::with($this->eagerLoad)->activos();

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }

        if ($request->filled('productor_id')) {
            $query->where('productor_id', $request->productor_id);
        }

        if ($request->filled('lote_id')) {
            $query->where('lote_id', $request->lote_id);
        }

        if ($request->filled('etapa_id')) {
            $query->where('etapa_id', $request->etapa_id);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_salida', 'like', "%{$search}%")
                  ->orWhere('chofer', 'like', "%{$search}%")
                  ->orWhere('vehiculo', 'like', "%{$search}%")
                  ->orWhere('observaciones', 'like', "%{$search}%")
                  ->orWhereHas('productor', function ($sub) use ($search) {
                      $sub->where('nombre', 'like', "%{$search}%")
                          ->orWhere('apellido', 'like', "%{$search}%");
                  });
            });
        }

        $salidas = $query->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $salidas,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'variedad_id' => 'nullable|required_without:etapa_id|exists:variedades,id',
            'lote_id' => 'required|exists:lotes,id',
            'tipo_carga_id' => 'required|exists:tipos_carga,id',
            'productor_id' => 'required|exists:productores,id',
            'destino_entity_id' => 'nullable|exists:entities,id',
            'fecha' => 'required|date',
            'es_compra_directa' => 'nullable|boolean',
            'cantidad' => 'required|integer|min:1',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'vehiculo' => 'required|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:registrada,en_transito,pendiente_descarga,entregada,cancelada',
        ]);

        // Si tiene etapa, obtener variedad de la etapa automáticamente
        if (!empty($validated['etapa_id'])) {
            $etapa = Etapa::find($validated['etapa_id']);
            $validated['variedad_id'] = $etapa?->variedad_id;
        }

        // Forzar status en_transito al crear
        $validated['status'] = 'en_transito';
        $validated['hora_salida'] = now('America/Mexico_City')->format('H:i:s');
        $validated['created_by'] = $request->user()->id;

        // Obtener zona_cultivo_id del lote
        $lote = Lote::find($validated['lote_id']);
        $validated['zona_cultivo_id'] = $lote?->zona_cultivo_id;

        // Auto-buscar convenio de compra activo para este productor/cultivo/variedad
        if (empty($validated['convenio_compra_id'])) {
            $cultivoId = null;
            if (!empty($validated['variedad_id'])) {
                $cultivoId = \App\Models\Variedad::find($validated['variedad_id'])?->cultivo_id;
            }
            if ($cultivoId) {
                $convenio = ConvenioCompra::activos()
                    ->porTemporada($validated['temporada_id'])
                    ->porProductor($validated['productor_id'])
                    ->porCultivo($cultivoId)
                    ->where(function ($q) use ($validated) {
                        $q->where('variedad_id', $validated['variedad_id'])
                          ->orWhereNull('variedad_id');
                    })
                    ->vigentesEnFecha($validated['fecha'])
                    ->orderByRaw('variedad_id IS NULL ASC')
                    ->first();
                $validated['convenio_compra_id'] = $convenio?->id;
            }
        }

        // Calcular peso estimado
        $tipoCarga = TipoCarga::find($validated['tipo_carga_id']);
        if ($tipoCarga) {
            $validated['peso_neto_kg'] = $validated['cantidad'] * $tipoCarga->peso_estimado_kg;
        }

        // Generar folio de salida (con reintento ante colisión por inserciones casi simultáneas)
        $salida = null;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $salida = DB::transaction(function () use ($validated) {
                    $payload = $validated;
                    $payload['folio_salida'] = $this->generarFolio($payload);

                    return SalidaCampoCosecha::create($payload);
                });

                break;
            } catch (QueryException $e) {
                if ($this->isFolioDuplicateError($e) && $attempt < $maxAttempts) {
                    continue;
                }
                throw $e;
            }
        }

        $salida->load($this->eagerLoad);

        broadcast(new SalidaCampoUpdated(
            'created',
            $salida->toArray(),
            'splendidfarms',
            'operacion-agricola',
            'cosecha'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Salida de campo registrada exitosamente',
            'data' => $salida,
        ], 201);
    }

    public function show(SalidaCampoCosecha $salida): JsonResponse
    {
        if ($salida->eliminado) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registro no encontrado',
            ], 404);
        }

        $salida->load([...$this->eagerLoad, 'calidadInspecciones']);

        return response()->json([
            'success' => true,
            'data' => $salida,
        ]);
    }

    public function update(Request $request, SalidaCampoCosecha $salida): JsonResponse
    {
        if ($salida->eliminado) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar un registro eliminado',
            ], 404);
        }

        // Block edit if salida has a recepcion linked
        $tieneRecepcion = RecepcionEmpaque::where('salida_campo_id', $salida->id)->whereNull('deleted_at')->exists();
        if ($tieneRecepcion) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar esta salida porque ya tiene una recepción vinculada',
            ], 422);
        }

        // Block edit if salida has a cierre linked
        $tieneCierre = CierreCosecha::where('temporada_id', $salida->temporada_id)
            ->where('fecha_inicio', $salida->fecha)
            ->where('productor_id', $salida->productor_id)
            ->where('lote_id', $salida->lote_id)
            ->where('etapa_id', $salida->etapa_id)
            ->whereNull('deleted_at')
            ->exists();
        if ($tieneCierre) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar esta salida porque ya tiene un cierre de cosecha asociado',
            ], 422);
        }

        $validated = $request->validate([
            'temporada_id' => 'sometimes|exists:temporadas,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'lote_id' => 'sometimes|exists:lotes,id',
            'tipo_carga_id' => 'sometimes|exists:tipos_carga,id',
            'productor_id' => 'sometimes|exists:productores,id',
            'destino_entity_id' => 'nullable|exists:entities,id',
            'fecha' => 'sometimes|date',
            'es_compra_directa' => 'nullable|boolean',
            'cantidad' => 'sometimes|integer|min:1',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'vehiculo' => 'sometimes|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:registrada,en_transito,pendiente_descarga,entregada,cancelada',
        ]);

        // Si tiene etapa, obtener variedad de la etapa automáticamente
        if (isset($validated['etapa_id']) && $validated['etapa_id']) {
            $etapa = Etapa::find($validated['etapa_id']);
            $validated['variedad_id'] = $etapa?->variedad_id;
        }

        // Recalcular zona si cambia lote
        if (isset($validated['lote_id'])) {
            $lote = Lote::find($validated['lote_id']);
            $validated['zona_cultivo_id'] = $lote?->zona_cultivo_id;
        }

        // Recalcular peso si cambia cantidad o tipo_carga
        $cantidad = $validated['cantidad'] ?? $salida->cantidad;
        $tipoCargaId = $validated['tipo_carga_id'] ?? $salida->tipo_carga_id;
        if (isset($validated['cantidad']) || isset($validated['tipo_carga_id'])) {
            $tipoCarga = TipoCarga::find($tipoCargaId);
            if ($tipoCarga) {
                $validated['peso_neto_kg'] = $cantidad * $tipoCarga->peso_estimado_kg;
            }
        }

        $salida->update($validated);
        $salida->load($this->eagerLoad);

        broadcast(new SalidaCampoUpdated(
            'updated',
            $salida->toArray(),
            'splendidfarms',
            'operacion-agricola',
            'cosecha'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Salida de campo actualizada',
            'data' => $salida,
        ]);
    }

    public function asignarPrecio(Request $request, SalidaCampoCosecha $salida): JsonResponse
    {
        if ($error = $this->ensurePermission($request, 'asignar_precio_compra_directa', 'asignar precio a salidas de compra directa')) {
            return $error;
        }

        if ($salida->eliminado) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar un registro eliminado',
            ], 404);
        }

        $salida->loadMissing('convenioCompra:id,folio_convenio,modalidad,status');

        if (!$salida->esCompraDirecta()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Esta salida no es de compra directa: no se le puede asignar precio',
            ], 422);
        }

        $validated = $request->validate([
            'precio_asignado' => 'required|numeric|min:0',
            'foto_ticket_bascula' => [
                $salida->foto_ticket_bascula_path ? 'nullable' : 'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        DB::transaction(function () use ($request, $salida, $validated) {
            $fotoPath = $salida->foto_ticket_bascula_path;

            if ($request->hasFile('foto_ticket_bascula')) {
                if ($fotoPath) {
                    Storage::disk('public')->delete($fotoPath);
                }

                $fotoPath = $request->file('foto_ticket_bascula')
                    ->store('salidas-campo/tickets-bascula', 'public');
            }

            $salida->update([
                'precio_asignado' => $validated['precio_asignado'],
                'foto_ticket_bascula_path' => $fotoPath,
                'precio_asignado_por' => $request->user()?->id,
                'precio_asignado_en' => now(),
            ]);
        });

        $salida->refresh()->load($this->eagerLoad);

        broadcast(new SalidaCampoUpdated(
            'updated',
            $salida->toArray(),
            'splendidfarms',
            'operacion-agricola',
            'cosecha'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Precio asignado exitosamente',
            'data' => $salida,
        ]);
    }

    public function destroy(SalidaCampoCosecha $salida): JsonResponse
    {
        if ($salida->eliminado) {
            return response()->json([
                'status' => 'error',
                'message' => 'Registro ya fue eliminado',
            ], 404);
        }

        // Block delete if salida has a recepcion linked
        $tieneRecepcion = RecepcionEmpaque::where('salida_campo_id', $salida->id)->whereNull('deleted_at')->exists();
        if ($tieneRecepcion) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar esta salida porque ya tiene una recepción vinculada. Elimina primero la recepción.',
            ], 422);
        }

        // Block delete if salida has a cierre linked
        $tieneCierre = CierreCosecha::where('temporada_id', $salida->temporada_id)
            ->where('fecha_inicio', $salida->fecha)
            ->where('productor_id', $salida->productor_id)
            ->where('lote_id', $salida->lote_id)
            ->where('etapa_id', $salida->etapa_id)
            ->whereNull('deleted_at')
            ->exists();
        if ($tieneCierre) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar esta salida porque ya tiene un cierre de cosecha asociado. Elimina primero el cierre.',
            ], 422);
        }

        $salida->update(['eliminado' => true]);

        broadcast(new SalidaCampoUpdated(
            'deleted',
            ['id' => $salida->id],
            'splendidfarms',
            'operacion-agricola',
            'cosecha'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Salida de campo eliminada',
        ]);
    }

    /**
     * Generar folio de salida: PP-ZZ-LL-EENN
     * PP = productor_id (pad 2)
     * ZZ = zona_cultivo_id (pad 2)
     * LL = lote numero_lote (pad 2)
     * EE = etapa orden (pad 2)
     * NN = consecutivo por combinación en la temporada (pad 2)
     */
    private function generarFolio(array $data): string
    {
        $productorId = str_pad($data['productor_id'], 2, '0', STR_PAD_LEFT);
        $zonaId = str_pad($data['zona_cultivo_id'] ?? 0, 2, '0', STR_PAD_LEFT);

        $lote = Lote::find($data['lote_id']);
        $loteNum = str_pad($lote?->numero_lote ?? 0, 2, '0', STR_PAD_LEFT);

        $etapa = isset($data['etapa_id']) ? Etapa::find($data['etapa_id']) : null;
        $etapaOrden = str_pad($etapa?->orden ?? 0, 2, '0', STR_PAD_LEFT);

        $prefix = "{$productorId}-{$zonaId}-{$loteNum}-{$etapaOrden}";

        // Consecutivo por combinación dentro de la temporada: se basa en el último
        // folio realmente usado (no en un COUNT de filas), porque un COUNT asume que
        // los folios existentes son contiguos 1..N. Si algún registro se eliminó
        // físicamente en algún momento (dejando un hueco en la numeración, p.ej.
        // ...0016, ...0018, ...0019 sin ...0017), COUNT()+1 puede volver a calcular
        // un número que ya está tomado y chocar siempre contra el mismo folio.
        $lastFolioSalida = SalidaCampoCosecha::where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'])
            ->where('zona_cultivo_id', $data['zona_cultivo_id'] ?? null)
            ->where('lote_id', $data['lote_id'])
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->where('folio_salida', 'like', "{$prefix}%")
            ->orderByDesc('folio_salida')
            ->value('folio_salida');

        // recepciones_empaque comparte el mismo espacio de numeración: hay
        // recepciones dadas de alta manualmente (sin salida_campo_id) que usan este
        // mismo formato de folio directamente en esa tabla. Si no se consideran aquí,
        // una salida nueva puede recalcular un consecutivo que una recepción manual
        // ya tomó y, al recibirla más adelante (RecepcionEmpaqueController copia
        // folio_salida tal cual a folio_recepcion), truena por folio_recepcion
        // duplicado.
        $lastFolioRecepcion = RecepcionEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'])
            ->where('zona_cultivo_id', $data['zona_cultivo_id'] ?? null)
            ->where('lote_id', $data['lote_id'])
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->where('folio_recepcion', 'like', "{$prefix}%")
            ->orderByDesc('folio_recepcion')
            ->value('folio_recepcion');

        $nextNum = 1;
        foreach ([$lastFolioSalida, $lastFolioRecepcion] as $folio) {
            if ($folio) {
                $nextNum = max($nextNum, (int) substr($folio, -2) + 1);
            }
        }

        $consecutivoStr = str_pad($nextNum, 2, '0', STR_PAD_LEFT);

        return "{$prefix}{$consecutivoStr}";
    }

    private function isFolioDuplicateError(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'salidas_campo_cosecha_folio_salida_unique');
    }

    private function ensurePermission(Request $request, string $permissionSlug, string $actionLabel): ?JsonResponse
    {
        $submoduleId = $this->getSubmoduleId();

        if (!$submoduleId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo salidas de campo no encontrado',
            ], 500);
        }

        if ($this->userHasPermission($request, $permissionSlug)) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => "No tienes permiso para {$actionLabel}",
        ], 403);
    }

    private function userHasPermission(Request $request, string $permissionSlug): bool
    {
        $submoduleId = $this->getSubmoduleId();
        $userId = $request->user()?->id;

        if (!$submoduleId || !$userId) {
            return false;
        }

        return UserSubmodulePermission::query()
            ->where('user_id', $userId)
            ->where('submodule_id', $submoduleId)
            ->whereHas('permissionType', fn($q) => $q->where('slug', $permissionSlug))
            ->where('is_granted', true)
            ->exists();
    }

    private function getSubmoduleId(): ?int
    {
        if (!is_null($this->submoduleId)) {
            return $this->submoduleId;
        }

        $this->submoduleId = Submodule::query()
            ->where('slug', 'salidas-campo')
            ->value('id');

        return $this->submoduleId;
    }
}
