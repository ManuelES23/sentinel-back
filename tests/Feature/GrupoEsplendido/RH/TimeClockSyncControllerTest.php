<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/TimeClockSyncControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Jobs\VerifyTimeClockCheckJob;
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

    private function baseCheckPayload(array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'employee_number' => 'EMP-0001',
            'pin' => '000001',
            'type' => 'check_in',
            'checked_at' => now()->toIso8601String(),
            'device_synced_at' => now()->toIso8601String(),
            'evidence_photo' => $this->tinyJpegBase64(),
        ], $overrides);
    }

    public function test_sync_does_not_require_authentication(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $this->createEmployee($enterprise->id, ['employee_number' => 'EMP-0001', 'pin' => '000001']);
        // Simula petición pública real: sin token de Sanctum.
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/checador/sync', ['checks' => [$this->baseCheckPayload()]]);

        $response->assertStatus(200);
    }

    public function test_sync_rejects_wrong_pin_without_creating_row(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $this->createEmployee($enterprise->id, ['employee_number' => 'EMP-0001', 'pin' => '000001']);

        $response = $this->postJson('/api/checador/sync', [
            'checks' => [$this->baseCheckPayload(['pin' => '999999'])],
        ]);

        $response->assertStatus(200);
        $this->assertSame('rejected', collect($response->json('data.results'))->first()['status']);
        $this->assertDatabaseCount('time_clock_checks', 0);
    }

    public function test_sync_accepts_valid_check_and_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $this->createEmployee($enterprise->id, ['employee_number' => 'EMP-0001', 'pin' => '000001']);

        $uuid = (string) Str::uuid();
        $response = $this->postJson('/api/checador/sync', [
            'checks' => [$this->baseCheckPayload(['client_uuid' => $uuid])],
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
        $this->createEmployee($enterprise->id, ['employee_number' => 'EMP-0001', 'pin' => '000001']);
        $payload = $this->baseCheckPayload();

        $this->postJson('/api/checador/sync', ['checks' => [$payload]])->assertStatus(200);
        $second = $this->postJson('/api/checador/sync', ['checks' => [$payload]]);

        $this->assertSame('duplicate', collect($second->json('data.results'))->first()['status']);
        $this->assertSame(1, TimeClockCheck::where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_sync_rejects_inactive_employee(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $this->createEmployee($enterprise->id, [
            'employee_number' => 'EMP-0001',
            'pin' => '000001',
            'status' => \App\Models\Employee::STATUS_INACTIVE,
        ]);

        $response = $this->postJson('/api/checador/sync', ['checks' => [$this->baseCheckPayload()]]);

        $this->assertSame('rejected', collect($response->json('data.results'))->first()['status']);
        $this->assertDatabaseCount('time_clock_checks', 0);
    }

    public function test_sync_computes_clock_skew(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $this->createEmployee($enterprise->id, ['employee_number' => 'EMP-0001', 'pin' => '000001']);

        $response = $this->postJson('/api/checador/sync', [
            'checks' => [$this->baseCheckPayload(['device_synced_at' => now()->subMinutes(45)->toIso8601String()])],
        ]);
        $response->assertStatus(200);

        $check = TimeClockCheck::first();
        $this->assertGreaterThanOrEqual(2600, $check->clock_skew_seconds);
    }
}
