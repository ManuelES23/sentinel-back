<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\SfEmployee;
use App\Models\Submodule;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmoduleAccess;
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

        // Se puebla tanto el pivot legacy user_enterprises (por si algún
        // consumidor viejo todavía lo lee) como user_enterprise_access — la
        // fuente real de acceso que usan authorizeEnterpriseAccess() en los
        // controllers de este módulo (SfFieldCheckController,
        // SfFaceTemplateController) y el resto del sistema de permisos
        // (HierarchicalPermissionController, AuthController::getUserPermissions()).
        // Sin la fila en user_enterprise_access, todos los tests "felices"
        // recibirían 403 en vez del código de estado que en realidad prueban.
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

    /**
     * Da al usuario el acceso al submódulo administration/personal/checador-campo
     * de la empresa (user_submodule_access). Crea, si faltan, la aplicación, el
     * módulo y el submódulo. $acceso sobrescribe is_active / expires_at.
     */
    protected function darAccesoChecadorCampo(User $user, Enterprise $empresa, array $acceso = []): Submodule
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
            ['name' => 'Administración', 'path' => '/' . $empresa->slug . '/administration', 'description' => 'Administración', 'is_active' => true]
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'personal'],
            ['name' => 'Personal', 'is_active' => true]
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'checador-campo'],
            ['name' => 'Checador de campo', 'is_active' => true]
        );

        UserSubmoduleAccess::updateOrCreate(
            ['user_id' => $user->id, 'submodule_id' => $submodulo->id],
            array_merge(['is_active' => true, 'granted_at' => now(), 'expires_at' => null], $acceso)
        );

        return $submodulo;
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
