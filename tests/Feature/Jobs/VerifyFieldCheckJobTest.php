<?php
// sentinel-back/tests/Feature/Jobs/VerifyFieldCheckJobTest.php
namespace Tests\Feature\Jobs;

use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class VerifyFieldCheckJobTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private function fakeEmbedResponse(array $embedding): void
    {
        Http::fake([
            '*/embed' => Http::response(['embedding' => $embedding, 'model_version' => 'faceapi-v1'], 200),
        ]);
    }

    private function makeCheck(int $employeeId, int $checkerId, array $overrides = []): SfFieldCheck
    {
        Storage::fake('local');

        // Ensure enterprise_id is provided - if not in overrides, create a default enterprise
        if (!isset($overrides['enterprise_id'])) {
            [$tempUser, $tempEnterprise] = $this->createAuthenticatedUserWithEnterprise();
            $overrides['enterprise_id'] = $tempEnterprise->id;
        }

        $path = 'private/sf-field-checks-evidence/' . uniqid() . '.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        return SfFieldCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'enterprise_id' => $overrides['enterprise_id'],
            'sf_employee_id' => $employeeId,
            'checked_by_user_id' => $checkerId,
            'type' => SfFieldCheck::TYPE_CHECK_IN,
            'checked_at' => now(),
            'synced_at' => now(),
            'evidence_photo_path' => $path,
            'client_confidence' => 0.1,
            'verification_status' => SfFieldCheck::STATUS_PENDING,
            'manual_override' => false,
            'clock_skew_seconds' => 5,
        ], $overrides));
    }

    public function test_verified_match_consolidates_into_attendance(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // distancia 0 -> match

        $check = $this->makeCheck($employee->id, $user->id, ['checked_at' => now()]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_VERIFIED, $check->verification_status);
        $this->assertEqualsWithDelta(0.0, (float) $check->server_confidence, 0.0001);

        $record = SfAttendanceRecord::where('sf_employee_id', $employee->id)
            ->where('date', $check->checked_at->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertSame('field_biometric', $record->source_device);
        $this->assertNotNull($record->check_in);
    }

    public function test_mismatch_does_not_consolidate(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.0),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        // Embedding muy distinto -> distancia grande -> mismatch
        $this->fakeEmbedResponse(array_fill(0, 128, 5.0));

        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_MISMATCH, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_no_template_status_when_employee_not_enrolled(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_NO_TEMPLATE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_no_employee_id_stays_no_template(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $check = $this->makeCheck($enterprise->id, $user->id, ['sf_employee_id' => null]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_NO_TEMPLATE, $check->verification_status);
    }

    public function test_manual_override_never_auto_verifies(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // match perfecto, pero manual_override obliga a revisión

        $check = $this->makeCheck($employee->id, $user->id, ['manual_override' => true]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_check_con_motivo_de_revision_queda_en_low_confidence_aunque_coincida(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // match perfecto y reloj bien, pero el motivo de revisión obliga a revisión humana

        $check = $this->makeCheck($employee->id, $user->id, [
            'review_reason' => SfFieldCheck::REVIEW_REASON_DISPOSITIVO_REVOCADO,
        ]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertEqualsWithDelta(0.0, (float) $check->server_confidence, 0.0001);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_clock_skew_beyond_tolerance_forces_review(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding);

        config(['biometrics.clock_skew_tolerance_minutes' => 10]);
        $check = $this->makeCheck($employee->id, $user->id, ['clock_skew_seconds' => 3600]); // 1 hora

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_model_version_mismatch_routes_to_low_confidence(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        // Plantilla enrolada con el modelo actual...
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        // ...pero el face-service (ya actualizado) responde con un embedding de
        // un modelo distinto. Comparar distancias entre modelos incompatibles
        // no es válido: debe enrutarse a revisión, nunca compararse ni
        // auto-verificarse, aunque el embedding en bruto coincida byte a byte.
        Http::fake([
            '*/embed' => Http::response(['embedding' => $embedding, 'model_version' => 'faceapi-v2'], 200),
        ]);

        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertNull($check->server_confidence);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_consolidate_attendance_does_not_downgrade_hr_set_status(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // match perfecto -> verified

        $today = now();

        // RH ya importó este empleado+fecha como incapacidad (vía el flujo de Excel).
        $existing = SfAttendanceRecord::create([
            'sf_employee_id' => $employee->id,
            'date' => $today->toDateString(),
            'status' => 'sick_leave',
            'source_file' => 'nomina-agosto.xlsx',
        ]);
        // Mismo ajuste que hace consolidateAttendance() sobre sus propias filas
        // (ver comentario en VerifyFieldCheckJob::consolidateAttendance): el cast
        // 'date' del modelo serializa con hora al escribir ("...00:00:00"), así
        // que en SQLite hay que normalizar la columna cruda a "AAAA-MM-DD" para
        // que el where('date', $date) de consolidateAttendance() (con fecha SIN
        // hora) encuentre esta fila existente — igual que encontraría cualquier
        // fila real importada por el flujo de Excel una vez normalizada.
        \Illuminate\Support\Facades\DB::table('sf_attendance_records')
            ->where('id', $existing->id)
            ->update(['date' => $today->toDateString()]);

        $check = $this->makeCheck($employee->id, $user->id, ['checked_at' => $today]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_VERIFIED, $check->verification_status);

        $existing->refresh();
        // El status de RH NO se sobreescribe a 'present' solo porque un chequeo
        // biométrico se verificó ese día.
        $this->assertSame('sick_leave', $existing->status);
        // pero check_in/check_out/hours_worked sí se siguen actualizando con
        // normalidad a partir de los chequeos verificados.
        $this->assertNotNull($existing->check_in);
        $this->assertSame('field_biometric', $existing->source_device);
    }

    public function test_check_out_after_check_in_computes_hours_worked(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding);

        $morning = now()->setTime(8, 0);
        $checkIn = $this->makeCheck($employee->id, $user->id, ['type' => SfFieldCheck::TYPE_CHECK_IN, 'checked_at' => $morning]);
        (new VerifyFieldCheckJob($checkIn->id))->handle();

        $evening = now()->setTime(17, 0);
        $checkOut = $this->makeCheck($employee->id, $user->id, ['type' => SfFieldCheck::TYPE_CHECK_OUT, 'checked_at' => $evening]);
        (new VerifyFieldCheckJob($checkOut->id))->handle();

        $record = SfAttendanceRecord::where('sf_employee_id', $employee->id)
            ->where('date', $morning->toDateString())
            ->firstOrFail();

        $this->assertEqualsWithDelta(9.0, (float) $record->hours_worked, 0.01);
    }

    public function test_face_recognition_exception_routes_to_low_confidence(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.2),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        // El face-service no pudo detectar un rostro en la foto -> FaceRecognitionService
        // lanza FaceRecognitionException('no_face'); el evento no debe perderse.
        Http::fake([
            '*/embed' => Http::response(['error' => 'no_face'], 422),
        ]);

        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertNull($check->server_confidence);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_missing_evidence_photo_routes_to_low_confidence(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding);

        $check = $this->makeCheck($employee->id, $user->id);
        // Simula una foto de evidencia borrada/no sincronizada: el disco tiene
        // el registro del SfFieldCheck pero el archivo ya no existe.
        Storage::disk('local')->delete($check->evidence_photo_path);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertNull($check->server_confidence);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_service_unavailable_is_not_caught_and_propagates_for_retry(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.2),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        Http::fake(['*/embed' => Http::response(null, 500)]);

        $check = $this->makeCheck($employee->id, $user->id);

        try {
            (new VerifyFieldCheckJob($check->id))->handle();
            $this->fail('Expected FaceRecognitionException to be thrown.');
        } catch (\App\Exceptions\FaceRecognitionException $e) {
            // expected
        }

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_PENDING, $check->verification_status);
    }

    public function test_no_face_detected_still_marks_low_confidence_without_retry(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.2),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        Http::fake(['*/embed' => Http::response(['error' => 'no_face'], 422)]);

        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
    }

    public function test_failed_callback_marks_check_low_confidence_after_retries_exhausted(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheck($employee->id, $user->id);

        $job = new VerifyFieldCheckJob($check->id);
        $job->failed(new \App\Exceptions\FaceRecognitionException('service_unavailable'));

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertNull($check->server_confidence);
    }

    public function test_failed_callback_does_not_downgrade_an_already_verified_check(): void
    {
        // Simula la carrera descrita en M1: handle() ya dejó el check en
        // 'verified' (y potencialmente ya consolidado en
        // sf_attendance_records) antes de que AttendanceConsolidationService::
        // consolidate() lanzara una excepción en un reintento posterior. Una
        // vez agotados los reintentos, failed() NUNCA debe degradar un check
        // que ya pasó de 'pending' — hacerlo tergiversaría lo que realmente
        // pasó (el check sí se verificó).
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheck($employee->id, $user->id, [
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'server_confidence' => 0.0123,
        ]);

        $job = new VerifyFieldCheckJob($check->id);
        $job->failed(new \RuntimeException('DB error during consolidate()'));

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_VERIFIED, $check->verification_status);
        $this->assertEqualsWithDelta(0.0123, (float) $check->server_confidence, 0.0001);
    }
}
