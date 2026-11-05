<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Enterprise;
use App\Services\Abastecimiento\GeneradorFolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeneradorFolioTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $slug): Enterprise
    {
        return Enterprise::create(['name' => $slug, 'slug' => $slug, 'description' => $slug, 'is_active' => true]);
    }

    public function test_folios_consecutivos_por_empresa_y_prefijo(): void
    {
        $sbp = $this->empresa('splendidbyporvenir');
        $otra = $this->empresa('otra-empresa');
        $gen = new GeneradorFolio;

        DB::transaction(function () use ($gen, $sbp, $otra) {
            $this->assertSame('CV-0001', $gen->siguiente($sbp, 'CV'));
            $this->assertSame('CV-0002', $gen->siguiente($sbp, 'CV'));
            $this->assertSame('CV-0001', $gen->siguiente($otra, 'CV'));
            $this->assertSame('RC-0001', $gen->siguiente($sbp, 'RC'));
        });

        $this->assertSame(2, (int) DB::table('abs_folios')->where(['enterprise_id' => $sbp->id, 'prefijo' => 'CV'])->value('ultimo'));
    }

    public function test_salta_folios_ocupados_incluidos_los_borrados(): void
    {
        $sbp = $this->empresa('splendidbyporvenir');
        $this->folioBorrado('abs_convenios', $sbp, 'CV-0001');
        $this->folioBorrado('abs_recepciones', $sbp, 'RC-0001');
        $this->folioBorrado('abs_recepciones', $sbp, 'RC-0002');
        $gen = new GeneradorFolio;

        DB::transaction(function () use ($gen, $sbp) {
            $this->assertSame('CV-0002', $gen->siguiente($sbp, 'CV'));
            $this->assertSame('RC-0003', $gen->siguiente($sbp, 'RC'));
            // Prefijo sin tabla propia: solo cuenta el contador.
            $this->assertSame('LQ-0001', $gen->siguiente($sbp, 'LQ'));
        });
    }

    /** Inserta una fila mínima ya borrada (borrado lógico) sin armar toda la cadena de llaves foráneas. */
    private function folioBorrado(string $tabla, Enterprise $empresa, string $folio): void
    {
        // Dentro de la transacción de la prueba las llaves foráneas se revisan hasta el commit, que nunca llega.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $base = ['enterprise_id' => $empresa->id, 'folio' => $folio, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()];
        $extra = $tabla === 'abs_convenios'
            ? ['temporada_id' => 1, 'productor_id' => 1, 'cultivo_id' => 1, 'variedad_id' => 1, 'modalidad' => 'compra_directa',
                'unidad' => 'caja', 'moneda' => 'MXN', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31', 'modo_volumen' => 'total']
            : ['convenio_id' => 1, 'fecha' => '2026-07-01', 'tipo_carga_id' => 1, 'cantidad' => 1];
        DB::table($tabla)->insert($base + $extra);
    }
}
