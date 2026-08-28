<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/RosterPackageControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\DevicePairing;
use App\Models\EmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class RosterPackageControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function pairedDeviceToken(): string
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $raw = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
        ]);
        return $raw;
    }

    private function enrollTemplate(int $employeeId, array $overrides = []): EmployeeFaceTemplate
    {
        return EmployeeFaceTemplate::create(array_merge([
            'employee_id' => $employeeId,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => 'private/employee-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => EmployeeFaceTemplate::STATUS_ACTIVE,
        ], $overrides));
    }

    public function test_roster_package_requires_device_token_header(): void
    {
        $this->getJson('/api/checador/roster-package')->assertStatus(401);
    }

    public function test_roster_package_rejects_invalid_token(): void
    {
        $this->withHeaders(['X-Device-Token' => 'token-invalido'])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }

    public function test_roster_package_rejects_revoked_token(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $raw = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
            'revoked_at' => now(),
        ]);

        $this->withHeaders(['X-Device-Token' => $raw])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }

    public function test_roster_package_returns_active_enrolled_employees_across_enterprises(): void
    {
        $token = $this->pairedDeviceToken();
        [, $enterpriseA] = $this->createAuthenticatedRhUser();
        [, $enterpriseB] = $this->createAuthenticatedRhUser();
        $employeeA = $this->createEmployee($enterpriseA->id, ['status' => 'active']);
        $employeeB = $this->createEmployee($enterpriseB->id, ['status' => 'active']);
        $this->enrollTemplate($employeeA->id);
        $this->enrollTemplate($employeeB->id);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->getJson('/api/checador/roster-package');

        $response->assertStatus(200);
        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertTrue($ids->contains($employeeA->id));
        $this->assertTrue($ids->contains($employeeB->id)); // cruza empresas, sin filtrar
        $this->assertSame('faceapi-v1', $response->json('data.model_version'));
    }

    public function test_roster_package_excludes_employees_without_active_template(): void
    {
        $token = $this->pairedDeviceToken();
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $noTemplateEmployee = $this->createEmployee($enterprise->id, ['status' => 'active']);
        $revokedEmployee = $this->createEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollTemplate($revokedEmployee->id, ['status' => EmployeeFaceTemplate::STATUS_REVOKED]);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->getJson('/api/checador/roster-package');

        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertFalse($ids->contains($noTemplateEmployee->id));
        $this->assertFalse($ids->contains($revokedEmployee->id));
    }

    public function test_roster_package_updates_last_used_at_on_the_pairing(): void
    {
        $token = $this->pairedDeviceToken();
        $pairing = DevicePairing::first();
        $this->assertNull($pairing->last_used_at);

        $this->withHeaders(['X-Device-Token' => $token])->getJson('/api/checador/roster-package');

        $this->assertNotNull($pairing->fresh()->last_used_at);
    }
}
