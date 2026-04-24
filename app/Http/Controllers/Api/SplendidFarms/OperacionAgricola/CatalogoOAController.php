<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Variedad;
use App\Models\TipoVariedad;
use App\Models\EtapaFenologica;
use App\Models\Plaga;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogoOAController extends Controller
{
    /**
     * Listar variedades por cultivo (para cascada en etapas).
     * GET /operacion-agricola/agricola/catalogos/variedades?cultivo_id=X
     */
    public function variedades(Request $request): JsonResponse
    {
        $request->validate([
            'cultivo_id' => 'required|exists:cultivos,id',
        ]);

        $variedades = Variedad::where('cultivo_id', $request->cultivo_id)
            ->with('tiposVariedad:id,variedad_id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'cultivo_id', 'nombre', 'descripcion']);

        return response()->json([
            'success' => true,
            'data' => $variedades,
        ]);
    }

    /**
     * Listar tipos de variedad por variedad (para cascada en etapas).
     * GET /operacion-agricola/agricola/catalogos/tipos-variedad?variedad_id=X
     */
    public function tiposVariedad(Request $request): JsonResponse
    {
        $request->validate([
            'variedad_id' => 'required|exists:variedades,id',
        ]);

        $tipos = TipoVariedad::where('variedad_id', $request->variedad_id)
            ->orderBy('nombre')
            ->get(['id', 'variedad_id', 'nombre', 'descripcion']);

        return response()->json([
            'success' => true,
            'data' => $tipos,
        ]);
    }

    /**
     * Listar etapas fenológicas por cultivo.
     * GET /operacion-agricola/agricola/catalogos/etapas-fenologicas?cultivo_id=X
     */
    public function etapasFenologicas(Request $request): JsonResponse
    {
        $request->validate([
            'cultivo_id' => 'required|exists:cultivos,id',
        ]);

        $fases = EtapaFenologica::byCultivo($request->cultivo_id)
            ->active()
            ->ordered()
            ->get(['id', 'cultivo_id', 'nombre', 'orden', 'color']);

        return response()->json([
            'success' => true,
            'data' => $fases,
        ]);
    }

    /**
     * Listar plagas activas.
     * GET /operacion-agricola/agricola/catalogos/plagas
     */
    public function plagasCatalogo(Request $request): JsonResponse
    {
        $query = Plaga::active()->orderBy('tipo')->orderBy('nombre');

        if ($request->filled('cultivo_id')) {
            $query->byCultivo($request->cultivo_id);
        }

        if ($request->filled('tipo')) {
            $query->byTipo($request->tipo);
        }

        $plagas = $query->get([
            'id',
            'cultivo_id',
            'nombre',
            'abreviatura',
            'nombre_cientifico',
            'tipo',
        ]);

        return response()->json([
            'success' => true,
            'data' => $plagas,
        ]);
    }

    /**
     * Buscar productos del inventario (para recomendaciones).
     * GET /operacion-agricola/agricola/catalogos/productos?search=X&category_id=X
     */
    public function productos(Request $request): JsonResponse
    {
        $query = Product::active()
            ->with('unit:id,name,abbreviation');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->inCategory($request->category_id);
        }

        $productos = $query->orderBy('name')
            ->limit(50)
            ->get(['id', 'code', 'name', 'unit_id', 'cost_price']);

        return response()->json([
            'success' => true,
            'data' => $productos,
        ]);
    }

    /**
     * Listar unidades de medida activas.
     * GET /operacion-agricola/agricola/catalogos/unidades
     */
    public function unidades(): JsonResponse
    {
        $unidades = UnitOfMeasure::where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'abbreviation', 'type']);

        return response()->json([
            'success' => true,
            'data' => $unidades,
        ]);
    }
}
