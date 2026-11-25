<?php

namespace Tests\Feature\Empaque\Calidad;

use App\Models\CalidadEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * El «aprovechable para cuarto frío» se suma a lo que cumple en el porcentaje de la evaluación.
 */
class CuartoFrioCalculoTest extends TestCase
{
    use RefreshDatabase, CreatesReporteEmpaqueFixtures;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/calidad';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteEmpaqueFixtures();
        $this->actingUser->update(['role' => 'admin']);
        Sanctum::actingAs($this->actingUser);
        $this->withHeaders(['X-Enterprise-Slug' => $this->enterprise->slug]);
    }

    private function muestra(int $muestra, float $cumple, float $noCumple, array $extra = []): array
    {
        return array_merge(['muestra' => $muestra, 'cumple' => $cumple, 'no_cumple' => $noCumple], $extra);
    }

    private function cuerpo(array $muestras, array $extra = [], string $tipo = 'recepcion'): array
    {
        return array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'tipo_evaluacion' => $tipo,
            'fecha_evaluacion' => '2026-11-20',
            'muestras' => $muestras,
        ], $extra);
    }

    public function test_sin_cuarto_frio_queda_igual_que_hoy(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)]))
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', '80.00')
            ->assertJsonPath('data.porcentaje_cumple_fresco', '80.00')
            ->assertJsonPath('data.cuarto_frio_total', '0.00');
    }

    public function test_el_cuarto_frio_se_suma_a_lo_que_cumple(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => 8]))
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', '88.00')
            ->assertJsonPath('data.porcentaje_cumple_fresco', '80.00')
            ->assertJsonPath('data.cuarto_frio_total', '8.00')
            ->assertJsonPath('data.cumple_total', '80.00')
            ->assertJsonPath('data.no_cumple_total', '20.00');
    }

    public function test_varias_muestras_acumulan_el_cuarto_frio_de_la_evaluacion(): void
    {
        $this->postJson(
            self::URL,
            $this->cuerpo([$this->muestra(100, 80, 20), $this->muestra(100, 90, 10)], ['cuarto_frio_total' => 10])
        )
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', '90.00')
            ->assertJsonPath('data.porcentaje_cumple_fresco', '85.00');
    }

    public function test_empacadores_usa_el_conteo_como_base(): void
    {
        $this->postJson(
            self::URL,
            $this->cuerpo([$this->muestra(192, 150, 50, ['conteo' => 200])], ['cuarto_frio_total' => 25], 'empacadores')
        )
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', '87.50')
            ->assertJsonPath('data.porcentaje_cumple_fresco', '75.00');
    }

    public function test_acepta_decimales_en_el_cuarto_frio(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => 8.5]))
            ->assertCreated()
            ->assertJsonPath('data.cuarto_frio_total', '8.50');
    }

    public function test_el_cuarto_frio_no_puede_pasar_de_lo_que_no_cumple(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 79, 20)], ['cuarto_frio_total' => 21]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuarto_frio_total']);

        $this->assertDatabaseCount('calidad_empaque', 0);
    }

    public function test_el_cuarto_frio_negativo_es_422(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuarto_frio_total']);
    }

    public function test_sin_base_los_porcentajes_quedan_nulos(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(0, 0, 0)]))
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', null)
            ->assertJsonPath('data.porcentaje_cumple_fresco', null);
    }

    public function test_el_porcentaje_con_cuarto_frio_nunca_pasa_de_100(): void
    {
        $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 95, 20)], ['cuarto_frio_total' => 20]))
            ->assertCreated()
            ->assertJsonPath('data.porcentaje_cumple', '100.00');
    }

    public function test_editar_sin_mandar_el_campo_conserva_el_cuarto_frio(): void
    {
        $id = $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => 8]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson(self::URL."/{$id}", ['observaciones' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.cuarto_frio_total', '8.00')
            ->assertJsonPath('data.porcentaje_cumple', '88.00');
    }

    public function test_editar_con_null_deja_el_cuarto_frio_en_cero(): void
    {
        $id = $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => 8]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson(self::URL."/{$id}", ['cuarto_frio_total' => null])
            ->assertOk()
            ->assertJsonPath('data.cuarto_frio_total', '0.00')
            ->assertJsonPath('data.porcentaje_cumple', '80.00');
    }

    public function test_editar_muestras_por_debajo_del_cuarto_frio_guardado_es_422_y_no_pierde_nada(): void
    {
        $id = $this->postJson(self::URL, $this->cuerpo([$this->muestra(100, 80, 20)], ['cuarto_frio_total' => 8]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson(self::URL."/{$id}", ['muestras' => [$this->muestra(100, 95, 5)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuarto_frio_total']);

        $calidad = CalidadEmpaque::find($id);
        $this->assertSame('88.00', (string) $calidad->porcentaje_cumple);
        $this->assertCount(1, $calidad->muestras);
        $this->assertSame('20.00', (string) $calidad->no_cumple_total);
        $this->assertEquals(20, $calidad->muestras->first()->no_cumple);
    }
}
