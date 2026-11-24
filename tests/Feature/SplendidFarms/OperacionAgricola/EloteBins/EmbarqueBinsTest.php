<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\EmbarqueEmpaque;
use App\Models\ProduccionEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Embarques en una temporada que opera en bins: los pallets entran a cuarto
 * frío sin peso de báscula, así que el peso de báscula del embarque debe
 * quedar nulo (no 0) para que los tableros caigan al peso estimado. Una
 * temporada sin el interruptor sigue sumando la báscula como hoy.
 */
class EmbarqueBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const EMBARQUES = '/api/splendidfarms/operacion-agricola/empaque/embarques';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function embarcar(int $temporadaId, array $pallets)
    {
        return $this->postJson(self::EMBARQUES, [
            'temporada_id' => $temporadaId,
            'entity_id' => $this->entity->id,
            'tipo_venta' => 'nacional',
            'fecha_embarque' => '2026-02-10',
            'pallets' => array_map(
                fn (ProduccionEmpaque $p, int $i) => ['id' => $p->id, 'posicion_carga' => $i + 1],
                $pallets,
                array_keys($pallets),
            ),
        ]);
    }

    private function palletMango(?float $pesoBascula): ProduccionEmpaque
    {
        $produccion = $this->crearRecepcionConProduccion($this->productorPrincipal, 'EMB-MANGO-'.uniqid())['produccion'];
        $produccion->update([
            'status' => 'en_almacen',
            'en_cuarto_frio' => true,
            'total_cajas' => 40,
            'peso_neto_kg' => 400,
            'peso_bascula_kg' => $pesoBascula,
        ]);

        return $produccion->fresh();
    }

    public function test_embarque_en_bins_sin_bascula_guarda_peso_bascula_nulo_y_peso_estimado(): void
    {
        $a = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_neto_kg' => 500]);
        $b = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_neto_kg' => 300]);

        $respuesta = $this->embarcar($this->temporadaBins->id, [$a, $b])->assertCreated();

        $embarque = EmbarqueEmpaque::findOrFail($respuesta->json('data.id'));
        $this->assertNull($embarque->peso_bascula_total_kg);
        $this->assertSame(800.0, (float) $embarque->peso_total_kg);
        $respuesta->assertJsonPath('data.peso_bascula_total_kg', null);

        // El tablero cae al peso estimado (COALESCE) en lugar de contar 0 kg.
        $kg = EmbarqueEmpaque::query()
            ->selectRaw('COALESCE(SUM(COALESCE(peso_bascula_total_kg,peso_total_kg)),0) as kg')
            ->value('kg');
        $this->assertSame(800.0, (float) $kg);
    }

    public function test_embarque_en_bins_con_algun_peso_de_bascula_conserva_la_suma(): void
    {
        $a = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_neto_kg' => 500, 'peso_bascula_kg' => 510.5]);
        $b = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_neto_kg' => 300]);

        $respuesta = $this->embarcar($this->temporadaBins->id, [$a, $b])->assertCreated();

        $embarque = EmbarqueEmpaque::findOrFail($respuesta->json('data.id'));
        $this->assertSame(510.5, (float) $embarque->peso_bascula_total_kg);
        $this->assertSame(800.0, (float) $embarque->peso_total_kg);
    }

    public function test_embarque_sin_interruptor_conserva_la_suma_de_bascula(): void
    {
        $a = $this->palletMango(405.25);
        $b = $this->palletMango(398.75);

        $respuesta = $this->embarcar($this->temporada->id, [$a, $b])->assertCreated();

        $embarque = EmbarqueEmpaque::findOrFail($respuesta->json('data.id'));
        $this->assertSame(804.0, (float) $embarque->peso_bascula_total_kg);
        $this->assertSame(800.0, (float) $embarque->peso_total_kg);
    }

    public function test_embarque_sin_interruptor_sin_bascula_sigue_guardando_cero_como_hoy(): void
    {
        $a = $this->palletMango(null);

        $respuesta = $this->embarcar($this->temporada->id, [$a])->assertCreated();

        $embarque = EmbarqueEmpaque::findOrFail($respuesta->json('data.id'));
        $this->assertNotNull($embarque->peso_bascula_total_kg);
        $this->assertSame(0.0, (float) $embarque->peso_bascula_total_kg);
    }
}
