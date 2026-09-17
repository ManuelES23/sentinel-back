<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Aplicacion;
use App\Models\AplicacionDetalle;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AplicacionController extends Controller
{
    public function __construct(private AlmacenAccessService $almacenes)
    {
    }

    private function reglaProducto(Request $request): \Illuminate\Validation\Rules\Exists
    {
        $empresa = $this->almacenes->resolverEmpresa($request);

        return Rule::exists('enterprise_product', 'product_id')->where('enterprise_id', $empresa->id);
    }

    /**
     * GET /aplicaciones?temporada_id=X&tipo_aplicacion=X&productor_id=X&fecha_inicio=X&fecha_fin=X&folio=X
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $query = Aplicacion::porTemporada($request->temporada_id)
            ->with([
                'productor:id,nombre,apellido',
                'zonaCultivo:id,nombre',
                'lote:id,nombre,numero_lote',
                'variedad:id,nombre',
                'detalles.producto:id,nombre,tipo',
                'detalles.product:id,name,ingrediente_activo,brand_id,unit_id',
                'detalles.product.brand:id,name',
                'createdBy:id,name',
            ])
            ->withCount('detalles');

        $query->when($request->filled('tipo_aplicacion'), function ($q) use ($request) {
            $q->where('tipo_aplicacion', $request->tipo_aplicacion);
        });

        $query->when($request->filled('productor_id'), function ($q) use ($request) {
            $q->where('productor_id', $request->productor_id);
        });

        $query->when($request->filled('lote_id'), function ($q) use ($request) {
            $q->where('lote_id', $request->lote_id);
        });

        $query->when($request->filled('fecha_inicio'), function ($q) use ($request) {
            $q->where('fecha', '>=', $request->fecha_inicio);
        });

        $query->when($request->filled('fecha_fin'), function ($q) use ($request) {
            $q->where('fecha', '<=', $request->fecha_fin);
        });

        $query->when($request->filled('folio'), function ($q) use ($request) {
            $q->where('folio', 'like', '%' . $request->folio . '%');
        });

        $perPage = (int) $request->get('per_page', 20);
        $aplicaciones = $query->orderByDesc('fecha')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $aplicaciones->items(),
            'meta' => [
                'total'        => $aplicaciones->total(),
                'per_page'     => $aplicaciones->perPage(),
                'current_page' => $aplicaciones->currentPage(),
                'last_page'    => $aplicaciones->lastPage(),
            ],
        ]);
    }

    /**
     * POST /aplicaciones
     */
    public function store(Request $request): JsonResponse
    {
        $temporadaId = $request->input('temporada_id');

        $validated = $request->validate([
            'temporada_id'       => 'required|exists:temporadas,id',
            'fecha'              => 'required|date',
            'autogenerar_folio'  => 'boolean',
            'folio'              => [
                'nullable', 'string', 'max:50',
                function ($attr, $value, $fail) use ($temporadaId) {
                    if ($value && Aplicacion::where('temporada_id', $temporadaId)->where('folio', $value)->exists()) {
                        $fail('El folio ya existe en esta temporada.');
                    }
                },
            ],
            'tipo_aplicacion'    => 'required|in:agroquimico,fertilizante',
            'productor_id'       => 'required|exists:productores,id',
            'zona_cultivo_id'    => 'nullable|exists:zonas_cultivo,id',
            'lote_id'            => 'nullable|exists:lotes,id',
            'variedad_id'        => 'nullable|exists:variedades,id',
            'superficie_aplicada'=> 'nullable|numeric|min:0.01',
            'metodo_aplicacion'  => 'nullable|string|max:150',
            'problematica'       => 'required|string',
            'observaciones'      => 'nullable|string',
            'productos'          => 'required|array|min:1',
            'productos.*.product_id' => ['required', 'integer', $this->reglaProducto($request)],
            'productos.*.dosis'       => 'required|numeric|min:0',
            'productos.*.unidad_medida' => 'required|string|max:50',
        ]);

        // Folio: autogenerar si se pide o no viene ninguno
        $folio = $validated['folio'] ?? null;
        if (empty($folio) || ($validated['autogenerar_folio'] ?? false)) {
            $folio = Aplicacion::generarFolio($validated['temporada_id']);
        }

        $productos = $validated['productos'];
        unset($validated['productos'], $validated['autogenerar_folio']);
        $validated['folio'] = $folio;
        $validated['created_by'] = Auth::id();

        $aplicacion = DB::transaction(function () use ($validated, $productos) {
            $aplicacion = Aplicacion::create($validated);

            foreach ($productos as $item) {
                AplicacionDetalle::create([
                    'aplicacion_id' => $aplicacion->id,
                    'product_id'    => $item['product_id'],
                    'dosis'         => $item['dosis'],
                    'unidad_medida' => $item['unidad_medida'],
                ]);
            }

            return $aplicacion;
        });

        $aplicacion->load([
            'productor:id,nombre,apellido',
            'zonaCultivo:id,nombre',
            'lote:id,nombre,numero_lote',
            'variedad:id,nombre',
            'detalles.producto:id,nombre,tipo',
            'detalles.product:id,name,ingrediente_activo,brand_id,unit_id',
            'detalles.product.brand:id,name',
            'createdBy:id,name',
        ]);
        $aplicacion->loadCount('detalles');

        return response()->json([
            'success' => true,
            'message' => 'Aplicación registrada correctamente.',
            'data'    => $aplicacion,
        ], 201);
    }

    /**
     * GET /aplicaciones/{id}
     */
    public function show(Aplicacion $aplicacion): JsonResponse
    {
        $aplicacion->load([
            'productor:id,nombre,apellido',
            'zonaCultivo:id,nombre',
            'lote:id,nombre,numero_lote',
            'variedad:id,nombre',
            'detalles.producto:id,nombre,ingrediente_activo,marca,tipo',
            'detalles.product:id,name,ingrediente_activo,brand_id,unit_id',
            'detalles.product.brand:id,name',
            'createdBy:id,name',
            'temporada:id,nombre,anio',
        ]);

        return response()->json(['success' => true, 'data' => $aplicacion]);
    }

    /**
     * PUT /aplicaciones/{id}
     */
    public function update(Request $request, Aplicacion $aplicacion): JsonResponse
    {
        $temporadaId = $aplicacion->temporada_id;

        $validated = $request->validate([
            'fecha'              => 'sometimes|date',
            'folio'              => [
                'nullable', 'string', 'max:50',
                function ($attr, $value, $fail) use ($aplicacion, $temporadaId) {
                    if ($value && Aplicacion::where('temporada_id', $temporadaId)->where('folio', $value)->where('id', '!=', $aplicacion->id)->exists()) {
                        $fail('El folio ya existe en esta temporada.');
                    }
                },
            ],
            'tipo_aplicacion'    => 'sometimes|in:agroquimico,fertilizante',
            'productor_id'       => 'sometimes|exists:productores,id',
            'zona_cultivo_id'    => 'nullable|exists:zonas_cultivo,id',
            'lote_id'            => 'nullable|exists:lotes,id',
            'variedad_id'        => 'nullable|exists:variedades,id',
            'superficie_aplicada'=> 'nullable|numeric|min:0.01',
            'metodo_aplicacion'  => 'nullable|string|max:150',
            'problematica'       => 'sometimes|string',
            'observaciones'      => 'nullable|string',
            'productos'          => 'sometimes|array|min:1',
            'productos.*.product_id' => ['required_with:productos', 'integer', $this->reglaProducto($request)],
            'productos.*.dosis'       => 'required_with:productos|numeric|min:0',
            'productos.*.unidad_medida' => 'required_with:productos|string|max:50',
        ]);

        $productos = $validated['productos'] ?? null;
        unset($validated['productos']);

        DB::transaction(function () use ($aplicacion, $validated, $productos) {
            $aplicacion->update($validated);

            if ($productos !== null) {
                AplicacionDetalle::where('aplicacion_id', $aplicacion->id)->delete();
                foreach ($productos as $item) {
                    AplicacionDetalle::create([
                        'aplicacion_id' => $aplicacion->id,
                        'product_id'    => $item['product_id'],
                        'dosis'         => $item['dosis'],
                        'unidad_medida' => $item['unidad_medida'],
                    ]);
                }
            }
        });

        $aplicacion->load([
            'productor:id,nombre,apellido',
            'zonaCultivo:id,nombre',
            'lote:id,nombre,numero_lote',
            'variedad:id,nombre',
            'detalles.producto:id,nombre,tipo',
            'detalles.product:id,name,ingrediente_activo,brand_id,unit_id',
            'detalles.product.brand:id,name',
            'createdBy:id,name',
        ]);
        $aplicacion->loadCount('detalles');

        return response()->json([
            'success' => true,
            'message' => 'Aplicación actualizada correctamente.',
            'data'    => $aplicacion,
        ]);
    }

    /**
     * DELETE /aplicaciones/{id}
     */
    public function destroy(Aplicacion $aplicacion): JsonResponse
    {
        $aplicacion->delete(); // soft delete; cascade eliminará detalles en hard delete

        return response()->json([
            'success' => true,
            'message' => 'Aplicación eliminada.',
        ]);
    }

    /**
     * GET /aplicaciones/folio-preview?temporada_id=X
     */
    public function folioPreview(Request $request): JsonResponse
    {
        $request->validate(['temporada_id' => 'required|exists:temporadas,id']);

        return response()->json([
            'success' => true,
            'folio'   => Aplicacion::generarFolio($request->temporada_id),
        ]);
    }
}
