<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmPresentacion;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Presentaciones de empaque por empresa y commodity para la cotización por caja
 * (spec 2026-10-31). La lectura es abierta dentro de la empresa; escribir exige
 * catalogos.productos crear|editar|eliminar. Los renglones guardados llevan la
 * presentación como texto: borrar una no los toca.
 */
class PresentacionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MENSAJES = [
        'commodity.required' => 'El commodity es requerido.',
        'commodity.max' => 'El commodity no puede pasar de 100 caracteres.',
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 150 caracteres.',
        'nombre.unique' => 'Ya existe una presentación con ese nombre para este commodity.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 32767.',
        'activo.boolean' => 'El estado debe ser verdadero o falso.',
    ];

    /** GET /crm/presentaciones — `?commodity=` filtra; sin él, todas las de la empresa. */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $presentaciones = CrmPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->when(is_string($request->query('commodity')) && trim($request->query('commodity')) !== '',
                fn ($q) => $q->where('commodity', trim($request->query('commodity'))))
            ->orderBy('commodity')->orderBy('orden')->orderBy('nombre')
            ->get();

        return $this->jsonSuccess($presentaciones);
    }

    /** POST /crm/presentaciones */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', 'productos', 'crear', 'No tienes permiso para crear presentaciones.');

        $validated = $request->validate($this->reglas($request, (int) $empresaId), self::MENSAJES);

        $presentacion = CrmPresentacion::create(['empresa_id' => $empresaId] + $this->sinNulos($validated));

        return $this->jsonSuccess($presentacion->fresh(), 'Presentación creada correctamente', 201);
    }

    /** PUT|PATCH /crm/presentaciones/{presentacion} */
    public function update(Request $request, CrmPresentacion $presentacion): JsonResponse
    {
        $this->verificarEmpresa($request, $presentacion);
        $this->exigirPermisoSubmodulo((int) $presentacion->empresa_id, 'catalogos', 'productos', 'editar', 'No tienes permiso para editar presentaciones.');

        $validated = $request->validate($this->reglas($request, (int) $presentacion->empresa_id, $presentacion), self::MENSAJES);

        $presentacion->update($this->sinNulos($validated));

        return $this->jsonSuccess($presentacion->fresh(), 'Presentación actualizada correctamente');
    }

    /** DELETE /crm/presentaciones/{presentacion} */
    public function destroy(Request $request, CrmPresentacion $presentacion): JsonResponse
    {
        $this->verificarEmpresa($request, $presentacion);
        $this->exigirPermisoSubmodulo((int) $presentacion->empresa_id, 'catalogos', 'productos', 'eliminar', 'No tienes permiso para eliminar presentaciones.');

        $presentacion->delete();

        return $this->jsonSuccess(null, 'Presentación eliminada correctamente');
    }

    private function reglas(Request $request, int $empresaId, ?CrmPresentacion $presentacion = null): array
    {
        // Commodity y nombre se recortan antes de validar para que la unicidad compare el texto real.
        // Un valor que no es texto (p. ej. un arreglo) se deja tal cual: la regla `string` lo rechaza con 422.
        foreach (['commodity', 'nombre'] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => trim($request->input($campo))]);
            }
        }

        // Al editar sin mandar nombre vale el actual, para que la unicidad corra siempre
        // (mover una presentación de commodity no debe chocar con el índice único en BD).
        if ($presentacion && ! $request->has('nombre')) {
            $request->merge(['nombre' => $presentacion->nombre]);
        }

        // La unicidad es por empresa + commodity; al editar sin mandar commodity vale el actual.
        $commodity = $request->has('commodity') ? $request->input('commodity') : $presentacion?->commodity;
        $commodity = is_string($commodity) ? $commodity : '';

        $unico = Rule::unique('crm_presentaciones', 'nombre')
            ->where('empresa_id', $empresaId)
            ->where('commodity', $commodity);
        if ($presentacion) {
            $unico->ignore($presentacion->id);
        }

        $requerido = $presentacion ? ['sometimes', 'required'] : ['required'];

        return [
            'commodity' => ['bail', ...$requerido, 'string', 'max:100'],
            'nombre' => ['bail', ...$requerido, 'string', 'max:150', $unico],
            'orden' => 'nullable|integer|min:0|max:32767',
            'activo' => 'nullable|boolean',
        ];
    }

    /** Un `orden` o `activo` nulo se ignora: queda el valor actual o el de la tabla. */
    private function sinNulos(array $validated): array
    {
        return array_filter($validated, fn ($valor) => $valor !== null);
    }

    /** 404 si la presentación no pertenece a la empresa del request. */
    private function verificarEmpresa(Request $request, CrmPresentacion $presentacion): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $presentacion->empresa_id !== (int) $empresaId) {
            abort(404, 'Presentación no encontrada');
        }
    }
}
