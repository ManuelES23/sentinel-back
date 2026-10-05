<?php

namespace Tests\Feature\ActivosFijos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class ContextoActivosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_mis_permisos(): void
    {
        $this->getJson('/api/splendidfarms/administration/activos-fijos/mis-permisos')->assertOk()
            ->assertJsonPath('data.activos.create', true)
            ->assertJsonPath('data.tipos_activo.create', false)
            ->assertJsonPath('data.corporativo', false);

        $this->getJson('/api/grupoesplendido/administration/activos-fijos/mis-permisos')->assertOk()
            ->assertJsonPath('data.tipos_activo.create', true)
            ->assertJsonPath('data.corporativo', true);
    }

    public function test_catalogos_de_sf_trae_su_arbol_de_ubicaciones_y_sus_marcas(): void
    {
        $area = \App\Models\Area::create(['code' => 'A1', 'name' => 'Recepción', 'slug' => 'recepcion', 'is_active' => true]);
        DB::table('entity_area')->insert(['entity_id' => $this->entity->id, 'area_id' => $area->id, 'is_active' => true, 'allows_inventory' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/splendidfarms/administration/activos-fijos/catalogos?enterprise_id='.$this->corporativo->id)
            ->assertOk()
            ->assertJsonPath('data.empresa_id', $this->enterprise->id) // fuera de GE se ignora
            ->assertJsonPath('data.empresas.0.slug', 'splendidfarms')
            ->assertJsonPath('data.sucursales.0.id', $this->branch->id)
            ->assertJsonPath('data.sucursales.0.entidades.0.entity_type.code', 'OFICINA')
            ->assertJsonPath('data.sucursales.0.entidades.0.areas.0.id', $area->id)
            ->assertJsonPath('data.marcas.0.id', $this->brand->id);
    }

    public function test_catalogos_en_ge_puede_pedir_otra_empresa_visible(): void
    {
        $this->getJson('/api/grupoesplendido/administration/activos-fijos/catalogos?enterprise_id='.$this->enterprise->id)
            ->assertOk()
            ->assertJsonPath('data.empresa_id', $this->enterprise->id)
            ->assertJsonCount(2, 'data.empresas')
            ->assertJsonPath('data.sucursales.0.id', $this->branch->id);
    }

    public function test_catalogos_en_ge_rechaza_una_empresa_no_visible(): void
    {
        $ajena = \App\Models\Enterprise::create(['name' => 'Canes', 'slug' => 'canes-agro', 'description' => 'Canes', 'is_active' => true]);

        $this->getJson('/api/grupoesplendido/administration/activos-fijos/catalogos?enterprise_id='.$ajena->id)
            ->assertStatus(422)->assertJsonValidationErrors(['enterprise_id']);
    }
}
