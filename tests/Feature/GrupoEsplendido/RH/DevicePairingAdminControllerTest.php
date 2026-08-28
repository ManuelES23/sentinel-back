<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/DevicePairingAdminControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\DevicePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class DevicePairingAdminControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function makeSelfPairing(int $employeeId, array $overrides = []): DevicePairing
    {
        return DevicePairing::create(array_merge([
            'device_token_hash' => DevicePairing::hashToken(DevicePairing::generateToken()),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employeeId,
        ], $overrides));
    }

    private function makeKioskPairing(int $userId, array $overrides = []): DevicePairing
    {
        return DevicePairing::create(array_merge([
            'device_token_hash' => DevicePairing::hashToken(DevicePairing::generateToken()),
            'mode' => DevicePairing::MODE_KIOSK,
            'paired_by_user_id' => $userId,
            'label' => 'Kiosco de prueba',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos')->assertStatus(401);
    }

    public function test_index_includes_self_pairing_from_accessible_enterprise(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pairing = $this->makeSelfPairing($employee->id);

        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pairing->id));
    }

    public function test_index_excludes_self_pairing_from_other_enterprise(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        [, $otherEnterprise] = $this->createAuthenticatedRhUser();
        $otherEmployee = $this->createEmployee($otherEnterprise->id);
        $otherPairing = $this->makeSelfPairing($otherEmployee->id);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($otherPairing->id));
    }

    public function test_index_includes_self_pairings_from_all_enterprises_user_has_access_to(): void
    {
        // Usuario de RH con acceso activo a DOS empresas (respeta las varias
        // empresas de cada usuario de RH — spec §9). Debe ver en una sola
        // llamada los emparejamientos self de ambas.
        [$user, $enterprise1] = $this->createAuthenticatedRhUser();

        $enterprise2 = \App\Models\Enterprise::create([
            'name' => 'Grupo Espléndido Test Segunda Empresa',
            'slug' => 'grupoesplendido-test-segunda-empresa',
            'description' => 'Empresa de prueba para RH',
            'is_active' => true,
        ]);
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterprise2->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        $employee1 = $this->createEmployee($enterprise1->id);
        $pairing1 = $this->makeSelfPairing($employee1->id);
        $employee2 = $this->createEmployee($enterprise2->id);
        $pairing2 = $this->makeSelfPairing($employee2->id);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pairing1->id));
        $this->assertTrue($ids->contains($pairing2->id));
    }

    public function test_index_includes_all_kiosk_pairings_regardless_of_who_paired_them(): void
    {
        [$user] = $this->createAuthenticatedRhUser();
        [$otherUser] = $this->createAuthenticatedRhUser();
        $kioskPairing = $this->makeKioskPairing($otherUser->id);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($kioskPairing->id));
    }

    public function test_revoke_marks_revoked_at(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pairing = $this->makeSelfPairing($employee->id);

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar");

        $response->assertStatus(200);
        $this->assertNotNull($pairing->fresh()->revoked_at);
    }

    public function test_revoked_device_immediately_loses_access_to_roster_package(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $rawToken = DevicePairing::generateToken();
        $pairing = DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($rawToken),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
        ]);

        $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar")
            ->assertStatus(200);

        $this->withHeaders(['X-Device-Token' => $rawToken])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }

    public function test_revoke_prevents_cross_enterprise_idor(): void
    {
        // Usuario de RH con acceso solo a empresa 1
        [$user1, $enterprise1] = $this->createAuthenticatedRhUser();
        // Usuario de RH con acceso solo a empresa 2
        [$user2, $enterprise2] = $this->createAuthenticatedRhUser();

        // Crear un emparejamiento personal en empresa 2
        $employee2 = $this->createEmployee($enterprise2->id);
        $pairing = $this->makeSelfPairing($employee2->id);

        // Usuario 1 intenta revocar el dispositivo de empresa 2 (IDOR attack)
        \Laravel\Sanctum\Sanctum::actingAs($user1);
        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar");

        // Debe ser 403 Forbidden
        $response->assertStatus(403);
        // El dispositivo no debe estar revocado
        $this->assertNull($pairing->fresh()->revoked_at);
    }

    // --- Regresión: empleado dado de baja (soft-delete) con emparejamiento self activo ---
    // Employee::destroy() aplica SoftDeletes (a diferencia de terminate(), que no borra el
    // registro). El global scope de SoftDeletes excluye por defecto al empleado de
    // cualquier relación, así que pairedByEmployee debe resolverse con withTrashed().

    public function test_index_includes_self_pairing_when_employee_is_soft_deleted(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->delete();

        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pairing->id));
    }

    public function test_index_excludes_soft_deleted_employee_self_pairing_from_other_enterprise(): void
    {
        [, $enterprise1] = $this->createAuthenticatedRhUser();
        [$user2] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise1->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->delete();

        \Laravel\Sanctum\Sanctum::actingAs($user2);
        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($pairing->id));
    }

    public function test_revoke_allows_admin_with_enterprise_access_when_employee_is_soft_deleted(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->delete();

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar");

        $response->assertStatus(200);
        $this->assertNotNull($pairing->fresh()->revoked_at);
    }

    public function test_revoke_denies_admin_without_enterprise_access_when_employee_is_soft_deleted(): void
    {
        [, $enterprise1] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise1->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->delete();

        [$user2] = $this->createAuthenticatedRhUser();
        \Laravel\Sanctum\Sanctum::actingAs($user2);
        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar");

        $response->assertStatus(403);
        $this->assertNull($pairing->fresh()->revoked_at);
    }

    // --- Regresión: empleado eliminado permanentemente (hard-delete) ---
    // paired_by_employee_id tiene ->nullOnDelete(), así que tras un forceDelete()
    // la columna queda NULL: el emparejamiento queda huérfano, sin empresa a la
    // cual asociarlo. Decisión de diseño: se trata como un kiosco (visible y
    // revocable por cualquier admin de RH autenticado con acceso activo a al
    // menos una empresa) en vez de quedar inaccesible para siempre.

    public function test_index_includes_orphaned_self_pairing_when_employee_is_hard_deleted(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->forceDelete();

        $response = $this->getJson('/api/grupoesplendido/rh/asistencia/dispositivos');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($pairing->id));
    }

    public function test_revoke_allows_any_rh_admin_when_employee_is_hard_deleted(): void
    {
        [, $enterprise1] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise1->id);
        $pairing = $this->makeSelfPairing($employee->id);
        $employee->forceDelete();

        // Admin sin ninguna relación con la empresa original del empleado,
        // pero con acceso activo a SU PROPIA empresa.
        [$user2] = $this->createAuthenticatedRhUser();
        \Laravel\Sanctum\Sanctum::actingAs($user2);
        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar");

        $response->assertStatus(200);
        $this->assertNotNull($pairing->fresh()->revoked_at);
    }
}
