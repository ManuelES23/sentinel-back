<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Services\Abastecimiento\AplicadorAbonos;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class SaldoProductorTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    /** Liquidación aprobada creada directo (como la deja el flujo al aprobar). */
    private function aprobada(Convenio $convenio, float $monto, array $over = []): Liquidacion
    {
        return $this->crearLiquidacion($convenio, array_merge([
            'status' => 'aprobada', 'monto_calculado' => $monto, 'monto_final' => $monto, 'aprobada_at' => now(),
        ], $over));
    }

    private function aplicar(Abono $abono, array $aplicaciones): void
    {
        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicar($abono, $aplicaciones));
    }

    private function saldo(Productor|int $productor, ?int $temporadaId = null): array
    {
        $id = $productor instanceof Productor ? $productor->id : $productor;

        return SaldoProductor::porProductores($this->sbp, [$id], $temporadaId)->get($id, []);
    }

    private function productor(Convenio $convenio): Productor
    {
        return Productor::findOrFail($convenio->productor_id);
    }

    private function assertSinEscribir(Abono $abono, int $aplicacionesEsperadas = 0): void
    {
        $this->assertSame($aplicacionesEsperadas, AbonoAplicacion::where('abono_id', $abono->id)->count());
    }

    // ---------------------------------------------------------------- saldo

    public function test_saldo_con_liquidacion_aprobada_y_abono_aplicado(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 400]);
        $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 400]]);

        $mxn = $this->saldo($this->productor($convenio))['MXN'];

        $this->assertSame('MXN', $mxn['moneda']);
        $this->assertSame(1000.0, $mxn['liquidado']);
        $this->assertSame(400.0, $mxn['aplicado']);
        $this->assertSame(600.0, $mxn['por_pagar']);
        $this->assertSame(0.0, $mxn['anticipos_sin_aplicar']);
        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_la_liquidacion_pasa_a_pagada_cuando_lo_aplicado_la_cubre(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $abono1 = $this->crearAbono($this->productor($convenio), ['monto' => 400]);
        $abono2 = $this->crearAbono($this->productor($convenio), ['monto' => 600]);

        $this->aplicar($abono1, [['liquidacion_id' => $liq->id, 'monto' => 400]]);
        $this->assertSame('aprobada', $liq->fresh()->status);

        $this->aplicar($abono2, [['liquidacion_id' => $liq->id, 'monto' => 600]]);
        $this->assertSame('pagada', $liq->fresh()->status);

        $mxn = $this->saldo($this->productor($convenio))['MXN'];
        $this->assertSame(1000.0, $mxn['liquidado']);   // pagadas cuentan igual que aprobadas
        $this->assertSame(0.0, $mxn['por_pagar']);
    }

    public function test_los_borradores_y_las_canceladas_no_cuentan_como_liquidado(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->aprobada($convenio, 500);
        $this->crearLiquidacion($convenio, ['status' => 'borrador', 'monto_final' => 300]);
        $this->crearLiquidacion($convenio, ['status' => 'en_revision', 'monto_final' => 200]);
        $this->crearLiquidacion($convenio, ['status' => 'cancelada', 'monto_final' => 100]);

        $this->assertSame(500.0, $this->saldo($this->productor($convenio))['MXN']['liquidado']);
    }

    public function test_anticipo_sin_aplicar_aparece_como_saldo_a_favor(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $this->crearAbono($this->productor($convenio), ['monto' => 500]);                        // anticipo completo
        $parcial = $this->crearAbono($this->productor($convenio), ['monto' => 300]);
        $this->aplicar($parcial, [['liquidacion_id' => $liq->id, 'monto' => 100]]);              // queda 200
        $cancelado = $this->crearAbono($this->productor($convenio), ['monto' => 999, 'status' => 'cancelado']);

        $mxn = $this->saldo($this->productor($convenio))['MXN'];

        $this->assertSame(700.0, $mxn['anticipos_sin_aplicar']);
        $this->assertSame(100.0, $mxn['aplicado']);
        $this->assertSame(900.0, $mxn['por_pagar']);
        $this->assertNotNull($cancelado->id);
    }

    public function test_mxn_y_usd_nunca_se_mezclan(): void
    {
        $mxn = $this->crearConvenio($this->sbp);
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'productor_id' => $mxn->productor_id]);
        $this->aprobada($mxn, 1000);
        $this->aprobada($usd, 50);
        $this->crearAbono($this->productor($mxn), ['monto' => 70, 'moneda' => 'USD']);
        $this->crearRecepcion($mxn, ['cantidad' => 2]);
        $this->crearRecepcion($usd, ['cantidad' => 3]);

        $saldo = $this->saldo($this->productor($mxn));

        $this->assertEqualsCanonicalizing(['MXN', 'USD'], array_keys($saldo));
        $this->assertSame(1000.0, $saldo['MXN']['liquidado']);
        $this->assertSame(0.0, $saldo['MXN']['anticipos_sin_aplicar']);
        $this->assertSame(200.0, $saldo['MXN']['sin_liquidar']);
        $this->assertSame(50.0, $saldo['USD']['liquidado']);
        $this->assertSame(70.0, $saldo['USD']['anticipos_sin_aplicar']);
        $this->assertSame(300.0, $saldo['USD']['sin_liquidar']);
    }

    public function test_sin_liquidar_cuenta_las_recepciones_libres_y_no_las_de_una_liquidacion_vigente(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['precio' => 100]);
        $this->crearRecepcion($convenio, ['cantidad' => 10]);                                   // libre: 1000
        $liq = $this->aprobada($convenio, 500);
        $this->crearRecepcion($convenio, ['cantidad' => 5, 'liquidacion_id' => $liq->id]);      // liquidada
        $cancelada = $this->crearLiquidacion($convenio, ['status' => 'cancelada']);
        $this->crearRecepcion($convenio, ['cantidad' => 2, 'liquidacion_id' => $cancelada->id]); // apunta a cancelada: libre

        $this->assertSame(1200.0, $this->saldo($this->productor($convenio))['MXN']['sin_liquidar']);
    }

    public function test_con_temporada_solo_cuentan_los_convenios_de_esa_temporada_y_se_omiten_los_anticipos(): void
    {
        $a = $this->crearConvenio($this->sbp);
        $temporadaB = \App\Models\Abastecimiento\Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $a->cultivo_id, 'nombre' => 'Otra temporada',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-01-31', 'estado' => 'activa',
        ]);
        $b = $this->crearConvenio($this->sbp, ['temporada_id' => $temporadaB->id, 'productor_id' => $a->productor_id]);
        $liqA = $this->aprobada($a, 1000);
        $liqB = $this->aprobada($b, 700);
        $abono = $this->crearAbono($this->productor($a), ['monto' => 900]);
        $this->aplicar($abono, [['liquidacion_id' => $liqA->id, 'monto' => 300], ['liquidacion_id' => $liqB->id, 'monto' => 400]]);
        $this->crearAbono($this->productor($a), ['monto' => 50]);
        $this->crearRecepcion($a, ['cantidad' => 1]);
        $this->crearRecepcion($b, ['cantidad' => 4, 'fecha' => '2026-10-02']);

        $total = $this->saldo($this->productor($a))['MXN'];
        $this->assertSame(1700.0, $total['liquidado']);
        $this->assertSame(700.0, $total['aplicado']);
        $this->assertSame(1000.0, $total['por_pagar']);
        $this->assertSame(250.0, $total['anticipos_sin_aplicar']);   // (900 - 700) + 50
        $this->assertSame(500.0, $total['sin_liquidar']);            // 1 x 100 + 4 x 100

        $soloA = $this->saldo($this->productor($a), $a->temporada_id)['MXN'];
        $this->assertSame(1000.0, $soloA['liquidado']);
        $this->assertSame(300.0, $soloA['aplicado']);
        $this->assertSame(700.0, $soloA['por_pagar']);
        $this->assertSame(0.0, $soloA['anticipos_sin_aplicar']);
        $this->assertSame(100.0, $soloA['sin_liquidar']);
    }

    public function test_productores_sin_movimiento_regresan_arreglo_vacio_y_no_se_filtran_ajenos(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->aprobada($convenio, 100);
        $otro = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Sin movimiento', 'tipo' => 'productor', 'is_active' => true]);

        $res = SaldoProductor::porProductores($this->sbp, [$convenio->productor_id, $otro->id]);

        $this->assertSame([], $res->get($otro->id));
        $this->assertSame(100.0, $res->get($convenio->productor_id)['MXN']['liquidado']);
        // Otra empresa no ve nada de SBP.
        $this->assertSame([], SaldoProductor::porProductores($this->otra, [$convenio->productor_id])->all()[$convenio->productor_id] ?? []);
    }

    public function test_sin_consultas_por_productor(): void
    {
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $p = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => "Productor {$i}", 'tipo' => 'productor', 'is_active' => true]);
            $convenio = $this->crearConvenio($this->sbp, ['productor_id' => $p->id]);
            $liq = $this->aprobada($convenio, 100 * $i);
            $this->crearRecepcion($convenio, ['cantidad' => $i]);
            $abono = $this->crearAbono($p, ['monto' => 50]);
            $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 20]]);
            $ids[] = $p->id;
        }

        $consultas = function (array $productores): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            SaldoProductor::porProductores($this->sbp, $productores);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con2 = $consultas(array_slice($ids, 0, 2));
        $con20 = $consultas($ids);

        $this->assertSame($con2, $con20, 'El número de consultas creció con el número de productores.');
        $this->assertLessThanOrEqual(8, $con20);
        $todos = SaldoProductor::porProductores($this->sbp);
        $this->assertCount(20, $todos);
        $this->assertSame(2000.0, $todos->get($ids[19])['MXN']['liquidado']);
    }

    // ------------------------------------------------------------ anticipos

    public function test_anticipo_de_500_se_aplica_solo_al_aprobar_una_liquidacion_de_300(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $anticipo = $this->crearAbono($this->productor($convenio), ['monto' => 500]);
        $liq = $this->aprobada($convenio, 300);

        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liq));

        $this->assertSame('pagada', $liq->fresh()->status);
        $this->assertSame(300.0, (float) AbonoAplicacion::where('abono_id', $anticipo->id)->where('liquidacion_id', $liq->id)->sum('monto'));
        $mxn = $this->saldo($this->productor($convenio))['MXN'];
        $this->assertSame(200.0, $mxn['anticipos_sin_aplicar']);
        $this->assertSame(0.0, $mxn['por_pagar']);
    }

    public function test_los_anticipos_se_aplican_del_mas_antiguo_al_mas_nuevo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $nuevo = $this->crearAbono($this->productor($convenio), ['monto' => 400, 'fecha' => '2026-06-20']);
        $viejo = $this->crearAbono($this->productor($convenio), ['monto' => 400, 'fecha' => '2026-06-05']);
        $liq = $this->aprobada($convenio, 500);

        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liq));

        $this->assertSame(400.0, (float) AbonoAplicacion::where('abono_id', $viejo->id)->sum('monto'));
        $this->assertSame(100.0, (float) AbonoAplicacion::where('abono_id', $nuevo->id)->sum('monto'));
        $this->assertSame('pagada', $liq->fresh()->status);
    }

    public function test_anticipo_ligado_a_otro_convenio_no_se_aplica(): void
    {
        $a = $this->crearConvenio($this->sbp);
        $b = $this->crearConvenio($this->sbp, ['productor_id' => $a->productor_id]);
        $anticipoB = $this->crearAbono($this->productor($a), ['monto' => 500, 'convenio_id' => $b->id]);
        $liqA = $this->aprobada($a, 300);

        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liqA));

        $this->assertSame(0, AbonoAplicacion::count());
        $this->assertSame('aprobada', $liqA->fresh()->status);

        // Al revés sí: el anticipo ligado a B se aplica a una liquidación de B.
        $liqB = $this->aprobada($b, 300);
        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liqB));
        $this->assertSame(300.0, (float) AbonoAplicacion::where('abono_id', $anticipoB->id)->sum('monto'));
    }

    public function test_los_anticipos_de_otro_productor_o_moneda_no_se_aplican(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro', 'tipo' => 'productor', 'is_active' => true]);
        $this->crearAbono($otroProductor, ['monto' => 500]);
        $this->crearAbono($this->productor($convenio), ['monto' => 500, 'moneda' => 'USD']);
        $this->crearAbono($this->productor($convenio), ['monto' => 500, 'status' => 'cancelado']);
        $liq = $this->aprobada($convenio, 300);

        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liq));

        $this->assertSame(0, AbonoAplicacion::count());
    }

    public function test_aplicar_anticipos_no_hace_nada_si_la_liquidacion_no_esta_aprobada(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearAbono($this->productor($convenio), ['monto' => 500]);
        $liq = $this->crearLiquidacion($convenio, ['status' => 'en_revision', 'monto_final' => 300]);

        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicarAnticiposA($liq));

        $this->assertSame(0, AbonoAplicacion::count());
    }

    // -------------------------------------------------- validación de aplicar

    public function test_abono_con_convenio_solo_aplica_a_liquidaciones_de_ese_convenio(): void
    {
        $a = $this->crearConvenio($this->sbp);
        $b = $this->crearConvenio($this->sbp, ['productor_id' => $a->productor_id]);
        $liqB = $this->aprobada($b, 300);
        $abono = $this->crearAbono($this->productor($a), ['monto' => 300, 'convenio_id' => $a->id]);

        $this->expectException(ValidationException::class);
        try {
            $this->aplicar($abono, [['liquidacion_id' => $liqB->id, 'monto' => 300]]);
        } finally {
            $this->assertSinEscribir($abono);
        }
    }

    public function test_aplicaciones_que_suman_mas_que_el_abono_dan_422_sin_escribir(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $l1 = $this->aprobada($convenio, 500);
        $l2 = $this->aprobada($convenio, 500);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 400]);

        try {
            $this->aplicar($abono, [['liquidacion_id' => $l1->id, 'monto' => 300], ['liquidacion_id' => $l2->id, 'monto' => 200]]);
            $this->fail('Debió fallar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('aplicaciones', $e->errors());
        }
        $this->assertSinEscribir($abono);
        $this->assertSame('aprobada', $l1->fresh()->status);
    }

    public function test_aplicacion_mayor_al_saldo_de_la_liquidacion_da_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 300);
        $previo = $this->crearAbono($this->productor($convenio), ['monto' => 100]);
        $this->aplicar($previo, [['liquidacion_id' => $liq->id, 'monto' => 100]]);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        try {
            $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 250]]);   // saldo = 200
            $this->fail('Debió fallar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('aplicaciones', $e->errors());
        }
        $this->assertSinEscribir($abono);
    }

    public function test_liquidacion_de_otra_moneda_otro_productor_o_no_aprobada_da_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'productor_id' => $convenio->productor_id]);
        $otroProd = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro', 'tipo' => 'productor', 'is_active' => true]);
        $deOtro = $this->crearConvenio($this->sbp, ['productor_id' => $otroProd->id]);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        $casos = [
            'moneda' => [$this->aprobada($usd, 300), 'moneda'],
            'productor' => [$this->aprobada($deOtro, 300), 'liquidacion_id'],
            'borrador' => [$this->crearLiquidacion($convenio, ['status' => 'borrador', 'monto_final' => 300]), 'liquidacion_id'],
            'pagada' => [$this->aprobada($convenio, 300, ['status' => 'pagada']), 'liquidacion_id'],
            'cancelada' => [$this->crearLiquidacion($convenio, ['status' => 'cancelada', 'monto_final' => 300]), 'liquidacion_id'],
        ];

        foreach ($casos as $nombre => [$liq, $campo]) {
            try {
                $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 100]]);
                $this->fail("Caso {$nombre}: debió fallar.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($campo, $e->errors(), "Caso {$nombre}");
            }
            $this->assertSinEscribir($abono);
        }
    }

    public function test_liquidacion_repetida_inexistente_o_monto_invalido_da_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 500);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        $casos = [
            'repetida' => [['liquidacion_id' => $liq->id, 'monto' => 100], ['liquidacion_id' => $liq->id, 'monto' => 100]],
            'inexistente' => [['liquidacion_id' => 999999, 'monto' => 100]],
            'monto cero' => [['liquidacion_id' => $liq->id, 'monto' => 0]],
            'monto negativo' => [['liquidacion_id' => $liq->id, 'monto' => -5]],
            'sin monto' => [['liquidacion_id' => $liq->id]],
        ];
        foreach ($casos as $nombre => $aplicaciones) {
            try {
                $this->aplicar($abono, $aplicaciones);
                $this->fail("Caso {$nombre}: debió fallar.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
            $this->assertSinEscribir($abono);
        }
    }

    public function test_liquidacion_de_otra_empresa_da_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $ajena = $this->crearConvenio($this->otra);
        $liqAjena = $this->aprobada($ajena, 300);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        $this->expectException(ValidationException::class);
        $this->aplicar($abono, [['liquidacion_id' => $liqAjena->id, 'monto' => 100]]);
    }

    public function test_aplicaciones_vacias_dejan_el_anticipo_completo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        $this->aplicar($abono, []);

        $this->assertSinEscribir($abono);
        $this->assertSame(500.0, $this->saldo($this->productor($convenio))['MXN']['anticipos_sin_aplicar']);
    }

    public function test_un_abono_cancelado_no_se_puede_aplicar(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 300);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500, 'status' => 'cancelado']);

        $this->expectException(ValidationException::class);
        $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 100]]);
    }

    // -------------------------------------------------------------- cancelar

    public function test_cancelar_el_abono_regresa_la_liquidacion_a_aprobada_y_guarda_las_aplicaciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 1000]);
        $this->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => 1000]]);
        $this->assertSame('pagada', $liq->fresh()->status);

        DB::transaction(fn () => app(AplicadorAbonos::class)->cancelar($abono, $this->captor, 'Pago devuelto por el banco'));

        $abono = $abono->fresh();
        $this->assertSame('cancelado', $abono->status);
        $this->assertSame('Pago devuelto por el banco', $abono->cancelado_motivo);
        $this->assertSame($this->captor->id, $abono->cancelado_by);
        $this->assertNotNull($abono->cancelado_at);
        $this->assertCount(1, $abono->aplicaciones_canceladas);
        $this->assertSame($liq->id, $abono->aplicaciones_canceladas[0]['liquidacion_id']);
        $this->assertEquals(1000, $abono->aplicaciones_canceladas[0]['monto']);
        $this->assertSame(0, AbonoAplicacion::where('abono_id', $abono->id)->count());
        $this->assertSame('aprobada', $liq->fresh()->status);

        $mxn = $this->saldo($this->productor($convenio))['MXN'];
        $this->assertSame(1000.0, $mxn['por_pagar']);
        $this->assertSame(0.0, $mxn['anticipos_sin_aplicar']);   // el cancelado no es anticipo
    }

    public function test_cancelar_vuelve_a_ofrecer_los_anticipos_a_la_liquidacion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 400);
        $pago = $this->crearAbono($this->productor($convenio), ['monto' => 400, 'fecha' => '2026-06-01']);
        $this->aplicar($pago, [['liquidacion_id' => $liq->id, 'monto' => 400]]);
        $anticipo = $this->crearAbono($this->productor($convenio), ['monto' => 300, 'fecha' => '2026-06-02']);
        $this->assertSame('pagada', $liq->fresh()->status);

        DB::transaction(function () use ($pago, $liq) {
            $aplicador = app(AplicadorAbonos::class);
            $aplicador->cancelar($pago, $this->captor, 'Cheque rebotado');
            $aplicador->aplicarAnticiposA($liq->fresh());
        });

        $this->assertSame(300.0, (float) AbonoAplicacion::where('abono_id', $anticipo->id)->sum('monto'));
        $this->assertSame('aprobada', $liq->fresh()->status);   // 300 de 400
        $this->assertSame(100.0, $this->saldo($this->productor($convenio))['MXN']['por_pagar']);
    }

    public function test_cancelar_requiere_motivo_y_no_se_puede_cancelar_dos_veces(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);

        try {
            DB::transaction(fn () => app(AplicadorAbonos::class)->cancelar($abono, $this->captor, '   '));
            $this->fail('Debió exigir motivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo', $e->errors());
        }
        $this->assertSame('aplicado', $abono->fresh()->status);

        DB::transaction(fn () => app(AplicadorAbonos::class)->cancelar($abono, $this->captor, 'Error de captura'));
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(AplicadorAbonos::class)->cancelar($abono, $this->captor, 'Otra vez'));
    }

    public function test_cancelar_un_abono_que_cubria_varias_liquidaciones_las_regresa_todas(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $l1 = $this->aprobada($convenio, 300);
        $l2 = $this->aprobada($convenio, 200);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 500]);
        $this->aplicar($abono, [['liquidacion_id' => $l1->id, 'monto' => 300], ['liquidacion_id' => $l2->id, 'monto' => 200]]);
        $this->assertSame(['pagada', 'pagada'], [$l1->fresh()->status, $l2->fresh()->status]);

        DB::transaction(fn () => app(AplicadorAbonos::class)->cancelar($abono, $this->captor, 'Duplicado'));

        $this->assertSame(['aprobada', 'aprobada'], [$l1->fresh()->status, $l2->fresh()->status]);
        $this->assertCount(2, $abono->fresh()->aplicaciones_canceladas);
    }

    // ------------------------------------------------------- refrescarPagada

    public function test_refrescar_pagada_ajusta_el_estado_segun_lo_aplicado(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 300);
        $abono = $this->crearAbono($this->productor($convenio), ['monto' => 300]);
        AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => 300]);

        DB::transaction(fn () => app(AplicadorAbonos::class)->refrescarPagada($liq));
        $this->assertSame('pagada', $liq->fresh()->status);
        $this->assertSame('pagada', $liq->status);   // también el modelo recibido

        AbonoAplicacion::where('abono_id', $abono->id)->delete();
        DB::transaction(fn () => app(AplicadorAbonos::class)->refrescarPagada($liq));
        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_refrescar_pagada_no_toca_liquidaciones_que_no_estan_aprobadas_ni_pagadas(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['status' => 'en_revision', 'monto_final' => 300]);

        DB::transaction(fn () => app(AplicadorAbonos::class)->refrescarPagada($liq));

        $this->assertSame('en_revision', $liq->fresh()->status);
    }
}
