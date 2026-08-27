<?php
// sentinel-back/tests/Feature/Console/PurgeBiometricDataCommandTest.php
namespace Tests\Feature\Console;

use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRhFixtures;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class PurgeBiometricDataCommandTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures, CreatesRhFixtures;

    public function test_purges_evidence_photo_of_old_resolved_check(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $path = 'private/sf-field-checks-evidence/old.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = SfFieldCheck::create([
            'enterprise_id' => $enterprise->id,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employee->id,
            'checked_by_user_id' => $user->id,
            'type' => 'check_in',
            'checked_at' => now()->subDays(100),
            'evidence_photo_path' => $path,
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'clock_skew_seconds' => 0,
        ]);
        $check->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertNull($check->evidence_photo_path);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_does_not_purge_evidence_photo_of_recent_check(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $path = 'private/sf-field-checks-evidence/recent.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = SfFieldCheck::create([
            'enterprise_id' => $enterprise->id,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employee->id,
            'checked_by_user_id' => $user->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'evidence_photo_path' => $path,
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'clock_skew_seconds' => 0,
        ]);

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertSame($path, $check->evidence_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_does_not_purge_pending_or_unresolved_check_even_if_old(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $path = 'private/sf-field-checks-evidence/pending.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = SfFieldCheck::create([
            'enterprise_id' => $enterprise->id,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employee->id,
            'checked_by_user_id' => $user->id,
            'type' => 'check_in',
            'checked_at' => now()->subDays(200),
            'evidence_photo_path' => $path,
            'verification_status' => SfFieldCheck::STATUS_LOW_CONFIDENCE,
            'clock_skew_seconds' => 0,
        ]);
        $check->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertSame($path, $check->evidence_photo_path);
    }

    public function test_purges_photo_and_embedding_of_old_revoked_template(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $path = 'private/sf-face-templates/old.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $template = SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => $path,
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now()->subDays(200),
            'consent_signed_at' => now()->subDays(200),
            'status' => SfEmployeeFaceTemplate::STATUS_REVOKED,
            'revoked_at' => now()->subDays(40),
        ]);

        $this->artisan('biometrics:purge')->assertSuccessful();

        $template->refresh();
        $this->assertNull($template->photo_path);
        $this->assertNull($template->embedding);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_does_not_purge_recently_revoked_template(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $path = 'private/sf-face-templates/recent.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $template = SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => $path,
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_REVOKED,
            'revoked_at' => now()->subDays(5),
        ]);

        $this->artisan('biometrics:purge')->assertSuccessful();

        $template->refresh();
        $this->assertSame($path, $template->photo_path);
    }

    public function test_running_twice_is_a_no_op_on_already_purged_rows(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $check = SfFieldCheck::create([
            'enterprise_id' => $enterprise->id,
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employee->id,
            'checked_by_user_id' => $user->id,
            'type' => 'check_in',
            'checked_at' => now()->subDays(100),
            'evidence_photo_path' => null,
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'clock_skew_seconds' => 0,
        ]);
        $check->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('biometrics:purge')->assertSuccessful();
        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertNull($check->evidence_photo_path);
    }

    public function test_purges_evidence_photo_of_old_resolved_time_clock_check(): void
    {
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $path = 'private/time-clock-evidence/old.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = \App\Models\TimeClockCheck::create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'type' => 'check_in',
            'checked_at' => now()->subDays(100),
            'evidence_photo_path' => $path,
            'verification_status' => 'verified',
            'clock_skew_seconds' => 0,
        ]);
        $check->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertNull($check->evidence_photo_path);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_does_not_purge_recent_time_clock_check(): void
    {
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $path = 'private/time-clock-evidence/recent.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = \App\Models\TimeClockCheck::create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'evidence_photo_path' => $path,
            'verification_status' => 'verified',
            'clock_skew_seconds' => 0,
        ]);

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertSame($path, $check->evidence_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    /**
     * Hallazgo #4 de la revision final del branch (ver Global Constraints/
     * Fix 4 del prompt de revision): el corte de retencion de
     * purgeTimeClockCheckEvidence() debe usar created_at (server-side,
     * inmutable), NUNCA checked_at (dato del dispositivo del empleado,
     * potencialmente incorrecto). Este check simula un dispositivo con
     * checked_at viejo (cruzaria el corte de retencion si se usara esa
     * columna) pero created_at reciente (default de Eloquent al crear el
     * registro en este mismo test) — con el fix, NO debe purgarse. Los
     * demas tests de esta clase mueven checked_at y created_at juntos, asi
     * que no detectan una regresion a checked_at por si solos; este si.
     */
    public function test_time_clock_check_purge_cutoff_uses_created_at_not_checked_at(): void
    {
        Storage::fake('local');
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $path = 'private/time-clock-evidence/device-clock-wrong.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $check = \App\Models\TimeClockCheck::create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'type' => 'check_in',
            'checked_at' => now()->subDays(100), // dato de dispositivo, viejo
            'evidence_photo_path' => $path,
            'verification_status' => 'verified',
            'clock_skew_seconds' => 0,
        ]);
        // created_at queda en su valor por defecto (now()) — NO se hace
        // forceFill aqui, a proposito, para separarlo de checked_at.

        $this->artisan('biometrics:purge')->assertSuccessful();

        $check->refresh();
        $this->assertSame($path, $check->evidence_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }
}
