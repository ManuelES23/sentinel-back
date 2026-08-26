<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/EmployeeFaceTemplateControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\ActivityLog;
use App\Models\EmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class EmployeeFaceTemplateControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function fakeNodeService(?array $embedding = null): void
    {
        Http::fake([
            '*/embed' => Http::response([
                'embedding' => $embedding ?? array_fill(0, 128, 0.25),
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);
    }

    private function enrollUrl(int $employeeId): string
    {
        return "/api/grupoesplendido/rh/empleados/{$employeeId}/face-template";
    }

    public function test_requires_authentication(): void
    {
        $this->postJson($this->enrollUrl(1))->assertStatus(401);
    }

    public function test_enroll_requires_consent(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('employee_face_templates', 0);
    }

    public function test_enroll_creates_template_with_canonical_embedding(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $template = EmployeeFaceTemplate::firstOrFail();
        $this->assertSame($employee->id, $template->employee_id);
        $this->assertCount(128, $template->embedding);
        $this->assertSame('faceapi-v1', $template->model_version);
        $this->assertSame('active', $template->status);
        Storage::disk('local')->assertExists($template->photo_path);
        $this->assertStringStartsWith('private/employee-face-templates/', $template->photo_path);
    }

    public function test_reenroll_replaces_existing_template(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->assertSame(1, EmployeeFaceTemplate::where('employee_id', $employee->id)->count());
    }

    public function test_enroll_does_not_leak_embedding_into_activity_log(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $log = ActivityLog::where('model', 'EmployeeFaceTemplate')->where('action', 'create')->firstOrFail();

        $this->assertArrayNotHasKey('embedding', $log->new_values ?? []);
        $this->assertNull($log->old_values);
        // El resto de los atributos sí se deben seguir logueando con normalidad.
        $this->assertArrayHasKey('photo_path', $log->new_values);
        $this->assertArrayHasKey('status', $log->new_values);
    }

    public function test_reenroll_does_not_leak_old_or_new_embedding_into_activity_log(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $updateLog = ActivityLog::where('model', 'EmployeeFaceTemplate')->where('action', 'update')->firstOrFail();

        $this->assertArrayNotHasKey('embedding', $updateLog->new_values ?? []);
        $this->assertArrayNotHasKey('embedding', $updateLog->old_values ?? []);
        // El resto de los campos que sí cambiaron (p. ej. photo_path) se deben
        // seguir logueando con normalidad.
        $this->assertArrayHasKey('photo_path', $updateLog->new_values);
    }

    public function test_reenroll_preserves_consent_document_path_when_not_reattached(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
            'consent_document' => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $firstTemplate = EmployeeFaceTemplate::where('employee_id', $employee->id)->firstOrFail();
        $originalConsentPath = $firstTemplate->consent_document_path;
        $this->assertNotNull($originalConsentPath);
        Storage::disk('local')->assertExists($originalConsentPath);

        // Re-enroll sin volver a adjuntar el documento de consentimiento.
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $template = EmployeeFaceTemplate::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($originalConsentPath, $template->consent_document_path);
        Storage::disk('local')->assertExists($originalConsentPath);
    }

    public function test_enroll_returns_422_when_no_face_detected(): void
    {
        Storage::fake('local');
        Http::fake(['*/embed' => Http::response(['error' => 'no_face'], 422)]);
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('landscape.jpg', 640, 480),
            'consent_signed' => '1',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertDatabaseCount('employee_face_templates', 0);
    }

    public function test_revoke_marks_template_revoked(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->deleteJson($this->enrollUrl($employee->id))
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $template = EmployeeFaceTemplate::firstOrFail();
        $this->assertSame('revoked', $template->status);
        $this->assertNotNull($template->revoked_at);
        $this->assertNull($employee->fresh()->faceTemplate);
    }

    public function test_revoke_without_active_template_returns_404(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->deleteJson($this->enrollUrl($employee->id))->assertStatus(404);
    }

    public function test_employee_index_includes_has_face_template(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $enrolled = $this->createEmployee($enterprise->id);
        $notEnrolled = $this->createEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($enrolled->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $response = $this->getJson('/api/grupoesplendido/rh/empleados?enterprise_id=' . $enterprise->id);
        $response->assertStatus(200);

        $rows = collect($response->json('data.data') ?? $response->json('data'));
        $this->assertTrue((bool) $rows->firstWhere('id', $enrolled->id)['has_face_template']);
        $this->assertFalse((bool) $rows->firstWhere('id', $notEnrolled->id)['has_face_template']);
    }

    public function test_photo_returns_binary_for_active_template(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->get("/api/grupoesplendido/rh/empleados/{$employee->id}/face-template/photo")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_photo_returns_real_content_type_for_png_upload(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.png', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->get("/api/grupoesplendido/rh/empleados/{$employee->id}/face-template/photo")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_photo_returns_404_without_active_template(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $this->get("/api/grupoesplendido/rh/empleados/{$employee->id}/face-template/photo")
            ->assertStatus(404);
    }

    public function test_store_rejects_employee_from_a_different_enterprise(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$userA, $enterpriseA] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterpriseA->id);

        // createAuthenticatedRhUser() re-autentica (Sanctum::actingAs) al
        // usuario recién creado: el usuario que actúa a partir de aquí es
        // $userB, miembro únicamente de $enterpriseB.
        [$userB, $enterpriseB] = $this->createAuthenticatedRhUser();

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(403);

        $this->assertDatabaseCount('employee_face_templates', 0);
    }

    public function test_destroy_rejects_employee_from_a_different_enterprise(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$userA, $enterpriseA] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterpriseA->id);
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        [$userB, $enterpriseB] = $this->createAuthenticatedRhUser();

        $this->deleteJson($this->enrollUrl($employee->id))->assertStatus(403);

        $template = EmployeeFaceTemplate::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('active', $template->status);
    }

    public function test_photo_rejects_employee_from_a_different_enterprise(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$userA, $enterpriseA] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterpriseA->id);
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        [$userB, $enterpriseB] = $this->createAuthenticatedRhUser();

        $this->get("/api/grupoesplendido/rh/empleados/{$employee->id}/face-template/photo")
            ->assertStatus(403);
    }
}
