<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\AssetCharacteristicDefinition;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class TipoActivoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const BASE_URL = '/api/grupoesplendido/administration/activos-fijos/tipos-activo';
    private const MODULE_URL = '/api/grupoesplendido/administration/activos-fijos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_puede_listar_tipos_de_activo(): void
    {
        $response = $this->getJson(self::BASE_URL);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertGreaterThanOrEqual(2, count($response->json('data'))); // categoría + subcategoría del fixture
    }

    public function test_puede_crear_un_tipo_de_activo_raiz_con_codigo_automatico(): void
    {
        $response = $this->postJson(self::BASE_URL, ['name' => 'Maquinaria y equipos']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Maquinaria y equipos')
            ->assertJsonPath('data.parent_id', null);

        $this->assertNotNull($response->json('data.code'));
        $this->assertStringStartsWith('TAC-', $response->json('data.code'));
    }

    public function test_puede_crear_un_subtipo_ligado_a_su_padre(): void
    {
        $response = $this->postJson(self::BASE_URL, [
            'name' => 'Tablets',
            'parent_id' => $this->assetCategory->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.parent_id', $this->assetCategory->id);

        $this->assertDatabaseHas('asset_categories', [
            'name' => 'Tablets',
            'parent_id' => $this->assetCategory->id,
        ]);
    }

    public function test_el_arbol_agrupa_subtipos_bajo_su_tipo_raiz(): void
    {
        $response = $this->getJson(self::BASE_URL.'/tree');

        $response->assertOk();
        $raiz = collect($response->json('data'))->firstWhere('id', $this->assetCategory->id);
        $this->assertNotNull($raiz);
        $this->assertCount(1, $raiz['all_children']);
        $this->assertSame($this->assetSubcategory->id, $raiz['all_children'][0]['id']);
    }

    public function test_una_categoria_no_puede_ser_su_propio_padre(): void
    {
        $response = $this->putJson(
            self::BASE_URL."/{$this->assetCategory->id}",
            ['parent_id' => $this->assetCategory->id]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id'])
            ->assertJsonPath('message', 'Un tipo de activo no puede ser su propio padre');
    }

    public function test_desde_sf_se_pueden_leer_los_tipos_pero_no_escribirlos(): void
    {
        $sf = '/api/splendidfarms/administration/activos-fijos/tipos-activo';

        $this->getJson($sf)->assertOk();
        $this->getJson($sf.'/tree')->assertOk();
        $this->postJson($sf, ['name' => 'Otro'])->assertForbidden();
        $this->putJson($sf."/{$this->assetCategory->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson($sf."/{$this->assetSubcategory->id}")->assertForbidden();
        $this->postJson($sf."/{$this->assetSubcategory->id}/caracteristicas", ['name' => 'RAM'])->assertForbidden();
    }

    public function test_en_ge_sin_permiso_create_de_tipos_responde_403(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($user, $this->corporativo, 'tipos-activo', ['view']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson(self::BASE_URL, ['name' => 'Otro'])->assertForbidden();
    }

    public function test_no_permite_un_tercer_nivel(): void
    {
        $this->postJson(self::BASE_URL, ['name' => 'Nieto', 'parent_id' => $this->assetSubcategory->id])
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    }

    public function test_no_permite_ciclos_al_editar(): void
    {
        $this->putJson(self::BASE_URL."/{$this->assetCategory->id}", ['parent_id' => $this->assetSubcategory->id])
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    }

    public function test_un_tipo_con_subtipos_no_puede_volverse_subtipo(): void
    {
        $otro = AssetCategory::create(['code' => 'TAC-050', 'name' => 'Vehículos', 'is_active' => true]);

        $this->putJson(self::BASE_URL."/{$this->assetCategory->id}", ['parent_id' => $otro->id])
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    }

    public function test_el_arbol_incluye_tipos_raiz_inactivos(): void
    {
        $this->assetCategory->update(['is_active' => false]);

        $ids = collect($this->getJson(self::BASE_URL.'/tree')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->assetCategory->id, $ids);
    }

    public function test_el_codigo_automatico_salta_los_existentes(): void
    {
        $this->postJson(self::BASE_URL, ['name' => 'Maquinaria'])->assertCreated()->assertJsonPath('data.code', 'TAC-003');
    }

    public function test_los_conteos_del_arbol_en_sf_solo_cuentan_activos_de_sf(): void
    {
        $sp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        [$sucursalSp, $entidadSp] = $this->crearUbicacion($sp, 'SP');
        FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $sp->id, 'code' => 'SP-1', 'branch_id' => $sucursalSp->id, 'entity_id' => $entidadSp->id, 'brand_id' => null]));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'SF-1']));

        $raiz = collect($this->getJson('/api/splendidfarms/administration/activos-fijos/tipos-activo/tree')->json('data'))
            ->firstWhere('id', $this->assetCategory->id);

        $this->assertSame(1, $raiz['assets_as_category_count']);
    }

    public function test_no_permite_eliminar_un_tipo_que_tiene_subtipos(): void
    {
        $response = $this->deleteJson(self::BASE_URL."/{$this->assetCategory->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el tipo de activo porque tiene subtipos');
        $this->assertDatabaseHas('asset_categories', ['id' => $this->assetCategory->id]);
    }

    public function test_no_permite_eliminar_un_subtipo_con_activos_asociados(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $response = $this->deleteJson(self::BASE_URL."/{$this->assetSubcategory->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el tipo de activo porque tiene activos fijos asociados');
    }

    public function test_puede_eliminar_un_subtipo_sin_dependencias(): void
    {
        $response = $this->deleteJson(self::BASE_URL."/{$this->assetSubcategory->id}");

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('asset_categories', ['id' => $this->assetSubcategory->id]);
    }

    public function test_puede_listar_caracteristicas_sugeridas_de_una_categoria(): void
    {
        AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'Procesador',
        ]);

        $response = $this->getJson(self::BASE_URL."/{$this->assetSubcategory->id}/caracteristicas");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Procesador');
    }

    public function test_puede_registrar_una_nueva_caracteristica_en_el_catalogo(): void
    {
        $response = $this->postJson(self::BASE_URL."/{$this->assetSubcategory->id}/caracteristicas", [
            'name' => 'RAM',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'RAM');
        $this->assertDatabaseHas('asset_characteristic_definitions', [
            'category_id' => $this->assetSubcategory->id,
            'name' => 'RAM',
        ]);
    }

    public function test_no_permite_registrar_una_caracteristica_duplicada_en_la_misma_categoria(): void
    {
        AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'RAM',
        ]);

        $response = $this->postJson(self::BASE_URL."/{$this->assetSubcategory->id}/caracteristicas", [
            'name' => 'RAM',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_puede_reciclar_una_caracteristica_previamente_borrada_del_catalogo(): void
    {
        $definition = AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'RAM',
        ]);
        $originalId = $definition->id;
        $definition->delete();

        $response = $this->postJson(self::BASE_URL."/{$this->assetSubcategory->id}/caracteristicas", [
            'name' => 'RAM',
        ]);

        $response->assertCreated()->assertJsonPath('data.id', $originalId);
        $this->assertDatabaseCount('asset_characteristic_definitions', 1);
    }

    public function test_puede_eliminar_una_caracteristica_del_catalogo_sin_borrar_valores_ya_capturados(): void
    {
        $definition = AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'Procesador',
        ]);
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));
        $asset->characteristics()->create([
            'definition_id' => $definition->id,
            'name' => 'Procesador',
            'value' => 'Intel Core i7',
        ]);

        $response = $this->deleteJson(self::MODULE_URL.'/caracteristicas/'.$definition->id);

        $response->assertOk()->assertJson(['success' => true]);
        // Es soft delete (igual que el resto de catálogos del proyecto)
        $this->assertSoftDeleted('asset_characteristic_definitions', ['id' => $definition->id]);
        // El valor ya capturado sigue existiendo. definition_id se conserva
        // (nullOnDelete solo aplica a DELETE físico, no a soft delete) —
        // sirve para trazabilidad aunque la definición ya no esté activa.
        $this->assertDatabaseHas('fixed_asset_characteristics', [
            'fixed_asset_id' => $asset->id,
            'name' => 'Procesador',
            'definition_id' => $definition->id,
        ]);
    }
}
