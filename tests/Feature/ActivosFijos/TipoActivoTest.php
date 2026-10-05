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
            ->assertJsonPath('message', 'Un tipo de activo no puede ser su propio padre');
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
