<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfAttendanceRecord;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;
use App\Models\SfPositionGroup;
use App\Services\SplendidFarms\SfPayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfNominaPuestoDelDiaTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private function position(int $enterpriseId, string $name, float $salary, string $groupCode): SfPosition
    {
        $group = SfPositionGroup::firstOrCreate(
            ['enterprise_id' => $enterpriseId, 'code' => $groupCode],
            ['name' => 'GRUPO ' . $groupCode, 'salary' => $salary, 'is_active' => true]
        );

        return SfPosition::create([
            'enterprise_id' => $enterpriseId,
            'code' => 'P-' . substr(md5($name), 0, 6),
            'name' => $name,
            'sf_position_group_id' => $group->id,
            'is_active' => true,
        ]);
    }

    private function attend(int $employeeId, string $date, string $status = 'present'): void
    {
        SfAttendanceRecord::create(['sf_employee_id' => $employeeId, 'date' => $date, 'status' => $status]);
    }

    private function assign(int $employeeId, SfPosition $position, string $date): void
    {
        SfPositionAssignment::create(['sf_employee_id' => $employeeId, 'sf_position_id' => $position->id, 'assignment_date' => $date]);
    }

    public function test_dia_con_puesto_paga_grupo_y_sin_puesto_paga_alta(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $montacargas = $this->position($e->id, 'MONTACARGUISTA', 370, 'E');
        $emp = $this->createSfEmployee($e->id, ['employee_type' => 'temporary', 'daily_rate' => 300]);

        $this->attend($emp->id, '2026-11-16');
        $this->attend($emp->id, '2026-11-17');
        $this->attend($emp->id, '2026-11-18', 'absent');
        $this->assign($emp->id, $montacargas, '2026-11-16');

        $row = collect(app(SfPayrollCalculator::class)->rows($e->id, '2026-11-16', '2026-11-22'))->firstWhere('sf_employee_id', $emp->id);

        $this->assertEquals(2, $row['effective_days']);
        $this->assertEquals(670, $row['gross_pay']); // 370 (grupo E) + 300 (alta)
        $this->assertEquals(335, $row['daily_rate']);
        $this->assertEquals(0, $row['destajo_days']);
        $this->assertEquals(0, $row['days_without_rate']);
    }

    public function test_nomina_destajo_y_sin_tarifa(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $empacadores = $this->position($e->id, 'EMPACADORES', 0, 'EMPACADORES');
        $sel = $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $emp = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);

        $this->attend($emp->id, '2026-11-16', 'half_day');
        $this->assign($emp->id, $empacadores, '2026-11-16');
        $this->attend($emp->id, '2026-11-17');
        $this->assign($emp->id, $sel, '2026-11-17');
        $this->attend($emp->id, '2026-11-18'); // sin puesto y sin salario en el alta

        $row = collect(app(SfPayrollCalculator::class)->rows($e->id, '2026-11-16', '2026-11-22'))->firstWhere('sf_employee_id', $emp->id);

        $this->assertEquals(2.5, $row['effective_days']);
        $this->assertEquals(0.5, $row['destajo_days']);
        $this->assertEquals(1, $row['days_without_rate']);
        $this->assertEquals(355, $row['gross_pay']);
    }

    public function test_procesar_archivo_guarda_dias_a_destajo_en_el_corte(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $empacadores = $this->position($e->id, 'EMPACADORES', 0, 'EMPACADORES');
        $emp = $this->createSfEmployee($e->id, ['employee_type' => 'temporary', 'checker_key' => '77']);
        $this->attend($emp->id, '2026-11-17');
        $this->assign($emp->id, $empacadores, '2026-11-17');

        $csv = \Illuminate\Http\UploadedFile::fake()->createWithContent('t.csv', "ID,Date,Time\n0000000077,2026-11-18,06:00\n0000000077,2026-11-18,15:00\n");

        $this->post('/api/splendidfarms/administration/personal/nomina/procesar-archivo', [
            'file' => $csv, 'enterprise_id' => $e->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-22',
        ], ['Accept' => 'application/json'])->assertOk();

        $item = \App\Models\SfPayrollRunItem::where('sf_employee_id', $emp->id)->sole();
        $this->assertEquals(2, $item->effective_days);
        $this->assertEquals(1, $item->destajo_days);
        $this->assertEquals(1, $item->days_without_rate);
    }
}
