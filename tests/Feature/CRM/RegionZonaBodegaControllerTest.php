<?php

namespace Tests\Feature\CRM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Jerarquía geográfica del CRM: Región → Zona → Bodega. Un solo archivo
 * porque los tres controllers comparten el mismo patrón de validación
 * (el padre debe existir y pertenecer a la empresa del contexto) y las
 * pruebas de la jerarquía se leen mejor juntas.
 */
class RegionZonaBodegaControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    // ---------------------------------------------------------------
    // Regiones
    // ---------------------------------------------------------------

    public function test_puede_crear_una_region(): void
    {
        $response = $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/regiones', ['nombre' => 'Noreste']);

        $response->assertCreated()->assertJsonPath('data.nombre', 'Noreste');
    }

    public function test_no_permite_eliminar_una_region_con_zonas(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/regiones/{$this->region->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('crm_regiones', ['id' => $this->region->id]);
    }

    // ---------------------------------------------------------------
    // Zonas
    // ---------------------------------------------------------------

    public function test_puede_crear_una_zona_ligada_a_su_region(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/zonas', [
            'region_id' => $this->region->id,
            'nombre' => 'Sonora',
        ]);

        $response->assertCreated()->assertJsonPath('data.region.id', $this->region->id);
    }

    public function test_rechaza_crear_una_zona_con_una_region_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $regionAjena = \App\Models\CRM\CrmRegion::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Región ajena']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/zonas', [
            'region_id' => $regionAjena->id,
            'nombre' => 'Zona inválida',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('crm_zonas', ['nombre' => 'Zona inválida']);
    }

    public function test_no_permite_eliminar_una_zona_con_bodegas(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/zonas/{$this->zona->id}");

        $response->assertStatus(409);
    }

    // ---------------------------------------------------------------
    // Bodegas
    // ---------------------------------------------------------------

    public function test_puede_crear_una_bodega_ligada_a_su_zona(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/bodegas', [
            'zona_id' => $this->zona->id,
            'nombre' => 'Bodega Guasave',
        ]);

        $response->assertCreated()->assertJsonPath('data.zona.id', $this->zona->id);
    }

    public function test_rechaza_crear_una_bodega_con_una_zona_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $regionAjena = \App\Models\CRM\CrmRegion::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Región ajena']);
        $zonaAjena = \App\Models\CRM\CrmZona::create([
            'empresa_id' => $otraEmpresa->id, 'region_id' => $regionAjena->id, 'nombre' => 'Zona ajena',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/bodegas', [
            'zona_id' => $zonaAjena->id,
            'nombre' => 'Bodega inválida',
        ]);

        $response->assertStatus(422);
    }

    public function test_puede_eliminar_una_bodega_sin_dependencias(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/bodegas/{$this->bodega->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('crm_bodegas', ['id' => $this->bodega->id]);
    }

    public function test_no_puede_ver_una_bodega_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $regionAjena = \App\Models\CRM\CrmRegion::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Región ajena']);
        $zonaAjena = \App\Models\CRM\CrmZona::create([
            'empresa_id' => $otraEmpresa->id, 'region_id' => $regionAjena->id, 'nombre' => 'Zona ajena',
        ]);
        $bodegaAjena = \App\Models\CRM\CrmBodega::create([
            'empresa_id' => $otraEmpresa->id, 'zona_id' => $zonaAjena->id, 'nombre' => 'Bodega ajena',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/bodegas/{$bodegaAjena->id}");

        $response->assertStatus(404);
    }
}
