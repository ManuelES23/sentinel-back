<?php
// tests/Feature/CRM/CrmOutlookConexionTest.php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmOutlookEventoMapeado;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CrmOutlookConexionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function crearConexion(): CrmOutlookConexion
    {
        return CrmOutlookConexion::create([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $this->vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'token-de-acceso-plano',
            'refresh_token' => 'token-de-refresco-plano',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function crearEventoAgenda(): CrmAgenda
    {
        return CrmAgenda::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'tarea',
            'titulo' => 'Evento de prueba',
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addHour(),
        ]);
    }

    public function test_access_token_y_refresh_token_se_guardan_cifrados_en_la_bd(): void
    {
        $conexion = $this->crearConexion();

        $crudo = DB::table('crm_outlook_conexiones')->where('id', $conexion->id)->first();
        $this->assertNotEquals('token-de-acceso-plano', $crudo->access_token);
        $this->assertNotEquals('token-de-refresco-plano', $crudo->refresh_token);

        $conexion->refresh();
        $this->assertEquals('token-de-acceso-plano', $conexion->access_token);
        $this->assertEquals('token-de-refresco-plano', $conexion->refresh_token);
    }

    public function test_access_token_no_aparece_al_serializar_el_modelo(): void
    {
        $conexion = $this->crearConexion();
        $array = $conexion->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_seeder_crea_el_submodulo_outlook_con_permiso_ver(): void
    {
        $this->seed(CrmPermisosSeeder::class);

        $modulo = Module::where('slug', 'integraciones')->first();
        $this->assertNotNull($modulo, 'El módulo integraciones debe existir (ya lo crea Dialpad).');

        $submodulo = Submodule::where('module_id', $modulo->id)->where('slug', 'outlook')->first();
        $this->assertNotNull($submodulo);

        $permiso = SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', 'ver')->first();
        $this->assertNotNull($permiso);
    }

    public function test_borrar_la_conexion_borra_en_cascada_sus_eventos_mapeados(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEventoAgenda();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'AAMkAGI1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $conexion->delete();

        $this->assertDatabaseMissing('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
    }

    public function test_borrar_el_evento_de_agenda_deja_el_mapeo_vivo_con_crm_agenda_id_nulo(): void
    {
        $conexion = $this->crearConexion();
        $evento = $this->crearEventoAgenda();

        $mapeo = CrmOutlookEventoMapeado::create([
            'crm_agenda_id' => $evento->id,
            'crm_outlook_conexion_id' => $conexion->id,
            'outlook_event_id' => 'AAMkAGI1',
            'ultima_actualizacion_enviada_at' => now(),
        ]);

        $evento->delete();
        $mapeo->refresh();

        $this->assertNull($mapeo->crm_agenda_id);
        $this->assertDatabaseHas('crm_outlook_eventos_mapeados', ['id' => $mapeo->id]);
    }
}
