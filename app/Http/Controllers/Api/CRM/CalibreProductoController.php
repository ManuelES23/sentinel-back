<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use App\Support\CRM\UsoEnAbastecimiento;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Calibres de un producto del catálogo para la cotización por caja (spec
 * 2026-10-31). Desde 2026-11-16 todos son propios del producto: los que antes se
 * leían en vivo de Splendid Farms se copiaron una vez (migración
 * `2026_11_16_120000_calibres_desde_productos_crm`) y `agricolas` siempre va vacío.
 * La lectura es abierta dentro de la empresa; escribir exige catalogos.productos
 * crear|editar|eliminar. Los renglones de cotización llevan el calibre como texto:
 * borrar no los toca; un calibre usado en Abastecimiento no se borra (409).
 */
class CalibreProductoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MENSAJES = [
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'nombre.unique' => 'Este producto ya tiene un calibre con ese nombre.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 32767.',
        'activo.boolean' => 'El estado debe ser verdadero o falso.',
    ];

    /** GET /crm/productos/{producto}/calibres — `propios` (y `agricolas` vacío, por compatibilidad). */
    public function index(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);

        $propios = $producto->calibresPropios()
            ->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'nombre', 'orden', 'activo']);

        // gricolas queda vacío: los calibres ya no se leen de Splendid Farms (se copiaron como propios).
        return $this->jsonSuccess(['agricolas' => [], 'propios' => $propios]);
    }

    /** POST /crm/productos/{producto}/calibres */
    public function store(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);
        $this->exigirPermisoSubmodulo((int) $producto->empresa_id, 'catalogos', 'productos', 'crear', 'No tienes permiso para crear calibres.');

        $validated = $request->validate($this->reglas($request, $producto), self::MENSAJES);

        $calibre = $producto->calibresPropios()->create($this->sinNulos($validated));

        return $this->jsonSuccess($this->presentar($calibre->fresh()), 'Calibre creado correctamente', 201);
    }

    /** PUT|PATCH /crm/productos/{producto}/calibres/{calibre} */
    public function update(Request $request, CrmProducto $producto, CrmProductoCalibre $calibre): JsonResponse
    {
        $this->verificarCalibre($request, $producto, $calibre);
        $this->exigirPermisoSubmodulo((int) $producto->empresa_id, 'catalogos', 'productos', 'editar', 'No tienes permiso para editar calibres.');

        $validated = $request->validate($this->reglas($request, $producto, $calibre), self::MENSAJES);

        $calibre->update($this->sinNulos($validated));

        return $this->jsonSuccess($this->presentar($calibre->fresh()), 'Calibre actualizado correctamente');
    }

    /** DELETE /crm/productos/{producto}/calibres/{calibre} */
    public function destroy(Request $request, CrmProducto $producto, CrmProductoCalibre $calibre): JsonResponse
    {
        $this->verificarCalibre($request, $producto, $calibre);
        $this->exigirPermisoSubmodulo((int) $producto->empresa_id, 'catalogos', 'productos', 'eliminar', 'No tienes permiso para eliminar calibres.');

        if (UsoEnAbastecimiento::calibre($calibre->id)) {
            return $this->jsonError('Este calibre ya se usa en Abastecimiento: desactívalo en lugar de eliminarlo.', 409);
        }

        $calibre->delete();

        return $this->jsonSuccess(null, 'Calibre eliminado correctamente');
    }

    private function reglas(Request $request, CrmProducto $producto, ?CrmProductoCalibre $calibre = null): array
    {
        // El nombre se recorta antes de validar para que la unicidad compare el texto real.
        // Un valor que no es texto (p. ej. un arreglo) se deja tal cual: la regla `string` lo rechaza con 422.
        if (is_string($request->input('nombre'))) {
            $request->merge(['nombre' => trim($request->input('nombre'))]);
        }

        $unico = Rule::unique('crm_producto_calibres', 'nombre')->where('producto_id', $producto->id);
        if ($calibre) {
            $unico->ignore($calibre->id);
        }

        return [
            'nombre' => ['bail', ...($calibre ? ['sometimes', 'required'] : ['required']), 'string', 'max:100', $unico],
            'orden' => 'nullable|integer|min:0|max:32767',
            'activo' => 'nullable|boolean',
        ];
    }

    /** Un `orden` o `activo` nulo se ignora: queda el valor actual o el de la tabla. */
    private function sinNulos(array $validated): array
    {
        return array_filter($validated, fn ($valor) => $valor !== null);
    }

    private function presentar(CrmProductoCalibre $calibre): array
    {
        return $calibre->only(['id', 'nombre', 'orden', 'activo']);
    }

    /** 404 si el producto no es de la empresa del request. */
    private function verificarEmpresa(Request $request, CrmProducto $producto): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $producto->empresa_id !== (int) $empresaId) {
            abort(404, 'Producto no encontrado');
        }
    }

    /** 404 si el producto es ajeno o el calibre pertenece a otro producto. */
    private function verificarCalibre(Request $request, CrmProducto $producto, CrmProductoCalibre $calibre): void
    {
        $this->verificarEmpresa($request, $producto);

        if ((int) $calibre->producto_id !== (int) $producto->id) {
            abort(404, 'Calibre no encontrado');
        }
    }
}
