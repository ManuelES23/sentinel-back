<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Plaga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlagaController extends Controller
{
    /**
     * Listar plagas. Filtro opcional por tipo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Plaga::query();

        if ($request->filled('cultivo_id')) {
            $query->byCultivo($request->cultivo_id);
        }

        if ($request->filled('tipo')) {
            $query->byTipo($request->tipo);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $plagas = $query->orderBy('tipo')->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'data' => $plagas,
        ]);
    }

    /**
     * Crear plaga.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'nombre' => 'required|string|max:150',
            'abreviatura' => 'nullable|string|max:15',
            'nombre_cientifico' => 'nullable|string|max:200',
            'tipo' => 'required|in:insecto,hongo,bacteria,maleza,virus,nematodo,otro',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['abreviatura'] = $this->resolveAbreviatura($validated['abreviatura'] ?? null, $validated['nombre']);

        $plaga = Plaga::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plaga registrada exitosamente.',
            'data' => $plaga,
        ], 201);
    }

    /**
     * Ver detalle de plaga.
     */
    public function show(Plaga $plaga): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $plaga,
        ]);
    }

    /**
     * Actualizar plaga.
     */
    public function update(Request $request, Plaga $plaga): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_id' => 'nullable|exists:cultivos,id',
            'nombre' => 'sometimes|required|string|max:150',
            'abreviatura' => 'nullable|string|max:15',
            'nombre_cientifico' => 'nullable|string|max:200',
            'tipo' => 'sometimes|required|in:insecto,hongo,bacteria,maleza,virus,nematodo,otro',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('abreviatura', $validated)) {
            $validated['abreviatura'] = $this->resolveAbreviatura($validated['abreviatura'], $validated['nombre'] ?? $plaga->nombre);
        }

        $plaga->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plaga actualizada exitosamente.',
            'data' => $plaga->fresh(),
        ]);
    }

    /**
     * Eliminar plaga.
     */
    public function destroy(Plaga $plaga): JsonResponse
    {
        $plaga->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plaga eliminada exitosamente.',
        ]);
    }

    /**
     * Genera una abreviatura a partir del nombre cuando no se especifica una.
     * Toma primeras letras de cada palabra en mayusculas (maximo 6 caracteres).
     */
    private function resolveAbreviatura(?string $provided, string $nombre): string
    {
        $provided = trim((string) $provided);
        if ($provided !== '') {
            return mb_strtoupper($provided);
        }

        $palabras = preg_split('/\s+/u', trim($nombre));
        if (count($palabras) > 1) {
            $letras = array_map(fn($p) => mb_substr($p, 0, 1), $palabras);
            $abre = mb_strtoupper(implode('', $letras));
        } else {
            $abre = mb_strtoupper(mb_substr($nombre, 0, 4));
        }

        return mb_substr($abre, 0, 6);
    }
}
