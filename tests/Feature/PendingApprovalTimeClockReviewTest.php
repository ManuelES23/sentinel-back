<?php
// sentinel-back/tests/Feature/PendingApprovalTimeClockReviewTest.php
namespace Tests\Feature;

use App\Models\TimeClockCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class PendingApprovalTimeClockReviewTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    /**
     * El carve-out 'time_clock_check_review' vive en
     * PendingApprovalController::summary() (mismo punto de integración que
     * getFieldCheckReviewProcessEntry() / 'field_check_review' — ver
     * app/Http/Controllers/Api/PendingApprovalController.php línea ~33).
     * index() ('/api/pending-approvals') no incluye ninguno de los dos
     * carve-outs de submódulo, así que el test real pega a
     * '/api/pending-approvals/summary'.
     */
    public function test_entry_absent_without_submodule_access(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        TimeClockCheck::create([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'verification_status' => 'low_confidence',
            'clock_skew_seconds' => 0,
        ]);

        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertStatus(200);
        $codes = collect($response->json('data.processes'))->pluck('code');
        $this->assertFalse($codes->contains('time_clock_check_review'));
    }
}
