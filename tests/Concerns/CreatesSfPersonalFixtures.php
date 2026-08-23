<?php

namespace Tests\Concerns;

use App\Models\Enterprise;
use App\Models\SfEmployee;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures mínimos para probar el módulo de Personal SF (empleados +
 * plantillas biométricas). No reutiliza CreatesAssetFixtures::setUpAssetFixtures()
 * porque ese helper crea Branch/EntityType/Entity/AssetCategory/Brand/UnitOfMeasure
 * irrelevantes para este módulo; sigue el mismo patrón de creación de
 * Enterprise/User autenticado documentado en ese trait.
 */
trait CreatesSfPersonalFixtures
{
    /**
     * Crea una Enterprise + User autenticado (Sanctum::actingAs) y los retorna
     * como [$user, $enterprise].
     */
    protected function createAuthenticatedUserWithEnterprise(array $enterpriseOverrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        $user = User::factory()->create();

        $enterprise = Enterprise::create(array_merge([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms-' . $sequence,
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ], $enterpriseOverrides));

        // Este fixture alimenta controllers que, hoy por hoy, validan acceso a
        // empresa contra DOS fuentes distintas (inconsistencia real del
        // proyecto, no un descuido de este fixture):
        //   - SfFieldCheckController::authorizeEnterpriseAccess() usa
        //     user_enterprise_access (UserEnterpriseAccess) — el fix de 57f8e87
        //     movió la fuente de verdad ahí a propósito (ver el comentario de
        //     ese método).
        //   - SfFaceTemplateController::photo() todavía usa
        //     User::activeEnterprises() (pivot legacy user_enterprises) y no
        //     fue migrado en ese mismo fix.
        // Hay que poblar ambas o los tests "felices" de uno de los dos
        // controllers reciben 403 en vez del código de estado que en realidad
        // prueban.
        $user->enterprises()->attach($enterprise->id, [
            'role' => 'admin',
            'is_active' => true,
            'granted_at' => now(),
        ]);

        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return [$user, $enterprise];
    }

    protected function createSfEmployee(int $enterpriseId, array $overrides = []): SfEmployee
    {
        static $sequence = 0;
        $sequence++;

        return SfEmployee::create(array_merge([
            'enterprise_id' => $enterpriseId,
            'code' => 'SFE-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'first_name' => 'Empleado',
            'last_name' => 'Prueba' . $sequence,
            'hire_date' => now()->subYear()->toDateString(),
            'status' => 'active',
        ], $overrides));
    }
}
