<?php
// sentinel-back/tests/Feature/Jobs/VerifyTimeClockCheckJobTest.php
namespace Tests\Feature\Jobs;

use App\Jobs\VerifyTimeClockCheckJob;
use App\Models\AttendanceRecord;
use App\Models\EmployeeFaceTemplate;
use App\Models\TimeClockCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class VerifyTimeClockCheckJobTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function fakeEmbedResponse(array $embedding): void
    {
        Http::fake([
            '*/embed' => Http::response(['embedding' => $embedding, 'model_version' => 'faceapi-v1'], 200),
        ]);
    }

    private function makeCheck(int $employeeId, array $overrides = []): TimeClockCheck
    {
        Storage::fake('local');
        $path = 'private/time-clock-evidence/' . uniqid() . '.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        return TimeClockCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employeeId,
            'type' => TimeClockCheck::TYPE_CHECK_IN,
            'checked_at' => now(),
            'synced_at' => now(),
            'evidence_photo_path' => $path,
            'verification_status' => TimeClockCheck::STATUS_PENDING,
            'clock_skew_seconds' => 5,
        ], $overrides));
    }

    /**
     * `handle()` declara sus dependencias (FaceRecognitionService,
     * FaceMatchingService) como parametros con method injection, tal como
     * hace el worker real de la cola (Illuminate\Queue\CallQueuedHandler,
     * que internamente usa Container::call() para resolverlas). Un
     * `$job->handle()` llamado a pelo en un test es una llamada de PHP
     * puro sin ese paso — por eso aqui se pasa igual por el contenedor con
     * app()->call(), para invocar el job exactamente como lo hace la cola
     * real en producción.
     */
    private function runJob(VerifyTimeClockCheckJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function enrollTemplate(int $employeeId, array $embedding): EmployeeFaceTemplate
    {
        return EmployeeFaceTemplate::create([
            'employee_id' => $employeeId,
            'embedding' => $embedding,
            'photo_path' => 'private/employee-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => EmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
    }

    public function test_matching_face_verifies_and_registers_check_in(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $embedding = array_fill(0, 128, 0.2);
        $this->enrollTemplate($employee->id, $embedding);
        $this->fakeEmbedResponse($embedding); // distancia 0 -> match

        $check = $this->makeCheck($employee->id, ['checked_at' => now()->setTime(8, 0)]);
        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_VERIFIED, $check->verification_status);

        // whereDate() (no where() con un string exacto) porque la columna
        // 'date' se guarda como datetime completo ("2026-08-26 00:00:00")
        // aunque el cast del modelo sea 'date' — el formato de
        // almacenamiento de Eloquent no trunca la hora, solo la
        // presentación al leer el atributo.
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $check->checked_at->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->check_in);
        $this->assertSame('biometric', $record->check_in_method);
    }

    public function test_non_matching_face_marks_low_confidence_without_consolidating(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $this->enrollTemplate($employee->id, array_fill(0, 128, 0.0));
        $this->fakeEmbedResponse(array_fill(0, 128, 5.0)); // distancia grande

        $check = $this->makeCheck($employee->id);
        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_no_template_status_when_employee_not_enrolled(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $check = $this->makeCheck($employee->id);

        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_NO_TEMPLATE, $check->verification_status);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_already_checked_in_today_marks_rejected_without_crashing_job(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $embedding = array_fill(0, 128, 0.2);
        $this->enrollTemplate($employee->id, $embedding);
        $this->fakeEmbedResponse($embedding);

        AttendanceRecord::checkIn($employee, 'biometric', null, now()->subHour());

        $check = $this->makeCheck($employee->id, ['checked_at' => now()]);
        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_REJECTED, $check->verification_status);
        $this->assertStringContainsString('Ya registraste tu entrada hoy', $check->review_notes ?? '');
    }

    public function test_check_out_after_check_in_computes_hours_worked(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $embedding = array_fill(0, 128, 0.2);
        $this->enrollTemplate($employee->id, $embedding);
        $this->fakeEmbedResponse($embedding);

        $checkIn = $this->makeCheck($employee->id, ['type' => TimeClockCheck::TYPE_CHECK_IN, 'checked_at' => now()->setTime(8, 0)]);
        $this->runJob(new VerifyTimeClockCheckJob($checkIn->id));

        $checkOut = $this->makeCheck($employee->id, ['type' => TimeClockCheck::TYPE_CHECK_OUT, 'checked_at' => now()->setTime(17, 0)]);
        $this->runJob(new VerifyTimeClockCheckJob($checkOut->id));

        $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(9.0, (float) $record->hours_worked, 0.01);
    }

    public function test_clock_skew_beyond_tolerance_forces_review_without_consolidating(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $embedding = array_fill(0, 128, 0.2);
        $this->enrollTemplate($employee->id, $embedding);
        $this->fakeEmbedResponse($embedding); // match perfecto, pero el reloj del dispositivo esta desfasado

        config(['biometrics.clock_skew_tolerance_minutes' => 10]);
        $check = $this->makeCheck($employee->id, ['clock_skew_seconds' => 3600]); // 1 hora

        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * Ex-guarda de medianoche (hallazgo #3 de la revision final del branch,
     * ver Global Constraints/Fix 3 del prompt de revision): la guarda que
     * mandaba a low_confidence cualquier checado offline con checked_at de
     * un dia distinto de "hoy" server-side quedo OBSOLETA cuando
     * AttendanceRecord::checkIn()/checkOut() se corrigio para anclar al
     * dia de $checkedAt (no a today() del servidor) — ver
     * test_review_approve_with_past_checked_at_registers_on_that_date_not_review_day
     * en TimeClockCheckControllerTest, que ya prueba esto a nivel
     * AttendanceRecord. Este test prueba el flujo completo del job: un
     * checado offline con checked_at de AYER que matchea correctamente
     * contra la plantilla facial debe consolidarse normalmente a
     * STATUS_VERIFIED, con un AttendanceRecord fechado al dia de
     * checked_at (no al dia de hoy).
     */
    public function test_offline_check_synced_after_midnight_consolidates_normally_on_checked_at_day(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $embedding = array_fill(0, 128, 0.2);
        $this->enrollTemplate($employee->id, $embedding);
        $this->fakeEmbedResponse($embedding); // match perfecto

        $checkedAt = now()->subDay()->setTime(23, 50);
        $check = $this->makeCheck($employee->id, ['checked_at' => $checkedAt]);
        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_VERIFIED, $check->verification_status);

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('date', $checkedAt->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->check_in);
        $this->assertTrue($record->check_in->equalTo($checkedAt));
    }

    public function test_stale_model_version_template_is_treated_as_no_template(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        // Plantilla enrolada con una version de modelo VIEJA (ej. tras
        // actualizar el face-service sin volver a enrolar) — comparar
        // embeddings de dos versiones distintas no es valido aunque
        // "coincidan" numericamente, asi que se trata igual que "sin
        // plantilla": va a revision, nunca se compara.
        \App\Models\EmployeeFaceTemplate::create([
            'employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.2),
            'photo_path' => 'private/employee-face-templates/x.jpg',
            'model_version' => 'faceapi-v0-vieja',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => \App\Models\EmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse(array_fill(0, 128, 0.2)); // face-service actual sigue reportando faceapi-v1

        $check = $this->makeCheck($employee->id);
        $this->runJob(new VerifyTimeClockCheckJob($check->id));

        $check->refresh();
        $this->assertSame(TimeClockCheck::STATUS_NO_TEMPLATE, $check->verification_status);
        $this->assertDatabaseCount('attendance_records', 0);
    }
}
