<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Services\RH\AprobadorVacaciones;
use App\Support\CRM\TelefonoWhatsApp;
use App\Support\RH\TextosVacaciones;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Avisos al empleado por WhatsApp (manual, con enlace wa.me) y su historial (spec §7.2, §7.3).
 * Lo usan RH (permisos del submódulo Vacaciones) y quien decidió un paso desde su bandeja:
 * por eso autoriza el propio controlador y no el grupo de rutas.
 */
class VacationAvisoController extends Controller
{
    use VerificaPermisoSubmodulo;

    public function mensaje(VacationRequest $vacation): JsonResponse
    {
        $this->autorizar($vacation, 'edit');

        $empleado = $vacation->employee;
        abort_unless($empleado, 404, 'La solicitud no tiene empleado.');

        $evento = TextosVacaciones::eventoDeEstado($vacation->status);

        return response()->json([
            'success' => true,
            'data' => [
                'evento' => $evento,
                'telefono' => TelefonoWhatsApp::normalizar($empleado->mobile) ?? TelefonoWhatsApp::normalizar($empleado->phone),
                'texto' => TextosVacaciones::whatsapp($vacation, $evento),
                'empleado' => $empleado->full_name,
            ],
        ]);
    }

    /** Solo se llama cuando el usuario confirma «Sí, lo envié». */
    public function registrar(Request $request, VacationRequest $vacation): JsonResponse
    {
        $this->autorizar($vacation, 'edit');

        $datos = $request->validate(['evento' => ['required', 'string', Rule::in(VacationNotification::EVENTOS)]]);

        $aviso = VacationNotification::create([
            'vacation_request_id' => $vacation->id,
            'channel' => VacationNotification::CANAL_WHATSAPP,
            'event' => $datos['evento'],
            'sent_by' => Auth::id(),
            'sent_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aviso registrado.',
            'data' => $this->presentar($aviso->load('sender:id,name')),
        ], 201);
    }

    public function avisos(VacationRequest $vacation): JsonResponse
    {
        $this->autorizar($vacation, 'view');

        return response()->json([
            'success' => true,
            'data' => $vacation->notifications()->with('sender:id,name')->get()
                ->map(fn (VacationNotification $aviso) => $this->presentar($aviso))->values(),
        ]);
    }

    /** RH con el permiso pedido en Vacaciones, o quien decidió algún paso de esta solicitud. */
    private function autorizar(VacationRequest $vacation, string $permisoRh): void
    {
        $empresaId = (int) Enterprise::where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)->value('id');

        if ($empresaId && $this->tienePermisoSubmodulo($empresaId, 'gestion', 'vacaciones', $permisoRh, 'rh')) {
            return;
        }

        if ($vacation->approvals()->where('decided_by', Auth::id())->exists()) {
            return;
        }

        abort(403, 'No tienes permiso para avisar sobre esta solicitud.');
    }

    private function presentar(VacationNotification $aviso): array
    {
        return [
            'id' => $aviso->id,
            'channel' => $aviso->channel,
            'event' => $aviso->event,
            'sent_by' => $aviso->sender?->name,
            'sent_at' => $aviso->sent_at?->toIso8601String(),
            'error' => $aviso->error,
        ];
    }
}
