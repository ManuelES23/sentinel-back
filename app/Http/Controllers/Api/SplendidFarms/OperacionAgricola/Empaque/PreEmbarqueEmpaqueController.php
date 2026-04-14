<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\PreEmbarqueEmpaque;
use App\Models\ProduccionEmpaque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PreEmbarqueEmpaqueController extends Controller
{
    /**
     * Listar pre-embarques de la entidad/temporada.
     */
    public function index(Request $request)
    {
        $query = PreEmbarqueEmpaque::with([
            'entity:id,name',
            'creator:id,name',
            'detalles.produccion:id,numero_pallet,total_cajas,peso_neto_kg',
        ])->withCount('detalles');

        if ($request->filled('temporada_id')) {
            $query->where('temporada_id', $request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $preEmbarques = $query->orderByDesc('created_at')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $preEmbarques->items(),
            'meta' => [
                'total' => $preEmbarques->total(),
                'per_page' => $preEmbarques->perPage(),
                'current_page' => $preEmbarques->currentPage(),
                'last_page' => $preEmbarques->lastPage(),
            ],
        ]);
    }

    /**
     * Crear un nuevo pre-embarque.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'espacios_caja' => 'nullable|integer|min:1|max:50',
            'observaciones' => 'nullable|string|max:500',
        ]);

        // Generar folio PRE-XX-XXXX
        $entityPad = str_pad($validated['entity_id'], 2, '0', STR_PAD_LEFT);
        $lastFolio = PreEmbarqueEmpaque::withTrashed()
            ->where('folio_pre_embarque', 'like', "PRE-{$entityPad}-%")
            ->orderByDesc('folio_pre_embarque')
            ->value('folio_pre_embarque');
        $nextNum = $lastFolio ? (int) substr($lastFolio, -4) + 1 : 1;
        $folio = "PRE-{$entityPad}-" . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $validated['folio_pre_embarque'] = $folio;
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = 'abierto';

        $preEmbarque = PreEmbarqueEmpaque::create($validated);
        $preEmbarque->load(['entity:id,name', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Pre-embarque creado correctamente',
            'data' => $preEmbarque,
        ], 201);
    }

    /**
     * Ver detalle de un pre-embarque con sus pallets.
     */
    public function show(PreEmbarqueEmpaque $preEmbarque)
    {
        $preEmbarque->load([
            'entity:id,name',
            'creator:id,name',
            'detalles' => fn($q) => $q->orderBy('posicion_carga'),
            'detalles.produccion' => fn($q) => $q->with([
                'proceso.productor:id,nombre,apellido',
                'proceso.lote:id,nombre,numero_lote',
                'proceso.etapa.variedad:id,nombre',
                'proceso.recepcion.salidaCampo.variedad:id,nombre',
                'variedad:id,nombre',
            ]),
        ]);
        $preEmbarque->loadCount('detalles');

        return response()->json(['success' => true, 'data' => $preEmbarque]);
    }

    /**
     * Escanear QR de pallet y agregarlo al pre-embarque.
     */
    public function scanPallet(Request $request, PreEmbarqueEmpaque $preEmbarque)
    {
        if ($preEmbarque->status !== 'abierto') {
            return response()->json([
                'status' => 'error',
                'message' => 'Este pre-embarque ya no está abierto',
            ], 422);
        }

        $validated = $request->validate([
            'qr_code' => 'required|string|max:36',
        ]);

        // Buscar pallet por pallet_qr_id
        $pallet = ProduccionEmpaque::where('pallet_qr_id', $validated['qr_code'])
            ->first();

        if (!$pallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró un pallet con ese código QR',
            ], 404);
        }

        // Validar que esté disponible
        if (!in_array($pallet->status, ['empacado', 'en_almacen'])) {
            return response()->json([
                'status' => 'error',
                'message' => "El pallet {$pallet->numero_pallet} tiene status '{$pallet->status}' y no está disponible para embarque",
            ], 422);
        }

        if (!$pallet->en_cuarto_frio) {
            return response()->json([
                'status' => 'error',
                'message' => "El pallet {$pallet->numero_pallet} no está en cuarto frío",
            ], 422);
        }

        // Verificar que no esté ya en este pre-embarque
        $yaExiste = $preEmbarque->detalles()->where('produccion_id', $pallet->id)->exists();
        if ($yaExiste) {
            return response()->json([
                'status' => 'error',
                'message' => "El pallet {$pallet->numero_pallet} ya está en este pre-embarque",
            ], 422);
        }

        // Verificar que no esté en otro pre-embarque abierto
        $enOtro = DB::table('pre_embarque_empaque_detalles')
            ->join('pre_embarques_empaque', 'pre_embarques_empaque.id', '=', 'pre_embarque_empaque_detalles.pre_embarque_id')
            ->where('pre_embarques_empaque.status', 'abierto')
            ->where('pre_embarque_empaque_detalles.produccion_id', $pallet->id)
            ->where('pre_embarques_empaque.id', '!=', $preEmbarque->id)
            ->whereNull('pre_embarques_empaque.deleted_at')
            ->exists();
        if ($enOtro) {
            return response()->json([
                'status' => 'error',
                'message' => "El pallet {$pallet->numero_pallet} ya está asignado en otro pre-embarque abierto",
            ], 422);
        }

        // Verificar espacio disponible
        if ($preEmbarque->isFull()) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pre-embarque está lleno, no hay más posiciones disponibles',
            ], 422);
        }

        // Asignar a siguiente posición
        $nextPos = $preEmbarque->nextPosition();

        $detalle = $preEmbarque->detalles()->create([
            'produccion_id' => $pallet->id,
            'posicion_carga' => $nextPos,
        ]);

        // Cargar relaciones del pallet para retornar
        $pallet->load([
            'proceso.productor:id,nombre,apellido',
            'proceso.lote:id,nombre,numero_lote',
            'proceso.etapa.variedad:id,nombre',
            'proceso.recepcion.salidaCampo.variedad:id,nombre',
            'variedad:id,nombre',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Pallet {$pallet->numero_pallet} asignado a posición {$nextPos}",
            'data' => [
                'detalle' => $detalle,
                'pallet' => $pallet,
                'posicion' => $nextPos,
                'total_escaneados' => $preEmbarque->detalles()->count(),
                'espacios_restantes' => $preEmbarque->espacios_caja - $preEmbarque->detalles()->count(),
            ],
        ]);
    }

    /**
     * Quitar un pallet del pre-embarque y reordenar posiciones.
     */
    public function removePallet(PreEmbarqueEmpaque $preEmbarque, $produccionId)
    {
        if ($preEmbarque->status !== 'abierto') {
            return response()->json([
                'status' => 'error',
                'message' => 'Este pre-embarque ya no está abierto',
            ], 422);
        }

        $detalle = $preEmbarque->detalles()->where('produccion_id', $produccionId)->first();
        if (!$detalle) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pallet no está en este pre-embarque',
            ], 404);
        }

        $removedPos = $detalle->posicion_carga;
        $detalle->delete();

        // Reordenar posiciones superiores
        $preEmbarque->detalles()
            ->where('posicion_carga', '>', $removedPos)
            ->orderBy('posicion_carga')
            ->get()
            ->each(function ($d) {
                $d->decrement('posicion_carga');
            });

        return response()->json([
            'success' => true,
            'message' => 'Pallet removido del pre-embarque',
            'data' => [
                'total_escaneados' => $preEmbarque->detalles()->count(),
            ],
        ]);
    }

    /**
     * Obtener datos del pre-embarque formateados para crear un embarque.
     */
    public function convertirDatos(PreEmbarqueEmpaque $preEmbarque)
    {
        if ($preEmbarque->detalles()->count() === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pre-embarque no tiene pallets escaneados',
            ], 422);
        }

        $preEmbarque->load([
            'detalles' => fn($q) => $q->orderBy('posicion_carga'),
            'detalles.produccion' => fn($q) => $q->with([
                'proceso.productor:id,nombre,apellido',
                'proceso.lote:id,nombre,numero_lote',
                'proceso.etapa.variedad:id,nombre',
                'proceso.recepcion.salidaCampo.variedad:id,nombre',
                'variedad:id,nombre',
            ]),
        ]);

        // Preparar pallets con posiciones para el formulario de embarque
        $pallets = $preEmbarque->detalles->map(fn($d) => [
            'id' => $d->produccion_id,
            'posicion_carga' => $d->posicion_carga,
            'pallet' => $d->produccion,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'pre_embarque_id' => $preEmbarque->id,
                'folio_pre_embarque' => $preEmbarque->folio_pre_embarque,
                'espacios_caja' => $preEmbarque->espacios_caja,
                'observaciones' => $preEmbarque->observaciones,
                'pallets' => $pallets,
            ],
        ]);
    }

    /**
     * Marcar pre-embarque como completado (ya se convirtió en embarque).
     */
    public function completar(Request $request, PreEmbarqueEmpaque $preEmbarque)
    {
        if ($preEmbarque->status !== 'abierto') {
            return response()->json([
                'status' => 'error',
                'message' => 'Este pre-embarque ya no está abierto',
            ], 422);
        }

        $preEmbarque->update(['status' => 'completado']);

        return response()->json([
            'success' => true,
            'message' => 'Pre-embarque marcado como completado',
        ]);
    }

    /**
     * Cancelar/eliminar un pre-embarque.
     */
    public function destroy(PreEmbarqueEmpaque $preEmbarque)
    {
        if ($preEmbarque->status === 'completado') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar un pre-embarque ya completado',
            ], 422);
        }

        $preEmbarque->detalles()->delete();
        $preEmbarque->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pre-embarque eliminado correctamente',
        ]);
    }
}
