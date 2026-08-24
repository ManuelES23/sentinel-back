<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class EnviarRecordatoriosAgendaCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearEvento(array $overrides = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada',
            'titulo' => 'Llamar a cliente',
            'fecha_inicio' => now()->addHour(),
            'fecha_fin' => now()->addHours(2),
            'completado' => false,
        ], $overrides));
    }

    public function test_notifica_y_marca_como_enviado_un_recordatorio_vencido(): void
    {
        $evento = $this->crearEvento(['recordatorio_at' => now()->subMinutes(10)]);

        $this->artisan('agenda:enviar-recordatorios')->assertExitCode(0);

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $this->vendedor->user_id,
            'title' => 'Llamar a cliente',
        ]);
        $this->assertNotNull($evento->fresh()->recordatorio_enviado_at);
    }

    public function test_no_reprocesa_un_recordatorio_ya_enviado(): void
    {
        $evento = $this->crearEvento([
            'recordatorio_at' => now()->subHour(),
            'recordatorio_enviado_at' => now()->subMinutes(30),
        ]);

        $this->artisan('agenda:enviar-recordatorios');

        $this->assertDatabaseMissing('system_notifications', [
            'user_id' => $this->vendedor->user_id,
            'title' => 'Llamar a cliente',
        ]);
        $this->assertEquals(
            $evento->recordatorio_enviado_at->toDateTimeString(),
            $evento->fresh()->recordatorio_enviado_at->toDateTimeString(),
        );
    }

    public function test_no_notifica_pero_marca_como_enviado_si_el_vendedor_no_tiene_usuario(): void
    {
        $vendedorSinUsuario = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Vendedor sin cuenta',
        ]);
        $evento = $this->crearEvento([
            'vendedor_id' => $vendedorSinUsuario->id,
            'recordatorio_at' => now()->subMinutes(5),
        ]);

        $this->artisan('agenda:enviar-recordatorios')->assertExitCode(0);

        $this->assertDatabaseMissing('system_notifications', ['title' => 'Llamar a cliente']);
        $this->assertNotNull($evento->fresh()->recordatorio_enviado_at);
    }

    public function test_no_notifica_un_recordatorio_futuro(): void
    {
        $evento = $this->crearEvento(['recordatorio_at' => now()->addHour()]);

        $this->artisan('agenda:enviar-recordatorios');

        $this->assertDatabaseMissing('system_notifications', ['title' => 'Llamar a cliente']);
        $this->assertNull($evento->fresh()->recordatorio_enviado_at);
    }

    public function test_un_fallo_en_un_evento_no_detiene_el_procesamiento_de_los_demas(): void
    {
        // Fuerza una excepción real en el punto exacto donde
        // NotificationService::send() inserta el SystemNotification, para
        // probar el aislamiento por evento sin acoplarse a los internos del
        // comando.
        \App\Models\SystemNotification::creating(function ($notification) {
            if ($notification->title === 'Evento que falla') {
                throw new \RuntimeException('Fallo forzado para probar aislamiento por evento');
            }
        });

        $eventoQueFalla = $this->crearEvento([
            'titulo' => 'Evento que falla',
            'recordatorio_at' => now()->subMinutes(5),
        ]);
        $eventoSano = $this->crearEvento([
            'titulo' => 'Evento con usuario',
            'recordatorio_at' => now()->subMinutes(5),
        ]);

        $this->artisan('agenda:enviar-recordatorios')->assertExitCode(0);

        $this->assertDatabaseHas('system_notifications', ['title' => 'Evento con usuario']);
        $this->assertNotNull($eventoSano->fresh()->recordatorio_enviado_at);
        $this->assertNull($eventoQueFalla->fresh()->recordatorio_enviado_at);
    }
}
