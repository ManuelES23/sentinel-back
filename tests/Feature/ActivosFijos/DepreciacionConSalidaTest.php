<?php

namespace Tests\Feature\ActivosFijos;

use App\Services\ActivosFijos\CierreDepreciacion;
use App\Services\ActivosFijos\ResolutorDepreciacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class DepreciacionConSalidaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    private CierreDepreciacion $cierres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        $this->cierres = app(CierreDepreciacion::class);
    }

    private function mes(string $mes): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $mes);
    }

    /** Activo en baja con salida vigente el 20 de mayo de 2026. */
    private function activoConSalidaEnMayo()
    {
        $activo = $this->crearActivoDepreciable();
        $activo->update(['status' => 'baja']);
        $this->crearSalidaDirecta($activo, ['disposal_date' => '2026-05-20']);

        return $activo;
    }

    public function test_el_mes_de_la_salida_se_deprecia_completo(): void
    {
        $this->activoConSalidaEnMayo();

        $mes = $this->cierres->calcular($this->enterprise, $this->mes('2026-05'));

        $this->assertCount(1, $mes['lines']);
        $this->assertSame('180.00', $mes['lines'][0]['month_amount']);
        $this->assertSame('900.00', $mes['lines'][0]['accumulated']);
    }

    public function test_los_meses_anteriores_a_la_salida_siguen_incluyendolo(): void
    {
        $this->activoConSalidaEnMayo();

        $mes = $this->cierres->calcular($this->enterprise, $this->mes('2026-04'));

        $this->assertCount(1, $mes['lines']);
        $this->assertSame('720.00', $mes['lines'][0]['accumulated']);
    }

    public function test_los_meses_posteriores_a_la_salida_lo_excluyen_sin_contarlo_como_sin_depreciar(): void
    {
        $this->activoConSalidaEnMayo();

        $mes = $this->cierres->calcular($this->enterprise, $this->mes('2026-06'));

        $this->assertSame([], $mes['lines']);
        $this->assertSame([], $mes['excluded']);
    }

    public function test_un_baja_antiguo_sin_salida_sigue_excluido_en_todos_los_meses(): void
    {
        $this->crearActivoDepreciable(['status' => 'baja']);

        foreach (['2026-04', '2026-05', '2026-06'] as $mes) {
            $calculo = $this->cierres->calcular($this->enterprise, $this->mes($mes));
            $this->assertSame([], $calculo['lines']);
            $this->assertSame([], $calculo['excluded']);
        }
    }

    public function test_una_salida_revertida_ya_no_cuenta(): void
    {
        $activo = $this->crearActivoDepreciable(['status' => 'baja']);
        $this->crearSalidaDirecta($activo, ['disposal_date' => '2026-05-20', 'reversed_at' => now(), 'reversal_reason' => 'Error']);

        $mes = $this->cierres->calcular($this->enterprise, $this->mes('2026-05'));

        $this->assertSame([], $mes['lines']);
    }

    public function test_el_cierre_de_mayo_incluye_al_activo_y_la_foto_no_cambia(): void
    {
        $activo = $this->activoConSalidaEnMayo();
        $usuario = $this->usuarioDepreciacion();

        $cierre = $this->cierres->cerrar($this->enterprise, $this->mes('2026-05'), $usuario);
        $this->assertSame(1, $cierre->assets_count);

        $activo->update(['purchase_value' => 50000]);
        $foto = $this->cierres->estadoDelMes($this->enterprise, $this->mes('2026-05'));

        $this->assertTrue($foto['closed']);
        $this->assertSame('12000.00', $foto['lines'][0]['purchase_value']);
        $this->assertSame('180.00', $foto['lines'][0]['month_amount']);
    }

    public function test_el_resumen_de_un_activo_con_salida_usa_las_cifras_congeladas(): void
    {
        $activo = $this->activoConSalidaEnMayo();

        $resumen = app(ResolutorDepreciacion::class)->resumen($activo->fresh());

        $this->assertSame('baja', $resumen['estado']);
        $this->assertSame('900.00', $resumen['acumulado']);
        $this->assertSame('11100.00', $resumen['valor_en_libros']);
        $this->assertNull($resumen['cuota_mensual']);
    }

    public function test_el_resumen_de_un_baja_antiguo_no_trae_cifras(): void
    {
        $activo = $this->crearActivoDepreciable(['status' => 'baja']);

        $resumen = app(ResolutorDepreciacion::class)->resumen($activo->fresh());

        $this->assertSame('baja', $resumen['estado']);
        $this->assertNull($resumen['acumulado']);
        $this->assertNull($resumen['valor_en_libros']);
    }
}
