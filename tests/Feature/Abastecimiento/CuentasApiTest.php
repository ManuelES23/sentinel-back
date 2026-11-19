<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** Lectura de las cuentas por convenio: `GET liquidaciones/cuentas` y `GET liquidaciones/cuentas/{convenio}`. */
class CuentasApiTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas'.$sufijo;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function productor(string $nombre): Productor
    {
        return Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'tipo' => 'productor', 'is_active' => true]);
    }

    /** Convenio clásico de $productor con una recepción de 10 cajas a 100 (valor 1,000). */
    private function convenioConRecepcion(Productor $productor, array $over = []): Convenio
    {
        $convenio = $this->crearConvenio($this->sbp, array_merge(['productor_id' => $productor->id], $over));
        $this->crearRecepcion($convenio);

        return $convenio;
    }

    /** @return list<int> ids de convenio de la lista, en el orden recibido */
    private function idsDeLaLista(string $consulta): array
    {
        return array_column(array_column(
            $this->getJson($this->url('?'.$consulta))->assertOk()->json('data'),
            'convenio',
        ), 'id');
    }

    public function test_lista_las_cuentas_de_la_temporada_con_sus_totales(): void
    {
        $deBeta = $this->convenioConRecepcion($this->productor('Beta'));
        $deAlfa = $this->convenioConRecepcion($this->productor('Alfa'));
        $this->crearRecepcion($deAlfa);
        $this->crearPago($deAlfa, ['monto' => 500]);
        // Solo un pago cancelado: también cuenta como convenio con movimiento.
        $soloPago = $this->crearConvenio($this->sbp, ['productor_id' => $this->productor('Gamma')->id]);
        $this->crearPago($soloPago, ['status' => 'cancelado', 'monto' => 50]);
        // Sin movimiento y en borrador: no salen.
        $this->crearConvenio($this->sbp, ['productor_id' => $this->productor('Delta')->id]);
        $this->convenioConRecepcion($this->productor('Epsilon'), ['status' => 'borrador']);
        Sanctum::actingAs($this->admin());

        $res = $this->getJson($this->url('?temporada_id='.$deAlfa->temporada_id))->assertOk();

        $data = $res->json('data');
        $this->assertSame(['Alfa', 'Beta', 'Gamma'], array_column(array_column($data, 'productor'), 'nombre'));
        $fila = $data[0];
        $this->assertSame([
            'convenio', 'productor', 'estado', 'valor_recibido', 'pagado', 'cargos_iniciales', 'por_pagar', 'sin_valor', 'recepciones', 'liquidacion',
        ], array_keys($fila));
        $this->assertSame([
            'id', 'folio', 'status', 'modalidad', 'unidad', 'moneda', 'usa_calibres', 'porcentaje_rezaga', 'variedad',
        ], array_keys($fila['convenio']));
        $this->assertSame($deAlfa->id, $fila['convenio']['id']);
        $this->assertSame('activo', $fila['convenio']['status']);
        $this->assertSame('MXN', $fila['convenio']['moneda']);
        $this->assertFalse($fila['convenio']['usa_calibres']);
        $this->assertSame(['id', 'nombre'], array_keys($fila['convenio']['variedad']));
        $this->assertSame(['id', 'nombre', 'tipo'], array_keys($fila['productor']));
        $this->assertSame('abierta', $fila['estado']);
        $this->assertEqualsWithDelta(2000, $fila['valor_recibido'], 0.001);
        $this->assertEqualsWithDelta(500, $fila['pagado'], 0.001);
        $this->assertEqualsWithDelta(0, $fila['cargos_iniciales'], 0.001);
        $this->assertEqualsWithDelta(1500, $fila['por_pagar'], 0.001);
        $this->assertSame(0, $fila['sin_valor']);
        $this->assertSame(2, $fila['recepciones']);
        $this->assertNull($fila['liquidacion']);
        $this->assertSame($deBeta->id, $data[1]['convenio']['id']);
        $this->assertEqualsWithDelta(1000, $data[1]['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(0, $data[2]['por_pagar'], 0.001);
    }

    public function test_ordena_por_productor_y_luego_por_folio(): void
    {
        $alfa = $this->productor('Alfa');
        $primero = $this->convenioConRecepcion($alfa);
        $segundo = $this->convenioConRecepcion($alfa);
        Sanctum::actingAs($this->admin());

        $this->assertSame([$primero->id, $segundo->id], $this->idsDeLaLista('temporada_id='.$primero->temporada_id));
        $this->assertLessThan($segundo->folio, $primero->folio);
    }

    public function test_sin_liquidadas_oculta_las_liquidadas_saldadas_pero_no_las_que_deben(): void
    {
        $saldada = $this->convenioConRecepcion($this->productor('Saldada'));
        $this->crearLiquidacion($saldada, ['monto_final' => 1000]);
        $this->crearPago($saldada, ['tipo' => 'liquidacion', 'monto' => 1000]);
        $debe = $this->convenioConRecepcion($this->productor('Debe'));
        $liq = $this->crearLiquidacion($debe, ['monto_final' => 1000]);
        $this->crearPago($debe, ['tipo' => 'liquidacion', 'monto' => 400]);
        $abierta = $this->convenioConRecepcion($this->productor('Abierta'));
        Sanctum::actingAs($this->admin());
        $t = $abierta->temporada_id;

        $this->assertSame([$abierta->id, $debe->id], $this->idsDeLaLista("temporada_id={$t}"));
        $this->assertSame([$abierta->id, $debe->id, $saldada->id], $this->idsDeLaLista("temporada_id={$t}&liquidadas=1"));

        $filaDebe = collect($this->getJson($this->url("?temporada_id={$t}"))->json('data'))->firstWhere('convenio.id', $debe->id);
        $this->assertSame('liquidada', $filaDebe['estado']);
        $this->assertEqualsWithDelta(600, $filaDebe['por_pagar'], 0.001);
        $this->assertSame(['id' => $liq->id, 'folio' => $liq->folio], $filaDebe['liquidacion']);
    }

    public function test_con_saldo_filtra_por_pagar_mayor_a_cero(): void
    {
        $pagada = $this->convenioConRecepcion($this->productor('Pagada'));
        $this->crearPago($pagada, ['monto' => 1000]);
        $debe = $this->convenioConRecepcion($this->productor('Debe'));
        $this->crearPago($debe, ['monto' => 100]);
        Sanctum::actingAs($this->admin());
        $t = $debe->temporada_id;

        $this->assertSame([$debe->id], $this->idsDeLaLista("temporada_id={$t}&con_saldo=1"));
        $this->assertSame([$debe->id, $pagada->id], $this->idsDeLaLista("temporada_id={$t}"));
    }

    public function test_busca_por_folio_y_productor(): void
    {
        $alfa = $this->convenioConRecepcion($this->productor('Alfa 100%'));
        $beta = $this->convenioConRecepcion($this->productor('Beta'));
        Sanctum::actingAs($this->admin());
        $t = $alfa->temporada_id;
        $buscar = fn (string $q) => $this->idsDeLaLista("temporada_id={$t}&search=".urlencode($q));

        $this->assertSame([$alfa->id], $buscar('alfa'));
        $this->assertSame([$beta->id], $buscar($beta->folio));
        $this->assertSame([$alfa->id], $buscar('100%'));
        // `%` y `_` son literales, no comodines.
        $this->assertSame([], $buscar('%%'));
        $this->assertSame([], $buscar('_eta'));
    }

    public function test_filtra_por_productor(): void
    {
        $alfa = $this->productor('Alfa');
        $deAlfa = $this->convenioConRecepcion($alfa);
        $this->convenioConRecepcion($this->productor('Beta'));
        Sanctum::actingAs($this->admin());

        $this->assertSame([$deAlfa->id], $this->idsDeLaLista("temporada_id={$deAlfa->temporada_id}&productor_id={$alfa->id}"));
    }

    public function test_temporada_es_obligatoria(): void
    {
        Sanctum::actingAs($this->admin());
        $deOtra = $this->crearConvenio($this->otra)->temporada_id;

        $this->getJson($this->url())->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url('?temporada_id='.$deOtra))->assertStatus(422)->assertJsonValidationErrors('temporada_id');
    }

    public function test_la_cuenta_trae_recepciones_con_estado_y_motivo_y_pagos_con_sus_recepciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['porcentaje_rezaga' => 5]);
        $r1 = $this->crearRecepcion($convenio, ['rezaga_kg' => 0]);
        $r2 = $this->crearRecepcion($convenio, ['rezaga_kg' => 0, 'fecha' => '2026-06-02']);
        $r3 = $this->crearRecepcion($convenio, ['rezaga_kg' => null, 'fecha' => '2026-06-03']);
        $quien = User::factory()->create(['name' => 'Ana Tesorera']);
        $p2 = $this->crearPago($convenio, ['fecha' => '2026-06-12', 'metodo_pago' => 'cheque', 'referencia' => 'CH-9'], [$r2->id => 400]);
        $p1 = $this->crearPago($convenio, ['fecha' => '2026-06-10'], [$r1->id => 1000]);
        $cancelado = $this->crearPago($convenio, [
            'tipo' => 'abono', 'monto' => 70, 'fecha' => '2026-06-10', 'status' => 'cancelado',
            'cancelado_motivo' => 'Se capturó doble', 'cancelado_by' => $quien->id, 'cancelado_at' => '2026-06-11 09:00:00',
        ]);
        Sanctum::actingAs($this->admin());

        $data = $this->getJson($this->url('/'.$convenio->id))->assertOk()->json('data');

        $this->assertSame(['convenio', 'productor', 'estado', 'totales', 'sin_valor', 'recepciones', 'pagos', 'liquidacion', 'puede'], array_keys($data));
        $this->assertSame(
            ['id', 'folio', 'status', 'modalidad', 'unidad', 'moneda', 'usa_calibres', 'porcentaje_rezaga', 'variedad', 'fecha_inicio', 'fecha_fin', 'temporada_id'],
            array_keys($data['convenio']),
        );
        $this->assertSame('2026-06-01', $data['convenio']['fecha_inicio']);
        $this->assertSame($convenio->temporada_id, $data['convenio']['temporada_id']);
        $this->assertSame('abierta', $data['estado']);
        $this->assertSame(['valor_recibido', 'pagado', 'cargos_iniciales', 'por_pagar'], array_keys($data['totales']));
        $this->assertEqualsWithDelta(3000, $data['totales']['valor_recibido'], 0.001);
        $this->assertEqualsWithDelta(1400, $data['totales']['pagado'], 0.001);
        $this->assertEqualsWithDelta(1600, $data['totales']['por_pagar'], 0.001);
        $this->assertSame(['cantidad' => 0, 'folios' => []], $data['sin_valor']);
        $this->assertNull($data['liquidacion']);

        $this->assertSame([$r1->id => 'pagada', $r2->id => 'parcial', $r3->id => 'no_pagable'], array_column($data['recepciones'], 'estado', 'id'));
        $porId = array_column($data['recepciones'], null, 'id');
        $this->assertSame('Falta la rezaga', $porId[$r3->id]['motivo']);
        $this->assertEqualsWithDelta(600, $porId[$r2->id]['falta'], 0.001);
        $this->assertSame([$p1->folio], $porId[$r1->id]['pagos']);

        // Pagos por fecha y id, incluido el cancelado.
        $this->assertSame([$p1->id, $cancelado->id, $p2->id], array_column($data['pagos'], 'id'));
        $this->assertSame([
            'id', 'folio', 'tipo', 'fecha', 'monto', 'moneda', 'metodo_pago', 'referencia', 'notas', 'status',
            'cancelado_motivo', 'cancelado_at', 'cancelado_por', 'recepciones', 'liquidacion_id', 'created_at',
        ], array_keys($data['pagos'][0]));
        $this->assertSame('recepcion', $data['pagos'][0]['tipo']);
        $this->assertSame('2026-06-10', $data['pagos'][0]['fecha']);
        $this->assertEqualsWithDelta(1000, $data['pagos'][0]['monto'], 0.001);
        $this->assertSame('aplicado', $data['pagos'][0]['status']);
        $this->assertNull($data['pagos'][0]['cancelado_por']);
        $this->assertNull($data['pagos'][0]['liquidacion_id']);
        $this->assertEqualsWithDelta(1000, $data['pagos'][0]['recepciones'][0]['monto'], 0.001);
        $this->assertSame(['recepcion_id', 'folio', 'monto'], array_keys($data['pagos'][0]['recepciones'][0]));
        $this->assertSame($r1->id, $data['pagos'][0]['recepciones'][0]['recepcion_id']);
        $this->assertSame($r1->folio, $data['pagos'][0]['recepciones'][0]['folio']);
        $this->assertSame('cancelado', $data['pagos'][1]['status']);
        $this->assertSame('Se capturó doble', $data['pagos'][1]['cancelado_motivo']);
        $this->assertSame('Ana Tesorera', $data['pagos'][1]['cancelado_por']);
        $this->assertNotNull($data['pagos'][1]['cancelado_at']);
        $this->assertSame([], $data['pagos'][1]['recepciones']);
        $this->assertSame('cheque', $data['pagos'][2]['metodo_pago']);
        $this->assertSame('CH-9', $data['pagos'][2]['referencia']);
    }

    public function test_una_cuenta_liquidada_trae_la_liquidacion_y_las_recepciones_sin_valor(): void
    {
        $convenio = $this->convenioConRecepcion($this->productor('Alfa'));
        $sinPrecio = $this->crearRecepcion($convenio, ['fecha' => '2026-05-01']);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1000]);
        Sanctum::actingAs($this->admin());

        $data = $this->getJson($this->url('/'.$convenio->id))->assertOk()->json('data');

        $this->assertSame('liquidada', $data['estado']);
        $this->assertSame(['cantidad' => 1, 'folios' => [$sinPrecio->folio]], $data['sin_valor']);
        $this->assertSame($liq->id, $data['liquidacion']['id']);
        $this->assertSame($liq->folio, $data['liquidacion']['folio']);
        $this->assertSame('liquidada', $data['liquidacion']['status']);
        $this->assertEqualsWithDelta(1000, $data['totales']['por_pagar'], 0.001);
    }

    public function test_puede_refleja_permisos_y_estado(): void
    {
        $convenio = $this->convenioConRecepcion($this->productor('Alfa'));
        $liquidado = $this->convenioConRecepcion($this->productor('Beta'));
        $this->crearLiquidacion($liquidado, ['monto_final' => 1000]);
        $saldado = $this->convenioConRecepcion($this->productor('Gamma'));
        $this->crearLiquidacion($saldado, ['monto_final' => 1000]);
        $this->crearPago($saldado, ['tipo' => 'liquidacion', 'monto' => 1000]);
        $puede = fn (Convenio $c) => $this->getJson($this->url('/'.$c->id))->assertOk()->json('data.puede');

        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view', 'create']);
        $this->assertSame([
            'pagar_recepciones' => true,
            'abonar' => true,
            'liquidar' => false,
            'cancelar_pago' => false,
            'cancelar_liquidacion' => false,
            'saldo_inicial' => false,
        ], $puede($convenio));

        // Con todos los permisos: cuenta liquidada con saldo solo se puede abonar y cancelar la liquidación.
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['liquidar', 'cancelar', 'delete', 'carga-inicial']);
        $this->assertSame([
            'pagar_recepciones' => false,
            'abonar' => true,
            'liquidar' => false,
            'cancelar_pago' => false,
            'cancelar_liquidacion' => true,
            'saldo_inicial' => false,
        ], $puede($liquidado));
        // Liquidada y saldada: ya no se abona.
        $this->assertFalse($puede($saldado)['abonar']);
        $this->assertTrue($puede($saldado)['cancelar_liquidacion']);
        // Abierta.
        $this->assertSame([
            'pagar_recepciones' => true,
            'abonar' => true,
            'liquidar' => true,
            'cancelar_pago' => true,
            'cancelar_liquidacion' => false,
            'saldo_inicial' => true,
        ], $puede($convenio));

        // Sin `create` no se paga ni se abona aunque la cuenta esté abierta.
        $soloVer = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($soloVer, $this->sbp, 'liquidaciones', ['view']);
        Sanctum::actingAs($soloVer);
        $this->assertSame(array_fill_keys(
            ['pagar_recepciones', 'abonar', 'liquidar', 'cancelar_pago', 'cancelar_liquidacion', 'saldo_inicial'],
            false,
        ), $puede($convenio));
    }

    public function test_sin_view_da_403_y_otra_empresa_da_404(): void
    {
        $convenio = $this->convenioConRecepcion($this->productor('Alfa'));
        $deOtra = $this->crearConvenio($this->otra);
        $this->crearRecepcion($deOtra);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url('?temporada_id='.$convenio->temporada_id))->assertForbidden();
        $this->getJson($this->url('/'.$convenio->id))->assertForbidden();
        // El dinero es de `liquidaciones`: ver convenios no basta.
        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view']);
        $this->getJson($this->url('/'.$convenio->id))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        $this->getJson($this->url('/'.$convenio->id))->assertOk();
        $this->getJson($this->url('/'.$deOtra->id))->assertNotFound();
        $this->getJson($this->url('/999999'))->assertNotFound();
    }

    public function test_por_calibre_trae_renglones_con_precio(): void
    {
        $variedad = Variedad::orderBy('id')->firstOrFail();
        $a = $this->crearCalibre($variedad, 'Extra');
        $b = $this->crearCalibre($variedad, 'Primera');
        $sinPrecio = $this->crearCalibre($variedad, 'Chico');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 100, 'precio' => 120],
            ['calibre' => $b, 'volumen' => 100, 'precio' => 100],
        ], ['variedad_id' => $variedad->id]);
        $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $a->id, 'cantidad' => 10],
            ['calibre_id' => $b->id, 'cantidad' => 5],
        ]);
        $r2 = $this->crearRecepcionConRenglones($convenio, [['calibre_id' => $sinPrecio->id, 'cantidad' => 3]], ['fecha' => '2026-06-02']);
        Sanctum::actingAs($this->admin());

        $data = $this->getJson($this->url('/'.$convenio->id))->assertOk()->json('data');

        $this->assertTrue($data['convenio']['usa_calibres']);
        $this->assertEqualsWithDelta(1700, $data['totales']['valor_recibido'], 0.001);
        $this->assertSame(['cantidad' => 1, 'folios' => [$r2->folio]], $data['sin_valor']);
        [$f1, $f2] = $data['recepciones'];
        $this->assertNull($f1['precio']);
        $this->assertEquals(
            [['Extra', 10.0, 120.0], ['Primera', 5.0, 100.0]],
            array_map(fn ($g) => [$g['calibre'], $g['cantidad'], $g['precio']], $f1['renglones']),
        );
        $this->assertSame(['calibre_id', 'calibre', 'cantidad', 'kilos', 'rezaga_kg', 'precio'], array_keys($f1['renglones'][0]));
        $this->assertNull($f2['renglones'][0]['precio']);
        $this->assertSame('no_pagable', $f2['estado']);

        $fila = $this->getJson($this->url('?temporada_id='.$convenio->temporada_id))->assertOk()->json('data.0');
        $this->assertSame(1, $fila['sin_valor']);
        $this->assertTrue($fila['convenio']['usa_calibres']);
        $this->assertEqualsWithDelta(1700, $fila['valor_recibido'], 0.001);
    }

    public function test_un_convenio_clasico_trae_precio_y_renglones_vacios(): void
    {
        $convenio = $this->convenioConRecepcion($this->productor('Alfa'));
        Sanctum::actingAs($this->admin());

        $fila = $this->getJson($this->url('/'.$convenio->id))->assertOk()->json('data.recepciones.0');

        $this->assertEqualsWithDelta(100, $fila['precio'], 0.001);
        $this->assertSame([], $fila['renglones']);
    }

    public function test_el_numero_de_consultas_no_crece_con_los_convenios(): void
    {
        $variedad = Variedad::orderBy('id')->firstOrFail();
        $calibre = $this->crearCalibre($variedad, 'Extra');
        $nuevo = function (string $nombre, bool $porCalibre, bool $liquidada) use ($variedad, $calibre): int {
            $productor = $this->productor($nombre);
            if ($porCalibre) {
                $c = $this->crearConvenioPorCalibre(
                    $this->sbp,
                    [['calibre' => $calibre, 'volumen' => 50, 'precio' => 110]],
                    ['productor_id' => $productor->id, 'variedad_id' => $variedad->id],
                );
                $this->crearRecepcionConRenglones($c, [['calibre_id' => $calibre->id, 'cantidad' => 4]]);
            } else {
                $c = $this->convenioConRecepcion($productor, ['variedad_id' => $variedad->id]);
            }
            $this->crearPago($c, ['monto' => 25]);
            if ($liquidada) {
                $this->crearLiquidacion($c, ['monto_final' => 500]);
            }

            return $c->temporada_id;
        };
        $consultas = function (int $temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url("?temporada_id={$temporada}&liquidadas=1"))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        Sanctum::actingAs($this->admin());

        $nuevo('A1', false, true);
        $t = $nuevo('A2', true, false);
        $con2 = $consultas($t);

        $nuevo('B1', false, false);
        $nuevo('B2', true, true);
        $nuevo('B3', false, true);
        $nuevo('B4', true, false);
        $con6 = $consultas($t);

        $this->assertCount(6, $this->getJson($this->url("?temporada_id={$t}&liquidadas=1"))->json('data'));
        $this->assertSame($con2, $con6, 'El número de consultas no debe crecer con los convenios');
    }
}
