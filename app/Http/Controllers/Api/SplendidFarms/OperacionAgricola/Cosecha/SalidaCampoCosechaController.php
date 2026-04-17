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
use App\Models\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalidaCampoCosechaController extends Controller
{
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
            'cantidad' => 'required|integer|min:1',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'vehiculo' => 'required|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:registrada,en_transito,entregada,cancelada',
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

        // Generar folio de salida
        $validated['folio_salida'] = $this->generarFolio($validated);

        $salida = SalidaCampoCosecha::create($validated);
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
            'cantidad' => 'sometimes|integer|min:1',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'vehiculo' => 'sometimes|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'observaciones' => 'nullable|string',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:registrada,en_transito,entregada,cancelada',
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

        // Consecutivo por combinación dentro de la temporada
        $consecutivo = SalidaCampoCosecha::where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'])
            ->where('zona_cultivo_id', $data['zona_cultivo_id'] ?? null)
            ->where('lote_id', $data['lote_id'])
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->max('id');

        // Contar registros existentes (incluyendo eliminados) + 1
        $count = SalidaCampoCosecha::where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'])
            ->where('zona_cultivo_id', $data['zona_cultivo_id'] ?? null)
            ->where('lote_id', $data['lote_id'])
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->count() + 1;

        $consecutivoStr = str_pad($count, 2, '0', STR_PAD_LEFT);

        return "{$productorId}-{$zonaId}-{$loteNum}-{$etapaOrden}{$consecutivoStr}";
    }
}
