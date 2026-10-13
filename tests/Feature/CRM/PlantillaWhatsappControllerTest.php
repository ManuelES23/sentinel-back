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

    public function test_search_como_arreglo_se_ignora_sin_500(): void
    {
        $this->plantilla();

        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?search[]=x&search[]=y')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_search_trata_por_ciento_y_guion_bajo_como_texto(): void
    {
        $this->plantilla(['nombre' => 'Descuento 10%']);
        $this->plantilla(['nombre' => 'Con_guion']);
        $this->plantilla(['nombre' => 'Sin guion']);

        $this->enviar('GET', '?search='.urlencode('%'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Descuento 10%');
        $this->enviar('GET', '?search=_')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Con_guion');
        $this->enviar('GET', '?search=guion')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_per_page_se_limita_a_100(): void
    {
        $this->plantilla();

        $this->enviar('GET', '?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->enviar('GET', '?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_el_cuerpo_admite_1000_caracteres_acentuados_y_rechaza_1001(): void
    {
        $this->conPermisos(['crear']);

        $this->enviar('POST', '', ['nombre' => 'Larga', 'cuerpo' => str_repeat('á', 1000)])->assertCreated();
        $this->enviar('POST', '', ['nombre' => 'Demasiado larga', 'cuerpo' => str_repeat('é', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cuerpo');
        $this->enviar('POST', '', ['nombre' => str_repeat('ñ', 100), 'cuerpo' => 'Hola'])->assertCreated();
        $this->enviar('POST', '', ['nombre' => str_repeat('ñ', 101), 'cuerpo' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
    }

    public function test_utf8_invalido_en_nombre_o_cuerpo_responde_422(): void
    {
        $this->conPermisos(['crear']);
        // Como formulario (no JSON): json_encode no admite bytes inválidos.
        $enviarFormulario = fn (array $datos) => $this->withHeaders($this->crmHeaders() + ['Accept' => 'application/json'])
            ->post(self::URL, $datos);

        $enviarFormulario(['nombre' => 'Válida', 'cuerpo' => "Hola \xFF {{nombre}}"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cuerpo');
        $enviarFormulario(['nombre' => "Mala \xC3", 'cuerpo' => 'Hola {{nombre}}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');

        $this->assertDatabaseCount('crm_plantillas_whatsapp', 0);
    }
}
