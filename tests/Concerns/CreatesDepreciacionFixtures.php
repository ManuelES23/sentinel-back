<?php

namespace Tests\Concerns;

use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\User;

/** Requiere CreatesAssetFixtures (usa $enterprise, $assetCategory, $branch, $entity, otorgarActivos...). */
trait CreatesDepreciacionFixtures
{
    protected const URL_DEPRECIACION = '/api/splendidfarms/administration/activos-fijos/depreciacion';
    protected const URL_DEPRECIACION_GE = '/api/grupoesplendido/administration/activos-fijos/depreciacion';

    /** Activo de Splendid Farms: 12,000 de compra, 20 % anual, 10 % de residual, desde enero de 2026. */
    protected function crearActivoDepreciable(array $overrides = []): FixedAsset
    {
        $numero = FixedAsset::withTrashed()->count() + 1;

        return FixedAsset::create($this->validFixedAssetPayload(array_merge([
            'code' => 'SF-AF-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT),
            'name' => 'Activo ' . $numero,
            'status' => 'en_uso',
            'purchase_date' => '2026-01-15',
            'purchase_value' => 12000,
            'depreciation_rate' => 20,
            'salvage_percent' => 10,
        ], $overrides)));
    }

    protected function usuarioDepreciacion(?Enterprise $empresa = null, array $permisos = ['view', 'create', 'delete']): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($usuario, $empresa ?? $this->enterprise, 'depreciacion', $permisos);

        return $usuario;
    }
}
