<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Calibres de un producto del catálogo para la cotización por caja (spec
 * 2026-10-31). Los agrícolas vienen de la variedad ligada al producto y solo se
 * leen; aquí se administran únicamente los propios. La lectura es abierta dentro
 * de la empresa; escribir exige catalogos.productos crear|editar|eliminar.
 * Los renglones guardados llevan el calibre como texto: borrar no los toca.
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

    /** GET /crm/productos/{producto}/calibres — agrícolas (solo nombres) y propios. */
    public function index(Request $request, CrmProducto $producto): JsonResponse
    {
        $this->verificarEmpresa($request, $producto);

        $agricolas = $producto->variedad_id
            ? Calibre::query()
                ->where('variedad_id', $producto->variedad_id)
                ->where('is_active', true)
                ->orderBy('order')->orderBy('nombre')
                ->pluck('nombre')->values()->all()
            : [];

        $propios = $producto->calibresPropios()
            ->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'nombre', 'orden', 'activo']);

        return $this->jsonSuccess(['agricolas' => $agricolas, 'propios' => $propios]);
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
