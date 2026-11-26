<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\DestinoEmbarque;
use App\Models\Planeacion\BodegaDestino;
use App\Services\Planeacion\ClientesPlaneacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de destinos de los embarques de Empaque. Se da de alta desde el modal
 * del embarque (botón «+» junto al selector). La liga con una bodega de Planeación
 * es opcional; sin ella el embarque cuenta en «Avance de embarques» solo si el
 * nombre del destino coincide con el de una bodega.
 */
class DestinoEmbarqueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['enterprise_id' => 'required|exists:enterprises,id']);

        $destinos = DestinoEmbarque::with('bodegaPlaneacion:id,nombre,is_active')
            ->where('enterprise_id', $request->integer('enterprise_id'))
            ->when(! $request->boolean('incluir_inactivos'), fn ($q) => $q->active())
            ->orderBy('nombre')
            ->get()
            ->map(fn (DestinoEmbarque $d) => $this->formato($d));

        return response()->json(['success' => true, 'data' => $destinos]);
    }

    /** Bodegas de Planeación (activas) con las que se puede ligar un destino. */
    public function bodegasPlaneacion(): JsonResponse
    {
        $bodegas = BodegaDestino::where('is_active', true)
            ->orderBy('orden')->orderBy('id')
            ->get(['id', 'nombre']);

        return response()->json(['success' => true, 'data' => $bodegas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'nombre' => 'required|string|max:150',
            'plan_bodega_destino_id' => 'nullable|integer|exists:plan_bodegas_destino,id',
        ], $this->mensajes());

        $validated['nombre'] = ClientesPlaneacion::limpiar($validated['nombre']);
        $this->validarNombreUnico((int) $validated['enterprise_id'], $validated['nombre']);

        $destino = DestinoEmbarque::create($validated + [
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Destino creado',
            'data' => $this->formato($destino->load('bodegaPlaneacion:id,nombre,is_active')),
        ], 201);
    }

    public function update(Request $request, DestinoEmbarque $destino): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:150',
            'plan_bodega_destino_id' => 'sometimes|nullable|integer|exists:plan_bodegas_destino,id',
            'is_active' => 'sometimes|boolean',
        ], $this->mensajes());

        if (array_key_exists('nombre', $validated)) {
            $validated['nombre'] = ClientesPlaneacion::limpiar($validated['nombre']);
            $this->validarNombreUnico((int) $destino->enterprise_id, $validated['nombre'], $destino->id);
        }

        $destino->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Destino actualizado',
            'data' => $this->formato($destino->fresh()->load('bodegaPlaneacion:id,nombre,is_active')),
        ]);
    }

    private function formato(DestinoEmbarque $d): array
    {
        return [
            'id' => $d->id,
            'nombre' => $d->nombre,
            'is_active' => (bool) $d->is_active,
            'plan_bodega_destino_id' => $d->plan_bodega_destino_id,
            'bodega_planeacion' => $d->bodegaPlaneacion?->nombre,
        ];
    }

    /** El nombre se compara sin mayúsculas, acentos ni espacios repetidos (como Planeación). */
    private function validarNombreUnico(int $enterpriseId, string $nombre, ?int $exceptoId = null): void
    {
        $clave = ClientesPlaneacion::clave($nombre);
        if ($clave === '') {
            throw ValidationException::withMessages(['nombre' => 'Escribe el nombre del destino.']);
        }

        $repetido = DestinoEmbarque::where('enterprise_id', $enterpriseId)
            ->when($exceptoId !== null, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->pluck('nombre')
            ->contains(fn ($n) => ClientesPlaneacion::clave((string) $n) === $clave);

        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un destino con ese nombre.']);
        }
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del destino.',
            'nombre.max' => 'El nombre no puede pasar de 150 caracteres.',
            'plan_bodega_destino_id.exists' => 'Ese destino de Planeación no existe.',
        ];
    }
}
