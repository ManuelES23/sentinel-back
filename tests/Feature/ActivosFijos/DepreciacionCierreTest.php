<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\DepreciationClose;
use App\Models\DepreciationCloseLine;
use App\Services\ActivosFijos\CierreDepreciacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\TestCase;

class DepreciacionCierreTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;

    private CierreDepreciacion $cierres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        $this->cierres = app(CierreDepreciacion::class);
    }

    private function mes(string $anioMes): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $anioMes);
    }

    public function test_calcula_solo_lo_que_corresponde(): void
    {
        $a = $this->crearActivoDepreciable(['name' => 'Depreciable']);
        $this->crearActivoDepreciable(['name' => 'Sin tasa', 'depreciation_rate' => null]);
        $this->crearActivoDepreciable(['name' => 'En baja', 'status' => 'baja']);
        $this->crearActivoDepreciable(['name' => 'Aún no empieza', 'purchase_date' => '2026-08-10']);
        $this->crearActivoDepreciable(['name' => 'Borrado'])->delete();

        $r = $this->cierres->calcular($this->enterprise, $this->mes('2026-05'));

        // Solo el depreciable genera línea; el «sin tasa» se cuenta aparte; baja, borrado y «por iniciar» no aparecen.
        $this->assertCount(1, $r['lines']);
        $this->assertSame($a->id, $r['lines'][0]['fixed_asset_id']);
        $this->assertSame('180.00', $r['lines'][0]['month_amount']);
        $this->assertSame('900.00', $r['lines'][0]['accumulated']); // enero a mayo
        $this->assertSame('11100.00', $r['lines'][0]['book_value']);
        $this->assertSame('Equipos de cómputo', $r['lines'][0]['category_name']);
        $this->assertSame('Laptops', $r['lines'][0]['subcategory_name']);
        $this->assertSame('2026-01-01', $r['lines'][0]['start_month']);

        $this->assertCount(1, $r['excluded']);
        $this->assertSame('Sin tasa', $r['excluded'][0]['name']);
        $this->assertSame('sin_tasa', $r['excluded'][0]['reason']);
    }

    public function test_no_incluye_activos_de_otra_empresa(): void
    {
        $this->crearActivoDepreciable();

        $r = $this->cierres->calcular($this->corporativo, $this->mes('2026-05'));

        $this->assertSame([], $r['lines']);
        $this->assertSame([], $r['excluded']);
    }

    public function test_un_activo_totalmente_depreciado_sigue_con_cuota_cero(): void
    {
        $this->crearActivoDepreciable(['purchase_date' => '2020-01-10']); // 60 meses: terminó en diciembre de 2024

        $linea = $this->cierres->calcular($this->enterprise, $this->mes('2026-05'))['lines'][0];

        $this->assertSame('0.00', $linea['month_amount']);
        $this->assertSame('10800.00', $linea['accumulated']);
        $this->assertSame('1200.00', $linea['book_value']);
    }

    public function test_cerrar_guarda_la_foto_y_los_totales(): void
    {
        $this->crearActivoDepreciable(['name' => 'A']);
        $this->crearActivoDepreciable(['name' => 'B', 'purchase_value' => 6000, 'salvage_percent' => 0]);
        $this->crearActivoDepreciable(['name' => 'Sin tasa', 'depreciation_rate' => null]);
        $usuario = $this->usuarioDepreciacion();

        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);

        $this->assertSame('2026-05-01', $cierre->period);
        $this->assertSame(2, $cierre->assets_count);
        $this->assertSame(1, $cierre->excluded_count);
        $this->assertSame('280.00', $cierre->month_total);       // 180 + 100
        $this->assertSame('1400.00', $cierre->accumulated_total); // 900 + 500
        $this->assertSame('16600.00', $cierre->book_total);       // 11,100 + 5,500
        $this->assertSame($usuario->id, $cierre->closed_by);
        $this->assertNotNull($cierre->closed_at);
        $this->assertSame(2, DepreciationCloseLine::where('depreciation_close_id', $cierre->id)->count());
    }

    public function test_la_foto_no_cambia_al_editar_el_activo_ni_el_tipo(): void
    {
        $activo = $this->crearActivoDepreciable();
        $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $this->usuarioDepreciacion());

        $activo->update(['purchase_value' => 1, 'name' => 'Otro nombre', 'depreciation_rate' => 50]);
        $this->assetSubcategory->update(['name' => 'Renombrado']);

        $estado = $this->cierres->estadoDelMes($this->enterprise, $this->mes('2026-05'));

        $this->assertTrue($estado['closed']);
        $this->assertSame('12000.00', $estado['lines'][0]['purchase_value']);
        $this->assertSame('180.00', $estado['lines'][0]['month_amount']);
        $this->assertSame('Laptops', $estado['lines'][0]['subcategory_name']);
        $this->assertNotSame('Otro nombre', $estado['lines'][0]['name']);

        // El mes abierto sí refleja los datos nuevos.
        $abierto = $this->cierres->estadoDelMes($this->enterprise, $this->mes('2026-06'));
        $this->assertFalse($abierto['closed']);
        $this->assertSame('Otro nombre', $abierto['lines'][0]['name']);
    }

    public function test_no_se_puede_cerrar_un_mes_futuro_ni_uno_ya_cerrado(): void
    {
        $usuario = $this->usuarioDepreciacion();

        try {
            $this->cierres->cerrar($this->enterprise, $this->mes('2026-07'), $usuario);
            $this->fail('Debió rechazar el mes futuro.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->cierres->cerrar($this->enterprise, $this->mes('2026-06'), $usuario); // el mes en curso sí se puede

        try {
            $this->cierres->cerrar($this->enterprise, $this->mes('2026-06'), $usuario);
            $this->fail('Debió rechazar el mes duplicado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame(1, DepreciationClose::count());
    }

    public function test_se_puede_cerrar_un_mes_sin_haber_cerrado_los_anteriores(): void
    {
        $this->crearActivoDepreciable();

        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $this->usuarioDepreciacion());

        $this->assertSame('900.00', $cierre->accumulated_total);
    }

    public function test_el_mes_abierto_es_vista_previa_y_trae_los_excluidos(): void
    {
        $this->crearActivoDepreciable();
        $this->crearActivoDepreciable(['name' => 'Sin fecha', 'purchase_date' => null]);

        $estado = $this->cierres->estadoDelMes($this->enterprise, $this->mes('2026-05'));

        $this->assertFalse($estado['closed']);
        $this->assertNull($estado['close']);
        $this->assertCount(1, $estado['lines']);
        $this->assertSame(1, $estado['excluded_count']);
        $this->assertSame('sin_fecha', $estado['excluded'][0]['reason']);
        $this->assertSame(1, $estado['totals']['assets_count']);
        $this->assertSame('180.00', $estado['totals']['month_total']);
        $this->assertSame('Equipos de cómputo', $estado['totals_by_category'][0]['category_name']);
        $this->assertSame('180.00', $estado['totals_by_category'][0]['month_amount']);
    }

    public function test_solo_se_reabre_el_ultimo_cierre_y_con_registro_del_motivo(): void
    {
        $this->crearActivoDepreciable();
        $usuario = $this->usuarioDepreciacion();
        $abril = $this->cierres->cerrar($this->enterprise, $this->mes('2026-04'), $usuario);
        $mayo = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);

        try {
            $this->cierres->reabrir($abril, 'Corrección', $usuario);
            $this->fail('Debió rechazar: no es el último cierre.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cierre', $e->errors());
        }

        $this->cierres->reabrir($mayo, 'Faltaba capturar la tasa de un activo', $usuario);

        $this->assertNull(DepreciationClose::find($mayo->id));
        $this->assertSame(0, DepreciationCloseLine::where('depreciation_close_id', $mayo->id)->count());
        $log = ActivityLog::where('action', 'reopen')->where('model', 'DepreciationClose')->where('model_id', $mayo->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Faltaba capturar la tasa de un activo', $log->new_values['reason']);

        // Ahora abril es el último y se puede reabrir; mayo se puede volver a cerrar.
        $this->cierres->reabrir($abril, 'Otra corrección', $usuario);
        $this->assertSame(0, DepreciationClose::count());
        $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);
    }

    public function test_reabrir_exige_un_motivo_no_vacio(): void
    {
        $usuario = $this->usuarioDepreciacion();
        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);

        try {
            $this->cierres->reabrir($cierre, "   \n ", $usuario);
            $this->fail('Debió rechazar el motivo vacío.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertNotNull(DepreciationClose::find($cierre->id));
        $this->assertSame(0, ActivityLog::where('action', 'reopen')->count());
    }

    public function test_reabrir_registra_datos_utiles_y_el_motivo_sin_espacios_sobrantes(): void
    {
        $this->crearActivoDepreciable();
        $this->crearActivoDepreciable(['name' => 'Sin tasa', 'depreciation_rate' => null]);
        $usuario = $this->usuarioDepreciacion();
        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);

        $this->cierres->reabrir($cierre, '  Corregir tasa  ', $usuario);

        $log = ActivityLog::where('action', 'reopen')->where('model_id', $cierre->id)->first();
        $this->assertSame('Corregir tasa', $log->new_values['reason']);
        $this->assertSame($usuario->id, $log->new_values['reopened_by']);
        $this->assertSame($this->enterprise->id, $log->old_values['enterprise_id']);
        $this->assertSame(1, $log->old_values['excluded_count']);
        $this->assertSame('900.00', $log->old_values['accumulated_total']);
        $this->assertSame('11100.00', $log->old_values['book_total']);
        $this->assertSame('180.00', $log->old_values['month_total']);
    }

    public function test_el_mismo_mes_se_puede_cerrar_en_dos_empresas(): void
    {
        $usuario = $this->usuarioDepreciacion();

        $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);
        $this->cierres->cerrar($this->corporativo, $this->mes('2026-05'), $usuario);

        $this->assertSame(2, DepreciationClose::where('period', '2026-05-01')->count());
    }

    public function test_un_cierre_de_otra_empresa_posterior_no_impide_reabrir_el_ultimo_de_esta(): void
    {
        $usuario = $this->usuarioDepreciacion();
        $propio = $this->cierres->cerrar($this->enterprise, $this->mes('2026-04'), $usuario);
        $this->cierres->cerrar($this->corporativo, $this->mes('2026-05'), $usuario);

        $this->cierres->reabrir($propio, 'Corrección', $usuario);

        $this->assertNull(DepreciationClose::find($propio->id));
        $this->assertSame(1, DepreciationClose::count());
    }

    public function test_aarreglo_formatea_el_cierre(): void
    {
        $usuario = $this->usuarioDepreciacion();
        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);

        $arreglo = $this->cierres->aArreglo($cierre->fresh('closedBy'));

        $this->assertSame('2026-05', $arreglo['period']);
        $this->assertSame($usuario->name, $arreglo['closed_by']);
        $this->assertSame(0, $arreglo['assets_count']);
    }
}
