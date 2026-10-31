<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Etiquetas de actividad del CRM, por empresa (spec 2026-10-30 §8, molde
 * CategoriaRechazoController). La lectura es abierta dentro del tenant: el
 * selector de la agenda y de las actividades las carga sin que el vendedor
 * tenga el menú de catálogos. Escribir exige
 * catalogos.etiquetas-actividad crear|editar|eliminar. Sin evento en tiempo
 * real, igual que las plantillas.
 */
class EtiquetaActividadController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public const SUBMODULO = 'etiquetas-actividad';

    public const MENSAJE_USADA = 'Esta etiqueta ya se usó; desactívala en lugar de borrarla.';

    private const MENSAJES = [
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'nombre.unique' => 'Ya existe una etiqueta con ese nombre.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 65535.',
        'activo.boolean' => 'El estado debe ser verdadero o falso.',
    ];

    /**
     * GET /crm/etiquetas-actividad — `?activas=1`: solo las activas (lo que
     * muestran los selectores). Sin el parámetro: todas (el catálogo).
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $query = CrmEtiquetaActividad::query()
            ->where('empresa_id', $empresaId)
            ->orderBy('orden')
            ->orderBy('id');

        if ($request->boolean('activas')) {
            $query->activas();
        }

        return $this->jsonSuccess($query->get());
    }

    /** POST /crm/etiquetas-actividad */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', self::SUBMODULO, 'crear', 'No tienes permiso para crear etiquetas de actividad.');

        $validated = $request->validate($this->reglas((int) $empresaId), self::MENSAJES);

        $etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $empresaId] + $this->sinNulos($validated));

        return $this->jsonSuccess($etiqueta, 'Etiqueta creada correctamente', 201);
    }

    /** PUT|PATCH /crm/etiquetas-actividad/{etiqueta} */
    public function update(Request $request, CrmEtiquetaActividad $etiqueta): JsonResponse
    {
        $this->verificarEmpresa($etiqueta);
        $this->exigirPermisoSubmodulo((int) $etiqueta->empresa_id, 'catalogos', self::SUBMODULO, 'editar', 'No tienes permiso para editar etiquetas de actividad.');

        $validated = $request->validate($this->reglas((int) $etiqueta->empresa_id, $etiqueta), self::MENSAJES);

        $etiqueta->update($this->sinNulos($validated));

        return $this->jsonSuccess($etiqueta->fresh(), 'Etiqueta actualizada correctamente');
    }

    /** DELETE /crm/etiquetas-actividad/{etiqueta} — 422 si algún evento o actividad ya la usa. */
    public function destroy(CrmEtiquetaActividad $etiqueta): JsonResponse
    {
        $this->verificarEmpresa($etiqueta);
        $this->exigirPermisoSubmodulo((int) $etiqueta->empresa_id, 'catalogos', self::SUBMODULO, 'eliminar', 'No tienes permiso para eliminar etiquetas de actividad.');

        $usada = CrmAgenda::where('etiqueta_id', $etiqueta->id)->exists()
            || CrmActividad::where('etiqueta_id', $etiqueta->id)->exists();

        if ($usada) {
            return $this->jsonError(self::MENSAJE_USADA, 422);
        }

        $etiqueta->delete();

        return $this->jsonSuccess(null, 'Etiqueta eliminada correctamente');
    }

    private function reglas(int $empresaId, ?CrmEtiquetaActividad $etiqueta = null): array
    {
        $unico = Rule::unique('crm_etiquetas_actividad', 'nombre')->where('empresa_id', $empresaId);
        if ($etiqueta) {
            $unico->ignore($etiqueta->id);
        }

        return [
            'nombre' => ['bail', ...($etiqueta ? ['sometimes', 'required'] : ['required']), 'string', 'max:100', $unico],
            'orden' => 'nullable|integer|min:0|max:65535',
            'activo' => 'nullable|boolean',
        ];
    }

    /** Un `orden` o `activo` nulo se ignora: queda el valor actual o el de la tabla. */
    private function sinNulos(array $validated): array
    {
        return array_filter($validated, fn ($valor) => $valor !== null);
    }

    /** 404 si la etiqueta no pertenece a la empresa del request. */
    private function verificarEmpresa(CrmEtiquetaActividad $etiqueta): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $etiqueta->empresa_id !== (int) $empresaId) {
            abort(404, 'Etiqueta no encontrada.');
        }
    }
}
