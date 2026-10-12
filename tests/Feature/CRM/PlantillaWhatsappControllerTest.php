<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmPlantillaWhatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** CRUD de plantillas de WhatsApp (spec 2026-10-12 §5.1, molde Regiones). */
class PlantillaWhatsappControllerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/plantillas-whatsapp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function conPermisos(array $permisos): void
    {
        $this->otorgarPermisosCrm('catalogos', 'plantillas-whatsapp', $permisos);
    }

    private function plantilla(array $datos = []): CrmPlantillaWhatsapp
    {
        return CrmPlantillaWhatsapp::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Seguimiento',
            'cuerpo' => 'Hola {{nombre}}',
        ], $datos));
    }

    private function enviar(string $metodo, string $uri = '', array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->json($metodo, self::URL.$uri, $datos);
    }

    public function test_la_lectura_es_abierta_dentro_de_la_empresa_y_no_muestra_otras(): void
    {
        // Sin ningún permiso de catálogos: el vendedor las usa desde el botón.
        $this->plantilla();
        CrmPlantillaWhatsapp::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajena', 'cuerpo' => 'x']);

        $this->enviar('GET')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Seguimiento')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_all_devuelve_solo_las_activas_en_orden(): void
    {
        $this->plantilla(['nombre' => 'B', 'orden' => 2]);
        $this->plantilla(['nombre' => 'A', 'orden' => 1]);
        $this->plantilla(['nombre' => 'Inactiva', 'orden' => 0, 'activo' => false]);

        $this->enviar('GET', '?all=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre', 'A')
            ->assertJsonPath('data.1.nombre', 'B')
            ->assertJsonStructure(['data' => [['id', 'nombre', 'cuerpo', 'orden']]]);
    }

    public function test_crea_con_permiso(): void
    {
        $this->conPermisos(['crear']);

        $this->enviar('POST', '', ['nombre' => 'Primer contacto', 'cuerpo' => 'Hola {{nombre}}, soy {{vendedor}}.', 'orden' => 3])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Primer contacto')
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.orden', 3);

        $this->assertDatabaseHas('crm_plantillas_whatsapp', [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Primer contacto', 'activo' => true,
        ]);
    }

    public function test_sin_permiso_no_crea_edita_ni_elimina(): void
    {
        $plantilla = $this->plantilla();

        $this->enviar('POST', '', ['nombre' => 'X', 'cuerpo' => 'Hola'])->assertForbidden();
        $this->enviar('PUT', "/{$plantilla->id}", ['nombre' => 'Otro'])->assertForbidden();
        $this->enviar('DELETE', "/{$plantilla->id}")->assertForbidden();

        $this->assertDatabaseCount('crm_plantillas_whatsapp', 1);
        $this->assertSame('Seguimiento', $plantilla->fresh()->nombre);
    }

    public function test_el_nombre_es_unico_por_empresa(): void
    {
        $this->conPermisos(['crear']);
        $this->plantilla();
        CrmPlantillaWhatsapp::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Primer contacto', 'cuerpo' => 'x']);

        $this->enviar('POST', '', ['nombre' => 'Seguimiento', 'cuerpo' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre')
            ->assertJsonPath('errors.nombre.0', 'Ya existe una plantilla con ese nombre.');

        $this->enviar('POST', '', ['nombre' => 'Primer contacto', 'cuerpo' => 'Hola'])->assertCreated();
    }

    public function test_una_variable_desconocida_responde_422_con_su_nombre(): void
    {
        $this->conPermisos(['crear']);

        $respuesta = $this->enviar('POST', '', ['nombre' => 'Mala', 'cuerpo' => 'Hola {{cliente}}, soy {{vendedor}}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cuerpo');

        $this->assertStringContainsString('{{cliente}}', $respuesta->json('errors.cuerpo.0'));
        $this->assertDatabaseCount('crm_plantillas_whatsapp', 0);
    }

    public function test_valida_largos_y_orden(): void
    {
        $this->conPermisos(['crear']);

        $this->enviar('POST', '', ['nombre' => str_repeat('a', 101), 'cuerpo' => str_repeat('b', 1001), 'orden' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre', 'cuerpo', 'orden']);
    }

    public function test_edita_con_permiso_y_puede_conservar_su_nombre(): void
    {
        $this->conPermisos(['editar']);
        $plantilla = $this->plantilla();

        $this->enviar('PUT', "/{$plantilla->id}", ['nombre' => 'Seguimiento', 'cuerpo' => 'Hola {{nombre}}, ¿cómo vas?', 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.cuerpo', 'Hola {{nombre}}, ¿cómo vas?');
    }

    public function test_una_plantilla_de_otra_empresa_responde_404(): void
    {
        $this->conPermisos(['editar', 'eliminar']);
        $ajena = CrmPlantillaWhatsapp::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajena', 'cuerpo' => 'x']);

        $this->enviar('PUT', "/{$ajena->id}", ['nombre' => 'Mía'])->assertNotFound();
        $this->enviar('DELETE', "/{$ajena->id}")->assertNotFound();

        $this->assertSame('Ajena', $ajena->fresh()->nombre);
    }

    public function test_elimina_con_permiso(): void
    {
        $this->conPermisos(['eliminar']);
        $plantilla = $this->plantilla();

        $this->enviar('DELETE', "/{$plantilla->id}")->assertOk();

        $this->assertDatabaseMissing('crm_plantillas_whatsapp', ['id' => $plantilla->id]);
    }
}
