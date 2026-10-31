<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ProductoUpdated;
use App\Models\CRM\CrmProducto;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ProductoController
 * Catálogo de productos/servicios del CRM utilizados en oportunidades.
 * Multi-tenant por empresa_id.
 */
class ProductoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /** Unidades de medida permitidas. */
    private const UNIDADES = ['pieza', 'kg', 'litro', 'servicio', 'hora', 'caja'];

    /** Columnas que devuelven la lista simple (all=1) y el buscador. */
    private const COLUMNAS_LISTA = ['id', 'nombre', 'precio', 'unidad_medida', 'commodity', 'activo'];

    /**
     * GET /crm/productos
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $query = CrmProducto::query()
            ->where('empresa_id', $empresaId)
            ->when($request->has('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->when($request->unidad_medida, fn ($q, $u) => $q->where('unidad_medida', $u))
            ->when($request->filled('commodity'), fn ($q) => $q->where('commodity', trim((string) $request->query('commodity'))))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nombre', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%");
                });
            })
            ->orderBy('nombre');

        // Modo lista simple para selects (sin paginación): sólo activos.
        if ($request->boolean('all')) {
            return $this->jsonSuccess(
                $query->clone()->where('activo', true)->get(self::COLUMNAS_LISTA)
            );
        }

        $perPage = $this->perPage($request, 50);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /**
     * GET /crm/productos/buscar?q=
     * Typeahead para oportunidades: devuelve máximo 10 productos activos.
     */
    public function buscar(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $term = trim((string) $request->query('q', ''));

        $productos = CrmProducto::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('nombre', 'like', "%{$term}%")
                        ->orWhere('descripcion', 'like', "%{$term}%");
                });
            })
            ->orderBy('nombre')
            ->limit(10)
            ->get(self::COLUMNAS_LISTA);

        return $this->jsonSuccess($productos);
    }

    /**
     * GET /crm/productos/commodities
     * Commodities distintos (no vacíos) de los productos de la empresa, para el autocompletado.
     */
    public function commodities(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $commodities = CrmProducto::commoditiesDe($empresaId);

        return $this->jsonSuccess($commodities);
    }

    /**
     * GET /crm/productos/{producto}
     */
    public function show(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);

        return $this->jsonSuccess($producto);
    }

    /**
     * POST /crm/productos
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'productos', 'crear', 'No tienes permiso para crear productos.');

        $validated = $request->validate([
            'nombre'        => 'required|string|max:255',
            'descripcion'   => 'nullable|string',
            'precio'        => 'required|numeric|min:0',
            'unidad_medida' => ['required', Rule::in(self::UNIDADES)],
            'commodity'     => 'nullable|string|max:100',
            'activo'        => 'nullable|boolean',
        ]);

        $producto = CrmProducto::create([
            'empresa_id'    => $empresaId,
            'nombre'        => $validated['nombre'],
            'descripcion'   => $validated['descripcion'] ?? null,
            'precio'        => $validated['precio'],
            'unidad_medida' => $validated['unidad_medida'],
            'commodity'     => $this->normalizarCommodity($validated['commodity'] ?? null),
            'activo'        => $validated['activo'] ?? true,
        ]);

        $this->difundir(new ProductoUpdated('created', $producto->toArray()));

        return $this->jsonSuccess($producto, 'Producto creado correctamente', 201);
    }

    /**
     * PUT/PATCH /crm/productos/{producto}
     */
    public function update(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'productos', 'editar', 'No tienes permiso para editar productos.');

        $validated = $request->validate([
            'nombre'        => 'sometimes|required|string|max:255',
            'descripcion'   => 'sometimes|nullable|string',
            'precio'        => 'sometimes|required|numeric|min:0',
            'unidad_medida' => ['sometimes', 'required', Rule::in(self::UNIDADES)],
            'commodity'     => 'sometimes|nullable|string|max:100',
            'activo'        => 'sometimes|boolean',
        ]);

        if (array_key_exists('commodity', $validated)) {
            $validated['commodity'] = $this->normalizarCommodity($validated['commodity']);
        }

        $producto->update($validated);

        $this->difundir(new ProductoUpdated('updated', $producto->toArray()));

        return $this->jsonSuccess($producto, 'Producto actualizado correctamente');
    }

    /**
     * PATCH /crm/productos/{producto}/toggle-activo
     */
    public function toggleActivo(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'productos', 'editar', 'No tienes permiso para editar productos.');

        $producto->update(['activo' => ! $producto->activo]);

        $this->difundir(new ProductoUpdated('updated', $producto->toArray()));

        return $this->jsonSuccess($producto, 'Estado del producto actualizado correctamente');
    }

    /**
     * DELETE /crm/productos/{producto}
     */
    public function destroy(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);
        $this->exigirPermisoSubmodulo($this->getEmpresaId(), 'catalogos', 'productos', 'eliminar', 'No tienes permiso para eliminar productos.');

        if ($producto->oportunidadProductos()->exists()) {
            return $this->jsonError('No se puede eliminar el producto porque está asociado a oportunidades.', 409);
        }

        $data = $producto->toArray();
        $producto->delete();

        $this->difundir(new ProductoUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Producto eliminado correctamente');
    }

    /** Texto recortado; vacío equivale a sin commodity. */
    private function normalizarCommodity(?string $commodity): ?string
    {
        $commodity = trim((string) $commodity);

        return $commodity === '' ? null : $commodity;
    }

    /**
     * Aborta con 404 si el producto no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmProducto $producto): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $producto->empresa_id !== (int) $empresaId) {
            abort(404, 'Producto no encontrado');
        }
    }
}
