<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Variedad;
use App\Services\Abastecimiento\MetasConvenio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** `MetasConvenio` con total propio leyendo de la base (sin relaciones precargadas) y las opciones del fixture. */
class MetasConvenioTotalPropioTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    /** @return array{0: \App\Models\CRM\CrmProductoCalibre, 1: \App\Models\CRM\CrmProductoCalibre} */
    private function calibres(): array
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();

        return [$this->crearCalibre($variedad, 'Jumbo'), $this->crearCalibre($variedad, 'Grande')];
    }

    public function test_modo_total_con_total_propio_desde_la_base(): void
    {
        [$a, $b] = $this->calibres();
        $c = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 1000],
            ['calibre' => $b, 'volumen' => null],
        ], ['volumen_total' => 7000]);
        $c = Convenio::findOrFail($c->id);

        $this->assertTrue(MetasConvenio::totalPropio($c));
        $this->assertSame(7000.0, MetasConvenio::pactado($c));
        $this->assertSame([$a->id => 1000.0, $b->id => 0.0], MetasConvenio::pactadoPorCalibre($c));
        $this->assertSame(1000.0, MetasConvenio::apartado($c));
        $this->assertSame(6000.0, MetasConvenio::libre($c));
    }

    public function test_modo_semanal_con_total_propio_desde_la_base(): void
    {
        [$a, $b] = $this->calibres();
        $semanas = ['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22'];
        $c = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a],
            ['calibre' => $b],
        ], [
            'modo_volumen' => 'semanal',
            'metas_total' => array_fill_keys($semanas, 1750),
            'metas_calibre' => [$a->id => array_fill_keys($semanas, 250)],
        ]);
        $c = Convenio::findOrFail($c->id);

        $this->assertTrue(MetasConvenio::totalPropio($c));
        $this->assertSame(7000.0, MetasConvenio::pactado($c));
        $this->assertSame([$a->id => 1000.0], MetasConvenio::pactadoPorCalibre($c));
        $this->assertSame(6000.0, MetasConvenio::libre($c));
    }

    public function test_sin_opciones_el_fixture_sigue_siendo_sin_total_propio(): void
    {
        [$a, $b] = $this->calibres();
        $c = Convenio::findOrFail($this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 100],
            ['calibre' => $b, 'volumen' => 50],
        ])->id);

        $this->assertFalse(MetasConvenio::totalPropio($c));
        $this->assertSame(150.0, MetasConvenio::pactado($c));
        $this->assertNull(MetasConvenio::libre($c));
    }
}
