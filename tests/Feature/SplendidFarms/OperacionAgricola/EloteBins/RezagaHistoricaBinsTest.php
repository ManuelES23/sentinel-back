<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\RezagaEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * `cantidad_historica_bins` (bins disponibles + bins vendidos) viaja junto a
 * `cantidad_historica_kg` solo para rezagas capturadas en bins; una rezaga en
 * kg conserva exactamente el mismo JSON de siempre.
 */
class RezagaHistoricaBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/empaque';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Rezaga de 2 bins disponibles tras vender 3 de 5 (900 -> 450 kg vendidos). */
    private function rezagaVendidaEnBins(): RezagaEmpaque
    {
        $proceso = $this->crearProcesoBins(10);
        $rezaga = $this->crearRezagaBins($proceso, ['cantidad_bins' => 5, 'cantidad_kg' => 750]);
        $this->crearVentaDeRezaga($rezaga, 450, [], 3);
        $rezaga->update(['cantidad_bins' => 2, 'cantidad_kg' => 300]);

        return $rezaga->fresh();
    }

    private function rezagaMango(): RezagaEmpaque
    {
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MANGO-HIST');

        return RezagaEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $base['proceso']->id,
            'folio_rezaga' => 'REZ-MANGO-H1',
            'tipo_rezaga' => 'produccion',
            'fecha' => '2026-02-02',
            'cantidad_kg' => 100,
            'modo_registro' => 'general',
            'status' => 'pendiente',
        ]);
    }

    public function test_listado_de_procesos_trae_la_historica_en_bins(): void
    {
        $rezaga = $this->rezagaVendidaEnBins();

        $resp = $this->getJson(self::BASE.'/proceso?temporada_id='.$this->temporadaBins->id)->assertOk();

        $r = collect($resp->json('data.0.rezagas'))->firstWhere('id', $rezaga->id);
        $this->assertSame(2, $r['cantidad_bins']);
        $this->assertSame(5, $r['cantidad_historica_bins']);
        $this->assertEquals(750, $r['cantidad_historica_kg']);
    }

    public function test_listado_de_rezagas_trae_la_historica_en_bins(): void
    {
        $rezaga = $this->rezagaVendidaEnBins();

        $resp = $this->getJson(self::BASE.'/rezaga?temporada_id='.$this->temporadaBins->id)->assertOk();

        $r = collect($resp->json('data'))->firstWhere('id', $rezaga->id);
        $this->assertSame(2, $r['cantidad_bins']);
        $this->assertSame(5, $r['cantidad_historica_bins']);
        $this->assertEquals(750, $r['cantidad_historica_kg']);
    }

    public function test_procesos_del_dia_trae_la_historica_en_bins(): void
    {
        $rezaga = $this->rezagaVendidaEnBins();

        $resp = $this->getJson(self::BASE.'/rezaga/procesos-del-dia?temporada_id='.$this->temporadaBins->id.'&entity_id='.$this->entity->id)->assertOk();

        $r = collect($resp->json('data.0.rezagas_del_dia'))->firstWhere('id', $rezaga->id);
        $this->assertSame(5, $r['cantidad_historica_bins']);
    }

    public function test_recorrido_de_folios_trae_la_historica_en_bins(): void
    {
        $rezaga = $this->rezagaVendidaEnBins();

        $resp = $this->getJson(self::BASE.'/recorrido-folios?folio='.$rezaga->proceso->folio_proceso)->assertOk();

        $r = collect($resp->json('data.rezagas'))->firstWhere('id', $rezaga->id);
        $this->assertNotNull($r, $resp->getContent());
        $this->assertSame(5, $r['cantidad_historica_bins']);
    }

    public function test_rezaga_sin_ventas_en_bins_es_su_propia_historica(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $rezaga = $this->crearRezagaBins($proceso, ['cantidad_bins' => 4, 'cantidad_kg' => 600]);

        $resp = $this->getJson(self::BASE.'/rezaga?temporada_id='.$this->temporadaBins->id)->assertOk();

        $r = collect($resp->json('data'))->firstWhere('id', $rezaga->id);
        $this->assertSame(4, $r['cantidad_historica_bins']);
    }

    public function test_rezaga_en_kg_no_trae_la_clave_nueva(): void
    {
        $this->rezagaMango();

        $resp = $this->getJson(self::BASE.'/rezaga?temporada_id='.$this->temporada->id)->assertOk();
        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonMissingPath('data.0.cantidad_historica_bins');
        $resp->assertJsonMissingPath('data.0.cantidad_bins');
        $this->assertEquals(100, $resp->json('data.0.cantidad_historica_kg'));

        $resp = $this->getJson(self::BASE.'/proceso?temporada_id='.$this->temporada->id)->assertOk();
        $resp->assertJsonMissingPath('data.0.rezagas.0.cantidad_historica_bins');
        $this->assertEquals(100, $resp->json('data.0.rezagas.0.cantidad_historica_kg'));
    }
}
