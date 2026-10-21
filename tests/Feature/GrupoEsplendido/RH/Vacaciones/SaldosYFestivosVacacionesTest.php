<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\VacationBalance;
use App\Models\VacationHistoryEntry;
use App\Services\VacationCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Pantalla de Vacaciones de RH: saldos de todos (pestaña Saldos) y festivos del rango (calendario del equipo). */
class SaldosYFestivosVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const SALDOS = '/api/grupoesplendido/rh/vacaciones/saldos';

    private const FESTIVOS = '/api/grupoesplendido/rh/vacaciones/festivos-rango';

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
        $this->rh = User::factory()->create();
        $this->concederVacaciones($this->rh, 'view');
        Sanctum::actingAs($this->rh);
    }

    private function fila(array $respuesta, Employee $empleado): array
    {
        return collect($respuesta['data'])->firstWhere('id', $empleado->id);
    }

    public function test_el_saldo_de_cada_empleado_coincide_con_el_calculo_individual(): void
    {
        $depto = $this->departamento($this->empresa, 'VEN');
        $ana = $this->empleado($this->empresa, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'department_id' => $depto->id]);
        $luis = $this->empleado($this->empresa, ['first_name' => 'Luis', 'last_name' => 'Soto', 'hire_date' => now()->subYears(6)->subDay()->toDateString()]);

        $this->solicitud($ana, 'approved', 4);
        $this->solicitud($ana, 'pending', 3);
        $this->solicitud($ana, 'rejected', 5);
        VacationHistoryEntry::create(['employee_id' => $ana->id, 'vacation_year' => (int) now()->subYear()->year, 'days' => 6]);
        VacationBalance::create([
            'employee_id' => $ana->id, 'year' => (int) now()->year, 'entitled_days' => 0, 'used_days' => 0,
            'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => -2,
        ]);
        $this->solicitud($luis, 'approved', 10);

        $respuesta = $this->getJson(self::SALDOS)->assertOk()->json();

        $filaAna = $this->fila($respuesta, $ana);
        $this->assertSame(42, $filaAna['le_corresponden']);
        $this->assertSame(-2, $filaAna['ajustes']);
        $this->assertSame(10, $filaAna['tomados']);
        $this->assertSame(6, $filaAna['del_historial']);
        $this->assertSame(3, $filaAna['en_revision']);
        $this->assertSame(VacationCalculatorService::diasDisponibles($ana), $filaAna['disponibles']);
        $this->assertSame(27, $filaAna['disponibles']);
        $this->assertSame('Depto VEN', $filaAna['departamento']['nombre']);
        $this->assertSame('Splendid Farms', $filaAna['empresa']['nombre']);
        $this->assertSame(3, $filaAna['anios']);
        $this->assertSame(now()->subDay()->addYear()->toDateString(), $filaAna['proximo_aniversario']['fecha']);
        $this->assertSame(18, $filaAna['proximo_aniversario']['dias']);

        $this->assertSame(VacationCalculatorService::diasDisponibles($luis), $this->fila($respuesta, $luis)['disponibles']);
    }

    public function test_solo_empleados_activos_o_con_permiso_y_filtra_por_empresa(): void
    {
        $activo = $this->empleado($this->empresa);
        $conPermiso = $this->empleado($this->empresa, ['status' => Employee::STATUS_ON_LEAVE]);
        $baja = $this->empleado($this->empresa, ['status' => Employee::STATUS_TERMINATED]);
        $otraEmpresa = $this->empleado($this->corporativo);

        $ids = collect($this->getJson(self::SALDOS)->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($activo->id));
        $this->assertTrue($ids->contains($conPermiso->id));
        $this->assertFalse($ids->contains($baja->id));
        $this->assertTrue($ids->contains($otraEmpresa->id));

        $ids = collect($this->getJson(self::SALDOS.'?enterprise_id='.$this->empresa->id)->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($otraEmpresa->id));
        $this->assertTrue($ids->contains($activo->id));
    }

    public function test_sin_permiso_de_ver_vacaciones_no_hay_saldos_ni_festivos(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::SALDOS)->assertForbidden();
        $this->getJson(self::FESTIVOS.'?desde=2026-10-01&hasta=2026-11-30')->assertForbidden();
    }

    public function test_festivos_del_rango_generales_y_de_la_empresa(): void
    {
        Holiday::create(['date' => '2026-11-16', 'name' => 'Revolución', 'is_official' => true]);
        Holiday::create(['date' => '2026-11-02', 'name' => 'Día de muertos', 'enterprise_id' => $this->empresa->id]);
        Holiday::create(['date' => '2026-11-03', 'name' => 'Solo corporativo', 'enterprise_id' => $this->corporativo->id]);
        Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad', 'is_official' => true]);

        $todos = $this->getJson(self::FESTIVOS.'?desde=2026-10-19&hasta=2026-11-29')->assertOk()->json('data');
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-16'], array_column($todos, 'date'));

        $deLaEmpresa = $this->getJson(self::FESTIVOS.'?desde=2026-10-19&hasta=2026-11-29&enterprise_id='.$this->empresa->id)->assertOk()->json('data');
        $this->assertSame(['2026-11-02', '2026-11-16'], array_column($deLaEmpresa, 'date'));
    }

    public function test_festivos_valida_el_rango(): void
    {
        $this->getJson(self::FESTIVOS.'?desde=2026-11-30&hasta=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->getJson(self::FESTIVOS.'?desde=2026-01-01&hasta=2027-12-31')->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->getJson(self::FESTIVOS)->assertStatus(422)->assertJsonValidationErrors(['desde', 'hasta']);
    }
}
