<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Mail\VacationStatusMail;
use App\Models\AttendanceRecord;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Solicitudes que ya se autorizaron fuera del sistema: RH las captura aprobadas, sin cadena ni avisos. */
class CapturaYaAutorizadaTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/grupoesplendido/rh/vacaciones';

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
        $this->rh = User::factory()->create(['name' => 'Laura RH']);
        Sanctum::actingAs($this->rh);
    }

    private function lunes(): Carbon
    {
        return today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    private function capturar(int $empleadoId, array $extra = [])
    {
        return $this->postJson(self::BASE, array_merge([
            'employee_id' => $empleadoId,
            'start_date' => $this->lunes()->toDateString(),
            'end_date' => $this->lunes()->addDays(5)->toDateString(),
            'ya_autorizada' => true,
            'nota_autorizacion' => 'Autorizada por el director el 10 oct',
        ], $extra));
    }

    public function test_queda_aprobada_con_un_solo_paso_que_dice_quien_la_capturo(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $this->regla(1, 'user', ['user_id' => User::factory()->create()->id]);
        $empleado = $this->empleado($this->empresa);

        $respuesta = $this->capturar($empleado->id)->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.resolved_by_rh', true)
            ->assertJsonPath('data.approved_by', $this->rh->id)
            ->assertJsonPath('data.days_requested', 6)
            ->assertJsonPath('data.current_step', null);

        $solicitud = VacationRequest::findOrFail($respuesta->json('data.id'));
        $this->assertSame(VacationRequest::SOURCE_RH, $solicitud->source);
        $this->assertCount(1, $solicitud->approvals);
        $paso = $solicitud->approvals->first();
        $this->assertSame(VacationRequestApproval::APPROVED, $paso->decision);
        $this->assertSame(CadenaAprobacionVacaciones::YA_AUTORIZADA, $paso->description);
        $this->assertSame($this->rh->id, $paso->decided_by);
        $this->assertSame('Capturada como ya autorizada por RH: Autorizada por el director el 10 oct', $paso->comment);
    }

    public function test_no_avisa_a_nadie_ni_por_correo(): void
    {
        Mail::fake();
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $jefe = User::factory()->create();
        $this->regla(1, 'user', ['user_id' => $jefe->id]);
        $empleado = $this->empleadoConUsuario($this->empresa, ['email' => 'ana@ejemplo.com']);

        $this->capturar($empleado->id)->assertCreated();

        Mail::assertNotSent(VacationStatusMail::class);
        $this->assertDatabaseCount('vacation_notifications', 0);
        $this->assertSame(0, SystemNotification::count());
    }

    public function test_descuenta_del_saldo_y_marca_la_asistencia(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $empleado = $this->empleado($this->empresa);
        $antes = app(VacationCalculatorService::class)->diasDisponibles($empleado);

        $this->capturar($empleado->id)->assertCreated();

        $this->assertSame($antes - 6, app(VacationCalculatorService::class)->diasDisponibles($empleado->fresh()));
        $this->assertSame(6, AttendanceRecord::where('employee_id', $empleado->id)->where('status', 'vacation')->count());
    }

    public function test_sin_saldo_cuenta_el_adelanto(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $nuevo = $this->empleado($this->empresa, ['hire_date' => now()->subMonths(5)->toDateString()]);

        $this->capturar($nuevo->id)->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.advance_days', 6);
    }

    public function test_sin_permiso_aprobar_responde_403_y_no_crea_nada(): void
    {
        $this->concederVacaciones($this->rh, 'create');

        $this->capturar($this->empleado($this->empresa)->id)->assertForbidden();

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_respeta_las_reglas_de_una_solicitud_normal(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $empleado = $this->empleado($this->empresa);
        $this->capturar($empleado->id)->assertCreated();

        $this->capturar($empleado->id)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'empalma'));
        $this->assertDatabaseCount('vacation_requests', 1);
    }

    public function test_rh_no_captura_como_autorizadas_sus_propias_vacaciones(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $propio = $this->empleado($this->empresa, ['user_id' => $this->rh->id]);

        $this->capturar($propio->id)->assertForbidden();

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_rh_puede_cancelarla_antes_de_que_empiece(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar', 'edit');
        $empleado = $this->empleado($this->empresa);
        $id = $this->capturar($empleado->id)->assertCreated()->json('data.id');

        $this->postJson(self::BASE."/{$id}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, AttendanceRecord::where('employee_id', $empleado->id)->where('status', 'vacation')->count());
    }

    public function test_sin_la_casilla_sigue_entrando_a_la_cadena(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');

        $this->capturar($this->empleado($this->empresa)->id, ['ya_autorizada' => false])->assertCreated()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_el_portal_muestra_el_paso_con_el_nombre_de_rh(): void
    {
        $this->concederVacaciones($this->rh, 'create', 'aprobar');
        $id = $this->capturar($this->empleado($this->empresa)->id)->json('data.id');

        $detalle = \App\Support\RH\PresentadorPortalVacaciones::detalle(VacationRequest::with('approvals.decider', 'approvals.decidedByEmployee')->findOrFail($id));

        $this->assertSame('approved', $detalle['estado']);
        $this->assertCount(1, $detalle['pasos']);
        $this->assertSame(CadenaAprobacionVacaciones::YA_AUTORIZADA, $detalle['pasos'][0]['descripcion']);
        $this->assertSame('Laura RH', $detalle['pasos'][0]['decidido_por']);
    }
}
