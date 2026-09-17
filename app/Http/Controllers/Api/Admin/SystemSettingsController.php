<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PruebaConfiguracionCorreo;
use App\Models\ActivityLog;
use App\Services\MailSettings;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ajustes del sistema (/admin/settings): sesión, contraseñas y correo.
 */
class SystemSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private MailSettings $mail)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->forClient()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(
            SettingsService::rulesForRequest(),
            SettingsService::messagesForRequest(),
            SettingsService::attributesForRequest(),
        );

        $cambios = $this->settings->update(Arr::dot($validated), $request->user());

        if ($cambios['despues'] !== []) {
            ActivityLog::log('settings_update', 'system_settings', null, $cambios['antes'], $cambios['despues']);
        }

        $this->mail->apply();

        return response()->json([
            'success' => true,
            'message' => 'Ajustes guardados.',
            'data' => $this->settings->forClient(),
        ]);
    }

    public function sendTestMail(Request $request): JsonResponse
    {
        $validated = $request->validate(['to' => 'nullable|email|max:255']);
        $to = $validated['to'] ?? $request->user()->email;

        if (! $this->settings->get('mail.enabled')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Activa y guarda la configuración SMTP antes de probarla.',
            ], 422);
        }

        $this->mail->apply();

        try {
            Mail::to($to)->send(new PruebaConfiguracionCorreo());
        } catch (Throwable $e) {
            ActivityLog::log('mail_test', 'system_settings', null, null, ['to' => $to, 'ok' => false]);

            $secreto = (string) $this->settings->get('mail.password');
            $detalle = $secreto !== '' ? str_replace($secreto, '***', $e->getMessage()) : $e->getMessage();

            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo enviar el correo: '.$detalle,
            ], 422);
        }

        ActivityLog::log('mail_test', 'system_settings', null, null, ['to' => $to, 'ok' => true]);

        return response()->json([
            'success' => true,
            'message' => "Correo de prueba enviado a {$to}.",
        ]);
    }
}
