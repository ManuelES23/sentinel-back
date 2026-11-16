<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Esquema de «prospectos como empresas»: bandera por empresa, datos de empresa
 * en prospectos/clientes y contacto_id en actividades y agenda.
 */
class ProspectoComoEmpresaEsquemaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const CONFIG_URL = '/api/crm/configuracion-comercial';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    public function test_esquema_tiene_bandera_campos_de_empresa_y_contacto_id(): void
    {
        $camposEmpresa = ['giro', 'sitio_web', 'ciudad', 'estado'];

        $this->assertTrue(Schema::hasColumns('crm_prospectos', $camposEmpresa));
        $this->assertTrue(Schema::hasColumns('crm_clientes', $camposEmpresa));
        $this->assertTrue(Schema::hasColumn('crm_configuraciones_comerciales', 'prospecto_como_empresa'));
        $this->assertTrue(Schema::hasColumn('crm_actividades', 'contacto_id'));
        $this->assertTrue(Schema::hasColumn('crm_agenda', 'contacto_id'));
    }

    public function test_borrar_contacto_deja_contacto_id_en_null(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente']);
        $contacto = CrmContacto::create([
            'empresa_id' => $this->enterprise->id,
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'nombre' => 'Ana Ruiz',
        ]);
        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
            'descripcion' => 'Llamada',
            'fecha_actividad' => now(),
            'contacto_id' => $contacto->id,
        ]);

        $this->assertSame($contacto->id, $actividad->fresh()->contacto->id);

        $contacto->delete();

        $this->assertNull($actividad->fresh()->contacto_id);
        $this->assertNull($actividad->fresh()->contacto);
    }

    public function test_bandera_apagada_por_defecto_y_se_enciende_por_put(): void
    {
        Sanctum::actingAs($this->actingUser);
        $this->otorgarPermisosCrm('catalogos', 'configuracion-comercial', ['editar']);

        $this->withHeaders($this->crmHeaders())->getJson(self::CONFIG_URL)
            ->assertOk()
            ->assertJsonPath('data.prospecto_como_empresa', false);
        $this->assertFalse(CrmConfiguracionComercial::prospectoComoEmpresa($this->enterprise->id));

        // Solo esa llave: no debe fallar por required_without_all.
        $this->withHeaders($this->crmHeaders())
            ->putJson(self::CONFIG_URL, ['prospecto_como_empresa' => true])
            ->assertOk();

        $this->assertTrue(CrmConfiguracionComercial::prospectoComoEmpresa($this->enterprise->id));
        $this->withHeaders($this->crmHeaders())->getJson(self::CONFIG_URL)
            ->assertJsonPath('data.prospecto_como_empresa', true);
    }

    public function test_prospecto_guarda_campos_de_empresa(): void
    {
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        $datos = ['giro' => 'Exportadora de berries', 'sitio_web' => 'https://berries.mx', 'ciudad' => 'Los Mochis', 'estado' => 'Sinaloa'];

        $id = $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/prospectos', ['nombre' => 'Berries SA'] + $datos)
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('crm_prospectos', ['id' => $id] + $datos);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/prospectos/{$id}", ['giro' => 'Empacadora', 'ciudad' => 'Culiacán'])
            ->assertOk();
        $this->assertDatabaseHas('crm_prospectos', ['id' => $id, 'giro' => 'Empacadora', 'ciudad' => 'Culiacán', 'estado' => 'Sinaloa']);

        $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/prospectos', ['nombre' => 'Otro', 'giro' => str_repeat('a', 151)])
            ->assertStatus(422)->assertJsonValidationErrors('giro');
    }

    public function test_cliente_guarda_campos_de_empresa(): void
    {
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        $datos = ['giro' => 'Distribuidor', 'sitio_web' => 'https://distri.mx', 'ciudad' => 'Guadalajara', 'estado' => 'Jalisco'];

        $id = $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/clientes', ['nombre' => 'Distri SA'] + $datos)
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('crm_clientes', ['id' => $id] + $datos);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/clientes/{$id}", ['giro' => 'Mayorista', 'estado' => 'Nayarit'])
            ->assertOk();
        $this->assertDatabaseHas('crm_clientes', ['id' => $id, 'giro' => 'Mayorista', 'estado' => 'Nayarit', 'ciudad' => 'Guadalajara']);

        $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/clientes', ['nombre' => 'Otro', 'giro' => str_repeat('a', 151)])
            ->assertStatus(422)->assertJsonValidationErrors('giro');
    }
}
