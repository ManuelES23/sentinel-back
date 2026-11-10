<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ProductoUpdated;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmSublinea;
use App\Services\CRM\OpcionesCotizacionService;
use App\Support\CRM\NombreCatalogo;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    private const COLUMNAS_LISTA = [
        'id', 'nombre', 'precio', 'unidad_medida', 'commodity', 'activo',
        'clave', 'marca_id', 'linea_id', 'sublinea_id',
    ];

    /** Relaciones de clasificación, con columnas mínimas. */
    private const RELACIONES = ['marca:id,nombre', 'linea:id,nombre', 'sublinea:id,nombre'];

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
                        ->orWhere('descripcion', 'like', "%{$search}%")
                        ->orWhere('clave', 'like', "%{$search}%");
                });
            })
            ->tap(fn ($q) => $this->filtrarClasificacion($q, $request))
            ->with(self::RELACIONES)
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
     * Typeahead para oportunidades y cotizaciones: máximo 20 productos activos (por nombre o clave).
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
                        ->orWhere('descripcion', 'like', "%{$term}%")
                        ->orWhere('clave', 'like', "%{$term}%");
                });
            })
            ->tap(fn ($q) => $this->filtrarClasificacion($q, $request))
            ->with(self::RELACIONES)
            ->orderBy('nombre')
            ->limit(20)
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
     * GET /crm/productos/opciones-cotizacion
     * Variedades, calibres y presentaciones que ofrece el formulario de cotización por caja.
     * Lectura abierta a cualquier usuario de la empresa.
     */
    public function opcionesCotizacion(Request $request, OpcionesCotizacionService $opciones): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        return $this->jsonSuccess($opciones->paraEmpresa($empresaId));
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

        // Con el catálogo por líneas el precio es opcional (se cotiza por renglón).
        $precioOpcional = CrmConfiguracionComercial::catalogoPorLineas((int) $empresaId);

        $validated = $request->validate([
            'nombre'        => 'required|string|max:255',
            'descripcion'   => 'nullable|string',
            'precio'        => $precioOpcional ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'unidad_medida' => ['required', Rule::in(self::UNIDADES)],
            'commodity'     => 'nullable|string|max:100',
            'activo'        => 'nullable|boolean',
        ] + $this->reglasClasificacion((int) $empresaId));

        $this->validarClave($validated['clave'] ?? null, (int) $empresaId);
        $clasificacion = $this->resolverClasificacion($validated, null);

        $producto = CrmProducto::create([
            'empresa_id'    => $empresaId,
            'nombre'        => $validated['nombre'],
            'descripcion'   => $validated['descripcion'] ?? null,
            'precio'        => $validated['precio'] ?? 0,
            'unidad_medida' => $validated['unidad_medida'],
            'commodity'     => $this->normalizarCommodity($validated['commodity'] ?? null),
            'activo'        => $validated['activo'] ?? true,
            'clave'         => $validated['clave'] ?? null,
        ] + $clasificacion);

        $this->difundir(new ProductoUpdated('created', $producto->toArray()));

        return $this->jsonSuccess($producto->load(self::RELACIONES), 'Producto creado correctamente', 201);
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
        ] + $this->reglasClasificacion((int) $producto->empresa_id));

        if (array_key_exists('commodity', $validated)) {
            $validated['commodity'] = $this->normalizarCommodity($validated['commodity']);
        }

        if (array_key_exists('clave', $validated)) {
            $this->validarClave($validated['clave'], (int) $producto->empresa_id, $producto->id);
        }

        $producto->update($this->resolverClasificacion($validated, $producto) + $validated);

        $this->difundir(new ProductoUpdated('updated', $producto->toArray()));

        return $this->jsonSuccess($producto->load(self::RELACIONES), 'Producto actualizado correctamente');
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

    /** Filtros opcionales marca_id / linea_id / sublinea_id del listado y del buscador. */
    private function filtrarClasificacion($query, Request $request): void
    {
        foreach (['marca_id', 'linea_id', 'sublinea_id'] as $campo) {
            if ($request->filled($campo)) {
                $query->where($campo, (int) $request->query($campo));
            }
        }
    }

    /** Reglas de clave y clasificación: marca, línea y sublínea deben ser de la empresa. */
    private function reglasClasificacion(int $empresaId): array
    {
        return [
            'clave'       => 'sometimes|nullable|string|max:60',
            'marca_id'    => ['sometimes', 'nullable', 'integer', Rule::exists('crm_marcas', 'id')->where('empresa_id', $empresaId)],
            'linea_id'    => ['sometimes', 'nullable', 'integer', Rule::exists('crm_lineas', 'id')->where('empresa_id', $empresaId)],
            'sublinea_id' => ['sometimes', 'nullable', 'integer', Rule::exists('crm_sublineas', 'id')
                ->whereIn('linea_id', CrmLinea::where('empresa_id', $empresaId)->select('id'))],
        ];
    }

    /** La clave no se repite en la empresa (sin distinguir mayúsculas, acentos ni espacios). */
    private function validarClave(?string $clave, int $empresaId, ?int $ignorarId = null): void
    {
        $normalizada = NombreCatalogo::clave($clave);
        if ($normalizada === '') {
            return;
        }

        $existe = CrmProducto::where('empresa_id', $empresaId)
            ->where('clave_normalizada', $normalizada)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['clave' => 'Ya existe un producto con esa clave.']);
        }
    }

    /**
     * Campos marca_id/linea_id/sublinea_id a guardar (solo los enviados).
     * La sublínea debe pertenecer a la línea; si no llega línea se toma la de la sublínea.
     * Cambiar de línea sin enviar sublínea limpia la sublínea anterior.
     */
    private function resolverClasificacion(array $validated, ?CrmProducto $actual): array
    {
        $salida = [];
        if (array_key_exists('marca_id', $validated)) {
            $salida['marca_id'] = $validated['marca_id'];
        }

        $tocaLinea = array_key_exists('linea_id', $validated);
        $tocaSub = array_key_exists('sublinea_id', $validated);
        if (! $tocaLinea && ! $tocaSub) {
            return $salida;
        }

        $lineaId = $tocaLinea ? $validated['linea_id'] : $actual?->linea_id;
        $subId = $tocaSub ? $validated['sublinea_id'] : $actual?->sublinea_id;

        if ($subId) {
            $sub = CrmSublinea::find($subId);
            if ($tocaSub) {
                if ($lineaId && (int) $sub->linea_id !== (int) $lineaId) {
                    throw ValidationException::withMessages(['sublinea_id' => 'La sublínea no pertenece a la línea elegida.']);
                }
                $lineaId = $sub->linea_id;
            } elseif ((int) $sub->linea_id !== (int) $lineaId) {
                // Cambió la línea sin tocar la sublínea: la anterior ya no aplica.
                $subId = null;
            }
        }

        $salida['linea_id'] = $lineaId;
        $salida['sublinea_id'] = $subId;

        return $salida;
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
