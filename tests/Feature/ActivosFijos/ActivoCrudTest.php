<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCharacteristicDefinition;
use App\Models\Area;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class ActivoCrudTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const BASE_URL = '/api/splendidfarms/administration/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_puede_listar_activos_fijos_paginados(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $this->getJson(self::BASE_URL)->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.code', 'AF-000001')
            ->assertJsonPath('data.per_page', 25);
    }

    public function test_puede_crear_un_activo_fijo_con_codigo_automatico(): void
    {
        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'SF-AF-000001')
            ->assertJsonPath('data.category.id', $this->assetCategory->id)
            ->assertJsonPath('data.category.icon', 'Laptop')
            ->assertJsonPath('data.subcategory.id', $this->assetSubcategory->id)
            ->assertJsonPath('data.brand.id', $this->brand->id);
    }

    public function test_los_codigos_automaticos_son_secuenciales(): void
    {
        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['name' => 'Activo 1']));

        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['name' => 'Activo 2']))
            ->assertJsonPath('data.code', 'SF-AF-000002');
    }

    public function test_respeta_un_codigo_capturado_a_mano(): void
    {
        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['code' => 'ETIQ-77']))
            ->assertCreated()->assertJsonPath('data.code', 'ETIQ-77');
    }

    public function test_requiere_nombre_tipo_de_activo_sucursal_y_entidad(): void
    {
        $this->postJson(self::BASE_URL, [])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category_id', 'branch_id', 'entity_id']);
    }

    public function test_rechaza_una_entidad_que_no_pertenece_a_la_sucursal_indicada(): void
    {
        $otraSucursal = \App\Models\Branch::create([
            'enterprise_id' => $this->enterprise->id, 'code' => 'SF-OTRA', 'name' => 'Otra sucursal',
            'slug' => 'otra-sucursal', 'is_active' => true,
        ]);

        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['branch_id' => $otraSucursal->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entity_id'])
            ->assertJsonPath('message', 'La entidad seleccionada no pertenece a la sucursal indicada');
    }

    public function test_rechaza_un_area_que_no_pertenece_a_la_entidad_indicada(): void
    {
        $area = Area::create(['code' => 'ARE-001', 'name' => 'Área sin asignar', 'slug' => 'area-sin-asignar', 'is_active' => true]);

        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['area_id' => $area->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['area_id']);
    }

    public function test_acepta_un_area_correctamente_vinculada_a_la_entidad(): void
    {
        $area = Area::create(['code' => 'ARE-002', 'name' => 'Recepción', 'slug' => 'recepcion', 'is_active' => true]);
        DB::table('entity_area')->insert([
            'entity_id' => $this->entity->id, 'area_id' => $area->id, 'is_active' => true,
            'allows_inventory' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['area_id' => $area->id]))
            ->assertCreated()->assertJsonPath('data.area.id', $area->id);
    }

    public function test_puede_ver_el_detalle_de_un_activo(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $this->getJson(self::BASE_URL."/{$asset->id}")->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    public function test_puede_actualizar_un_activo(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $this->putJson(self::BASE_URL."/{$asset->id}", $this->validFixedAssetPayload([
            'name' => 'Laptop Dell Latitude 5530 (actualizada)',
            'status' => 'en_mantenimiento',
        ]))->assertOk()
            ->assertJsonPath('data.name', 'Laptop Dell Latitude 5530 (actualizada)')
            ->assertJsonPath('data.status', 'en_mantenimiento')
            ->assertJsonPath('data.code', 'AF-000001');
    }

    public function test_puede_eliminar_un_activo_con_soft_delete(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $this->deleteJson(self::BASE_URL."/{$asset->id}")->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('fixed_assets', ['id' => $asset->id]);
    }

    public function test_filtra_por_estado(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001', 'status' => 'en_uso']));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000002', 'status' => 'baja']));

        $this->getJson(self::BASE_URL.'?status=baja')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.code', 'AF-000002');
    }

    public function test_al_crear_registra_caracteristicas_nuevas_en_el_catalogo_del_subtipo(): void
    {
        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [
            ['name' => 'Procesador', 'value' => 'Intel Core i7'],
            ['name' => 'RAM', 'value' => '16GB'],
        ];

        $this->postJson(self::BASE_URL, $payload)->assertCreated()->assertJsonCount(2, 'data.characteristics');

        $this->assertDatabaseHas('asset_characteristic_definitions', ['category_id' => $this->assetSubcategory->id, 'name' => 'Procesador']);
        $this->assertDatabaseHas('fixed_asset_characteristics', ['name' => 'RAM', 'value' => '16GB']);
    }

    public function test_reutiliza_una_definicion_de_caracteristica_existente_en_vez_de_duplicarla(): void
    {
        $definition = AssetCharacteristicDefinition::create(['category_id' => $this->assetSubcategory->id, 'name' => 'Procesador']);

        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [['name' => 'Procesador', 'value' => 'AMD Ryzen 7', 'definition_id' => $definition->id]];

        $this->postJson(self::BASE_URL, $payload)->assertCreated();
        $this->assertDatabaseCount('asset_characteristic_definitions', 1);
    }

    public function test_reutilizar_el_nombre_de_una_caracteristica_borrada_del_catalogo_no_revienta(): void
    {
        AssetCharacteristicDefinition::create(['category_id' => $this->assetSubcategory->id, 'name' => 'Procesador'])->delete();

        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [['name' => 'Procesador', 'value' => 'Intel Core i9']];

        $this->postJson(self::BASE_URL, $payload)->assertCreated();
        $this->assertDatabaseHas('fixed_asset_characteristics', ['name' => 'Procesador', 'value' => 'Intel Core i9']);
        $this->assertDatabaseCount('asset_characteristic_definitions', 1);
    }
}
