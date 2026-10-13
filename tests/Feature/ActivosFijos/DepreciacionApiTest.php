<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\DepreciationClose;
use App\Services\ActivosFijos\CierreDepreciacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\TestCase;

class DepreciacionApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        $this->crearActivoDepreciable();
    }

    private function cerrarMayo(): DepreciationClose
    {
        return app(CierreDepreciacion::class)->cerrar(
            $this->enterprise,
            CarbonImmutable::createFromFormat('!Y-m', '2026-05'),
            $this->usuarioDepreciacion(),
        );
    }

    public function test_vista_previa_de_un_mes_abierto(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view']));

        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')
            ->assertOk()
            ->assertJsonPath('data.closed', false)
            ->assertJsonPath('data.month', '2026-05')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.month_amount', '180.00')
            ->assertJsonPath('data.totals.month_total', '180.00')
            ->assertJsonPath('data.totals.assets_count', 1)
            ->assertJsonPath('data.totals_by_category.0.category_name', 'Equipos de cómputo');
    }

    public function test_el_mes_se_valida(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view']));

        $this->getJson(self::URL_DEPRECIACION)->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson(self::URL_DEPRECIACION . '?month=2026-13')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson(self::URL_DEPRECIACION . '?month=mayo')->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_el_mes_tiene_limite_inferior_en_2000_01(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());

        $this->getJson(self::URL_DEPRECIACION . '?month=1999-12')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson(self::URL_DEPRECIACION . '?month=0001-01')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '1999-12'])
            ->assertStatus(422)->assertJsonValidationErrors('month');

        $this->getJson(self::URL_DEPRECIACION . '?month=2000-01')->assertOk();
    }

    public function test_sin_el_permiso_de_cada_accion_es_403(): void
    {
        $cierre = $this->cerrarMayo();

        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, []));
        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')->assertForbidden();
        $this->getJson(self::URL_DEPRECIACION . '/cierres')->assertForbidden();

        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view']));
        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-04'])->assertForbidden();
        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $cierre->id, ['reason' => 'x'])->assertForbidden();
    }

    public function test_solo_create_cierra_el_mes_pero_no_lo_reabre(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['create']));

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])->assertCreated();
        $cierre = DepreciationClose::where('enterprise_id', $this->enterprise->id)->firstOrFail();

        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $cierre->id, ['reason' => 'Falta una tasa'])
            ->assertForbidden();
        $this->assertNotNull(DepreciationClose::find($cierre->id));
    }

    public function test_solo_delete_reabre_el_mes_pero_no_lo_cierra(): void
    {
        $cierre = $this->cerrarMayo();
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['delete']));

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-04'])->assertForbidden();
        $this->assertSame(1, DepreciationClose::where('enterprise_id', $this->enterprise->id)->count());

        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $cierre->id, ['reason' => 'Falta una tasa'])
            ->assertOk();
        $this->assertNull(DepreciationClose::find($cierre->id));
    }

    public function test_solo_view_lee_pero_no_cierra_ni_reabre(): void
    {
        $cierre = $this->cerrarMayo();
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view']));

        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')->assertOk();
        $this->getJson(self::URL_DEPRECIACION . '/cierres')->assertOk();
        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-04'])->assertForbidden();
        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $cierre->id, ['reason' => 'x'])->assertForbidden();
        $this->assertNotNull(DepreciationClose::find($cierre->id));
    }

    public function test_sin_autenticar_las_cuatro_rutas_responden_401(): void
    {
        $cierre = $this->cerrarMayo();

        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')->assertUnauthorized();
        $this->getJson(self::URL_DEPRECIACION . '/cierres')->assertUnauthorized();
        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-04'])->assertUnauthorized();
        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $cierre->id, ['reason' => 'x'])->assertUnauthorized();
        $this->assertNotNull(DepreciationClose::find($cierre->id));
    }

    public function test_un_usuario_de_ge_con_delete_reabre_un_cierre_de_sf_que_ve_en_su_alcance(): void
    {
        $cierre = $this->cerrarMayo();
        Sanctum::actingAs($this->usuarioDepreciacion($this->corporativo, ['delete']));

        $this->deleteJson(self::URL_DEPRECIACION_GE . '/cierres/' . $cierre->id, ['reason' => 'Corrección desde corporativo'])
            ->assertOk();
        $this->assertNull(DepreciationClose::find($cierre->id));
    }

    public function test_cerrar_y_despues_ver_la_foto(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])
            ->assertCreated()
            ->assertJsonPath('data.period', '2026-05')
            ->assertJsonPath('data.assets_count', 1)
            ->assertJsonPath('data.month_total', '180.00');

        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')
            ->assertOk()
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.close.period', '2026-05')
            ->assertJsonPath('data.lines.0.accumulated', '900.00');

        $this->getJson(self::URL_DEPRECIACION . '/cierres')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period', '2026-05');
    }

    public function test_cerrar_un_mes_futuro_o_repetido_es_422(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-07'])
            ->assertStatus(422)->assertJsonValidationErrors('month');

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])->assertCreated();
        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])
            ->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_reabrir_exige_motivo_y_solo_el_ultimo(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());
        $abril = $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-04'])->json('data.id');
        $mayo = $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])->json('data.id');

        $this->deleteJson(self::URL_DEPRECIACION . "/cierres/{$mayo}", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson(self::URL_DEPRECIACION . "/cierres/{$abril}", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('cierre');

        $this->deleteJson(self::URL_DEPRECIACION . "/cierres/{$mayo}", ['reason' => 'Falta una tasa'])->assertOk();
        $this->assertNull(DepreciationClose::find($mayo));
        $this->assertNotNull(DepreciationClose::find($abril));
    }

    public function test_reabrir_con_motivo_en_blanco_es_422(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());
        $mayo = $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05'])->json('data.id');

        $this->deleteJson(self::URL_DEPRECIACION . "/cierres/{$mayo}", ['reason' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertNotNull(DepreciationClose::find($mayo));
    }

    public function test_un_cierre_de_otra_empresa_es_404(): void
    {
        $ajeno = app(CierreDepreciacion::class)->cerrar(
            $this->corporativo,
            CarbonImmutable::createFromFormat('!Y-m', '2026-05'),
            $this->usuarioDepreciacion(),
        );
        Sanctum::actingAs($this->usuarioDepreciacion());

        $this->deleteJson(self::URL_DEPRECIACION . '/cierres/' . $ajeno->id, ['reason' => 'x'])->assertNotFound();
        $this->assertNotNull(DepreciationClose::find($ajeno->id));
    }

    public function test_cada_empresa_ve_solo_sus_cierres(): void
    {
        $this->cerrarMayo();
        Sanctum::actingAs($this->usuarioDepreciacion($this->corporativo));

        // GE elige la empresa; los cierres de SF no aparecen en los de GE.
        $this->getJson(self::URL_DEPRECIACION_GE . '/cierres?enterprise_id=' . $this->corporativo->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL_DEPRECIACION_GE . '/cierres?enterprise_id=' . $this->enterprise->id)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_en_ge_hay_que_elegir_la_empresa(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion($this->corporativo));

        $this->getJson(self::URL_DEPRECIACION_GE . '?month=2026-05')
            ->assertStatus(422)->assertJsonValidationErrors('enterprise_id');
        $this->getJson(self::URL_DEPRECIACION_GE . '?month=2026-05&enterprise_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('enterprise_id');

        $this->getJson(self::URL_DEPRECIACION_GE . '?month=2026-05&enterprise_id=' . $this->enterprise->id)
            ->assertOk()->assertJsonCount(1, 'data.lines');

        $this->postJson(self::URL_DEPRECIACION_GE . '/cierres', ['month' => '2026-05', 'enterprise_id' => $this->enterprise->id])
            ->assertCreated();
        $this->assertTrue(DepreciationClose::where('enterprise_id', $this->enterprise->id)->exists());
    }

    public function test_fuera_de_ge_se_ignora_el_enterprise_id_del_cliente(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion());

        $this->postJson(self::URL_DEPRECIACION . '/cierres', ['month' => '2026-05', 'enterprise_id' => $this->corporativo->id])
            ->assertCreated();

        $this->assertTrue(DepreciationClose::where('enterprise_id', $this->enterprise->id)->exists());
        $this->assertFalse(DepreciationClose::where('enterprise_id', $this->corporativo->id)->exists());
    }

    public function test_mis_permisos_incluye_depreciacion(): void
    {
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view', 'create']));

        $this->getJson('/api/splendidfarms/administration/activos-fijos/mis-permisos')
            ->assertOk()
            ->assertJsonPath('data.depreciacion.view', true)
            ->assertJsonPath('data.depreciacion.create', true)
            ->assertJsonPath('data.depreciacion.delete', false);
    }

    public function test_las_fechas_de_un_cierre_guardado_salen_como_y_m_d(): void
    {
        $cierre = $this->cerrarMayo();
        // Simula un motor que devuelve la fecha con hora (p. ej. "2026-01-15 00:00:00").
        DB::table('depreciation_close_lines')
            ->where('depreciation_close_id', $cierre->id)
            ->update(['purchase_date' => '2026-01-15 00:00:00', 'start_month' => '2026-01-01 00:00:00']);
        Sanctum::actingAs($this->usuarioDepreciacion($this->enterprise, ['view']));

        $this->getJson(self::URL_DEPRECIACION . '?month=2026-05')
            ->assertOk()
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.lines.0.purchase_date', '2026-01-15')
            ->assertJsonPath('data.lines.0.start_month', '2026-01-01');
    }
}
