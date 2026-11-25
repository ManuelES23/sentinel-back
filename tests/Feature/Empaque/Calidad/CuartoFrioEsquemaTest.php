<?php

namespace Tests\Feature\Empaque\Calidad;

use App\Models\CalidadEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

class CuartoFrioEsquemaTest extends TestCase
{
    use RefreshDatabase, CreatesReporteEmpaqueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteEmpaqueFixtures();
    }

    private function evaluacion(string $folio, array $extra = []): CalidadEmpaque
    {
        return CalidadEmpaque::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'tipo_evaluacion' => 'recepcion',
            'folio_evaluacion' => $folio,
            'fecha_evaluacion' => '2026-11-20',
            'resultado' => 'aprobada',
        ], $extra));
    }

    public function test_calidad_empaque_tiene_las_columnas_nuevas(): void
    {
        $this->assertTrue(Schema::hasColumns('calidad_empaque', ['cuarto_frio_total', 'porcentaje_cumple_fresco']));
    }

    public function test_una_evaluacion_nueva_nace_con_cuarto_frio_en_cero(): void
    {
        $evaluacion = $this->evaluacion('QC-R-01-0001');

        $this->assertSame('0.00', $evaluacion->fresh()->cuarto_frio_total);
    }

    public function test_la_migracion_rellena_el_porcentaje_en_fresco_de_las_evaluaciones_anteriores(): void
    {
        $con = $this->evaluacion('QC-R-01-0001', ['porcentaje_cumple' => 88]);
        $sin = $this->evaluacion('QC-R-01-0002');

        DB::table('calidad_empaque')->update(['porcentaje_cumple_fresco' => null]);

        (require database_path('migrations/2026_11_25_130000_add_cuarto_frio_to_calidad_empaque.php'))->up();

        $this->assertSame('88.00', $con->fresh()->porcentaje_cumple_fresco);
        $this->assertNull($sin->fresh()->porcentaje_cumple_fresco);
    }
}
