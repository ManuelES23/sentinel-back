<?php

namespace App\Services\SplendidFarms\ChecadorCampo;

use App\Models\SfEmployee;
use App\Services\ThumbnailService;

/**
 * Arma el paquete de cuadrilla del checador de campo: empleados activos y
 * enrolados de la empresa, con embedding y miniatura. Lo comparten el endpoint
 * Sanctum de Administración y los endpoints públicos del dispositivo emparejado.
 */
class PaqueteCuadrillaSf
{
    private const CURRENT_MODEL_VERSION = 'faceapi-v1';

    public function __construct(private readonly ThumbnailService $thumbnailService)
    {
    }

    /**
     * @return array{model_version: string, generated_at: string, employees: \Illuminate\Support\Collection}
     */
    public function armar(int $enterpriseId): array
    {
        $employees = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->whereHas('faceTemplate', function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            })
            ->with(['faceTemplate' => function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            }])
            ->get(['id', 'enterprise_id', 'code', 'first_name', 'last_name', 'second_last_name']);

        $rows = $employees->map(function (SfEmployee $employee) {
            $template = $employee->faceTemplate;

            return [
                'id' => $employee->id,
                'code' => $employee->code,
                'full_name' => trim("{$employee->first_name} {$employee->last_name} {$employee->second_last_name}"),
                'embedding' => $template->embedding,
                'thumbnail' => $this->thumbnailService->makeThumbnailDataUri($template->photo_path),
            ];
        });

        return [
            'model_version' => self::CURRENT_MODEL_VERSION,
            'generated_at' => now()->toIso8601String(),
            'employees' => $rows,
        ];
    }
}
