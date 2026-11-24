<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaque;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Interruptor `opera_en_bins` de la temporada: alta, edición con candado
 * cuando ya hay rezaga o ventas, y su salida en el JSON de Operación Agrícola.
 */
class TemporadaOperaEnBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/agricola/temporadas';
    private const URL_OA = '/api/splendidfarms/operacion-agricola/temporadas';

    private const MENSAJE_CANDADO = 'No se puede cambiar: la temporada ya tiene rezaga o ventas de rezaga registradas.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function cuerpo(array $extra = []): array
    {
        return array_merge([
            'cultivo_id' => $this->cultivoElote->id,
            'locacion' => 'Guasave',
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-06-30',
        ], $extra);
    }

    private function crearRezaga(Temporada $temporada): RezagaEmpaque
    {
        return RezagaEmpaque::create([
            'temporada_id' => $temporada->id,
            'entity_id' => $this->entity->id,
            'folio_rezaga' => 'REZ-BIN-1',
            'tipo_rezaga' => 'descarte',
            'fecha' => '2026-02-02',
            'cantidad_kg' => 100,
            'status' => 'pendiente',
        ]);
    }

    public function test_crear_temporada_guarda_opera_en_bins(): void
    {
        $this->postJson(self::URL, $this->cuerpo(['opera_en_bins' => true]))
            ->assertCreated();

        $this->assertDatabaseHas('temporadas', ['locacion' => 'Guasave', 'opera_en_bins' => 1]);
    }

    public function test_sin_el_campo_queda_apagado(): void
    {
        $this->postJson(self::URL, $this->cuerpo())->assertCreated();

        $this->assertDatabaseHas('temporadas', ['locacion' => 'Guasave', 'opera_en_bins' => 0]);
    }

    public function test_no_se_cambia_con_rezaga_registrada(): void
    {
        $this->crearRezaga($this->temporadaBins);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['opera_en_bins' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.opera_en_bins.0', self::MENSAJE_CANDADO);

        $this->assertTrue(Temporada::find($this->temporadaBins->id)->opera_en_bins);
    }

    public function test_no_se_cambia_con_venta_de_rezaga_registrada(): void
    {
        SalidaRezagaEmpaque::create([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'folio_venta' => 'VRZ-BIN-1',
            'comprador' => 'Comprador de prueba',
            'fecha_venta' => '2026-02-03',
            'status' => 'pendiente',
        ]);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['opera_en_bins' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.opera_en_bins.0', self::MENSAJE_CANDADO);
    }

    public function test_se_cambia_sin_rezaga_ni_ventas(): void
    {
        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['opera_en_bins' => false]))
            ->assertOk();

        $this->assertFalse(Temporada::find($this->temporadaBins->id)->opera_en_bins);
    }

    public function test_mismo_valor_con_rezaga_si_se_guarda(): void
    {
        $this->crearRezaga($this->temporadaBins);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['opera_en_bins' => true, 'locacion' => 'Mochis Norte']))
            ->assertOk();

        $this->assertDatabaseHas('temporadas', ['id' => $this->temporadaBins->id, 'locacion' => 'Mochis Norte', 'opera_en_bins' => 1]);
    }

    public function test_omitir_el_campo_con_rezaga_deja_el_valor_como_estaba(): void
    {
        $this->crearRezaga($this->temporadaBins);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['locacion' => 'Mochis Sur']))
            ->assertOk();

        $this->assertDatabaseHas('temporadas', ['id' => $this->temporadaBins->id, 'locacion' => 'Mochis Sur', 'opera_en_bins' => 1]);
    }

    public function test_temporadas_oa_trae_opera_en_bins(): void
    {
        $lista = $this->getJson(self::URL_OA)->assertOk()->json('data');

        $porId = collect($lista)->keyBy('id');
        $this->assertTrue($porId[$this->temporadaBins->id]['opera_en_bins']);
        $this->assertFalse($porId[$this->temporada->id]['opera_en_bins']);

        $this->getJson(self::URL_OA.'/'.$this->temporadaBins->id)
            ->assertOk()
            ->assertJsonPath('data.opera_en_bins', true);
        $this->getJson(self::URL_OA.'/'.$this->temporada->id)
            ->assertOk()
            ->assertJsonPath('data.opera_en_bins', false);
    }

    public function test_es_de_bins_es_falso_para_null_o_inexistente(): void
    {
        $this->assertTrue(Temporada::esDeBins($this->temporadaBins->id));
        $this->assertFalse(Temporada::esDeBins($this->temporada->id));
        $this->assertFalse(Temporada::esDeBins(null));
        $this->assertFalse(Temporada::esDeBins(999999));
        $this->assertTrue($this->temporadaBins->operaEnBins());
    }

    public function test_los_fixtures_de_bins_quedan_armados(): void
    {
        $this->assertNull($this->binCampo->clase_rezaga);
        $this->assertSame('hoja', $this->binHoja->clase_rezaga);
        $this->assertSame('fresco', $this->binFresco->clase_rezaga);
        $this->assertSame('cuarto_frio', $this->binCuartoFrio->clase_rezaga);

        $proceso = $this->crearProcesoBins(10);
        $this->assertSame('en_proceso', $proceso->status);
        $this->assertSame(10, $proceso->cantidad_disponible);
        $this->assertSame($this->temporadaBins->id, $proceso->temporada_id);
    }
}
