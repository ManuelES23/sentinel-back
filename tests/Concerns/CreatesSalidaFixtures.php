<?php

namespace Tests\Concerns;

use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\User;

/** Requiere CreatesAssetFixtures (usa $enterprise y otorgarActivos). */
trait CreatesSalidaFixtures
{
    protected const URL_SALIDAS = '/api/splendidfarms/administration/activos-fijos/salidas';
    protected const URL_SALIDAS_GE = '/api/grupoesplendido/administration/activos-fijos/salidas';

    protected function usuarioSalidas(?Enterprise $empresa = null, array $permisos = ['view', 'create', 'delete']): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($usuario, $empresa ?? $this->enterprise, 'salidas', $permisos);

        return $usuario;
    }

    /** Fila de salida vigente creada directo (sin pasar por el servicio ni tocar el activo). */
    protected function crearSalidaDirecta(FixedAsset $activo, array $overrides = []): FixedAssetDisposal
    {
        return FixedAssetDisposal::create(array_merge([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'type' => 'desecho',
            'disposal_date' => '2026-05-20',
            'reason' => 'Obsoleto',
            'authorized_by_name' => 'Gerencia',
            'previous_status' => 'en_uso',
            'asset_snapshot' => ['code' => $activo->code, 'name' => $activo->name],
            'purchase_value' => '12000.00',
            'accumulated_depreciation' => '900.00',
            'book_value' => '11100.00',
            'result_amount' => '-11100.00',
            'created_by_name' => 'Tester',
        ], $overrides));
    }
}
