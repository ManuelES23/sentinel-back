<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/EmployeeControllerFaceRevocationTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\EmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class EmployeeControllerFaceRevocationTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function enrollTemplate(int $employeeId): EmployeeFaceTemplate
    {
        return EmployeeFaceTemplate::create([
            'employee_id' => $employeeId,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => 'private/employee-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => EmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
    }

    public function test_terminate_revokes_active_face_template(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $template = $this->enrollTemplate($employee->id);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);

        $template->refresh();
        $this->assertSame('revoked', $template->status);
        $this->assertNotNull($template->revoked_at);
    }

    public function test_destroy_revokes_active_face_template(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $template = $this->enrollTemplate($employee->id);

        $this->deleteJson("/api/grupoesplendido/rh/empleados/{$employee->id}")
            ->assertStatus(200);

        $template->refresh();
        $this->assertSame('revoked', $template->status);
    }

    public function test_terminate_does_not_fail_without_face_template(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertStatus(200);
    }
}
