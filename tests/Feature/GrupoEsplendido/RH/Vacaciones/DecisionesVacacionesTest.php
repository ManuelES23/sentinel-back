<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Exceptions\RH\VacacionesException;
use App\Models\ApprovalFlowStep;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\VacationRequestService;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class DecisionesVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Employee $jefe;

    private Employee $coordinador;

    private Employee $solicitante;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $puesto = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $this->coordinador = $this->empleadoConUsuario($this->corporativo, ['position_id' => $puesto->id]);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, 'position', ['position_id' => $puesto->id]);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['reports_to' => $this->jefe->id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(): VacationRequest
    {
        return $this->servicio()->crear($this->solicitante, $this->lunes, $this->lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    private function esperar(int $status, string $mensaje, callable $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba VacacionesException {$status}");
        } catch (VacacionesException $e) {
            $this->assertSame($status, $e->status);
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    public function test_el_jefe_aprueba_y_pasa_al_coordinador(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(2, $solicitud->current_step);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $this->coordinador->user_id, 'category' => 'vacation']);
    }

    public function test_el_ultimo_paso_aprueba_crea_asistencia_y_avisa_al_empleado(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->coordinador->user);

        $this->assertSame('approved', $solicitud->status);
        $this->assertSame($this->coordinador->user_id, $solicitud->approved_by);
        $this->assertNull($solicitud->current_step);
        $this->assertSame(CadenaAprobacionVacaciones::NO_REQUERIDO, $solicitud->approvals()->where('is_rh_final', true)->value('comment'));
        $this->assertSame(5, AttendanceRecord::where('employee_id', $this->solicitante->id)->where('status', 'vacation')->count());
        $this->assertDatabaseHas('system_notifications', ['user_id' => $this->solicitante->user_id, 'title' => '¡Vacaciones aprobadas!']);
    }

    public function test_quien_no_es_elegible_recibe_403(): void
    {
        $solicitud = $this->nueva();

        $this->esperar(403, 'No puede aprobar', fn () => $this->servicio()->aprobarPaso($solicitud, $this->coordinador->user));
        $this->esperar(403, 'No puede aprobar', fn () => $this->servicio()->aprobarPaso($solicitud, User::factory()->create()));
    }

    public function test_doble_clic_del_jefe_no_avanza_dos_pasos(): void
    {
        $solicitud = $this->nueva();
        $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        $this->esperar(403, 'No puede aprobar', fn () => $this->servicio()->aprobarPaso($solicitud, $this->jefe->user));
        $this->assertSame(2, $solicitud->fresh()->current_step);
    }

    public function test_no_se_decide_una_solicitud_ya_resuelta(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->coordinador->user);

        $this->esperar(422, 'Solo se pueden modificar o decidir solicitudes pendientes', fn () => $this->servicio()->aprobarPaso($solicitud, $this->coordinador->user));
    }

    public function test_rechazar_termina_la_cadena_y_libera_los_dias(): void
    {
        $solicitud = $this->servicio()->rechazar($this->nueva(), $this->jefe->user, 'Cierre de mes');

        $this->assertSame('rejected', $solicitud->status);
        $this->assertSame('Cierre de mes', $solicitud->rejection_reason);
        $this->assertSame(0, $solicitud->approvals()->where('decision', VacationRequestApproval::WAITING)->count());
        $this->assertSame('Cierre de mes', $solicitud->approvals()->where('step_order', 1)->value('comment'));
        $this->assertSame(42, VacationCalculatorService::diasDisponibles($this->solicitante));
        $this->assertDatabaseHas('system_notifications', ['user_id' => $this->solicitante->user_id, 'title' => 'Solicitud de vacaciones rechazada']);
    }

    public function test_rechazar_exige_motivo(): void
    {
        $this->esperar(422, 'motivo', fn () => $this->servicio()->rechazar($this->nueva(), $this->jefe->user, '   '));
    }

    public function test_rh_resuelve_en_cualquier_paso(): void
    {
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'aprobar');

        $solicitud = $this->servicio()->resolverComoRh($this->nueva(), $rh, 'approved');

        $this->assertSame('approved', $solicitud->status);
        $this->assertTrue($solicitud->resolved_by_rh);
        $this->assertSame('Resuelto por RH', $solicitud->approvals()->where('step_order', 1)->value('comment'));
        $this->assertSame(VacationRequestApproval::APPROVED, $solicitud->approvals()->where('step_order', 1)->value('decision'));
        $this->assertSame(0, $solicitud->approvals()->where('decision', VacationRequestApproval::WAITING)->count());
        $this->assertSame(5, AttendanceRecord::where('employee_id', $this->solicitante->id)->count());
    }

    public function test_resolver_como_rh_exige_el_permiso_y_no_aplica_a_lo_propio(): void
    {
        $solicitud = $this->nueva();

        $this->esperar(403, 'como RH', fn () => $this->servicio()->resolverComoRh($solicitud, User::factory()->create(), 'approved'));

        $this->concederVacaciones($this->solicitante->user, 'aprobar');
        $this->esperar(403, 'como RH', fn () => $this->servicio()->resolverComoRh($solicitud, $this->solicitante->user, 'approved'));
    }

    public function test_rh_rechaza_con_motivo(): void
    {
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'aprobar');
        $solicitud = $this->nueva();

        $this->esperar(422, 'motivo', fn () => $this->servicio()->resolverComoRh($solicitud, $rh, 'rejected'));

        $rechazada = $this->servicio()->resolverComoRh($solicitud, $rh, 'rejected', 'Temporada alta');
        $this->assertSame('rejected', $rechazada->status);
        $this->assertSame('Resuelto por RH: Temporada alta', $rechazada->approvals()->where('step_order', 1)->value('comment'));
    }

    public function test_la_asistencia_respeta_los_dias_con_entrada(): void
    {
        AttendanceRecord::create(['employee_id' => $this->solicitante->id, 'date' => $this->lunes->toDateString(), 'status' => 'present', 'check_in' => $this->lunes->copy()->setTime(8, 0)]);
        AttendanceRecord::create(['employee_id' => $this->solicitante->id, 'date' => $this->lunes->copy()->addDay()->toDateString(), 'status' => 'absent']);
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'aprobar');

        $this->servicio()->resolverComoRh($this->nueva(), $rh, 'approved');

        $this->assertSame('present', AttendanceRecord::where('employee_id', $this->solicitante->id)->whereDate('date', $this->lunes->toDateString())->value('status'));
        $this->assertSame('vacation', AttendanceRecord::where('employee_id', $this->solicitante->id)->whereDate('date', $this->lunes->copy()->addDay()->toDateString())->value('status'));
    }
}
