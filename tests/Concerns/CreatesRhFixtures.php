<?php
// sentinel-back/tests/Concerns/CreatesRhFixtures.php
namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Laravel\Sanctum\Sanctum;

trait CreatesRhFixtures
{
    /**
     * Crea una empresa + usuario con acceso activo (vía UserEnterpriseAccess,
     * el sistema real de permisos — NUNCA el pivot legacy user_enterprises,
     * ver Global Constraints) y lo autentica con Sanctum.
     *
     * @return array{0: User, 1: Enterprise}
     */
    protected function createAuthenticatedRhUser(array $enterpriseOverrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        // 'description' es NOT NULL en la tabla enterprises (sin default) —
        // confirmado en database/migrations/2025_12_04_155615_create_enterprises_table.php.
        // El fillable de Enterprise no lo deja fuera de tabla, así que se agrega
        // aquí al array base en vez de exigir un override por test.
        $enterprise = Enterprise::create(array_merge([
            'name' => 'Grupo Espléndido Test ' . $sequence,
            'slug' => 'grupoesplendido-test-' . $sequence,
            'description' => 'Empresa de prueba para RH',
            'is_active' => true,
        ], $enterpriseOverrides));

        $user = User::create([
            'name' => 'RH Tester ' . $sequence,
            'email' => "rh-tester-{$sequence}@test.local",
            'password' => bcrypt('password'),
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

    protected function createEmployee(int $enterpriseId, array $overrides = []): Employee
    {
        static $sequence = 0;
        $sequence++;

        // 'hire_date' y 'qr_code' son NOT NULL sin default en la tabla employees
        // (database/migrations/2026_01_31_003000_create_employees_table.php,
        // 'qr_code' además es unique) — se agregan aquí al array base en vez
        // de exigir overrides por test.
        return Employee::create(array_merge([
            'enterprise_id' => $enterpriseId,
            'employee_number' => 'EMP-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Empleado',
            'last_name' => 'Prueba' . $sequence,
            'pin' => str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'qr_code' => 'QR-RH-TEST-' . $sequence,
            'hire_date' => now()->subYear(),
            'status' => Employee::STATUS_ACTIVE,
        ], $overrides));
    }
}
