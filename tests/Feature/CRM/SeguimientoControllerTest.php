<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use App\Services\CRM\SeguimientoService;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class SeguimientoControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const URL = '/api/crm/seguimientos';

    private CrmVendedor $propio;
    private CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');

        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $this->otorgarPermisosCrm('agenda', 'agenda', ['crear']);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente A']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id,
            'actividad' => [
                'tipo' => 'llamada',
                'descripcion' => 'Llamé para revisar la propuesta',
                'resultado' => 'Pide ajuste de precio',
                'fecha_actividad' => '2026-09-14 09:45:00',
            ],
            'siguiente' => [
                'tipo' => 'llamada',
                'titulo' => 'Seguimiento: Cliente A',
                'fecha_inicio' => '2026-09-15 09:00:00',
            ],
        ], $overrides);
    }

    private function enviar(array $payload)
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::URL, $payload);
    }

    public function test_crea_actividad_y_siguiente_evento_asignados_al_vendedor_propio(): void
    {
        $this->enviar($this->payload())
            ->assertCreated()
            ->assertJsonPath('data.actividad.resultado', 'Pide ajuste de precio')
            ->assertJsonPath('data.evento.titulo', 'Seguimiento: Cliente A');

        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'tipo' => 'llamada', 'fuente' => 'manual',
        ]);
        $this->assertDatabaseHas('crm_agenda', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'completado' => false,
            'fecha_inicio' => '2026-09-15 09:00:00', 'fecha_fin' => '2026-09-15 09:30:00',
        ]);
    }

    public function test_solo_actividad_o_solo_siguiente_paso(): void
    {
        $this->enviar($this->payload(['siguiente' => null]))->assertCreated()->assertJsonPath('data.evento', null);
        $this->enviar($this->payload(['actividad' => null]))->assertCreated()->assertJsonPath('data.actividad', null);

        $this->assertDatabaseCount('crm_actividades', 1);
        $this->assertDatabaseCount('crm_agenda', 1);
    }

    public function test_sin_actividad_ni_siguiente_paso_da_422(): void
    {
        $this->enviar($this->payload(['actividad' => null, 'siguiente' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('actividad');
    }

    public function test_el_siguiente_paso_debe_ser_futuro(): void
    {
        $this->enviar($this->payload(['siguiente' => [
            'tipo' => 'llamada', 'titulo' => 'Tarde', 'fecha_inicio' => '2026-09-13 09:00:00',
        ]]))->assertStatus(422)->assertJsonValidationErrors('siguiente.fecha_inicio');
    }

    public function test_si_falla_el_evento_no_queda_la_actividad(): void
    {
        $this->partialMock(SeguimientoService::class, function ($mock) {
            $mock->shouldReceive('programar')->andThrow(new \RuntimeException('falla simulada'));
        });

        $this->enviar($this->payload())->assertStatus(500);

        $this->assertDatabaseCount('crm_actividades', 0);
        $this->assertDatabaseCount('crm_agenda', 0);
    }

    public function test_siguiente_paso_sin_permiso_de_agenda_da_403(): void
    {
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);

        $this->enviar($this->payload())->assertForbidden();
        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_entidad_de_otra_empresa_da_422(): void
    {
        $ajeno = CrmCliente::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno']);

        $this->enviar($this->payload(['entidad_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entidad_id');
    }

    public function test_asignar_a_otro_vendedor_sin_equipo_da_403(): void
    {
        $this->enviar($this->payload(['vendedor_id' => $this->vendedor->id]))->assertForbidden();
    }

    public function test_un_broadcast_que_falla_no_produce_500(): void
    {
        $this->mock(BroadcastFactory::class, function ($mock) {
            $mock->shouldReceive('event')->andThrow(new \RuntimeException('Pusher error: No matching application'));
        });

        $this->enviar($this->payload())->assertCreated();
        $this->assertDatabaseCount('crm_actividades', 1);
    }
}
