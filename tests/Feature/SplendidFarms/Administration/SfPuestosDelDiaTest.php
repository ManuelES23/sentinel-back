<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfAttendanceRecord;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;
use App\Models\SfPositionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfPuestosDelDiaTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private const URL = '/api/splendidfarms/administration/personal/asistencia/puestos-del-dia';

    private function position(int $enterpriseId, string $name, float $salary, string $groupCode): SfPosition
    {
        $group = SfPositionGroup::firstOrCreate(
            ['enterprise_id' => $enterpriseId, 'code' => $groupCode],
            ['name' => 'GRUPO ' . $groupCode, 'salary' => $salary, 'is_active' => true]
        );

        return SfPosition::create([
            'enterprise_id' => $enterpriseId,
            'code' => 'P-' . $enterpriseId . '-' . substr(md5($name), 0, 6),
            'name' => $name,
            'sf_position_group_id' => $group->id,
            'is_active' => true,
        ]);
    }

    private function row(array $employees, int $id): array
    {
        return collect($employees)->firstWhere('id', $id);
    }

    public function test_hoja_trae_puesto_fijo_del_permanente_y_temporal_sin_puesto(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $montacargas = $this->position($e->id, 'MONTACARGUISTA', 370, 'E');
        $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $perm = $this->createSfEmployee($e->id, ['employee_type' => 'permanent', 'position' => ' montacarguista ', 'daily_rate' => 370]);
        $temp = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);
        SfAttendanceRecord::create(['sf_employee_id' => $temp->id, 'date' => '2026-11-19', 'status' => 'present']);

        $res = $this->getJson(self::URL . "?enterprise_id={$e->id}&date=2026-11-19")->assertOk();

        $this->assertCount(2, $res->json('data.positions'));
        $p = $this->row($res->json('data.employees'), $perm->id);
        $this->assertSame($montacargas->id, $p['position_id']);
        $this->assertSame('alta', $p['source']);
        $this->assertSame('alta', $p['pay']['kind']);
        $this->assertFalse($p['checked_in']);
        $t = $this->row($res->json('data.employees'), $temp->id);
        $this->assertNull($t['position_id']);
        $this->assertSame('sin_tarifa', $t['pay']['kind']);
        $this->assertTrue($t['checked_in']);
    }

    public function test_permanente_sin_puesto_en_catalogo_queda_sin_puesto(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $perm = $this->createSfEmployee($e->id, ['employee_type' => 'permanent', 'position' => 'PUESTO QUE NO EXISTE']);

        $res = $this->getJson(self::URL . "?enterprise_id={$e->id}&date=2026-11-19")->assertOk();

        $p = $this->row($res->json('data.employees'), $perm->id);
        $this->assertNull($p['position_id']);
        $this->assertNull($p['source']);
    }

    public function test_asignar_varios_y_quitar(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $sel = $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $a = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);
        $b = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);

        $res = $this->postJson(self::URL . '/asignar', [
            'enterprise_id' => $e->id, 'date' => '2026-11-19',
            'employee_ids' => [$a->id, $b->id], 'sf_position_id' => $sel->id,
        ])->assertOk();

        $this->assertCount(2, $res->json('data.employees'));
        $row = $this->row($res->json('data.employees'), $a->id);
        $this->assertSame($sel->id, $row['position_id']);
        $this->assertSame('dia', $row['source']);
        $this->assertSame(['amount' => 355, 'kind' => 'grupo'], $row['pay']);
        $this->assertSame(2, SfPositionAssignment::count());

        // Reasignar no duplica: actualiza la fila del día.
        $otro = $this->position($e->id, 'ESTIBADOR', 355, 'B');
        $this->postJson(self::URL . '/asignar', [
            'enterprise_id' => $e->id, 'date' => '2026-11-19', 'employee_ids' => [$a->id], 'sf_position_id' => $otro->id,
        ])->assertOk();
        $this->assertSame(2, SfPositionAssignment::count());

        $this->postJson(self::URL . '/asignar', [
            'enterprise_id' => $e->id, 'date' => '2026-11-19', 'employee_ids' => [$a->id], 'sf_position_id' => null,
        ])->assertOk()->assertJsonPath('data.employees.0.position_id', null);
        $this->assertSame(1, SfPositionAssignment::count());
    }

    public function test_permanente_regresa_a_su_puesto_fijo(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $portero = $this->position($e->id, 'PORTERO', 360, 'C');
        $sel = $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $perm = $this->createSfEmployee($e->id, ['employee_type' => 'permanent', 'position' => 'PORTERO']);

        $base = ['enterprise_id' => $e->id, 'date' => '2026-11-19', 'employee_ids' => [$perm->id]];
        $this->postJson(self::URL . '/asignar', $base + ['sf_position_id' => $sel->id])->assertOk();
        $this->assertSame(1, SfPositionAssignment::count());

        $this->postJson(self::URL . '/asignar', $base + ['sf_position_id' => $portero->id])
            ->assertOk()
            ->assertJsonPath('data.employees.0.position_id', $portero->id)
            ->assertJsonPath('data.employees.0.source', 'alta');
        $this->assertSame(0, SfPositionAssignment::count());
    }

    public function test_asignar_rechaza_otra_empresa(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        [, $otra] = $this->createAuthenticatedUserWithEnterprise();
        $sel = $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $selOtra = $this->position($otra->id, 'SELECCIONADOR', 355, 'B');
        $mio = $this->createSfEmployee($e->id);
        $ajeno = $this->createSfEmployee($otra->id);

        $this->postJson(self::URL . '/asignar', [
            'enterprise_id' => $e->id, 'date' => '2026-11-19', 'employee_ids' => [$mio->id, $ajeno->id], 'sf_position_id' => $sel->id,
        ])->assertStatus(422);

        $this->postJson(self::URL . '/asignar', [
            'enterprise_id' => $e->id, 'date' => '2026-11-19', 'employee_ids' => [$mio->id], 'sf_position_id' => $selOtra->id,
        ])->assertStatus(422);

        $this->assertSame(0, SfPositionAssignment::count());
    }

    public function test_copiar_usa_el_dia_mas_reciente_y_solo_temporales_sin_puesto(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $sel = $this->position($e->id, 'SELECCIONADOR', 355, 'B');
        $est = $this->position($e->id, 'ESTIBADOR', 355, 'B');
        $a = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);
        $b = $this->createSfEmployee($e->id, ['employee_type' => 'temporary']);
        $perm = $this->createSfEmployee($e->id, ['employee_type' => 'permanent']);

        // Sábado 14 con asignaciones; domingo 15 sin nada; lunes 16 se copia.
        foreach ([[$a, $sel], [$b, $sel], [$perm, $sel]] as [$emp, $pos]) {
            SfPositionAssignment::create(['sf_employee_id' => $emp->id, 'sf_position_id' => $pos->id, 'assignment_date' => '2026-11-14']);
        }
        // b ya tiene puesto el lunes: no se toca.
        SfPositionAssignment::create(['sf_employee_id' => $b->id, 'sf_position_id' => $est->id, 'assignment_date' => '2026-11-16']);

        $this->postJson(self::URL . '/copiar-anterior', ['enterprise_id' => $e->id, 'date' => '2026-11-16'])
            ->assertOk()
            ->assertJsonPath('data.copied', 1)
            ->assertJsonPath('data.source_date', '2026-11-14');

        $lunes = SfPositionAssignment::whereDate('assignment_date', '2026-11-16')->get()->keyBy('sf_employee_id');
        $this->assertSame($sel->id, $lunes[$a->id]->sf_position_id);
        $this->assertSame($est->id, $lunes[$b->id]->sf_position_id);
        $this->assertFalse($lunes->has($perm->id));
    }

    public function test_copiar_sin_dias_previos_no_copia(): void
    {
        [, $e] = $this->createAuthenticatedUserWithEnterprise();
        $this->postJson(self::URL . '/copiar-anterior', ['enterprise_id' => $e->id, 'date' => '2026-11-16'])
            ->assertOk()
            ->assertJsonPath('data.copied', 0)
            ->assertJsonPath('data.source_date', null);
    }
}
