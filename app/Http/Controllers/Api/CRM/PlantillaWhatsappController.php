<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmPlantillaWhatsapp;
use App\Support\CRM\PlantillaWhatsappVariables;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plantillas de mensaje de WhatsApp del CRM, por empresa (spec 2026-10-12
 * §5.1, molde RegionController). La lectura es abierta dentro del tenant: el
 * vendedor las usa desde el botón sin tener el menú de catálogos. Escribir
 * exige catalogos.plantillas-whatsapp crear|editar|eliminar. Sin evento de
 * tiempo real (D10): el panel recarga después de cada cambio.
 */
class PlantillaWhatsappController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const SUBMODULO = 'plantillas-whatsapp';

    private const MENSAJES = [
        'nombre.required' => 'El nombre es requerido.',
        'nombre.max' => 'El nombre no puede pasar de 100 caracteres.',
        'nombre.unique' => 'Ya existe una plantilla con ese nombre.',
        'cuerpo.required' => 'El mensaje es requerido.',
        'cuerpo.max' => 'El mensaje no puede pasar de 1000 caracteres.',
        'orden.integer' => 'El orden debe ser un número entero.',
        'orden.min' => 'El orden no puede ser negativo.',
        'orden.max' => 'El orden no puede pasar de 65535.',
    ];

    /** GET /crm/plantillas-whatsapp — `?all=1`: solo activas, sin paginar. */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        // `search` solo cuenta si es texto (un arreglo se ignora). `%` y `_` se
        // buscan literalmente: se escapan con `!` y ESCAPE, que vale en MySQL y SQLite.
        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : '';

        $query = CrmPlantillaWhatsapp::query()
            ->where('empresa_id', $empresaId)
            ->when($search !== '', fn ($q) => $q->whereRaw(
                "nombre LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'],
            ))
            ->orderBy('orden')
            ->orderBy('nombre');

        if ($request->boolean('all')) {
            return $this->jsonSuccess($query->where('activo', true)->get(['id', 'nombre', 'cuerpo', 'orden']));
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);

        return $this->jsonPaginated($query->paginate($perPage));
    }

    /** POST /crm/plantillas-whatsapp */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', self::SUBMODULO, 'crear', 'No tienes permiso para crear plantillas de WhatsApp.');

        $validated = $request->validate($this->reglas((int) $empresaId), self::MENSAJES);

        $plantilla = CrmPlantillaWhatsapp::create(['empresa_id' => $empresaId] + $validated);

        return $this->jsonSuccess($plantilla, 'Plantilla creada correctamente', 201);
    }

    /** PUT|PATCH /crm/plantillas-whatsapp/{plantilla} */
    public function update(Request $request, CrmPlantillaWhatsapp $plantilla): JsonResponse
    {
        $this->verificarEmpresa($plantilla);
        $empresaId = (int) $plantilla->empresa_id;
        $this->exigirPermisoSubmodulo($empresaId, 'catalogos', self::SUBMODULO, 'editar', 'No tienes permiso para editar plantillas de WhatsApp.');

        $validated = $request->validate($this->reglas($empresaId, $plantilla), self::MENSAJES);

        $plantilla->update($validated);

        return $this->jsonSuccess($plantilla->fresh(), 'Plantilla actualizada correctamente');
    }

    /** DELETE /crm/plantillas-whatsapp/{plantilla} */
    public function destroy(Request $request, CrmPlantillaWhatsapp $plantilla): JsonResponse
    {
        $this->verificarEmpresa($plantilla);
        $this->exigirPermisoSubmodulo((int) $plantilla->empresa_id, 'catalogos', self::SUBMODULO, 'eliminar', 'No tienes permiso para eliminar plantillas de WhatsApp.');

        $plantilla->delete();

        return $this->jsonSuccess(null, 'Plantilla eliminada correctamente');
    }

    private function reglas(int $empresaId, ?CrmPlantillaWhatsapp $plantilla = null): array
    {
        $requerido = $plantilla ? ['sometimes', 'required'] : ['required'];
        $unico = Rule::unique('crm_plantillas_whatsapp', 'nombre')->where('empresa_id', $empresaId);
        if ($plantilla) {
            $unico->ignore($plantilla->id);
        }

        // UTF-8 inválido (posible en un formulario, no en JSON): 422 antes de
        // medir el largo, consultar la unicidad o buscar variables.
        $utf8 = function (string $atributo, mixed $valor, Closure $fallar) {
            if (is_string($valor) && ! PlantillaWhatsappVariables::esTextoValido($valor)) {
                $fallar(PlantillaWhatsappVariables::MENSAJE_TEXTO_INVALIDO);
            }
        };

        return [
            'nombre' => ['bail', ...$requerido, 'string', $utf8, 'max:100', $unico],
            'cuerpo' => ['bail', ...$requerido, 'string', $utf8, 'max:1000', function (string $atributo, mixed $valor, Closure $fallar) {
                $desconocidas = PlantillaWhatsappVariables::desconocidas((string) $valor);
                if ($desconocidas !== []) {
                    $fallar(PlantillaWhatsappVariables::mensajeDesconocidas($desconocidas));
                }
            }],
            'activo' => 'sometimes|boolean',
            'orden' => 'sometimes|integer|min:0|max:65535',
        ];
    }

    /** 404 si la plantilla no pertenece a la empresa del request. */
    private function verificarEmpresa(CrmPlantillaWhatsapp $plantilla): void
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        if ((int) $plantilla->empresa_id !== (int) $empresaId) {
            abort(404, 'Plantilla no encontrada.');
        }
    }
}
