<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Ajustes del sistema (/admin/settings): sesión, contraseñas y correo.
 */
class SystemSettingsController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->forClient()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(SettingsService::rulesForRequest());

        $cambios = $this->settings->update(Arr::dot($validated), $request->user());

        if ($cambios['despues'] !== []) {
            ActivityLog::log('settings_update', 'system_settings', null, $cambios['antes'], $cambios['despues']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ajustes guardados.',
            'data' => $this->settings->forClient(),
        ]);
    }
}
