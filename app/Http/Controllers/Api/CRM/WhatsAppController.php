<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmPlantillaWhatsapp;
use App\Models\Enterprise;
use App\Services\CRM\VendedorActualService;
use App\Services\CRM\VisibilidadOportunidades;
use App\Services\CRM\WhatsAppMensajeService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM · WhatsApp Fase 0 (spec 2026-10-12 §5.2 y §5.3). El back resuelve
 * teléfonos y mensaje; el front solo abre el enlace wa.me.
 *
 * Orden: empresa y actividades.crear (403), validación (422), entidad del
 * tenant y oportunidad visible (404, igual que si no existiera), plantilla
 * activa de la empresa (404), cotización visible y de esa entidad (404) y
 * destinatario del registro (422).
 */
class WhatsAppController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const MENSAJE_SIN_PERMISO = 'No tienes permiso para registrar actividades.';

    private const MENSAJE_NO_ENCONTRADO = 'El registro no existe o no pertenece a la empresa.';

    public function __construct(
        private readonly WhatsAppMensajeService $mensajes,
        private readonly VisibilidadOportunidades $visibilidad,
        private readonly VendedorActualService $vendedores,
    ) {}

    /** GET /crm/whatsapp/destinatarios?entidad_tipo=&entidad_id= */
    public function destinatarios(Request $request): JsonResponse
    {
        $empresaId = $this->exigirUso();
        $validated = $request->validate($this->reglasEntidad());

        $entidad = $this->entidadVisible($empresaId, $validated['entidad_tipo'], (int) $validated['entidad_id']);
        $vendedor = $this->vendedores->vendedorDelUsuario($empresaId, (int) $request->user()->id);

        return $this->jsonSuccess([
            'entidad' => [
                'tipo' => $validated['entidad_tipo'],
                'id' => $entidad->id,
                'nombre' => $this->mensajes->nombreDe($entidad),
            ],
            'vendedor' => $vendedor ? ['nombre' => $vendedor->nombre] : null,
            'destinatarios' => $this->mensajes->destinatarios($entidad),
        ]);
    }

    /** POST /crm/whatsapp/renderizar */
    public function renderizar(Request $request): JsonResponse
    {
        $empresaId = $this->exigirUso();
        $validated = $request->validate($this->reglasEntidad() + [
            'plantilla_id' => 'required|integer',
            'destinatario_clave' => ['nullable', 'string', 'max:40', 'regex:/^(entidad|contacto:\d+)$/'],
            'cotizacion_id' => 'nullable|integer',
        ], [
            'destinatario_clave.regex' => 'El destinatario no pertenece a este registro.',
        ]);

        $entidad = $this->entidadVisible($empresaId, $validated['entidad_tipo'], (int) $validated['entidad_id']);

        $plantilla = CrmPlantillaWhatsapp::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->find((int) $validated['plantilla_id']);
        abort_unless($plantilla, 404, 'Plantilla no encontrada.');

        $cotizacion = isset($validated['cotizacion_id'])
            ? $this->cotizacionVisible($empresaId, (int) $validated['cotizacion_id'], $entidad)
            : null;

        $destinatario = null;
        if (! empty($validated['destinatario_clave'])) {
            $destinatario = $this->mensajes->destinatario($entidad, $validated['destinatario_clave']);
            if (! $destinatario) {
                throw ValidationException::withMessages([
                    'destinatario_clave' => 'El destinatario no pertenece a este registro.',
                ]);
            }
        }

        $valores = $this->mensajes->valores(
            $entidad,
            $destinatario,
            $cotizacion,
            $this->vendedores->vendedorDelUsuario($empresaId, (int) $request->user()->id),
            Enterprise::whereKey($empresaId)->value('name'),
        );

        return $this->jsonSuccess($this->mensajes->renderizar($plantilla, $valores));
    }

    /** 403 sin empresa de contexto o sin actividades.crear (el permiso que exige el registro). */
    private function exigirUso(): int
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'actividades', 'actividades', 'crear', self::MENSAJE_SIN_PERMISO);

        return (int) $empresaId;
    }

    private function reglasEntidad(): array
    {
        return [
            'entidad_tipo' => ['required', Rule::in(WhatsAppMensajeService::TIPOS_ENTIDAD)],
            'entidad_id' => 'required|integer',
        ];
    }

    /** Entidad del tenant; una oportunidad no visible responde igual que una inexistente. */
    private function entidadVisible(int $empresaId, string $tipo, int $id): Model
    {
        $entidad = $this->visibilidad->entidadVisible($this->mensajes->buscarEntidad($empresaId, $tipo, $id));
        abort_unless($entidad, 404, self::MENSAJE_NO_ENCONTRADO);

        return $entidad;
    }

    /**
     * Misma regla de lectura que CotizacionController::show (cotizaciones.ver y
     * oportunidad visible), pero sin abortar con 403: todo lo que no se puede
     * usar responde 404 para no filtrar folio ni total. Además, la cotización
     * debe ser de la entidad pedida. Una cotización sin oportunidad (eliminada)
     * nunca aplica aquí.
     */
    private function cotizacionVisible(int $empresaId, int $id, Model $entidad): CrmCotizacion
    {
        $cotizacion = CrmCotizacion::where('empresa_id', $empresaId)->find($id);

        $visible = $cotizacion !== null
            && $this->tienePermisoSubmodulo($empresaId, 'cotizaciones', 'cotizaciones', 'ver')
            && $cotizacion->oportunidad !== null
            // Defensa en profundidad: una FK corrupta hacia otra empresa nunca aplica.
            && (int) $cotizacion->oportunidad->empresa_id === $empresaId
            && $this->visibilidad->esVisible($cotizacion->oportunidad)
            && $this->mensajes->cotizacionEsDeLaEntidad($entidad, $cotizacion);

        abort_unless($visible, 404, 'Cotización no encontrada.');

        return $cotizacion;
    }
}
