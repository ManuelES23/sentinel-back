<?php
// sentinel-back/tests/Feature/Console/RequeueStaleFieldChecksCommandTest.php
namespace Tests\Feature\Console;

use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfFieldCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class RequeueStaleFieldChecksCommandTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures, CreatesRhFixtures;

    private function makeCheck(int $enterpriseId, ?int $employeeId, int $checkerId, array $overrides = []): SfFieldCheck
    {
        return SfFieldCheck::create(array_merge([
            'enterprise_id' => $enterpriseId,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employeeId,
            'checked_by_user_id' => $checkerId,
            'type' => 'check_in',
            'checked_at' => now(),
            'synced_at' => now(),
            'evidence_photo_path' => 'private/sf-field-checks-evidence/fake.jpg',
            'verification_status' => SfFieldCheck::STATUS_PENDING,
            'manual_override' => false,
            'clock_skew_seconds' => 0,
        ], $overrides));
    }

    public function test_requeues_pending_check_older_than_threshold(): void
    {
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $stale = $this->makeCheck($enterprise->id, $employee->id, $user->id, [
            'synced_at' => now()->subHours(2),
        ]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertPushed(VerifyFieldCheckJob::class, fn ($job) => $job->fieldCheckId === $stale->id);
    }

    public function test_does_not_requeue_recent_pending_check_still_within_normal_retry_window(): void
    {
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $recent = $this->makeCheck($enterprise->id, $employee->id, $user->id, [
            'synced_at' => now()->subMinutes(10),
        ]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertNotPushed(VerifyFieldCheckJob::class, fn ($job) => $job->fieldCheckId === $recent->id);
    }

    public function test_falls_back_to_created_at_when_synced_at_is_null(): void
    {
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $stale = $this->makeCheck($enterprise->id, $employee->id, $user->id, [
            'synced_at' => null,
        ]);
        $stale->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertPushed(VerifyFieldCheckJob::class, fn ($job) => $job->fieldCheckId === $stale->id);
    }

    public function test_does_not_requeue_already_resolved_checks_even_if_old(): void
    {
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $resolvedStatuses = [
            SfFieldCheck::STATUS_VERIFIED,
            SfFieldCheck::STATUS_LOW_CONFIDENCE,
            SfFieldCheck::STATUS_MISMATCH,
            SfFieldCheck::STATUS_NO_TEMPLATE,
            SfFieldCheck::STATUS_MANUALLY_APPROVED,
            SfFieldCheck::STATUS_REJECTED,
        ];

        $checks = [];
        foreach ($resolvedStatuses as $status) {
            $checks[] = $this->makeCheck($enterprise->id, $employee->id, $user->id, [
                'synced_at' => now()->subHours(2),
                'verification_status' => $status,
            ]);
        }

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertNotPushed(VerifyFieldCheckJob::class);
    }

    public function test_running_twice_keeps_requeueing_a_check_still_stuck_pending(): void
    {
        // Si el requeue tampoco logra completar (p. ej. el worker sigue caído),
        // el check sigue viejo y pending en la siguiente corrida — el sweeper
        // debe seguir intentando, no marcarlo como "ya procesado" una sola vez.
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $stale = $this->makeCheck($enterprise->id, $employee->id, $user->id, [
            'synced_at' => now()->subHours(2),
        ]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();
        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertPushed(VerifyFieldCheckJob::class, 2);
    }

    private function makeTimeClockCheck(int $employeeId, array $overrides = []): \App\Models\TimeClockCheck
    {
        return \App\Models\TimeClockCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employeeId,
            'type' => 'check_in',
            'checked_at' => now(),
            'synced_at' => now(),
            'verification_status' => \App\Models\TimeClockCheck::STATUS_PENDING,
            'clock_skew_seconds' => 0,
        ], $overrides));
    }

    public function test_requeues_stale_pending_time_clock_check(): void
    {
        Queue::fake();
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $stale = $this->makeTimeClockCheck($employee->id, ['synced_at' => now()->subHours(2)]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertPushed(\App\Jobs\VerifyTimeClockCheckJob::class, fn ($job) => $job->timeClockCheckId === $stale->id);
    }

    public function test_does_not_requeue_recent_pending_time_clock_check(): void
    {
        Queue::fake();
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $recent = $this->makeTimeClockCheck($employee->id, ['synced_at' => now()->subMinutes(10)]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertNotPushed(\App\Jobs\VerifyTimeClockCheckJob::class, fn ($job) => $job->timeClockCheckId === $recent->id);
    }

    public function test_does_not_requeue_already_resolved_time_clock_checks_even_if_old(): void
    {
        Queue::fake();
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $resolvedStatuses = [
            \App\Models\TimeClockCheck::STATUS_VERIFIED,
            \App\Models\TimeClockCheck::STATUS_LOW_CONFIDENCE,
            \App\Models\TimeClockCheck::STATUS_NO_TEMPLATE,
            \App\Models\TimeClockCheck::STATUS_MANUALLY_APPROVED,
            \App\Models\TimeClockCheck::STATUS_REJECTED,
        ];

        foreach ($resolvedStatuses as $status) {
            $this->makeTimeClockCheck($employee->id, ['synced_at' => now()->subHours(2), 'verification_status' => $status]);
        }

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertNotPushed(\App\Jobs\VerifyTimeClockCheckJob::class);
    }

    public function test_sf_field_check_and_time_clock_check_sweeps_are_independent(): void
    {
        // Confirma que extender el comando a TimeClockCheck no rompió el
        // barrido existente de SfFieldCheck ni los mezcló entre sí.
        Queue::fake();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $sfEmployee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $staleFieldCheck = $this->makeCheck($enterprise->id, $sfEmployee->id, $user->id, ['synced_at' => now()->subHours(2)]);

        [, $rhEnterprise] = $this->createAuthenticatedRhUser();
        $rhEmployee = $this->createEmployee($rhEnterprise->id);
        $staleTimeClockCheck = $this->makeTimeClockCheck($rhEmployee->id, ['synced_at' => now()->subHours(2)]);

        $this->artisan('biometrics:requeue-stale-checks')->assertSuccessful();

        Queue::assertPushed(VerifyFieldCheckJob::class, fn ($job) => $job->fieldCheckId === $staleFieldCheck->id);
        Queue::assertPushed(\App\Jobs\VerifyTimeClockCheckJob::class, fn ($job) => $job->timeClockCheckId === $staleTimeClockCheck->id);
    }
}
