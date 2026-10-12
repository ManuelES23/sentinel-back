<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\DepreciationClose;
use App\Models\FixedAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class DepreciacionEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const URL_TIPOS = '/api/grupoesplendido/administration/activos-fijos/tipos-activo';
    private const URL_ACTIVOS = '/api/splendidfarms/administration/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
    }

    public function test_existen_las_columnas_nuevas(): void
    {
        $this->assertTrue(Schema::hasColumns('asset_categories', ['depreciation_rate', 'salvage_percent']));
        $this->assertTrue(Schema::hasColumns('fixed_assets', ['depreciation_start', 'depreciation_rate', 'salvage_percent']));
        $this->assertTrue(Schema::hasColumns('depreciation_closes', [
            'enterprise_id', 'period', 'assets_count', 'excluded_count', 'month_total',
            'accumulated_total', 'book_total', 'closed_by', 'closed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('depreciation_close_lines', [
            'depreciation_close_id', 'fixed_asset_id', 'code', 'name', 'category_name', 'subcategory_name',
            'purchase_date', 'purchase_value', 'rate', 'salvage_percent', 'start_month',
            'month_amount', 'accumulated', 'book_value',
        ]));
    }

    public function test_un_cierre_por_empresa_y_mes(): void
    {
        $datos = ['enterprise_id' => $this->enterprise->id, 'period' => '2026-05-01'];
        DepreciationClose::create($datos);

        $this->expectException(QueryException::class);
        DepreciationClose::create($datos);
    }

    public function test_el_inicio_de_depreciacion_se_guarda_siempre_el_dia_uno(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001', 'depreciation_start' => '2026-03-17']));

        $this->assertSame('2026-03-01', $asset->fresh()->depreciation_start->format('Y-m-d'));
    }

    public function test_el_tipo_guarda_tasa_y_residual(): void
    {
        Sanctum::actingAs($this->actingUser);

        $this->postJson(self::URL_TIPOS, ['name' => 'Maquinaria', 'depreciation_rate' => 10, 'salvage_percent' => 5])
            ->assertCreated()
            ->assertJsonPath('data.depreciation_rate', '10.00')
            ->assertJsonPath('data.salvage_percent', '5.00');

        $this->putJson(self::URL_TIPOS . '/' . $this->assetCategory->id, ['depreciation_rate' => 25.5])
            ->assertOk()
            ->assertJsonPath('data.depreciation_rate', '25.50');
    }

    public function test_la_tasa_y_el_residual_del_tipo_se_validan(): void
    {
        Sanctum::actingAs($this->actingUser);

        $this->postJson(self::URL_TIPOS, ['name' => 'A', 'depreciation_rate' => 0])->assertStatus(422)->assertJsonValidationErrors('depreciation_rate');
        $this->postJson(self::URL_TIPOS, ['name' => 'B', 'depreciation_rate' => 101])->assertStatus(422)->assertJsonValidationErrors('depreciation_rate');
        $this->postJson(self::URL_TIPOS, ['name' => 'C', 'salvage_percent' => 100])->assertStatus(422)->assertJsonValidationErrors('salvage_percent');
        $this->postJson(self::URL_TIPOS, ['name' => 'D', 'salvage_percent' => -1])->assertStatus(422)->assertJsonValidationErrors('salvage_percent');
    }

    public function test_el_activo_valida_tasa_y_residual(): void
    {
        Sanctum::actingAs($this->actingUser);

        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['depreciation_rate' => 150]))
            ->assertStatus(422)->assertJsonValidationErrors('depreciation_rate');
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['salvage_percent' => 100]))
            ->assertStatus(422)->assertJsonValidationErrors('salvage_percent');
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['depreciation_start' => 'no-es-fecha']))
            ->assertStatus(422)->assertJsonValidationErrors('depreciation_start');
    }

    public function test_al_dar_de_alta_copia_la_tasa_del_tipo_o_del_subtipo(): void
    {
        $this->assetCategory->update(['depreciation_rate' => 10, 'salvage_percent' => 5]);
        Sanctum::actingAs($this->actingUser);

        // El subtipo no tiene valores propios: hereda los del tipo.
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['name' => 'Equipo A']))
            ->assertCreated()
            ->assertJsonPath('data.depreciation_rate', '10.00')
            ->assertJsonPath('data.salvage_percent', '5.00');

        // El subtipo con valores propios gana.
        $this->assetSubcategory->update(['depreciation_rate' => 20, 'salvage_percent' => 0]);
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['name' => 'Equipo B']))
            ->assertCreated()
            ->assertJsonPath('data.depreciation_rate', '20.00')
            ->assertJsonPath('data.salvage_percent', '0.00');

        // Lo que manda el usuario gana sobre el catálogo.
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['name' => 'Equipo C', 'depreciation_rate' => 33.33]))
            ->assertCreated()
            ->assertJsonPath('data.depreciation_rate', '33.33');
    }
}
