<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Models\VacationNotification;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class VacacionesAvisosApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const RH = '/api/grupoesplendido/rh/vacaciones';

    private const BANDEJA = '/api/pending-approvals/vacation_request';

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
        $this->rh = User::factory()->create();
        $this->concederVacaciones($this->rh, 'view', 'edit');
        Sanctum::actingAs($this->rh);
    }

    public function test_arma_el_mensaje_de_una_aprobada_con_el_telefono_del_celular(): void
    {
        $empleado = $this->empleado($this->empresa, ['first_name' => 'Ana', 'mobile' => '667 123 4567']);
        $solicitud = $this->solicitud($empleado, 'approved', 5);

        $this->getJson(self::RH."/{$solicitud->id}/whatsapp-mensaje")
            ->assertOk()
            ->assertJsonPath('data.evento', 'aprobada')
            ->assertJsonPath('data.telefono', '526671234567')
            ->assertJsonPath('data.texto', fn ($t) => str_contains($t, 'Hola Ana')
                && str_contains($t, 'APROBADA')
                && str_contains($t, $solicitud->start_date->format('d/m/Y')));
    }

    public function test_usa_el_telefono_fijo_si_el_celular_no_sirve_y_null_si_ninguno_sirve(): void
    {
        $conFijo = $this->solicitud($this->empleado($this->empresa, ['mobile' => 'abc', 'phone' => '6671234567']), 'pending', 3);
        $sinNada = $this->solicitud($this->empleado($this->empresa, ['mobile' => null, 'phone' => '123']), 'pending', 3);

        $this->getJson(self::RH."/{$conFijo->id}/whatsapp-mensaje")->assertOk()->assertJsonPath('data.telefono', '526671234567');
        $this->getJson(self::RH."/{$sinNada->id}/whatsapp-mensaje")->assertOk()->assertJsonPath('data.telefono', null);
    }

    public function test_el_texto_cambia_con_el_estado(): void
    {
        $empleado = $this->empleado($this->empresa, ['mobile' => '6671234567']);
        $rechazada = $this->solicitud($empleado, 'rejected', 3, ['rejection_reason' => 'Sin cobertura']);
        $cancelada = $this->solicitud($empleado, 'cancelled', 3);
        $pendiente = $this->solicitud($empleado, 'pending', 3);

        $this->getJson(self::RH."/{$rechazada->id}/whatsapp-mensaje")->assertJsonPath('data.evento', 'rechazada')
            ->assertJsonPath('data.texto', fn ($t) => str_contains($t, 'RECHAZADA') && str_contains($t, 'Sin cobertura'));
        $this->getJson(self::RH."/{$cancelada->id}/whatsapp-mensaje")->assertJsonPath('data.evento', 'cancelada_por_rh')
            ->assertJsonPath('data.texto', fn ($t) => str_contains($t, 'CANCELADA'));
        $this->getJson(self::RH."/{$pendiente->id}/whatsapp-mensaje")->assertJsonPath('data.evento', 'recibida')
            ->assertJsonPath('data.texto', fn ($t) => str_contains($t, config('vacaciones.portal_url')));
    }

    public function test_sin_permiso_de_rh_ni_haber_decidido_es_403(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['mobile' => '6671234567']), 'approved', 3);
        $sinPermiso = User::factory()->create();
        Sanctum::actingAs($sinPermiso);

        $this->getJson(self::RH."/{$solicitud->id}/whatsapp-mensaje")->assertForbidden();
        $this->getJson(self::BANDEJA."/{$solicitud->id}/whatsapp-mensaje")->assertForbidden();
        $this->postJson(self::BANDEJA."/{$solicitud->id}/avisos/whatsapp", ['evento' => 'aprobada'])->assertForbidden();
        $this->getJson(self::RH."/{$solicitud->id}/avisos")->assertForbidden();
    }

    public function test_quien_decidio_un_paso_puede_avisar_desde_la_bandeja_y_otro_no(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $otro = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitante = $this->empleado($this->empresa, ['reports_to' => $jefe->id, 'mobile' => '6671234567']);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $solicitud = app(VacationRequestService::class)->crear($solicitante, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
        app(VacationRequestService::class)->aprobarPaso($solicitud, $jefe->user);

        Sanctum::actingAs($jefe->user);
        $this->getJson(self::BANDEJA."/{$solicitud->id}/whatsapp-mensaje")
            ->assertOk()
            ->assertJsonPath('data.telefono', '526671234567')
            ->assertJsonPath('data.evento', 'aprobada');
        $this->postJson(self::BANDEJA."/{$solicitud->id}/avisos/whatsapp", ['evento' => 'aprobada'])->assertCreated();
        $this->getJson(self::RH."/{$solicitud->id}/whatsapp-mensaje")->assertOk(); // la ruta de RH también lo acepta: decidió un paso

        Sanctum::actingAs($otro->user);
        $this->getJson(self::BANDEJA."/{$solicitud->id}/whatsapp-mensaje")->assertForbidden();
    }

    public function test_registra_el_aviso_de_whatsapp_con_quien_lo_envio(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3);

        $this->postJson(self::RH."/{$solicitud->id}/avisos/whatsapp", ['evento' => 'aprobada'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.event', 'aprobada')
            ->assertJsonPath('data.sent_by', $this->rh->name);

        $this->assertDatabaseHas('vacation_notifications', [
            'vacation_request_id' => $solicitud->id, 'channel' => 'whatsapp', 'event' => 'aprobada', 'sent_by' => $this->rh->id, 'error' => null,
        ]);
    }

    public function test_rechaza_un_evento_que_no_existe(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3);

        $this->postJson(self::RH."/{$solicitud->id}/avisos/whatsapp", ['evento' => 'otra-cosa'])->assertStatus(422)->assertJsonValidationErrors('evento');
        $this->postJson(self::RH."/{$solicitud->id}/avisos/whatsapp", [])->assertStatus(422);
        $this->assertDatabaseCount('vacation_notifications', 0);
    }

    public function test_lista_los_avisos_del_mas_reciente_al_mas_viejo_con_el_nombre_de_quien_envio(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3);
        VacationNotification::create(['vacation_request_id' => $solicitud->id, 'channel' => 'email', 'event' => 'aprobada', 'sent_by' => null, 'sent_at' => now()->subHour(), 'error' => 'SMTP caído']);
        VacationNotification::create(['vacation_request_id' => $solicitud->id, 'channel' => 'whatsapp', 'event' => 'aprobada', 'sent_by' => $this->rh->id, 'sent_at' => now()]);

        $respuesta = $this->getJson(self::RH."/{$solicitud->id}/avisos")->assertOk();

        $this->assertSame(['whatsapp', 'email'], array_column($respuesta->json('data'), 'channel'));
        $this->assertSame($this->rh->name, $respuesta->json('data.0.sent_by'));
        $this->assertNull($respuesta->json('data.1.sent_by'));
        $this->assertSame('SMTP caído', $respuesta->json('data.1.error'));
        $this->assertSame(['id', 'channel', 'event', 'sent_by', 'sent_at', 'error'], array_keys($respuesta->json('data.0')));
    }
}
