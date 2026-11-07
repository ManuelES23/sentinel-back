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

    public function test_con_el_contador_existente_no_se_vuelve_a_insertar(): void
    {
        $sbp = $this->empresa('splendidbyporvenir');
        $gen = new GeneradorFolio;

        DB::transaction(function () use ($gen, $sbp) {
            $gen->siguiente($sbp, 'CV'); // crea la fila del contador

            $consultas = [];
            DB::listen(function ($q) use (&$consultas) {
                $consultas[] = $q->sql;
            });
            $this->assertSame('CV-0002', $gen->siguiente($sbp, 'CV'));
            $this->assertSame('CV-0003', $gen->siguiente($sbp, 'CV'));

            $inserts = array_filter($consultas, fn ($sql) => str_contains(strtolower($sql), 'insert'));
            $this->assertSame([], array_values($inserts), 'No debe insertar si la fila del contador ya existe.');
        });

        $this->assertSame(1, DB::table('abs_folios')->where(['enterprise_id' => $sbp->id, 'prefijo' => 'CV'])->count());
        $this->assertSame(3, (int) DB::table('abs_folios')->where(['enterprise_id' => $sbp->id, 'prefijo' => 'CV'])->value('ultimo'));
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

    public function test_reservar_da_folios_consecutivos_con_un_solo_update_y_siguiente_sigue_igual(): void
    {
        $sbp = $this->empresa('splendidbyporvenir');
        $gen = new GeneradorFolio;

        DB::transaction(function () use ($gen, $sbp) {
            $this->assertSame('RC-0001', $gen->siguiente($sbp, 'RC'));

            $sql = [];
            DB::listen(function ($q) use (&$sql) {
                $sql[] = strtolower($q->sql);
            });
            $folios = $gen->reservar($sbp, 'RC', 1200);
            $updates = array_filter($sql, fn ($q) => str_starts_with($q, 'update') && str_contains($q, 'abs_folios'));

            $this->assertCount(1, $updates);
            $this->assertCount(1200, $folios);
            $this->assertSame('RC-0002', $folios[0]);
            $this->assertSame('RC-1201', $folios[1199]);
            $this->assertSame('RC-1202', $gen->siguiente($sbp, 'RC'));
            $this->assertSame([], $gen->reservar($sbp, 'RC', 0));
        });

        $this->assertSame(1202, (int) DB::table('abs_folios')->where(['enterprise_id' => $sbp->id, 'prefijo' => 'RC'])->value('ultimo'));
    }

    public function test_reservar_salta_los_folios_ocupados_incluidos_los_borrados(): void
    {
        $sbp = $this->empresa('splendidbyporvenir');
        $this->folioBorrado('abs_recepciones', $sbp, 'RC-0002');
        $this->folioBorrado('abs_recepciones', $sbp, 'RC-0004');
        $gen = new GeneradorFolio;

        $folios = DB::transaction(fn () => $gen->reservar($sbp, 'RC', 4));

        $this->assertSame(['RC-0001', 'RC-0003', 'RC-0005', 'RC-0006'], $folios);
        $this->assertSame(6, (int) DB::table('abs_folios')->where(['enterprise_id' => $sbp->id, 'prefijo' => 'RC'])->value('ultimo'));
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
