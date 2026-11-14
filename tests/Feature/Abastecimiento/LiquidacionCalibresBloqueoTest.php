<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Plan 2 de calibres, Task B7: bloqueo temporal. Hasta que el plan 3 calcule la liquidación por
 * (recepción, calibre), las recepciones de un convenio por calibre no se pueden liquidar (falla cerrado);
 * los convenios clásicos y la carga inicial de «solo monto» siguen como siempre.
 */
class LiquidacionCalibresBloqueoTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private const MENSAJE = 'Las recepciones de un convenio por calibre se liquidan en la siguiente entrega.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function url(string $ruta = 'liquidaciones'): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    /** @return array{0: Convenio, 1: Recepcion} convenio por calibre con una recepción de dos renglones (2 de junio). */
    private function convenioPorCalibreConRecepcion(): array
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $a = $this->crearCalibre($variedad, 'A');
        $b = $this->crearCalibre($variedad, 'B');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 100, 'precio' => 20.0],
            ['calibre' => $b, 'volumen' => 100, 'precio' => 35.5],
        ], ['variedad_id' => $variedad->id]);
        $recepcion = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $a->id, 'cantidad' => 10],
            ['calibre_id' => $b->id, 'cantidad' => 4],
        ], ['fecha' => '2026-06-02']);

        return [$convenio, $recepcion];
    }

    private function cuerpo(Convenio $c, array $ids, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'recepcion_ids' => $ids,
        ];
    }

    public function test_no_se_liquidan_recepciones_de_un_convenio_por_calibre(): void
    {
        [$c, $r] = $this->convenioPorCalibreConRecepcion();

        $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$r->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids'])
            ->assertJsonPath('errors.recepcion_ids.0', self::MENSAJE);

        $this->postJson($this->url(), $this->cuerpo($c, [$r->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids'])
            ->assertJsonPath('errors.recepcion_ids.0', self::MENSAJE);

        $this->assertSame(0, Liquidacion::count());
        $this->assertNull($r->fresh()->liquidacion_id);
    }

    public function test_enviar_un_borrador_con_recepciones_por_calibre_falla_cerrado(): void
    {
        [$c, $r] = $this->convenioPorCalibreConRecepcion();
        $creador = User::factory()->create(['role' => 'user']);
        $liq = $this->crearLiquidacion($c, ['created_by' => $creador->id]);
        $r->update(['liquidacion_id' => $liq->id]);
        LiquidacionDetalle::create([
            'liquidacion_id' => $liq->id, 'recepcion_id' => $r->id, 'cantidad' => 14, 'kilos' => 0, 'rezaga_kg' => 0,
            'precio' => 20, 'porcentaje_productor' => 100, 'subtotal' => 280, 'descuento' => 0, 'a_pagar' => 280,
        ]);

        try {
            DB::transaction(fn () => FlujoLiquidacion::enviar($liq->fresh(), $creador));
            $this->fail('Debió rechazar el envío.');
        } catch (ValidationException $e) {
            $this->assertSame(self::MENSAJE, $e->errors()['recepcion_ids'][0]);
        }

        $this->assertSame('borrador', $liq->fresh()->status);
        $this->assertSame(0, (int) $liq->fresh()->ronda);
    }

    public function test_recepciones_disponibles_marca_no_liquidable(): void
    {
        [$c, $r] = $this->convenioPorCalibreConRecepcion();
        $consulta = '?'.http_build_query(['convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15']);

        $fila = $this->getJson($this->url('liquidaciones/recepciones-disponibles').$consulta)
            ->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame($r->id, $fila['id']);
        $this->assertNull($fila['precio']);
        $this->assertArrayHasKey('liquidable', $fila);
        $this->assertFalse($fila['liquidable']);
    }

    public function test_carga_inicial_con_recepciones_por_calibre_se_rechaza(): void
    {
        [$c, $r] = $this->convenioPorCalibreConRecepcion();

        $this->postJson($this->url('liquidaciones/carga-inicial'), $this->cuerpo($c, [$r->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids'])
            ->assertJsonPath('errors.recepcion_ids.0', self::MENSAJE);

        $this->assertSame(0, Liquidacion::count());
        $this->assertNull($r->fresh()->liquidacion_id);
    }

    public function test_carga_inicial_solo_monto_sigue_funcionando_en_convenio_por_calibre(): void
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $a = $this->crearCalibre($variedad, 'A');
        $c = $this->crearConvenioPorCalibre($this->sbp, [['calibre' => $a, 'volumen' => 100, 'precio' => 20.0]], ['variedad_id' => $variedad->id]);

        $this->postJson($this->url('liquidaciones/carga-inicial'), [
            'convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-28',
            'monto' => 5000, 'motivo' => 'Saldo pendiente de la temporada pasada',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.monto_final', 5000);
    }

    public function test_las_liquidaciones_clasicas_siguen_igual(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $a = $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'cantidad' => 10]);

        $consulta = '?'.http_build_query(['convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15']);
        $fila = $this->getJson($this->url('liquidaciones/recepciones-disponibles').$consulta)->assertOk()->json('data.0');
        $this->assertEquals(100, $fila['precio']);
        $this->assertArrayNotHasKey('liquidable', $fila);

        $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$a->id]))
            ->assertOk()->assertJsonPath('data.monto_final', 1000);
        $this->postJson($this->url(), $this->cuerpo($c, [$a->id]))
            ->assertStatus(201)->assertJsonPath('data.status', 'borrador')->assertJsonPath('data.monto_final', 1000);
        $this->assertNotNull($a->fresh()->liquidacion_id);
    }
}
