<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\ProduccionEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Cuarto frío sin báscula: en una temporada que opera en bins el pallet entra
 * sin peso de báscula y el ingreso masivo está permitido; en cualquier otra
 * temporada todo sigue igual que hoy.
 */
class CuartoFrioBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/empaque/produccion';

    private const MENSAJE_MASIVO = 'Para ingresar a cuarto frío debes capturar peso báscula por pallet. Usa el ingreso individual.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function palletMango(): ProduccionEmpaque
    {
        $produccion = $this->crearRecepcionConProduccion($this->productorPrincipal, 'CF-MANGO')['produccion'];
        $produccion->update(['en_cuarto_frio' => false, 'peso_bascula_kg' => null]);

        return $produccion->fresh();
    }

    public function test_toggle_en_bins_sin_peso(): void
    {
        $pallet = $this->crearProduccionBins();

        $this->postJson(self::BASE."/{$pallet->id}/toggle-cuarto-frio")
            ->assertOk()
            ->assertJsonPath('success', true);

        $pallet->refresh();
        $this->assertTrue((bool) $pallet->en_cuarto_frio);
        $this->assertNull($pallet->peso_bascula_kg);
    }

    public function test_toggle_en_bins_con_peso_lo_guarda(): void
    {
        $pallet = $this->crearProduccionBins();

        $this->postJson(self::BASE."/{$pallet->id}/toggle-cuarto-frio", ['peso_bascula_kg' => 480.5])
            ->assertOk();

        $pallet->refresh();
        $this->assertTrue((bool) $pallet->en_cuarto_frio);
        $this->assertEquals(480.5, (float) $pallet->peso_bascula_kg);
    }

    public function test_toggle_en_bins_con_peso_invalido_sigue_validando(): void
    {
        $pallet = $this->crearProduccionBins();

        $this->postJson(self::BASE."/{$pallet->id}/toggle-cuarto-frio", ['peso_bascula_kg' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('peso_bascula_kg');

        $this->assertFalse((bool) $pallet->fresh()->en_cuarto_frio);
    }

    public function test_toggle_sin_interruptor_exige_peso(): void
    {
        $pallet = $this->palletMango();

        $this->postJson(self::BASE."/{$pallet->id}/toggle-cuarto-frio")
            ->assertStatus(422)
            ->assertJsonValidationErrors('peso_bascula_kg');

        $this->assertFalse((bool) $pallet->fresh()->en_cuarto_frio);
    }

    public function test_retirar_en_bins_no_toca_el_peso(): void
    {
        $pallet = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_bascula_kg' => 480.5]);

        $this->postJson(self::BASE."/{$pallet->id}/toggle-cuarto-frio")->assertOk();

        $pallet->refresh();
        $this->assertFalse((bool) $pallet->en_cuarto_frio);
        $this->assertEquals(480.5, (float) $pallet->peso_bascula_kg);
    }

    public function test_masivo_en_bins_ingresa(): void
    {
        $a = $this->crearProduccionBins();
        $b = $this->crearProduccionBins();

        $this->postJson(self::BASE.'/toggle-cuarto-frio-masivo', [
            'ids' => [$a->id, $b->id],
            'en_cuarto_frio' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue((bool) $a->fresh()->en_cuarto_frio);
        $this->assertTrue((bool) $b->fresh()->en_cuarto_frio);
    }

    public function test_masivo_mezclado_rechaza(): void
    {
        $bins = $this->crearProduccionBins();
        $mango = $this->palletMango();

        $this->postJson(self::BASE.'/toggle-cuarto-frio-masivo', [
            'ids' => [$bins->id, $mango->id],
            'en_cuarto_frio' => true,
        ])->assertStatus(422)->assertExactJson([
            'status' => 'error',
            'message' => self::MENSAJE_MASIVO,
        ]);

        $this->assertFalse((bool) $bins->fresh()->en_cuarto_frio);
        $this->assertFalse((bool) $mango->fresh()->en_cuarto_frio);
    }

    public function test_masivo_solo_mango_rechaza_como_hoy(): void
    {
        $mango = $this->palletMango();

        $this->postJson(self::BASE.'/toggle-cuarto-frio-masivo', [
            'ids' => [$mango->id],
            'en_cuarto_frio' => true,
        ])->assertStatus(422)->assertExactJson([
            'status' => 'error',
            'message' => self::MENSAJE_MASIVO,
        ]);

        $this->assertFalse((bool) $mango->fresh()->en_cuarto_frio);
    }

    public function test_masivo_en_bins_con_retiro_sigue_funcionando(): void
    {
        $a = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_bascula_kg' => 300]);
        $b = $this->crearProduccionBins(['en_cuarto_frio' => true]);

        $this->postJson(self::BASE.'/toggle-cuarto-frio-masivo', [
            'ids' => [$a->id, $b->id],
            'en_cuarto_frio' => false,
        ])->assertOk();

        $this->assertFalse((bool) $a->fresh()->en_cuarto_frio);
        $this->assertFalse((bool) $b->fresh()->en_cuarto_frio);
        $this->assertEquals(300, (float) $a->fresh()->peso_bascula_kg);
    }
}
