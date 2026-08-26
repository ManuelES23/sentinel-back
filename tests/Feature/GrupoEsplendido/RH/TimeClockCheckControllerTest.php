<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/TimeClockCheckControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\TimeClockCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class TimeClockCheckControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function makeCheckDirectly(int $employeeId, array $overrides = []): TimeClockCheck
    {
        return TimeClockCheck::create(array_merge([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employeeId,
            'type' => TimeClockCheck::TYPE_CHECK_IN,
            'checked_at' => now(),
            'verification_status' => TimeClockCheck::STATUS_LOW_CONFIDENCE,
            'clock_skew_seconds' => 0,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/grupoesplendido/rh/asistencia/checador?enterprise_id=1')
            ->assertStatus(401);
    }

    public function test_index_filters_by_enterprise_and_status(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $lowConfidence = $this->makeCheckDirectly($employee->id, ['verification_status' => 'low_confidence']);
        $verified = $this->makeCheckDirectly($employee->id, ['verification_status' => 'verified']);

        $response = $this->getJson("/api/grupoesplendido/rh/asistencia/checador?enterprise_id={$enterprise->id}&verification_status=low_confidence");

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($lowConfidence->id));
        $this->assertFalse($ids->contains($verified->id));
    }

    public function test_index_excludes_other_enterprises(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        [, $otherEnterprise] = $this->createAuthenticatedRhUser();
        $otherEmployee = $this->createEmployee($otherEnterprise->id);
        $this->makeCheckDirectly($otherEmployee->id);

        // Ensure we're authenticated as the first user (first enterprise)
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->getJson("/api/grupoesplendido/rh/asistencia/checador?enterprise_id={$enterprise->id}");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.data'));
    }

    public function test_review_requires_authentication(): void
    {
        // Create enterprise and employee to satisfy FK constraint (without auth)
        $enterprise = Enterprise::create([
            'name' => 'Test Enterprise',
            'slug' => 'test-enterprise',
            'description' => 'Test',
            'is_active' => true,
        ]);
        $employee = Employee::create([
            'enterprise_id' => $enterprise->id,
            'employee_number' => 'EMP-9999',
            'first_name' => 'Test',
            'last_name' => 'User',
            'pin' => '999999',
            'qr_code' => 'QR-TEST-9999',
            'hire_date' => now()->subYear(),
            'status' => Employee::STATUS_ACTIVE,
        ]);
        $check = $this->makeCheckDirectly($employee->id);

        $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", ['decision' => 'approve'])
            ->assertStatus(401);
    }

    public function test_review_rejects_check_from_another_enterprise(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        [, $otherEnterprise] = $this->createAuthenticatedRhUser();
        $otherEmployee = $this->createEmployee($otherEnterprise->id);
        $check = $this->makeCheckDirectly($otherEmployee->id);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", ['decision' => 'approve'])
            ->assertStatus(403);
    }

    public function test_review_approve_registers_check_in_with_real_time(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $checkedAt = now()->setTime(8, 0);
        $check = $this->makeCheckDirectly($employee->id, ['checked_at' => $checkedAt]);

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", [
            'decision' => 'approve',
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame('manually_approved', $check->verification_status);
        $this->assertSame($user->id, $check->reviewed_by_user_id);

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $checkedAt->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertTrue($record->check_in->equalTo($checkedAt));
    }

    public function test_review_reject_never_consolidates(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $check = $this->makeCheckDirectly($employee->id);

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", [
            'decision' => 'reject',
        ]);

        $response->assertStatus(200);
        $check->refresh();
        $this->assertSame('rejected', $check->verification_status);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_review_on_already_resolved_check_fails(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $check = $this->makeCheckDirectly($employee->id, ['verification_status' => 'verified']);

        $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", [
            'decision' => 'approve',
        ])->assertStatus(422);
    }

    public function test_review_approve_when_already_checked_in_marks_rejected_not_500(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        AttendanceRecord::checkIn($employee, 'biometric', null, now()->subHour());
        $check = $this->makeCheckDirectly($employee->id, ['checked_at' => now()]);

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", [
            'decision' => 'approve',
        ]);

        $response->assertStatus(422);
        $check->refresh();
        $this->assertSame('low_confidence', $check->verification_status); // sin cambio, sigue pendiente de resolver
    }

    public function test_review_approve_with_past_checked_at_registers_on_that_date_not_review_day(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pastCheckedAt = now()->subDays(3)->setTime(8, 0);
        $check = $this->makeCheckDirectly($employee->id, ['checked_at' => $pastCheckedAt]);

        $response = $this->postJson("/api/grupoesplendido/rh/asistencia/checador/{$check->id}/review", [
            'decision' => 'approve',
        ]);

        $response->assertStatus(200);
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $pastCheckedAt->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertTrue($record->check_in->equalTo($pastCheckedAt));
    }
}
