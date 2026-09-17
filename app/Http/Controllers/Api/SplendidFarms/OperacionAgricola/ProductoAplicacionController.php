<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Product;
use App\Services\Inventory\CatalogoAgricolaService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Insumos para Aplicaciones. Desde la fase 1 de inventario agrícola son
 * artículos del catálogo (products) en las categorías Agroquímicos y
 * Fertilizantes; la respuesta conserva la forma del catálogo anterior.
 */
class ProductoAplicacionController extends Controller
{
    public function __construct(private CatalogoAgricolaService $catalogo)
    {
    }

    /**
     * GET /productos-aplicacion?search=X&tipo=X
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);
        $categorias = $request->filled('tipo') && isset(CatalogoAgricolaService::CATEGORIAS[$request->tipo])
            ? [CatalogoAgricolaService::CATEGORIAS[$request->tipo]]
            : array_values(CatalogoAgricolaService::CATEGORIAS);

        $query = Product::forEnterprise($empresa->id)
            ->with(['brand:id,name', 'category:id,name', 'unit:id,name,abbreviation'])
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->whereIn('name', $categorias));

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('ingrediente_activo', 'like', "%{$search}%")
                   ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        });

        $data = $query->orderBy('name')->get()->map(fn (Product $p) => $this->forma($p))->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /productos-aplicacion — registro rápido desde el modal de aplicaciones.
     */
    public function store(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);
        $validated = $request->validate([
            'nombre'             => 'required|string|max:200',
            'ingrediente_activo' => 'nullable|string|max:200',
            'marca'              => 'nullable|string|max:150',
            'tipo'               => 'required|in:agroquimico,fertilizante',
            'activo'             => 'boolean',
        ]);

        $producto = $this->catalogo->crearProducto($empresa, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto registrado correctamente.',
            'data'    => $this->forma($producto->load(['brand:id,name', 'category:id,name', 'unit:id,name,abbreviation'])),
        ], 201);
    }

    /**
     * PUT /productos-aplicacion/{producto}
     */
    public function update(Request $request, Product $producto): JsonResponse
    {
        $empresa = $this->empresa($request);
        if (! $producto->enterprises()->where('enterprises.id', $empresa->id)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Producto no encontrado'], 404);
        }

        $validated = $request->validate([
            'nombre'             => 'sometimes|string|max:200',
            'ingrediente_activo' => 'nullable|string|max:200',
            'marca'              => 'nullable|string|max:150',
            'activo'             => 'boolean',
        ]);

        $cambios = [];
        if (array_key_exists('nombre', $validated)) {
            $cambios['name'] = $validated['nombre'];
        }
        if (array_key_exists('ingrediente_activo', $validated)) {
            $cambios['ingrediente_activo'] = $validated['ingrediente_activo'];
        }
        if (array_key_exists('marca', $validated)) {
            $cambios['brand_id'] = $this->catalogo->marca($empresa, $validated['marca'])?->id;
        }
        if (array_key_exists('activo', $validated)) {
            $cambios['is_active'] = $validated['activo'];
        }
        $producto->update($cambios);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado.',
            'data'    => $this->forma($producto->fresh(['brand:id,name', 'category:id,name', 'unit:id,name,abbreviation'])),
        ]);
    }

    private function forma(Product $p): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->name,
            'ingrediente_activo' => $p->ingrediente_activo,
            'marca' => $p->brand?->name,
            'tipo' => $this->catalogo->tipoDe($p),
            'activo' => (bool) $p->is_active,
            'unidad' => $p->unit?->abbreviation,
            'requiere_revision' => (bool) $p->requiere_revision,
        ];
    }

    private function empresa(Request $request): Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');
        $empresa = $slug ? Enterprise::where('slug', $slug)->first() : null;

        if (! $empresa) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422));
        }

        return $empresa;
    }
}
