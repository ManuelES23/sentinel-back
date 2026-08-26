<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/AttendanceRecordCheckInOutTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class AttendanceRecordCheckInOutTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    public function test_check_in_uses_explicit_checked_at_when_given(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        // startOfSecond(): AttendanceRecord::$casts usa el formato de fecha por
        // defecto de Eloquent ('Y-m-d H:i:s', sin microsegundos) — truncar aquí
        // evita comparar contra una precisión que el modelo nunca conserva
        // (pre-existente, no relacionado a este cambio; ver task-2-report.md).
        $realTime = now()->subMinutes(20)->startOfSecond();

        $record = AttendanceRecord::checkIn($employee, 'biometric', null, $realTime);

        $this->assertTrue($record->check_in->equalTo($realTime));
    }

    public function test_check_in_defaults_to_now_without_checked_at(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);

        $before = now();
        $record = AttendanceRecord::checkIn($employee, 'qr');
        $after = now();

        // $before se trunca al segundo por la misma razón de startOfSecond()
        // arriba: check_in queda truncado al segundo por el cast del modelo,
        // así que puede caer una fracción de segundo antes de $before.
        $this->assertTrue($record->check_in->betweenIncluded($before->copy()->startOfSecond(), $after));
    }

    public function test_check_out_uses_explicit_checked_at_when_given(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $checkInTime = now()->setTime(8, 0);
        $checkOutTime = now()->setTime(17, 0);

        AttendanceRecord::checkIn($employee, 'biometric', null, $checkInTime);
        $record = AttendanceRecord::checkOut($employee, 'biometric', null, $checkOutTime);

        $this->assertTrue($record->check_out->equalTo($checkOutTime));
        $this->assertEqualsWithDelta(9.0, (float) $record->hours_worked, 0.01);
    }

    public function test_check_in_twice_same_day_throws(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        AttendanceRecord::checkIn($employee, 'biometric', null, now());

        $this->expectExceptionMessage('Ya registraste tu entrada hoy');
        AttendanceRecord::checkIn($employee, 'biometric', null, now());
    }

    public function test_check_in_with_past_checked_at_creates_record_on_that_date_not_today(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pastCheckedAt = now()->subDays(2)->setTime(8, 0);

        $record = AttendanceRecord::checkIn($employee, 'biometric', null, $pastCheckedAt);

        $this->assertSame($pastCheckedAt->toDateString(), $record->date->toDateString());
        $this->assertNotSame(today()->toDateString(), $record->date->toDateString());
    }

    public function test_check_out_with_past_checked_at_completes_the_matching_past_day_record(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $pastCheckIn = now()->subDays(2)->setTime(8, 0);
        $pastCheckOut = now()->subDays(2)->setTime(17, 0);

        AttendanceRecord::checkIn($employee, 'biometric', null, $pastCheckIn);
        $record = AttendanceRecord::checkOut($employee, 'biometric', null, $pastCheckOut);

        $this->assertSame($pastCheckIn->toDateString(), $record->date->toDateString());
        $this->assertTrue($record->check_out->equalTo($pastCheckOut));
        $this->assertEqualsWithDelta(9.0, (float) $record->hours_worked, 0.01);
    }
}
