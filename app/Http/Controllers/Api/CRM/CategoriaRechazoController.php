<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categorías de rechazo del CRM, por empresa (spec 2026-10-23 §4.5, molde
 * PlantillaWhatsappController). La lectura es abierta dentro del tenant: el
 * modal de pérdida las carga sin que el vendedor tenga el menú de catálogos.
 * Escribir exige catalogos.categorias-rechazo crear|editar|eliminar. Los
 * motivos los maneja MotivoRechazoController con los mismos permisos.
 */
class CategoriaRechazoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public const SUBMODULO = 'categorias-rechazo';

    public const MENSAJE_MOTIVO_USADO = 'Este motivo ya se usó en oportunidades; desactívalo en lugar de borrarlo.';

    private const MENSAJES = [
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'nombre.unique' => 'Ya existe una categoría con ese nombre.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 65535.',
        'activo.boolean' => 'El estado debe ser verdadero o falso.',
    ];

    /**
     * GET /crm/categorias-rechazo — `?activas=1`: solo categorías activas con
     * sus motivos activos, sin las que quedan vacías (lo utilizable en el modal
     * de pérdida). Sin el parámetro: todas con todos sus motivos (el catálogo).
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $query = CrmCategoriaRechazo::query()
            ->where('empresa_id', $empresaId)
            ->orderBy('orden')
            ->orderBy('id');

        if ($request->boolean('activas')) {
            $query->where('activo', true)
                ->whereHas('motivos', fn ($q) => $q->where('activo', true))
                ->with(['motivos' => fn ($q) => $q->where('activo', true)]);
        } else {
            $query->with('motivos');
        }

        return $this->jsonSuccess($query->get());
    }

    /** POST /crm/categorias-rechazo */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', self::SUBMODULO, 'crear', 'No tienes permiso para crear categorías de rechazo.');

        $validated = $request->validate($this->reglas((int) $empresaId), self::MENSAJES);

        $categoria = CrmCategoriaRechazo::create(['empresa_id' => $empresaId] + $this->sinNulos($validated));

        return $this->jsonSuccess($categoria->load('motivos'), 'Categoría creada correctamente', 201);
    }

    /** PUT|PATCH /crm/categorias-rechazo/{categoria} */
    public function update(Request $request, CrmCategoriaRechazo $categoria): JsonResponse
    {
        $this->verificarEmpresa($categoria);
        $this->exigirPermisoSubmodulo((int) $categoria->empresa_id, 'catalogos', self::SUBMODULO, 'editar', 'No tienes permiso para editar categorías de rechazo.');

        $validated = $request->validate($this->reglas((int) $categoria->empresa_id, $categoria), self::MENSAJES);

        $categoria->update($this->sinNulos($validated));

        return $this->jsonSuccess($categoria->fresh('motivos'), 'Categoría actualizada correctamente');
    }

    /** DELETE /crm/categorias-rechazo/{categoria} — 409 si algún motivo ya se usó. */
    public function destroy(CrmCategoriaRechazo $categoria): JsonResponse
    {
        $this->verificarEmpresa($categoria);
        $this->exigirPermisoSubmodulo((int) $categoria->empresa_id, 'catalogos', self::SUBMODULO, 'eliminar', 'No tienes permiso para eliminar categorías de rechazo.');

        // withTrashed: una oportunidad en la papelera también conserva su motivo.
        $usada = CrmOportunidad::withTrashed()
            ->whereIn('motivo_rechazo_id', CrmMotivoRechazo::where('categoria_id', $categoria->id)->select('id'))
            ->exists();

        if ($usada) {
            return $this->jsonError(self::MENSAJE_MOTIVO_USADO, 409);
        }

        $categoria->delete(); // Los motivos se van por la FK en cascada.

        return $this->jsonSuccess(null, 'Categoría eliminada correctamente');
    }

    private function reglas(int $empresaId, ?CrmCategoriaRechazo $categoria = null): array
    {
        $unico = Rule::unique('crm_categorias_rechazo', 'nombre')->where('empresa_id', $empresaId);
        if ($categoria) {
            $unico->ignore($categoria->id);
        }

        return [
            'nombre' => ['bail', ...($categoria ? ['sometimes', 'required'] : ['required']), 'string', 'max:100', $unico],
            'orden' => 'nullable|integer|min:0|max:65535',
            'activo' => 'nullable|boolean',
        ];
    }

    /** Un `orden` o `activo` nulo se ignora: queda el valor actual o el de la tabla. */
    private function sinNulos(array $validated): array
    {
        return array_filter($validated, fn ($valor) => $valor !== null);
    }

    /** 404 si la categoría no pertenece a la empresa del request. */
    private function verificarEmpresa(CrmCategoriaRechazo $categoria): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $categoria->empresa_id !== (int) $empresaId) {
            abort(404, 'Categoría no encontrada.');
        }
    }
}
