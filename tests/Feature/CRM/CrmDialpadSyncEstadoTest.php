<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmDialpadSyncEstado;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CrmDialpadSyncEstadoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    public function test_obtener_singleton_crea_la_fila_si_no_existe(): void
    {
        $this->assertDatabaseCount('crm_dialpad_sync_estado', 0);

        $estado = CrmDialpadSyncEstado::obtenerSingleton();

        $this->assertDatabaseCount('crm_dialpad_sync_estado', 1);
        $this->assertNull($estado->ultimo_call_id_sincronizado);
        $this->assertNull($estado->ultimo_sync_at);
        $this->assertNull($estado->ultimo_error);
    }

    public function test_obtener_singleton_devuelve_la_misma_fila_en_llamadas_subsecuentes(): void
    {
        $primera = CrmDialpadSyncEstado::obtenerSingleton();
        $primera->update(['ultimo_error' => 'Error de prueba']);

        $segunda = CrmDialpadSyncEstado::obtenerSingleton();

        $this->assertEquals($primera->id, $segunda->id);
        $this->assertEquals('Error de prueba', $segunda->ultimo_error);
        $this->assertDatabaseCount('crm_dialpad_sync_estado', 1);
    }

    /**
     * Guarda de regresión: este plan depende de que el seeder YA tenga estos
     * permisos (no los vuelve a crear). Si algún día alguien los quita del
     * seeder sin darse cuenta, este test lo detecta.
     */
    public function test_el_seeder_ya_tiene_los_permisos_sync_ver_editar_de_dialpad(): void
    {
        $this->seed(CrmPermisosSeeder::class);

        $modulo = Module::where('slug', 'integraciones')->first();
        $this->assertNotNull($modulo, 'El módulo integraciones debe existir.');

        $submodulo = Submodule::where('module_id', $modulo->id)->where('slug', 'dialpad')->first();
        $this->assertNotNull($submodulo, 'El submódulo dialpad debe existir.');

        foreach (['sync', 'ver', 'editar'] as $slug) {
            $this->assertNotNull(
                SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', $slug)->first(),
                "Falta el permiso '{$slug}' en el submódulo dialpad.",
            );
        }
    }
}
