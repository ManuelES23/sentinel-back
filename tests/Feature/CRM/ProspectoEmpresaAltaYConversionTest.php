<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ContactoUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Alta rápida de un prospecto con su contacto y conversión a cliente que
 * conserva contactos y datos de empresa (CRM SBP: prospectos como empresas).
 */
class ProspectoEmpresaAltaYConversionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->otorgarTodosLosPermisosCrm();
        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio', 'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rapido(array $payload)
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/prospectos/rapido', $payload);
    }

    public function test_alta_rapida_con_contacto_crea_principal(): void
    {
        Event::fake([ContactoUpdated::class]);

        $respuesta = $this->rapido([
            'nombre' => 'Berries del Valle',
            'giro' => 'Agrícola', 'ciudad' => 'Culiacán', 'estado' => 'Sinaloa',
            'contacto' => ['nombre' => 'Ana Ruiz', 'cargo' => 'Compras', 'email' => 'ana@berries.mx', 'telefono' => '6671234567'],
        ])->assertCreated()
            ->assertJsonPath('data.prospecto.giro', 'Agrícola')
            ->assertJsonPath('data.prospecto.ciudad', 'Culiacán')
            ->assertJsonPath('data.prospecto.estado', 'Sinaloa')
            ->assertJsonCount(1, 'data.prospecto.contactos')
            ->assertJsonPath('data.prospecto.contactos.0.nombre', 'Ana Ruiz')
            ->assertJsonPath('data.evento', null);

        $prospectoId = $respuesta->json('data.prospecto.id');
        $this->assertDatabaseHas('crm_contactos', [
            'empresa_id' => $this->enterprise->id,
            'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospectoId,
            'nombre' => 'Ana Ruiz', 'cargo' => 'Compras', 'es_principal' => true,
        ]);
        Event::assertDispatched(ContactoUpdated::class);
    }

    public function test_alta_rapida_con_contacto_vacio_o_sin_contacto_no_falla(): void
    {
        $this->rapido(['nombre' => 'Sin contacto', 'contacto' => []])->assertCreated();
        $this->rapido(['nombre' => 'Sin contacto 2'])->assertCreated();

        $this->assertDatabaseCount('crm_contactos', 0);
    }

    public function test_alta_rapida_con_contacto_sin_nombre_responde_422(): void
    {
        $this->rapido(['nombre' => 'Berries del Valle', 'contacto' => ['cargo' => 'Compras']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contacto.nombre']);

        $this->assertDatabaseCount('crm_prospectos', 0);
        $this->assertDatabaseCount('crm_contactos', 0);
    }

    public function test_alta_rapida_con_primer_contacto_y_contacto_liga_el_evento(): void
    {
        $respuesta = $this->rapido([
            'nombre' => 'Berries del Valle',
            'contacto' => ['nombre' => 'Ana Ruiz'],
            'primer_contacto' => [
                'tipo' => 'llamada', 'titulo' => 'Primer contacto', 'fecha_inicio' => '2026-09-15 09:00:00',
            ],
        ])->assertCreated();

        $contacto = CrmContacto::where('nombre', 'Ana Ruiz')->firstOrFail();
        $this->assertSame($contacto->id, $respuesta->json('data.evento.contacto_id'));
        $this->assertDatabaseHas('crm_agenda', ['titulo' => 'Primer contacto', 'contacto_id' => $contacto->id]);
    }

    public function test_convertir_mueve_contactos_y_copia_datos_de_empresa(): void
    {
        $prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Berries del Valle', 'vendedor_id' => $this->propio->id,
            'giro' => 'Agrícola', 'sitio_web' => 'https://berries.mx', 'ciudad' => 'Culiacán', 'estado' => 'Sinaloa',
        ]);
        $principal = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id,
            'nombre' => 'Ana', 'es_principal' => true,
        ]);
        $otro = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id,
            'nombre' => 'Beto', 'es_principal' => false,
        ]);

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$prospecto->id}/convertir-cliente")
            ->assertCreated()
            ->assertJsonPath('data.giro', 'Agrícola')
            ->assertJsonPath('data.sitio_web', 'https://berries.mx')
            ->assertJsonPath('data.ciudad', 'Culiacán')
            ->assertJsonPath('data.estado', 'Sinaloa');

        $clienteId = $respuesta->json('data.id');
        foreach ([$principal, $otro] as $c) {
            $this->assertDatabaseHas('crm_contactos', [
                'id' => $c->id, 'entidad_type' => CrmCliente::class, 'entidad_id' => $clienteId,
            ]);
        }
        $this->assertTrue($principal->fresh()->es_principal);
        $this->assertSame(0, $prospecto->contactos()->count());
    }

    public function test_convertir_no_mueve_contactos_de_otro_prospecto_y_las_oportunidades_siguen_al_cliente(): void
    {
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'A', 'vendedor_id' => $this->propio->id]);
        $ajeno = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'B', 'vendedor_id' => $this->propio->id]);
        $contactoAjeno = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmProspecto::class, 'entidad_id' => $ajeno->id,
            'nombre' => 'Ajeno', 'es_principal' => true,
        ]);
        $abierta = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Abierta', 'monto_esperado' => 100,
        ]);

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$prospecto->id}/convertir-cliente")->assertCreated();

        $this->assertDatabaseHas('crm_contactos', ['id' => $contactoAjeno->id, 'entidad_type' => CrmProspecto::class, 'entidad_id' => $ajeno->id]);
        $this->assertSame($respuesta->json('data.id'), $abierta->fresh()->cliente_id);
    }

    public function test_actividad_vieja_conserva_contacto_tras_convertir(): void
    {
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'A', 'vendedor_id' => $this->propio->id]);
        $contacto = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id,
            'nombre' => 'Ana', 'es_principal' => true,
        ]);
        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'tipo' => 'llamada',
            'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id,
            'vendedor_id' => $this->propio->id, 'descripcion' => 'Habló con Ana',
            'fecha_actividad' => now(), 'fuente' => 'manual', 'contacto_id' => $contacto->id,
        ]);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id,
            'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id,
            'tipo' => 'llamada', 'titulo' => 'Seguimiento', 'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addMinutes(30), 'completado' => false, 'contacto_id' => $contacto->id,
        ]);

        $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$prospecto->id}/convertir-cliente")->assertCreated();

        $this->assertSame($contacto->id, $actividad->fresh()->contacto_id);
        $this->assertSame($contacto->id, $evento->fresh()->contacto_id);
        $this->assertNotNull(CrmContacto::find($contacto->id));
    }
}
