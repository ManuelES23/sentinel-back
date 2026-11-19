<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** Ficha de la liquidación final (`GET liquidaciones/{id}`): sin cadena de aprobación y con periodos nulos. */
class LiquidacionFichaTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function url(int $id): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/{$id}";
    }

    public function test_la_ficha_no_trae_la_cadena_de_aprobacion_ni_periodos(): void
    {
        $liq = $this->crearLiquidacion($this->crearConvenio($this->sbp), ['monto_final' => 1000, 'saldo_inicial' => 50]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $data = $this->getJson($this->url($liq->id))->assertOk()->json('data');

        $this->assertSame('liquidada', $data['status']);
        $this->assertNull($data['periodo_inicio']);
        $this->assertNull($data['periodo_fin']);
        $this->assertNotNull($data['liquidada_at']);
        $this->assertEqualsWithDelta(50, $data['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(1000, $data['monto_final'], 0.001);
        $this->assertSame(['cancelar' => true], $data['puede']);
        foreach (['paso_actual', 'ronda', 'enviada_at', 'aprobada_at', 'rechazo_motivo', 'rechazada_at', 'aprobaciones', 'rondas'] as $clave) {
            $this->assertArrayNotHasKey($clave, $data, $clave);
        }
    }

    public function test_sin_liquidaciones_view_es_403_y_otra_empresa_es_404(): void
    {
        $liq = $this->crearLiquidacion($this->crearConvenio($this->sbp));
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url($liq->id))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        $res = $this->getJson($this->url($liq->id))->assertOk();
        // Con `view` pero sin `delete` no puede cancelar.
        $this->assertSame(['cancelar' => false], $res->json('data.puede'));

        $deOtra = $this->crearLiquidacion($this->crearConvenio($this->otra));
        $this->getJson($this->url($deOtra->id))->assertNotFound();
    }
}
