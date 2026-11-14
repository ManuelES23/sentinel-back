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
    private function convenio(string $modo, array $calibres = [], array $metas = [], float $total = 0): Convenio
    {
        $c = new Convenio([
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-28',
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
}
