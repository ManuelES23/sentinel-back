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
}
