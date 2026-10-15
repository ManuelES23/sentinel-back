<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Jobs\EnviarCorreoVacacionesJob;
use App\Mail\RH\VacationStatusMail;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\AvisosVacaciones;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\CorreoVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class CorreoVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(Employee $empleado): VacationRequest
    {
        return $this->servicio()->crear($empleado, $this->lunes, $this->lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    private function usuarioRh(): User
    {
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'aprobar');

        return $rh;
    }

    public function test_al_crear_avisa_que_la_recibio_sin_pin_y_lo_registra(): void
    {
        Mail::fake();
        $empleado = $this->empleado($this->empresa, ['email' => 'ana@ejemplo.com']);

        $solicitud = $this->nueva($empleado);

        Mail::assertSent(VacationStatusMail::class, function (VacationStatusMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('ana@ejemplo.com')
                && $mail->evento === VacationNotification::EVENTO_RECIBIDA
                && str_contains($html, config('vacaciones.portal_url'))
                && str_contains($html, $this->lunes->format('d/m/Y'))
                && ! str_contains($html, '123456');
        });
        $this->assertDatabaseHas('vacation_notifications', [
            'vacation_request_id' => $solicitud->id, 'channel' => 'email', 'event' => 'recibida', 'sent_by' => null, 'error' => null,
        ]);
    }

    public function test_sin_correo_valido_no_envia_ni_falla(): void
    {
        Mail::fake();

        foreach ([null, '', 'sin-arroba'] as $correo) {
            $solicitud = $this->nueva($this->empleado($this->empresa, ['email' => $correo]));
            $this->assertSame('pending', $solicitud->fresh()->status);
        }

        Mail::assertNothingSent();
        $this->assertDatabaseCount('vacation_notifications', 0);
    }

    public function test_aprobada_y_rechazada_avisan_con_su_resultado(): void
    {
        Mail::fake();
        $rh = $this->usuarioRh();
        $aprobada = $this->nueva($this->empleado($this->empresa, ['email' => 'a@ejemplo.com']));
        $rechazada = $this->nueva($this->empleado($this->empresa, ['email' => 'b@ejemplo.com']));

        $this->servicio()->resolverComoRh($aprobada, $rh, 'approved');
        $this->servicio()->resolverComoRh($rechazada, $rh, 'rejected', 'Cobertura insuficiente');

        Mail::assertSent(VacationStatusMail::class, fn ($m) => $m->hasTo('a@ejemplo.com') && $m->evento === 'aprobada');
        Mail::assertSent(VacationStatusMail::class, fn ($m) => $m->hasTo('b@ejemplo.com')
            && $m->evento === 'rechazada'
            && str_contains($m->render(), 'Cobertura insuficiente'));
        $this->assertDatabaseHas('vacation_notifications', ['vacation_request_id' => $aprobada->id, 'event' => 'aprobada']);
        $this->assertDatabaseHas('vacation_notifications', ['vacation_request_id' => $rechazada->id, 'event' => 'rechazada']);
    }

    public function test_solo_avisa_la_cancelacion_cuando_cancela_rh(): void
    {
        Mail::fake();
        $porRh = $this->nueva($this->empleado($this->empresa, ['email' => 'rh@ejemplo.com']));
        $porEmpleado = $this->nueva($this->empleado($this->empresa, ['email' => 'yo@ejemplo.com']));

        $this->servicio()->cancelar($porRh, VacationRequestService::CANCELA_RH);
        $this->servicio()->cancelar($porEmpleado, VacationRequestService::CANCELA_EMPLEADO);

        Mail::assertSent(VacationStatusMail::class, fn ($m) => $m->hasTo('rh@ejemplo.com') && $m->evento === 'cancelada_por_rh');
        Mail::assertNotSent(VacationStatusMail::class, fn ($m) => $m->evento === 'cancelada_por_rh' && $m->hasTo('yo@ejemplo.com'));
    }

    public function test_un_fallo_de_smtp_no_rompe_la_operacion_y_queda_registrado(): void
    {
        $empleado = $this->empleado($this->empresa, ['email' => 'ana@ejemplo.com']);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP caído'));

        $solicitud = $this->nueva($empleado);

        $this->assertSame('pending', $solicitud->fresh()->status);
        $aviso = VacationNotification::where('vacation_request_id', $solicitud->id)->sole();
        $this->assertSame('email', $aviso->channel);
        $this->assertSame('recibida', $aviso->event);
        $this->assertStringContainsString('SMTP caído', $aviso->error);
    }

    public function test_si_el_tiempo_real_o_el_aviso_interno_fallan_la_operacion_sigue_y_el_correo_sale(): void
    {
        Mail::fake();
        // Subclase registrada en el contenedor cuyo tiempo real y aviso interno lanzan. (Con
        // BROADCAST_CONNECTION=null un listener de eventos no se invoca al emitir, por eso no se usa Event::listen.)
        $this->app->bind(AvisosVacaciones::class, fn ($app) => new class($app->make(AprobadorVacaciones::class), $app->make(CadenaAprobacionVacaciones::class), $app->make(CorreoVacaciones::class)) extends AvisosVacaciones {
            protected function emitir(string $accion, VacationRequest $solicitud): void
            {
                throw new \RuntimeException('Reverb caído');
            }

            protected function avisarAprobadoresDelPasoActual(VacationRequest $solicitud): void
            {
                throw new \RuntimeException('NotificationService caído');
            }
        });

        $solicitud = $this->nueva($this->empleado($this->empresa, ['email' => 'ana@ejemplo.com']));

        $this->assertSame('pending', $solicitud->fresh()->status);
        Mail::assertSent(VacationStatusMail::class, fn ($m) => $m->hasTo('ana@ejemplo.com') && $m->evento === 'recibida');
        $this->assertDatabaseHas('vacation_notifications', ['vacation_request_id' => $solicitud->id, 'event' => 'recibida', 'error' => null]);
    }

    public function test_con_la_cola_activa_despacha_el_trabajo_y_el_trabajo_envia(): void
    {
        Mail::fake();
        Queue::fake();
        config(['vacaciones.correo_en_cola' => true]);
        $solicitud = $this->nueva($this->empleado($this->empresa, ['email' => 'ana@ejemplo.com']));

        Queue::assertPushed(EnviarCorreoVacacionesJob::class, fn ($job) => $job->solicitudId === $solicitud->id && $job->evento === 'recibida');
        Mail::assertNothingSent();

        (new EnviarCorreoVacacionesJob($solicitud->id, 'recibida'))->handle(app(CorreoVacaciones::class));

        Mail::assertSent(VacationStatusMail::class, 1);
        $this->assertDatabaseHas('vacation_notifications', ['vacation_request_id' => $solicitud->id, 'event' => 'recibida']);
    }

    public function test_el_trabajo_ignora_una_solicitud_que_ya_no_existe(): void
    {
        Mail::fake();

        (new EnviarCorreoVacacionesJob(999999, 'recibida'))->handle(app(CorreoVacaciones::class));

        Mail::assertNothingSent();
    }

    public function test_reprogramar_vuelve_a_avisar_al_aprobador_del_paso_actual(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitud = $this->nueva($this->empleado($this->empresa, ['reports_to' => $jefe->id]));
        $antes = SystemNotification::where('user_id', $jefe->user_id)->count();

        $this->servicio()->reprogramar($solicitud, $this->lunes->copy()->addWeek(), $this->lunes->copy()->addWeek()->addDays(4), null);

        $this->assertSame($antes + 1, SystemNotification::where('user_id', $jefe->user_id)->count());
    }
}
