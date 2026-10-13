<?php

namespace Tests\Feature\ActivosFijos;

use App\Services\ActivosFijos\ResolutorDepreciacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\TestCase;

class DepreciacionResolutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;

    private ResolutorDepreciacion $resolutor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->resolutor = app(ResolutorDepreciacion::class);
    }

    private function resolver($activo): array
    {
        return $this->resolutor->resolver($activo->fresh(['category', 'subcategory']));
    }

    public function test_usa_los_valores_propios_del_activo(): void
    {
        $r = $this->resolver($this->crearActivoDepreciable());

        $this->assertNull($r['motivo']);
        $this->assertSame(1200000, $r['datos']->valorCentavos);
        $this->assertSame(20.0, $r['datos']->tasaAnual);
        $this->assertSame(10.0, $r['datos']->residualPct);
        $this->assertSame('2026-01-01', $r['datos']->inicio->toDateString());
    }

    public function test_hereda_del_subtipo_y_luego_del_tipo(): void
    {
        $activo = $this->crearActivoDepreciable(['depreciation_rate' => null, 'salvage_percent' => null]);

        $this->assetCategory->update(['depreciation_rate' => 10, 'salvage_percent' => 5]);
        $r = $this->resolver($activo);
        $this->assertSame(10.0, $r['datos']->tasaAnual); // del tipo
        $this->assertSame(5.0, $r['datos']->residualPct);

        $this->assetSubcategory->update(['depreciation_rate' => 25, 'salvage_percent' => 0]);
        $r = $this->resolver($activo);
        $this->assertSame(25.0, $r['datos']->tasaAnual); // el subtipo gana
        $this->assertSame(0.0, $r['datos']->residualPct); // 0 es un valor válido, no «vacío»
    }

    public function test_cae_a_la_vida_util_en_anios(): void
    {
        $activo = $this->crearActivoDepreciable(['depreciation_rate' => null, 'useful_life_years' => 5]);

        $this->assertSame(20.0, $this->resolver($activo)['datos']->tasaAnual);
    }

    public function test_sin_tasa_queda_sin_depreciar(): void
    {
        $activo = $this->crearActivoDepreciable(['depreciation_rate' => null]);

        $r = $this->resolver($activo);
        $this->assertNull($r['datos']);
        $this->assertSame(ResolutorDepreciacion::SIN_TASA, $r['motivo']);
    }

    public function test_una_vida_util_de_cero_o_una_tasa_de_cero_no_es_una_tasa_valida(): void
    {
        // Vida útil 0 y sin tasa: no se divide entre cero ni se genera una tasa inválida.
        $vidaCero = $this->crearActivoDepreciable(['depreciation_rate' => null, 'useful_life_years' => 0]);
        $r = $this->resolver($vidaCero);
        $this->assertNull($r['datos']);
        $this->assertSame(ResolutorDepreciacion::SIN_TASA, $r['motivo']);

        // Tasa 0 en el activo (valor legado) y nada que herede: sin_tasa.
        $tasaCero = $this->crearActivoDepreciable(['depreciation_rate' => 0, 'useful_life_years' => 0]);
        $r = $this->resolver($tasaCero);
        $this->assertNull($r['datos']);
        $this->assertSame(ResolutorDepreciacion::SIN_TASA, $r['motivo']);

        // Tasa 0 en el activo pero el subtipo sí tiene tasa: se salta el 0 y hereda.
        $this->assetSubcategory->update(['depreciation_rate' => 25]);
        $this->assertSame(25.0, $this->resolver($tasaCero)['datos']->tasaAnual);

        // Vida útil nula tampoco rompe.
        $this->assetSubcategory->update(['depreciation_rate' => null]);
        $vidaNula = $this->crearActivoDepreciable(['depreciation_rate' => null, 'useful_life_years' => null]);
        $this->assertSame(ResolutorDepreciacion::SIN_TASA, $this->resolver($vidaNula)['motivo']);
    }

    public function test_un_residual_fuera_de_rango_se_ignora(): void
    {
        $activo = $this->crearActivoDepreciable(['salvage_percent' => 10]);
        $this->assetSubcategory->update(['salvage_percent' => 7]);

        // Se fuerza en la base un residual inválido (la validación de la API no lo permitiría).
        DB::table('fixed_assets')->where('id', $activo->id)->update(['salvage_percent' => 100]);
        $this->assertSame(7.0, $this->resolver($activo)['datos']->residualPct); // sigue con el subtipo

        DB::table('fixed_assets')->where('id', $activo->id)->update(['salvage_percent' => -5]);
        $this->assertSame(7.0, $this->resolver($activo)['datos']->residualPct);

        $this->assetSubcategory->update(['salvage_percent' => 150]);
        $this->assetCategory->update(['salvage_percent' => null]);
        $this->assertSame(0.0, $this->resolver($activo)['datos']->residualPct); // nada válido: 0
    }

    public function test_sin_fecha_o_sin_valor_queda_sin_depreciar(): void
    {
        $this->assertSame(
            ResolutorDepreciacion::SIN_FECHA,
            $this->resolver($this->crearActivoDepreciable(['purchase_date' => null]))['motivo'],
        );
        $this->assertSame(
            ResolutorDepreciacion::SIN_VALOR,
            $this->resolver($this->crearActivoDepreciable(['purchase_value' => null]))['motivo'],
        );
        $this->assertSame(
            ResolutorDepreciacion::SIN_VALOR,
            $this->resolver($this->crearActivoDepreciable(['purchase_value' => 0]))['motivo'],
        );
    }

    public function test_el_inicio_propio_gana_sobre_la_fecha_de_compra(): void
    {
        $activo = $this->crearActivoDepreciable(['depreciation_start' => '2026-04-20']);

        $this->assertSame('2026-04-01', $this->resolver($activo)['datos']->inicio->toDateString());
    }

    public function test_sin_fecha_de_compra_pero_con_inicio_si_deprecia(): void
    {
        $activo = $this->crearActivoDepreciable(['purchase_date' => null, 'depreciation_start' => '2026-02-01']);

        $this->assertNull($this->resolver($activo)['motivo']);
    }

    public function test_resumen_de_hoy(): void
    {
        $this->travelTo('2026-03-10');
        $activo = $this->crearActivoDepreciable()->fresh(['category', 'subcategory']);

        $resumen = $this->resolutor->resumen($activo);

        $this->assertSame('depreciando', $resumen['estado']);
        $this->assertSame('180.00', $resumen['cuota_mensual']);
        $this->assertSame('540.00', $resumen['acumulado']); // enero, febrero y marzo
        $this->assertSame('11460.00', $resumen['valor_en_libros']);
        $this->assertSame(20.0, $resumen['tasa']);
        $this->assertSame(10.0, $resumen['residual_pct']);
        $this->assertSame('2026-01-01', $resumen['inicio']);
    }

    public function test_resumen_de_un_activo_en_baja_o_incompleto(): void
    {
        $baja = $this->crearActivoDepreciable(['status' => 'baja'])->fresh(['category', 'subcategory']);
        $this->assertSame('baja', $this->resolutor->resumen($baja)['estado']);
        $this->assertNull($this->resolutor->resumen($baja)['valor_en_libros']);

        $incompleto = $this->crearActivoDepreciable(['depreciation_rate' => null])->fresh(['category', 'subcategory']);
        $resumen = $this->resolutor->resumen($incompleto);
        $this->assertSame('sin_depreciar', $resumen['estado']);
        $this->assertSame('sin_tasa', $resumen['motivo']);
        $this->assertNull($resumen['valor_en_libros']);
    }

    public function test_la_ficha_y_el_listado_traen_la_depreciacion(): void
    {
        $this->travelTo('2026-03-10');
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->actingUser);

        $base = '/api/splendidfarms/administration/activos-fijos/activos';

        $this->getJson("{$base}/{$activo->id}")
            ->assertOk()
            ->assertJsonPath('data.depreciacion.estado', 'depreciando')
            ->assertJsonPath('data.depreciacion.valor_en_libros', '11460.00')
            ->assertJsonPath('data.depreciacion.acumulado', '540.00');

        $this->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.data.0.depreciacion.valor_en_libros', '11460.00');
    }

    public function test_el_alta_y_la_edicion_devuelven_la_depreciacion(): void
    {
        $this->travelTo('2026-03-10');
        Sanctum::actingAs($this->actingUser);
        $base = '/api/splendidfarms/administration/activos-fijos/activos';

        $id = $this->postJson($base, $this->validFixedAssetPayload([
            'name' => 'Laptop nueva', 'purchase_date' => '2026-03-02', 'purchase_value' => 6000,
            'depreciation_rate' => 25, 'salvage_percent' => 0,
        ]))->assertCreated()
            ->assertJsonPath('data.depreciacion.estado', 'depreciando')
            ->assertJsonPath('data.depreciacion.acumulado', '125.00')
            ->json('data.id');

        $this->putJson("{$base}/{$id}", ['depreciation_rate' => 50])
            ->assertOk()
            ->assertJsonPath('data.depreciacion.acumulado', '250.00');
    }
}
