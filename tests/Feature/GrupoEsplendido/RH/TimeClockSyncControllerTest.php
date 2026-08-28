<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/TimeClockSyncControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Jobs\VerifyTimeClockCheckJob;
use App\Models\DevicePairing;
use App\Models\Employee;
use App\Models\TimeClockCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class TimeClockSyncControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function tinyJpegBase64(): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
        ));
    }

    /**
     * Empareja un dispositivo (mode self) para un empleado dado y regresa el
     * token crudo, listo para mandarse en el header X-Device-Token. Mismo
     * patrón que RosterPackageControllerTest::pairedDeviceToken() (Task 4).
     */
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

    private function baseCheckPayload(array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'type' => 'check_in',
            'checked_at' => now()->toIso8601String(),
            'device_synced_at' => now()->toIso8601String(),
            'evidence_photo' => $this->tinyJpegBase64(),
        ], $overrides);
    }

    public function test_sync_does_not_require_sanctum_authentication(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($employee->id);
        // Simula petición pública real: sin token de Sanctum (solo device.token).
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', ['checks' => [$this->baseCheckPayload(['employee_id' => $employee->id])]]);

        $response->assertStatus(200);
    }

    public function test_sync_requires_device_token_header(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $response = $this->postJson('/api/checador/sync', [
            'checks' => [$this->baseCheckPayload(['employee_id' => $employee->id])],
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('time_clock_checks', 0);
    }

    public function test_sync_rejects_nonexistent_employee_id_without_creating_row(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($employee->id);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', [
                'checks' => [$this->baseCheckPayload(['employee_id' => $employee->id + 999])],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('time_clock_checks', 0);
    }

    public function test_sync_accepts_valid_check_and_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($employee->id);

        $uuid = (string) Str::uuid();
        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', [
                'checks' => [$this->baseCheckPayload(['client_uuid' => $uuid, 'employee_id' => $employee->id])],
            ]);

        $response->assertStatus(200);
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $this->assertDatabaseHas('time_clock_checks', ['client_uuid' => $uuid, 'verification_status' => 'pending']);
        Queue::assertPushed(VerifyTimeClockCheckJob::class);
    }

    public function test_sync_is_idempotent_on_repeated_client_uuid(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($employee->id);
        $payload = $this->baseCheckPayload(['employee_id' => $employee->id]);

        $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', ['checks' => [$payload]])
            ->assertStatus(200);
        $second = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', ['checks' => [$payload]]);

        $this->assertSame('duplicate', collect($second->json('data.results'))->first()['status']);
        $this->assertSame(1, TimeClockCheck::where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_sync_rejects_inactive_employee(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        // El dispositivo se empareja con un empleado activo distinto — el
        // middleware device.token no ata el checado al empleado que emparejó,
        // solo valida que el token siga vigente (ver AuthenticateDeviceToken).
        $pairingOwner = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($pairingOwner->id);
        $inactiveEmployee = $this->createEmployee($enterprise->id, [
            'status' => Employee::STATUS_INACTIVE,
        ]);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', [
                'checks' => [$this->baseCheckPayload(['employee_id' => $inactiveEmployee->id])],
            ]);

        $response->assertStatus(200);
        $this->assertSame('rejected', collect($response->json('data.results'))->first()['status']);
        $this->assertDatabaseCount('time_clock_checks', 0);
    }

    public function test_sync_computes_clock_skew(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $token = $this->pairedDeviceToken($employee->id);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/checador/sync', [
                'checks' => [$this->baseCheckPayload([
                    'employee_id' => $employee->id,
                    'device_synced_at' => now()->subMinutes(45)->toIso8601String(),
                ])],
            ]);
        $response->assertStatus(200);

        $check = TimeClockCheck::first();
        $this->assertGreaterThanOrEqual(2600, $check->clock_skew_seconds);
    }
}
