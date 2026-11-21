<?php

namespace Tests\Unit\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Services\Abastecimiento\MetasConvenio;
use Tests\TestCase;

/** Convenios armados en memoria: el servicio puro no toca la base. */
class MetasCalibresTest extends TestCase
{
    private function convenio(string $modo, array $calibres = [], array $metas = [], ?float $total = 0, string $fin = '2026-06-28'): Convenio
    {
        $c = new Convenio([
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => $fin,
            'unidad' => 'caja',
            'modo_volumen' => $modo,
            'volumen_total' => $total,
            'usa_calibres' => $calibres !== [],
        ]);
        $c->id = 1;
        $c->setRelation('calibres', collect(array_map(
            fn ($id, $vol) => new ConvenioCalibre(['convenio_id' => 1, 'calibre_id' => $id, 'volumen_total' => $vol]),
            array_keys($calibres),
            array_values($calibres),
        )));
        $c->setRelation('metas', collect(array_map(
            fn (array $m) => new ConvenioMeta(['convenio_id' => 1, 'calibre_id' => $m[0], 'semana' => $m[1], 'cantidad' => $m[2]]),
            $metas,
        )));

        return $c;
    }

    public function test_total_con_calibres_reparte_cada_calibre(): void
    {
        $c = $this->convenio('total', [7 => 100, 8 => 101]);

        $this->assertSame([
            7 => ['2026-06-01' => 25.0, '2026-06-08' => 25.0, '2026-06-15' => 25.0, '2026-06-22' => 25.0],
            8 => ['2026-06-01' => 25.0, '2026-06-08' => 25.0, '2026-06-15' => 25.0, '2026-06-22' => 26.0],
        ], MetasConvenio::metasPorCalibre($c));
        $this->assertSame(
            ['2026-06-01' => 50.0, '2026-06-08' => 50.0, '2026-06-15' => 50.0, '2026-06-22' => 51.0],
            MetasConvenio::metas($c),
        );
        $this->assertSame(201.0, MetasConvenio::pactado($c));
        $this->assertSame([7 => 100.0, 8 => 101.0], MetasConvenio::pactadoPorCalibre($c));
    }

    public function test_semanal_con_calibres_agrupa_por_calibre_y_suma_por_semana(): void
    {
        $c = $this->convenio('semanal', [7 => 0, 8 => 0], [
            [7, '2026-06-01', 10], [7, '2026-06-08', 20],
            [8, '2026-06-01', 5], [8, '2026-06-08', 6],
        ]);

        $this->assertSame([
            7 => ['2026-06-01' => 10.0, '2026-06-08' => 20.0],
            8 => ['2026-06-01' => 5.0, '2026-06-08' => 6.0],
        ], MetasConvenio::metasPorCalibre($c));
        $this->assertSame(['2026-06-01' => 15.0, '2026-06-08' => 26.0], MetasConvenio::metas($c));
        $this->assertSame(41.0, MetasConvenio::pactado($c));
        $this->assertSame([7 => 30.0, 8 => 11.0], MetasConvenio::pactadoPorCalibre($c));
    }

    public function test_sin_calibres_se_comporta_como_antes(): void
    {
        $total = $this->convenio('total', [], [], 101);
        $this->assertSame([], MetasConvenio::metasPorCalibre($total));
        $this->assertSame([], MetasConvenio::pactadoPorCalibre($total));
        $this->assertSame(
            ['2026-06-01' => 25.0, '2026-06-08' => 25.0, '2026-06-15' => 25.0, '2026-06-22' => 26.0],
            MetasConvenio::metas($total),
        );
        $this->assertSame(101.0, MetasConvenio::pactado($total));

        $semanal = $this->convenio('semanal', [], [[null, '2026-06-08', 4], [null, '2026-06-01', 3]]);
        $this->assertSame(['2026-06-01' => 3.0, '2026-06-08' => 4.0], MetasConvenio::metas($semanal));
        $this->assertSame(7.0, MetasConvenio::pactado($semanal));
    }

    public function test_total_propio_en_modo_total_reparte_el_total_y_los_calibres_son_apartados(): void
    {
        $c = $this->convenio('total', [7 => 1000, 8 => 1000, 9 => null], [], 7000);

        $this->assertTrue(MetasConvenio::totalPropio($c));
        $this->assertSame(7000.0, MetasConvenio::pactado($c));
        $this->assertSame(
            ['2026-06-01' => 1750.0, '2026-06-08' => 1750.0, '2026-06-15' => 1750.0, '2026-06-22' => 1750.0],
            MetasConvenio::metas($c),
        );
        $this->assertSame([7 => 1000.0, 8 => 1000.0, 9 => 0.0], MetasConvenio::pactadoPorCalibre($c));
        $this->assertSame(2000.0, MetasConvenio::apartado($c));
        $this->assertSame(5000.0, MetasConvenio::libre($c));
    }

    public function test_sin_volumen_total_no_hay_total_propio_y_el_pactado_es_la_suma_de_los_calibres(): void
    {
        $c = $this->convenio('total', [7 => 100, 8 => 101], [], null);

        $this->assertFalse(MetasConvenio::totalPropio($c));
        $this->assertSame(201.0, MetasConvenio::pactado($c));
        $this->assertSame([7 => 100.0, 8 => 101.0], MetasConvenio::pactadoPorCalibre($c));
        $this->assertNull(MetasConvenio::libre($c));
    }

    public function test_semanal_con_metas_sin_calibre_es_total_propio_y_las_de_calibre_son_apartados(): void
    {
        $semanas = ['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22'];
        $metas = [];
        foreach ($semanas as $lunes) {
            $metas[] = [null, $lunes, 1750];
            $metas[] = [7, $lunes, 250];
        }
        $c = $this->convenio('semanal', [7 => 0], $metas, null);

        $this->assertTrue(MetasConvenio::totalPropio($c));
        $this->assertSame(7000.0, MetasConvenio::pactado($c));
        $this->assertSame(
            ['2026-06-01' => 1750.0, '2026-06-08' => 1750.0, '2026-06-15' => 1750.0, '2026-06-22' => 1750.0],
            MetasConvenio::metas($c),
        );
        $this->assertSame([7 => 1000.0], MetasConvenio::pactadoPorCalibre($c));
        $this->assertSame([7 => ['2026-06-01' => 250.0, '2026-06-08' => 250.0, '2026-06-15' => 250.0, '2026-06-22' => 250.0]], MetasConvenio::metasPorCalibre($c));
        $this->assertSame(1000.0, MetasConvenio::apartado($c));
        $this->assertSame(6000.0, MetasConvenio::libre($c));
    }

    public function test_semanal_ordena_las_metas_sin_calibre_por_semana(): void
    {
        $c = $this->convenio('semanal', [7 => 0], [[null, '2026-06-08', 4], [7, '2026-06-01', 1], [null, '2026-06-01', 3]], null);

        $this->assertSame(['2026-06-01' => 3.0, '2026-06-08' => 4.0], MetasConvenio::metas($c));
    }

    public function test_semanal_sin_metas_sin_calibre_no_hay_total_propio(): void
    {
        $c = $this->convenio('semanal', [7 => 0, 8 => 0], [[7, '2026-06-01', 10], [8, '2026-06-01', 5]], null);

        $this->assertFalse(MetasConvenio::totalPropio($c));
        $this->assertSame(15.0, MetasConvenio::pactado($c));
        $this->assertNull(MetasConvenio::libre($c));
    }

    public function test_el_reparto_del_total_propio_deja_el_redondeo_en_la_ultima_semana(): void
    {
        $c = $this->convenio('total', [7 => 5, 8 => 5], [], 10, '2026-06-21');

        $this->assertSame(['2026-06-01' => 3.0, '2026-06-08' => 3.0, '2026-06-15' => 4.0], MetasConvenio::metas($c));
        $this->assertSame(10.0, MetasConvenio::pactado($c));
        $this->assertSame(10.0, MetasConvenio::apartado($c));
        $this->assertSame(0.0, MetasConvenio::libre($c));
    }

    public function test_un_convenio_clasico_no_tiene_total_propio_aunque_tenga_metas_sin_calibre(): void
    {
        $semanal = $this->convenio('semanal', [], [[null, '2026-06-01', 3], [null, '2026-06-08', 4]]);
        $this->assertFalse(MetasConvenio::totalPropio($semanal));
        $this->assertNull(MetasConvenio::libre($semanal));
        $this->assertSame(7.0, MetasConvenio::pactado($semanal));

        $total = $this->convenio('total', [], [], 101);
        $this->assertFalse(MetasConvenio::totalPropio($total));
        $this->assertSame(101.0, MetasConvenio::pactado($total));
    }

    public function test_el_apartado_mayor_al_total_deja_el_libre_en_cero(): void
    {
        $c = $this->convenio('total', [7 => 600, 8 => 600], [], 1000);

        $this->assertSame(0.0, MetasConvenio::libre($c));
    }
}