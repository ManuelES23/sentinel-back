<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/EmployeeControllerDevicePairingRevocationTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\DevicePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class EmployeeControllerDevicePairingRevocationTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function pairedDeviceToken(int $employeeId): string
    {
        $raw = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employeeId,
        ]);

        return $raw;
    }

    private function tinyJpegBase64(): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
        ));
    }

    public function test_terminate_revokes_active_device_pairing(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $this->pairedDeviceToken($employee->id);
        $pairing = DevicePairing::where('paired_by_employee_id', $employee->id)->firstOrFail();

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->assertNotNull($pairing->fresh()->revoked_at);
    }

    public function test_destroy_revokes_active_device_pairing(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $this->pairedDeviceToken($employee->id);
        $pairing = DevicePairing::where('paired_by_employee_id', $employee->id)->firstOrFail();

        $this->deleteJson("/api/grupoesplendido/rh/empleados/{$employee->id}")
            ->assertStatus(200);

        $this->assertNotNull($pairing->fresh()->revoked_at);
    }

    public function test_terminate_does_not_fail_without_device_pairing(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);
    }

    public function test_terminate_does_not_revoke_already_revoked_pairing_twice_or_other_employees(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $otherEmployee = $this->createEmployee($enterprise->id);
        $this->pairedDeviceToken($otherEmployee->id);
        $otherPairing = DevicePairing::where('paired_by_employee_id', $otherEmployee->id)->firstOrFail();

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->assertNull($otherPairing->fresh()->revoked_at);
    }

    public function test_terminated_employee_device_token_immediately_loses_access_to_roster_package(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $rawToken = $this->pairedDeviceToken($employee->id);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->withHeaders(['X-Device-Token' => $rawToken])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }

    public function test_terminated_employee_device_token_can_no_longer_submit_checks_via_sync(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $rawToken = $this->pairedDeviceToken($employee->id);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->withHeaders(['X-Device-Token' => $rawToken])
            ->postJson('/api/checador/sync', [
                'checks' => [[
                    'client_uuid' => (string) Str::uuid(),
                    'employee_id' => $employee->id,
                    'type' => 'check_in',
                    'checked_at' => now()->toIso8601String(),
                    'device_synced_at' => now()->toIso8601String(),
                    'evidence_photo' => $this->tinyJpegBase64(),
                ]],
            ])
            ->assertStatus(401);
    }

    public function test_destroyed_employee_device_token_immediately_loses_access_to_roster_package(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $rawToken = $this->pairedDeviceToken($employee->id);

        $this->deleteJson("/api/grupoesplendido/rh/empleados/{$employee->id}")
            ->assertStatus(200);

        $this->withHeaders(['X-Device-Token' => $rawToken])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }
}
