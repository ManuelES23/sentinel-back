<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\VacationBalance;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** vacaciones:reiniciar — borra solicitudes, avisos, ajustes e historial para empezar de cero. */
class ReiniciarVacacionesCommandTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Employee $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->ana = $this->empleado($this->empresa, ['first_name' => 'Ana', 'last_name' => 'Ruiz']);

        $pendiente = $this->solicitud($this->ana, 'pending', 3);
        $aprobada = $this->solicitud($this->ana, 'approved', 2);
        $borrada = $this->solicitud($this->ana, 'cancelled', 1);
        $borrada->delete();

        DB::table('vacation_request_approvals')->insert([
            'vacation_request_id' => $pendiente->id, 'step_order' => 1, 'description' => 'Jefe',
            'decision' => 'waiting', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vacation_notifications')->insert([
            'vacation_request_id' => $aprobada->id, 'channel' => 'email', 'event' => 'approved',
            'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        VacationBalance::create([
            'employee_id' => $this->ana->id, 'year' => (int) now()->year, 'entitled_days' => 0,
            'used_days' => 0, 'pending_days' => 0, 'carried_over' => 0,
            'adjustment_days' => -4, 'adjustment_reason' => 'Conciliación al migrar (portal de vacaciones) (-4)',
        ]);
        VacationHistoryEntry::create([
            'employee_id' => $this->ana->id, 'vacation_year' => (int) now()->subYear()->year, 'days' => 5,
        ]);
        VacationHistoryEntry::create([
            'employee_id' => $this->ana->id, 'vacation_year' => (int) now()->subYears(2)->year, 'days' => 2,
        ])->delete();

        Holiday::create(['date' => now()->startOfYear()->toDateString(), 'name' => 'Año nuevo']);
    }

    private function totales(): array
    {
        return [
            'solicitudes' => DB::table('vacation_requests')->count(),
            'aprobaciones' => DB::table('vacation_request_approvals')->count(),
            'avisos' => DB::table('vacation_notifications')->count(),
            'saldos' => DB::table('vacation_balances')->count(),
            'historial' => DB::table('vacation_history')->count(),
        ];
    }

    public function test_sin_confirmar_solo_muestra_lo_que_borraria(): void
    {
        $antes = $this->totales();

        $this->artisan('vacaciones:reiniciar')
            ->expectsOutputToContain('Solicitudes')
            ->expectsOutputToContain('Nada se borró')
            ->assertSuccessful();

        $this->assertSame($antes, $this->totales());
        $this->assertSame(['solicitudes' => 3, 'aprobaciones' => 1, 'avisos' => 1, 'saldos' => 1, 'historial' => 2], $antes);
    }

    public function test_con_confirmar_borra_todo_lo_de_vacaciones_y_conserva_lo_demas(): void
    {
        $this->artisan('vacaciones:reiniciar', ['--confirmar' => true])
            ->expectsConfirmation('¿Borrar todo lo de vacaciones de todos los empleados? No se puede deshacer.', 'yes')
            ->expectsOutputToContain('Listo')
            ->assertSuccessful();

        $this->assertSame(['solicitudes' => 0, 'aprobaciones' => 0, 'avisos' => 0, 'saldos' => 0, 'historial' => 0], $this->totales());
        $this->assertNotNull(Employee::find($this->ana->id));
        $this->assertSame(1, Holiday::count());
        $this->assertSame(0, VacationRequest::withTrashed()->count());
    }

    public function test_si_no_confirma_no_borra_nada(): void
    {
        $antes = $this->totales();

        $this->artisan('vacaciones:reiniciar', ['--confirmar' => true])
            ->expectsConfirmation('¿Borrar todo lo de vacaciones de todos los empleados? No se puede deshacer.', 'no')
            ->expectsOutputToContain('Nada se borró')
            ->assertSuccessful();

        $this->assertSame($antes, $this->totales());
    }

    public function test_despues_del_reinicio_el_saldo_es_el_de_ley(): void
    {
        $this->artisan('vacaciones:reiniciar', ['--confirmar' => true])
            ->expectsConfirmation('¿Borrar todo lo de vacaciones de todos los empleados? No se puede deshacer.', 'yes')
            ->assertSuccessful();

        // Ingreso hace 3 años (fixture): 42 días ganados, sin nada tomado.
        $this->assertSame(42, \App\Services\VacationCalculatorService::diasDisponibles($this->ana->fresh()));
    }
}
