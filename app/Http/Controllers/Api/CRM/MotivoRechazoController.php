<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Motivos de rechazo dentro de una categoría (spec 2026-10-23 §4.5). Los
 * permisos son los del submódulo catalogos.categorias-rechazo. La lectura va
 * en GET crm/categorias-rechazo, que ya trae los motivos de cada categoría.
 */
class MotivoRechazoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MENSAJES = [
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'nombre.unique' => 'Ya existe un motivo con ese nombre en esta categoría.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 65535.',
        'activo.boolean' => 'El estado debe ser verdadero o falso.',
    ];

    /** POST /crm/categorias-rechazo/{categoria}/motivos */
    public function store(Request $request, CrmCategoriaRechazo $categoria): JsonResponse
    {
        $this->verificarEmpresaDeCategoria($categoria);
        $this->exigirPermisoSubmodulo((int) $categoria->empresa_id, 'catalogos', CategoriaRechazoController::SUBMODULO, 'crear', 'No tienes permiso para crear motivos de rechazo.');

        $validated = $request->validate($this->reglas((int) $categoria->id), self::MENSAJES);

        $motivo = CrmMotivoRechazo::create(['categoria_id' => $categoria->id] + $this->sinNulos($validated));

        return $this->jsonSuccess($motivo, 'Motivo creado correctamente', 201);
    }

    /** PUT|PATCH /crm/motivos-rechazo/{motivo} */
    public function update(Request $request, CrmMotivoRechazo $motivo): JsonResponse
    {
        $categoria = $this->categoriaDelMotivo($motivo);
        $this->exigirPermisoSubmodulo((int) $categoria->empresa_id, 'catalogos', CategoriaRechazoController::SUBMODULO, 'editar', 'No tienes permiso para editar motivos de rechazo.');

        $validated = $request->validate($this->reglas((int) $categoria->id, $motivo), self::MENSAJES);

        $motivo->update($this->sinNulos($validated));

        return $this->jsonSuccess($motivo->fresh(), 'Motivo actualizado correctamente');
    }

    /** DELETE /crm/motivos-rechazo/{motivo} — 409 si alguna oportunidad lo usa. */
    public function destroy(CrmMotivoRechazo $motivo): JsonResponse
    {
        $categoria = $this->categoriaDelMotivo($motivo);
        $this->exigirPermisoSubmodulo((int) $categoria->empresa_id, 'catalogos', CategoriaRechazoController::SUBMODULO, 'eliminar', 'No tienes permiso para eliminar motivos de rechazo.');

        // withTrashed: una oportunidad en la papelera también conserva su motivo.
        if ($motivo->oportunidades()->withTrashed()->exists()) {
            return $this->jsonError(CategoriaRechazoController::MENSAJE_MOTIVO_USADO, 409);
        }

        $motivo->delete();

        return $this->jsonSuccess(null, 'Motivo eliminado correctamente');
    }

    private function reglas(int $categoriaId, ?CrmMotivoRechazo $motivo = null): array
    {
        $unico = Rule::unique('crm_motivos_rechazo', 'nombre')->where('categoria_id', $categoriaId);
        if ($motivo) {
            $unico->ignore($motivo->id);
        }

        return [
            'nombre' => ['bail', ...($motivo ? ['sometimes', 'required'] : ['required']), 'string', 'max:100', $unico],
            'orden' => 'nullable|integer|min:0|max:65535',
            'activo' => 'nullable|boolean',
        ];
    }

    /** Un `orden` o `activo` nulo se ignora: queda el valor actual o el de la tabla. */
    private function sinNulos(array $validated): array
    {
        return array_filter($validated, fn ($valor) => $valor !== null);
    }

    /** Categoría del motivo, con 404 si no es de la empresa del request. */
    private function categoriaDelMotivo(CrmMotivoRechazo $motivo): CrmCategoriaRechazo
    {
        $categoria = $motivo->categoria;
        abort_unless($categoria, 404, 'Motivo no encontrado.');
        $this->verificarEmpresaDeCategoria($categoria, 'Motivo no encontrado.');

        return $categoria;
    }

    private function verificarEmpresaDeCategoria(CrmCategoriaRechazo $categoria, string $mensaje = 'Categoría no encontrada.'): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $categoria->empresa_id !== (int) $empresaId) {
            abort(404, $mensaje);
        }
    }
}
