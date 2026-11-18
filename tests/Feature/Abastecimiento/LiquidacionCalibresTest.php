<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Variedad;
use App\Models\CRM\CrmProductoCalibre;
use App\Models\User;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Plan 3 de calibres: la liquidación de un convenio por calibre lleva un renglón por (recepción, calibre)
 * con el precio de ese calibre (el especial o, si no tiene, el general), UNA sola rezaga real por
 * liquidación y el descuento repartido en proporción al subtotal de cada renglón (spec C10).
 *
 * Escenario de casi todas las pruebas: Jumbo con precio especial de 180 y Grande con el general de 150,
 * rezaga aceptada 5 %. R1 (2 jun): Jumbo 10 cajas / 100 kg / 10 kg de rezaga y Grande 4 / 40 / 4;
 * R2 (3 jun): Grande 6 / 60 / 0. Rezaga real = 14 ÷ 200 = 7 % → excedente 2 %.
 *   R1 Jumbo  10 × 180 = 1800, descuento 36, a pagar 1764
 *   R1 Grande  4 × 150 =  600, descuento 12, a pagar  588
 *   R2 Grande  6 × 150 =  900, descuento 18, a pagar  882
 *   Bruto 3300, descuento 66, monto 3234.
 */
class LiquidacionCalibresTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private User $admin;

    private CrmProductoCalibre $jumbo;

    private CrmProductoCalibre $grande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function url(string $ruta = 'liquidaciones'): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    /** @return array{0: Convenio, 1: Recepcion, 2: Recepcion} */
    private function escenario(array $convenioOver = []): array
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $this->jumbo = $this->crearCalibre($variedad, 'Jumbo', true, 1);
        $this->grande = $this->crearCalibre($variedad, 'Grande', true, 2);
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->jumbo, 'volumen' => 100, 'precio' => 180.0],
            ['calibre' => $this->grande, 'volumen' => 100, 'precio' => 150.0],
        ], array_merge(['variedad_id' => $variedad->id, 'porcentaje_rezaga' => 5], $convenioOver));
        // Grande se queda sin precio especial: usa el general (precio sin calibre) de 150.
        ConvenioPrecio::where('convenio_id', $convenio->id)->where('calibre_id', $this->grande->id)->update(['calibre_id' => null]);

        $r1 = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $this->jumbo->id, 'cantidad' => 10, 'kilos' => 100, 'rezaga_kg' => 10],
            ['calibre_id' => $this->grande->id, 'cantidad' => 4, 'kilos' => 40, 'rezaga_kg' => 4],
        ], ['fecha' => '2026-06-02']);
        $r2 = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $this->grande->id, 'cantidad' => 6, 'kilos' => 60, 'rezaga_kg' => 0],
        ], ['fecha' => '2026-06-03']);

        return [$convenio->fresh(), $r1, $r2];
    }

    private function cuerpo(Convenio $c, array $ids, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'recepcion_ids' => $ids,
        ];
    }

    /** Renglones en una forma comparable: [folio de recepción, calibre, subtotal, descuento, a pagar]. */
    private function renglones(array $detalles, array $folios): array
    {
        return array_map(fn (array $d) => [
            $folios[$d['recepcion_id']], $d['calibre']['nombre'] ?? null, (float) $d['subtotal'], (float) $d['descuento'], (float) $d['a_pagar'],
        ], $detalles);
    }

    public function test_previa_liquida_cada_calibre_con_su_precio_y_una_sola_rezaga(): void
    {
        [$c, $r1, $r2] = $this->escenario();

        $data = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$r2->id, $r1->id]))
            ->assertOk()->json('data');

        $this->assertSame([
            ['R1', 'Jumbo', 1800.0, 36.0, 1764.0],
            ['R1', 'Grande', 600.0, 12.0, 588.0],
            ['R2', 'Grande', 900.0, 18.0, 882.0],
        ], $this->renglones($data['detalles'], [$r1->id => 'R1', $r2->id => 'R2']));
        $this->assertEquals([180, 150, 150], array_column($data['detalles'], 'precio'));
        $this->assertSame([$this->jumbo->id, $this->grande->id, $this->grande->id], array_column($data['detalles'], 'calibre_id'));
        $this->assertSame($r1->folio, $data['detalles'][0]['folio']);
        $this->assertEquals(20, $data['cantidad']);
        $this->assertEquals(200, $data['kilos']);
        $this->assertEquals(3300, $data['bruto']);
        $this->assertEquals(7, $data['rezaga_real_pct']);
        $this->assertEquals(66, $data['descuento']);
        $this->assertEquals(3234, $data['monto_final']);
    }

    public function test_borrador_y_envio_guardan_un_renglon_por_calibre(): void
    {
        [$c, $r1, $r2] = $this->escenario();

        $id = $this->postJson($this->url(), $this->cuerpo($c, [$r1->id, $r2->id]))
            ->assertStatus(201)->assertJsonPath('data.status', 'borrador')->assertJsonPath('data.monto_final', 3234)
            ->json('data.id');
        $this->assertSame(3, LiquidacionDetalle::where('liquidacion_id', $id)->count());
        $this->assertSame($id, $r1->fresh()->liquidacion_id);

        // Enviar arma la selección con los renglones guardados: R1 tiene dos y no debe verse «repetida».
        User::factory()->create(['role' => 'admin']); // quien decide (el que envía no puede)
        $liq = DB::transaction(fn () => FlujoLiquidacion::enviar(Liquidacion::findOrFail($id), $this->admin));
        $this->assertSame('en_revision', $liq->status);
        $this->assertEquals(3234, $liq->monto_final);
        $this->assertSame(
            [$this->jumbo->id, $this->grande->id, $this->grande->id],
            LiquidacionDetalle::where('liquidacion_id', $id)->orderBy('id')->pluck('calibre_id')->all(),
        );

        $ficha = $this->getJson($this->url("liquidaciones/{$id}"))->assertOk()->json('data');
        $this->assertSame([
            ['R1', 'Jumbo', 1800.0, 36.0, 1764.0],
            ['R1', 'Grande', 600.0, 12.0, 588.0],
            ['R2', 'Grande', 900.0, 18.0, 882.0],
        ], $this->renglones($ficha['detalles'], [$r1->id => 'R1', $r2->id => 'R2']));
        $this->assertSame($this->jumbo->id, $ficha['detalles'][0]['calibre_id']);
    }

    public function test_el_especial_no_se_mueve_si_cambia_el_general(): void
    {
        [$c, $r1] = $this->escenario();
        // Nueva vigencia del general (160) desde el 2 de junio: Grande sube, Jumbo sigue en 180.
        $tipo = ConvenioPrecio::where('convenio_id', $c->id)->value('tipo_carga_id');
        ConvenioPrecio::create([
            'convenio_id' => $c->id, 'tipo_carga_id' => $tipo, 'calibre_id' => null, 'precio' => 160,
            'vigencia_inicio' => '2026-06-02', 'es_principal' => true,
        ]);

        $data = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$r1->id]))->assertOk()->json('data');

        $this->assertEquals([180, 160], array_column($data['detalles'], 'precio'));
    }

    public function test_un_calibre_sin_precio_vigente_da_422_con_folio_y_calibre(): void
    {
        [$c, $r1] = $this->escenario();
        ConvenioPrecio::where('convenio_id', $c->id)->whereNull('calibre_id')->delete();

        $mensaje = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$r1->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids'])
            ->json('errors.recepcion_ids.0');

        $this->assertSame("Las recepciones {$r1->folio} (Grande) no tienen un precio vigente a su fecha.", $mensaje);
        $this->assertSame(0, Liquidacion::count());
    }

    public function test_recepciones_disponibles_traen_renglones_con_su_precio(): void
    {
        [$c, $r1] = $this->escenario();
        $consulta = '?'.http_build_query(['convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15']);

        $fila = $this->getJson($this->url('liquidaciones/recepciones-disponibles').$consulta)
            ->assertOk()->assertJsonCount(2, 'data')->json('data.0');

        $this->assertSame($r1->id, $fila['id']);
        $this->assertNull($fila['precio']);
        $this->assertArrayNotHasKey('liquidable', $fila);
        $this->assertSame(['Jumbo', 'Grande'], array_column(array_column($fila['renglones'], 'calibre'), 'nombre'));
        $this->assertEquals([180, 150], array_column($fila['renglones'], 'precio'));
        $this->assertEquals([10, 4], array_column($fila['renglones'], 'cantidad'));
        $this->assertEquals([100, 40], array_column($fila['renglones'], 'kilos'));
    }

    public function test_por_kilo_cada_renglon_se_paga_por_sus_kilos(): void
    {
        [$c, $r1, $r2] = $this->escenario(['unidad' => 'kg', 'porcentaje_rezaga' => 10]);

        $data = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$r1->id, $r2->id]))
            ->assertOk()->json('data');

        // Rezaga real 7 % ≤ 10 % aceptada: sin descuento. 100 × 180 + 40 × 150 + 60 × 150.
        $this->assertEquals([18000, 6000, 9000], array_column($data['detalles'], 'subtotal'));
        $this->assertEquals(0, $data['descuento']);
        $this->assertEquals(33000, $data['monto_final']);
    }

    public function test_carga_inicial_con_recepciones_por_calibre(): void
    {
        [$c, $r1, $r2] = $this->escenario();

        $id = $this->postJson($this->url('liquidaciones/carga-inicial'), $this->cuerpo($c, [$r1->id, $r2->id]))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.monto_final', 3234)
            ->json('data.id');

        $this->assertSame(3, LiquidacionDetalle::where('liquidacion_id', $id)->whereNotNull('calibre_id')->count());
        $this->assertSame($id, $r2->fresh()->liquidacion_id);
    }

    public function test_carga_inicial_solo_monto_sigue_funcionando_en_convenio_por_calibre(): void
    {
        [$c] = $this->escenario();

        $this->postJson($this->url('liquidaciones/carga-inicial'), [
            'convenio_id' => $c->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-01',
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
        $this->assertArrayNotHasKey('renglones', $fila);

        $previa = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$a->id]))
            ->assertOk()->assertJsonPath('data.monto_final', 1000)->json('data.detalles.0');
        $this->assertArrayNotHasKey('calibre_id', $previa);
        $this->assertArrayNotHasKey('calibre', $previa);

        $id = $this->postJson($this->url(), $this->cuerpo($c, [$a->id]))
            ->assertStatus(201)->assertJsonPath('data.status', 'borrador')->assertJsonPath('data.monto_final', 1000)
            ->json('data.id');
        $this->assertNull(LiquidacionDetalle::where('liquidacion_id', $id)->value('calibre_id'));
        $this->getJson($this->url("liquidaciones/{$id}"))->assertOk()
            ->assertJsonPath('data.detalles.0.calibre_id', null)
            ->assertJsonPath('data.detalles.0.calibre', null);
    }
}
