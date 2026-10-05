<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\AssetCharacteristicDefinition;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class IntegridadActivoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const URL = '/api/splendidfarms/administration/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
        Storage::fake('public');
    }

    private function crearConCaracteristica(): int
    {
        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [['name' => 'Procesador', 'value' => 'i7']];

        return $this->postJson(self::URL, $payload)->assertCreated()->json('data.id');
    }

    public function test_actualizar_sin_la_llave_characteristics_las_conserva(): void
    {
        $id = $this->crearConCaracteristica();

        $this->putJson(self::URL."/{$id}", ['status' => 'en_mantenimiento'])->assertOk();

        $this->assertDatabaseHas('fixed_asset_characteristics', ['fixed_asset_id' => $id, 'name' => 'Procesador']);
    }

    public function test_sync_characteristics_sin_filas_las_quita_todas(): void
    {
        $id = $this->crearConCaracteristica();

        $this->putJson(self::URL."/{$id}", ['sync_characteristics' => true])->assertOk();

        $this->assertDatabaseMissing('fixed_asset_characteristics', ['fixed_asset_id' => $id]);
    }

    public function test_codigo_vacio_al_editar_conserva_el_actual(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000010']));

        $this->putJson(self::URL."/{$asset->id}", ['code' => ''])->assertOk()->assertJsonPath('data.code', 'AF-000010');
    }

    public function test_estado_vacio_al_editar_es_error_de_validacion_y_no_500(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000011']));

        $this->putJson(self::URL."/{$asset->id}", ['status' => ''])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_el_borrado_logico_conserva_la_imagen(): void
    {
        $id = $this->post(self::URL, $this->validFixedAssetPayload(['image' => UploadedFile::fake()->image('a.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $ruta = FixedAsset::find($id)->image;

        $this->deleteJson(self::URL."/{$id}")->assertOk();

        Storage::disk('public')->assertExists($ruta);
    }

    public function test_remove_image_quita_la_imagen_y_borra_el_archivo(): void
    {
        $id = $this->post(self::URL, $this->validFixedAssetPayload(['image' => UploadedFile::fake()->image('a.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $ruta = FixedAsset::find($id)->image;

        $this->putJson(self::URL."/{$id}", ['remove_image' => true])->assertOk()->assertJsonPath('data.image', null);

        Storage::disk('public')->assertMissing($ruta);
    }

    public function test_el_subtipo_debe_ser_hijo_del_tipo(): void
    {
        $otroTipo = AssetCategory::create(['code' => 'TAC-010', 'name' => 'Vehículos', 'is_active' => true]);

        $this->postJson(self::URL, $this->validFixedAssetPayload(['category_id' => $otroTipo->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['subcategory_id']);
    }

    public function test_un_subtipo_no_puede_usarse_como_tipo(): void
    {
        $this->postJson(self::URL, $this->validFixedAssetPayload(['category_id' => $this->assetSubcategory->id, 'subcategory_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_la_definicion_de_caracteristica_debe_ser_del_tipo_del_activo(): void
    {
        $otroTipo = AssetCategory::create(['code' => 'TAC-011', 'name' => 'Vehículos', 'is_active' => true]);
        $ajena = AssetCharacteristicDefinition::create(['category_id' => $otroTipo->id, 'name' => 'Placas']);

        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [['name' => 'Placas', 'value' => 'ABC', 'definition_id' => $ajena->id]];

        $this->postJson(self::URL, $payload)->assertStatus(422)->assertJsonValidationErrors(['characteristics.0.definition_id']);
    }

    public function test_filtro_vacio_no_vacia_el_resultado(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000012']));

        $this->getJson(self::URL.'?branch_id=&category_id=')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_la_busqueda_trata_el_porcentaje_como_literal(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000013', 'name' => 'Descuento 100% laptop']));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000014', 'name' => 'Laptop normal']));

        $this->getJson(self::URL.'?search='.urlencode('100%'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson(self::URL.'?search='.urlencode('%'))->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_per_page_tiene_tope(): void
    {
        $this->getJson(self::URL.'?per_page=5000')->assertOk()->assertJsonPath('data.per_page', 100);
    }

    public function test_actualizar_con_image_nula_conserva_la_imagen_y_el_archivo(): void
    {
        $id = $this->post(self::URL, $this->validFixedAssetPayload(['image' => UploadedFile::fake()->image('a.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $ruta = FixedAsset::find($id)->image;

        $this->putJson(self::URL."/{$id}", ['name' => 'Otro nombre', 'image' => null])->assertOk();
        $this->putJson(self::URL."/{$id}", ['name' => 'Otro nombre 2', 'image' => ''])->assertOk();

        $this->assertSame($ruta, FixedAsset::find($id)->image);
        Storage::disk('public')->assertExists($ruta);
    }

    public function test_un_fallo_del_broadcast_no_convierte_el_guardado_en_500(): void
    {
        // El evento es ShouldBroadcastNow: un driver que lanza excepción simula Reverb caído.
        config(['broadcasting.default' => 'caido', 'broadcasting.connections.caido' => ['driver' => 'caido']]);
        app(\Illuminate\Broadcasting\BroadcastManager::class)->extend('caido', fn () => new class extends \Illuminate\Broadcasting\Broadcasters\Broadcaster {
            public function auth($request) {}
            public function validAuthenticationResponse($request, $result) {}
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new \RuntimeException('reverb caído');
            }
        });

        $this->postJson(self::URL, $this->validFixedAssetPayload())->assertCreated();
        $this->assertDatabaseCount('fixed_assets', 1);
    }
}
