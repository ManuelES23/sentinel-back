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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * La liquidación de un convenio por calibre («Liquidar total» sobre todas sus recepciones) lleva un renglón por
 * (recepción, calibre) con el precio de ese calibre (el especial o, si no tiene, el general), UNA sola rezaga real
 * del convenio y el descuento repartido en proporción al subtotal de cada renglón (spec C10 y spec de la cuenta 5.4).
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

    private function url(string $ruta): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/{$ruta}";
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

    private function previa(Convenio $c): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/previa"), []);
    }

    private function liquidar(Convenio $c): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/liquidar"), ['registrar_pago' => false]);
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

        $data = $this->previa($c)->assertOk()->json('data');

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
        $this->assertEquals(3234, $data['monto_calculado']);
        $this->assertEquals(3234, $data['monto_final']);
    }

    public function test_liquidar_guarda_un_renglon_por_calibre(): void
    {
        [$c, $r1, $r2] = $this->escenario();

        $res = $this->liquidar($c)->assertStatus(201);
        $id = $res->json('data.liquidacion.id');
        $this->assertEqualsWithDelta(3234, $res->json('data.liquidacion.monto_final'), 0.001);
        $this->assertSame(
            [$this->jumbo->id, $this->grande->id, $this->grande->id],
            LiquidacionDetalle::where('liquidacion_id', $id)->orderBy('id')->pluck('calibre_id')->all(),
        );
        $this->assertSame($id, $r1->fresh()->liquidacion_id);
        $this->assertSame($id, $r2->fresh()->liquidacion_id);

        $ficha = $this->getJson($this->url((string) $id))->assertOk()->json('data');
        $this->assertSame([
            ['R1', 'Jumbo', 1800.0, 36.0, 1764.0],
            ['R1', 'Grande', 600.0, 12.0, 588.0],
            ['R2', 'Grande', 900.0, 18.0, 882.0],
        ], $this->renglones($ficha['detalles'], [$r1->id => 'R1', $r2->id => 'R2']));
        $this->assertSame($this->jumbo->id, $ficha['detalles'][0]['calibre_id']);
        $this->assertEqualsWithDelta(3234, $ficha['monto_final'], 0.001);
    }

    public function test_una_recepcion_por_calibre_sin_renglones_da_422_claro(): void
    {
        $this->sinRenglonesDa422('caja');
    }

    public function test_por_kilo_sin_renglones_dice_eso_y_no_sin_kilos(): void
    {
        $this->sinRenglonesDa422('kg', null);
    }

    private function sinRenglonesDa422(string $unidad, ?float $kilos = 50): void
    {
        [$c] = $this->escenario(['unidad' => $unidad]);
        // Cabecera sin renglones (no se puede capturar así; solo por datos viejos o a mano).
        $vacia = $this->crearRecepcion($c, ['fecha' => '2026-06-04', 'cantidad' => 5, 'kilos' => $kilos]);

        $this->previa($c)
            ->assertStatus(422)
            ->assertJsonPath('errors.convenio.0', "No se puede liquidar: {$vacia->folio} (Sin renglones por calibre).");
    }

    public function test_el_especial_no_se_mueve_si_cambia_el_general(): void
    {
        [$c] = $this->escenario();
        // Nueva vigencia del general (160) desde el 2 de junio: Grande sube, Jumbo sigue en 180.
        $tipo = ConvenioPrecio::where('convenio_id', $c->id)->value('tipo_carga_id');
        ConvenioPrecio::create([
            'convenio_id' => $c->id, 'tipo_carga_id' => $tipo, 'calibre_id' => null, 'precio' => 160,
            'vigencia_inicio' => '2026-06-02', 'es_principal' => true,
        ]);

        $data = $this->previa($c)->assertOk()->json('data');

        $this->assertEquals([180, 160, 160], array_column($data['detalles'], 'precio'));
    }

    public function test_un_calibre_sin_precio_vigente_da_422_con_folio_y_calibre(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        ConvenioPrecio::where('convenio_id', $c->id)->whereNull('calibre_id')->delete();

        $mensaje = $this->previa($c)->assertStatus(422)->assertJsonValidationErrors(['convenio'])->json('errors.convenio.0');

        $this->assertSame("No se puede liquidar: {$r1->folio} (Sin precio vigente: Grande), {$r2->folio} (Sin precio vigente: Grande).", $mensaje);
        $this->liquidar($c)->assertStatus(422);
        $this->assertSame(0, Liquidacion::count());
    }

    public function test_por_kilo_cada_renglon_se_paga_por_sus_kilos(): void
    {
        [$c] = $this->escenario(['unidad' => 'kg', 'porcentaje_rezaga' => 10]);

        $data = $this->previa($c)->assertOk()->json('data');

        // Rezaga real 7 % ≤ 10 % aceptada: sin descuento. 100 × 180 + 40 × 150 + 60 × 150.
        $this->assertEquals([18000, 6000, 9000], array_column($data['detalles'], 'subtotal'));
        $this->assertEquals(0, $data['descuento']);
        $this->assertEquals(33000, $data['monto_final']);
    }

    public function test_las_liquidaciones_clasicas_siguen_igual(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'cantidad' => 10]);

        $data = $this->previa($c)->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000, $data['monto_final'], 0.001);
        $previa = $data['detalles'][0];
        $this->assertArrayNotHasKey('calibre_id', $previa);
        $this->assertArrayNotHasKey('calibre', $previa);

        $res = $this->liquidar($c)->assertStatus(201);
        $this->assertEqualsWithDelta(1000, $res->json('data.liquidacion.monto_final'), 0.001);
        $id = $res->json('data.liquidacion.id');
        $this->assertNull(LiquidacionDetalle::where('liquidacion_id', $id)->value('calibre_id'));
        $this->getJson($this->url((string) $id))->assertOk()
            ->assertJsonPath('data.detalles.0.calibre_id', null)
            ->assertJsonPath('data.detalles.0.calibre', null);
    }
}
