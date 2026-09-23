<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ProspectoRapidoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const URL = '/api/crm/prospectos/rapido';

    private CrmVendedor $propio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->otorgarPermisosCrm('prospectos', 'prospectos', ['crear']);
        $this->otorgarPermisosCrm('agenda', 'agenda', ['crear']);
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

    private function enviar(array $payload)
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::URL, $payload);
    }

    public function test_crea_prospecto_solo_con_nombre_asignado_al_vendedor_propio(): void
    {
        $this->enviar(['nombre' => 'Rancho Nuevo'])
            ->assertCreated()
            ->assertJsonPath('data.prospecto.nombre', 'Rancho Nuevo')
            ->assertJsonPath('data.evento', null);

        $this->assertDatabaseHas('crm_prospectos', [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Nuevo',
            'estatus' => 'nuevo', 'vendedor_id' => $this->propio->id,
        ]);
    }

    public function test_con_primer_contacto_programa_el_evento_ligado_al_prospecto(): void
    {
        $respuesta = $this->enviar([
            'nombre' => 'Rancho Nuevo',
            'telefono' => '6671234567',
            'primer_contacto' => [
                'tipo' => 'llamada', 'titulo' => 'Primer contacto: Rancho Nuevo', 'fecha_inicio' => '2026-09-15 09:00:00',
            ],
        ])->assertCreated();

        $prospectoId = $respuesta->json('data.prospecto.id');
        $this->assertDatabaseHas('crm_agenda', [
            'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospectoId,
            'vendedor_id' => $this->propio->id, 'titulo' => 'Primer contacto: Rancho Nuevo',
        ]);
    }

    public function test_correo_duplicado_en_la_empresa_da_422(): void
    {
        CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Existente', 'email' => 'ya@existe.mx']);

        $this->enviar(['nombre' => 'Otro', 'email' => 'ya@existe.mx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_sin_nombre_da_422(): void
    {
        $this->enviar(['telefono' => '123'])->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_sin_vendedor_propio_da_422_y_no_crea_nada(): void
    {
        $this->propio->delete();

        $this->enviar(['nombre' => 'Rancho Nuevo'])->assertStatus(422);
        $this->assertDatabaseCount('crm_prospectos', 0);
    }

    public function test_sin_permiso_crear_prospectos_da_403(): void
    {
        \App\Models\UserSubmodulePermission::query()->delete();

        $this->enviar(['nombre' => 'Rancho Nuevo'])->assertForbidden();
    }
}
