<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\User;
use App\Services\Abastecimiento\Semanas;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Abonos, anticipos y estado de cuenta por HTTP (plan 2, Task 9). El convenio por omisión de los fixtures es
 * de compra directa por caja a $100 en MXN; las liquidaciones se crean aprobadas directo con el monto que
 * pide cada prueba (como las deja el flujo al aprobar).
 */
class AbonosApiTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $this->gestor = $this->usuarioCon(['view', 'create', 'cancelar']);
        Sanctum::actingAs($this->gestor);
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $ruta = 'abonos', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function usuarioCon(array $permisos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($usuario, $this->sbp, 'abonos', $permisos);

        return $usuario;
    }

    private function como(User $usuario): void
    {
        Sanctum::actingAs($usuario);
    }

    private function aprobada(Convenio $convenio, float $monto, array $over = []): Liquidacion
    {
        return $this->crearLiquidacion($convenio, array_merge([
            'status' => 'aprobada', 'monto_calculado' => $monto, 'monto_final' => $monto, 'aprobada_at' => '2026-06-20 10:00:00',
        ], $over));
    }

    private function productorDe(Convenio $convenio): Productor
    {
        return Productor::findOrFail($convenio->productor_id);
    }

    /** Cuerpo de alta válido para el productor; `$over` pisa cualquier campo. */
    private function cuerpo(Productor $productor, float $monto, array $aplicaciones = [], array $over = []): array
    {
        return array_merge([
            'productor_id' => $productor->id, 'fecha' => '2026-06-25', 'monto' => $monto, 'moneda' => 'MXN',
            'metodo_pago' => 'transferencia', 'aplicaciones' => $aplicaciones,
        ], $over);
    }

    private function aplicacion(Liquidacion $liq, float $monto): array
    {
        return ['liquidacion_id' => $liq->id, 'monto' => $monto];
    }

    private function alta(array $cuerpo)
    {
        return $this->postJson($this->url(), $cuerpo);
    }

    /** Rechazo sin escribir nada: 422, ningún abono ni aplicación nuevos y el folio del siguiente sigue siendo AB-0001. */
    private function assertRechazoLimpio(array $cuerpo, string $campo, ?Productor $productor = null): void
    {
        $this->alta($cuerpo)->assertStatus(422)->assertJsonValidationErrors($campo);

        $this->assertSame(0, Abono::withTrashed()->count());
        $this->assertSame(0, AbonoAplicacion::count());

        $productor ??= Productor::findOrFail($cuerpo['productor_id']);
        $this->alta($this->cuerpo($productor, 10))->assertStatus(201)->assertJsonPath('data.folio', 'AB-0001');
    }

    private function saldoDe(Productor $productor): array
    {
        return SaldoProductor::porProductores($this->sbp, [$productor->id])->get($productor->id, []);
    }

    // ---------------------------------------------------------------- alta

    public function test_alta_con_una_aplicacion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);

        $r = $this->alta($this->cuerpo($productor, 400, [$this->aplicacion($liq, 400)], ['referencia' => 'SPEI 123', 'notas' => 'Primer pago']))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.folio', 'AB-0001')
            ->assertJsonPath('data.status', 'aplicado')
            ->assertJsonPath('data.referencia', 'SPEI 123')
            ->assertJsonPath('data.metodo_pago', 'transferencia')
            ->assertJsonPath('data.liquidaciones_pagadas', []);

        $d = $r->json('data');
        $this->assertEquals(400, $d['monto']);
        $this->assertEquals(400, $d['aplicado']);
        $this->assertEquals(0, $d['sin_aplicar']);
        $this->assertSame($liq->id, $d['aplicaciones'][0]['liquidacion_id']);
        $this->assertSame($liq->folio, $d['aplicaciones'][0]['folio']);
        $this->assertEquals(1000, $d['saldo_antes']['por_pagar']);
        $this->assertEquals(0, $d['saldo_antes']['anticipos_sin_aplicar']);
        $this->assertEquals(600, $d['saldo_despues']['por_pagar']);
        $this->assertEquals(0, $d['saldo_despues']['anticipos_sin_aplicar']);

        $abono = Abono::findOrFail($d['id']);
        $this->assertSame($this->sbp->id, $abono->enterprise_id);
        $this->assertSame($this->gestor->id, $abono->created_by);
        $this->assertSame(1, AbonoAplicacion::where('abono_id', $abono->id)->count());
        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_alta_con_varias_aplicaciones_deja_pagada_la_que_queda_cubierta(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $a = $this->aprobada($convenio, 1000);
        $b = $this->aprobada($convenio, 500);
        $productor = $this->productorDe($convenio);

        $d = $this->alta($this->cuerpo($productor, 1200, [$this->aplicacion($a, 1000), $this->aplicacion($b, 200)]))
            ->assertStatus(201)->json('data');

        $this->assertSame([['id' => $a->id, 'folio' => $a->folio]], $d['liquidaciones_pagadas']);
        $this->assertSame('pagada', $a->fresh()->status);
        $this->assertSame('aprobada', $b->fresh()->status);
        $this->assertEquals(1500, $d['saldo_antes']['por_pagar']);
        $this->assertEquals(300, $d['saldo_despues']['por_pagar']);
        $this->assertCount(2, $d['aplicaciones']);
    }

    public function test_un_abono_puede_aplicar_menos_que_su_monto_y_el_resto_es_anticipo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 300);
        $productor = $this->productorDe($convenio);

        $d = $this->alta($this->cuerpo($productor, 500, [$this->aplicacion($liq, 300)]))->assertStatus(201)->json('data');

        $this->assertEquals(300, $d['aplicado']);
        $this->assertEquals(200, $d['sin_aplicar']);
        $this->assertSame('pagada', $liq->fresh()->status);
        $this->assertSame([$liq->id], array_column($d['liquidaciones_pagadas'], 'id'));
        $this->assertEquals(0, $d['saldo_despues']['por_pagar']);
        $this->assertEquals(200, $d['saldo_despues']['anticipos_sin_aplicar']);
    }

    public function test_anticipo_sin_aplicaciones_con_y_sin_convenio(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);

        $sin = $this->alta($this->cuerpo($productor, 700))->assertStatus(201)->json('data');
        $con = $this->alta($this->cuerpo($productor, 300, [], ['convenio_id' => $convenio->id]))->assertStatus(201)->json('data');

        $this->assertNull($sin['convenio']);
        $this->assertSame(['id' => $convenio->id, 'folio' => $convenio->folio], $con['convenio']);
        $this->assertEquals(700, $sin['sin_aplicar']);
        $this->assertSame([], $sin['aplicaciones']);
        $this->assertSame([], $con['liquidaciones_pagadas']);
        $this->assertEquals(700, $con['saldo_antes']['anticipos_sin_aplicar']);
        $this->assertEquals(1000, $con['saldo_despues']['anticipos_sin_aplicar']);
        $this->assertSame(['AB-0001', 'AB-0002'], [$sin['folio'], $con['folio']]);
        $this->assertSame(0, AbonoAplicacion::count());
    }

    public function test_el_folio_es_consecutivo_por_empresa(): void
    {
        $productor = $this->productorDe($this->crearConvenio($this->sbp));
        $this->alta($this->cuerpo($productor, 10))->assertStatus(201)->assertJsonPath('data.folio', 'AB-0001');
        $this->alta($this->cuerpo($productor, 10))->assertStatus(201)->assertJsonPath('data.folio', 'AB-0002');
    }

    public function test_un_anticipo_ligado_a_un_convenio_de_otro_productor_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otro = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro', 'tipo' => 'productor', 'is_active' => true]);

        $this->assertRechazoLimpio($this->cuerpo($otro, 100, [], ['convenio_id' => $convenio->id]), 'convenio_id', $otro);
    }

    // ---------------------------------------------------------------- Review Focus 1: 422 sin escribir

    public function test_aplicaciones_que_suman_mas_que_el_monto_del_abono_son_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $a = $this->aprobada($convenio, 1000);
        $b = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);

        $this->assertRechazoLimpio($this->cuerpo($productor, 500, [$this->aplicacion($a, 300), $this->aplicacion($b, 300)]), 'aplicaciones');
    }

    public function test_aplicacion_mayor_al_saldo_de_la_liquidacion_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        $previo = $this->crearAbono($productor, ['monto' => 800]);
        AbonoAplicacion::create(['abono_id' => $previo->id, 'liquidacion_id' => $liq->id, 'monto' => 800]);

        $this->alta($this->cuerpo($productor, 500, [$this->aplicacion($liq, 300)]))
            ->assertStatus(422)->assertJsonValidationErrors('aplicaciones');

        $this->assertSame(1, Abono::count());
        $this->assertSame(1, AbonoAplicacion::count());
        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_aplicar_a_una_liquidacion_de_otra_moneda_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['moneda' => 'USD']);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);

        $this->assertRechazoLimpio($this->cuerpo($productor, 500, [$this->aplicacion($liq, 500)], ['moneda' => 'MXN']), 'moneda');
    }

    public function test_aplicar_a_una_liquidacion_de_otro_productor_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otro = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro productor', 'tipo' => 'productor', 'is_active' => true]);
        $convenioAjeno = $this->crearConvenio($this->sbp, ['productor_id' => $otro->id]);
        $liqAjena = $this->aprobada($convenioAjeno, 1000);

        $this->assertRechazoLimpio($this->cuerpo($this->productorDe($convenio), 500, [$this->aplicacion($liqAjena, 500)]), 'liquidacion_id', $this->productorDe($convenio));
    }

    public function test_aplicar_a_una_liquidacion_no_aprobada_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);

        foreach (['borrador', 'en_revision', 'cancelada', 'pagada'] as $status) {
            $liq = $this->aprobada($convenio, 1000, ['status' => $status]);
            $this->alta($this->cuerpo($productor, 500, [$this->aplicacion($liq, 500)]))
                ->assertStatus(422)->assertJsonValidationErrors('liquidacion_id');
        }

        $this->assertSame(0, Abono::withTrashed()->count());
        $this->assertSame(0, AbonoAplicacion::count());
    }

    public function test_una_liquidacion_repetida_en_las_aplicaciones_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);

        $this->assertRechazoLimpio($this->cuerpo($this->productorDe($convenio), 500, [$this->aplicacion($liq, 100), $this->aplicacion($liq, 100)]), 'aplicaciones');
    }

    public function test_aplicar_a_una_liquidacion_de_otra_empresa_es_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $ajeno = $this->crearConvenio($this->otra);
        $liqAjena = $this->aprobada($ajeno, 1000);

        $this->assertRechazoLimpio($this->cuerpo($this->productorDe($convenio), 500, [$this->aplicacion($liqAjena, 500)]), 'liquidacion_id', $this->productorDe($convenio));
    }

    public function test_un_anticipo_ligado_a_un_convenio_solo_aplica_a_liquidaciones_de_ese_convenio(): void
    {
        $uno = $this->crearConvenio($this->sbp);
        $dos = $this->crearConvenio($this->sbp);
        $liqDos = $this->aprobada($dos, 1000);

        $this->assertRechazoLimpio($this->cuerpo($this->productorDe($uno), 500, [$this->aplicacion($liqDos, 500)], ['convenio_id' => $uno->id]), 'liquidacion_id', $this->productorDe($uno));
    }

    // ---------------------------------------------------------------- validación del cuerpo

    public function test_validacion_del_cuerpo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);
        $hoy = Semanas::hoy();

        $casos = [
            'fecha futura' => [['fecha' => $hoy->addDay()->toDateString()], 'fecha'],
            'fecha con formato malo' => [['fecha' => '25/06/2026'], 'fecha'],
            'fecha imposible' => [['fecha' => '2026-02-31'], 'fecha'],
            'fecha ausente' => [['fecha' => null], 'fecha'],
            'monto cero' => [['monto' => 0], 'monto'],
            'monto negativo' => [['monto' => -5], 'monto'],
            'monto con tres decimales' => [['monto' => '10.123'], 'monto'],
            'monto no numérico' => [['monto' => 'mucho'], 'monto'],
            'moneda inválida' => [['moneda' => 'EUR'], 'moneda'],
            'método inválido' => [['metodo_pago' => 'trueque'], 'metodo_pago'],
            'productor ausente' => [['productor_id' => null], 'productor_id'],
            'productor de otra empresa' => [['productor_id' => $this->crearConvenio($this->otra)->productor_id], 'productor_id'],
            'convenio de otra empresa' => [['convenio_id' => $this->crearConvenio($this->otra)->id], 'convenio_id'],
            'aplicación con tres decimales' => [['aplicaciones' => [['liquidacion_id' => 1, 'monto' => '1.005']]], 'aplicaciones.0.monto'],
            'aplicación sin liquidación' => [['aplicaciones' => [['monto' => 10]]], 'aplicaciones.0.liquidacion_id'],
        ];

        foreach ($casos as $nombre => [$cambio, $campo]) {
            $this->alta(array_merge($this->cuerpo($productor, 100), $cambio))
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo, 'errors');
        }

        $this->assertSame(0, Abono::withTrashed()->count(), 'Ningún caso inválido debe escribir.');
    }

    public function test_la_fecha_de_hoy_se_acepta_en_la_zona_de_abastecimiento(): void
    {
        $productor = $this->productorDe($this->crearConvenio($this->sbp));

        $this->alta($this->cuerpo($productor, 10, [], ['fecha' => Semanas::hoy()->toDateString()]))->assertStatus(201);
    }

    // ---------------------------------------------------------------- permisos y empresa

    public function test_los_permisos_se_exigen_por_endpoint(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);
        $abono = $this->crearAbono($productor);

        $soloVer = $this->usuarioCon(['view']);
        $soloCrear = $this->usuarioCon(['create']);
        $nada = $this->usuarioCon([]);

        $this->como($soloVer);
        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url('abonos/productores'))->assertOk();
        $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta"))->assertOk();
        $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productor->id}&moneda=MXN"))->assertForbidden();
        $this->alta($this->cuerpo($productor, 10))->assertForbidden();
        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), ['motivo' => 'x'])->assertForbidden();

        $this->como($soloCrear);
        $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productor->id}&moneda=MXN"))->assertOk();
        $this->alta($this->cuerpo($productor, 10))->assertStatus(201);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('abonos/productores'))->assertForbidden();
        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), ['motivo' => 'x'])->assertForbidden();

        $this->como($nada);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta"))->assertForbidden();
        $this->alta($this->cuerpo($productor, 10))->assertForbidden();

        $this->assertSame('aplicado', $abono->fresh()->status);
    }

    public function test_productor_o_abono_de_otra_empresa_es_404(): void
    {
        $ajeno = $this->crearConvenio($this->otra);
        $productorAjeno = $this->productorDe($ajeno);
        $abonoAjeno = $this->crearAbono($productorAjeno);

        $this->getJson($this->url("abonos/productores/{$productorAjeno->id}/estado-cuenta"))->assertNotFound();
        $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productorAjeno->id}&moneda=MXN"))->assertNotFound();
        $this->postJson($this->url("abonos/{$abonoAjeno->id}/cancelar"), ['motivo' => 'x'])->assertNotFound();

        $this->assertSame('aplicado', $abonoAjeno->fresh()->status);
    }

    // ---------------------------------------------------------------- cancelar

    public function test_cancelar_exige_motivo(): void
    {
        $productor = $this->productorDe($this->crearConvenio($this->sbp));
        $abono = $this->crearAbono($productor);

        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), [])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), ['motivo' => '   '])->assertStatus(422)->assertJsonValidationErrors('motivo');

        $this->assertSame('aplicado', $abono->fresh()->status);
    }

    public function test_cancelar_un_abono_ya_cancelado_es_422(): void
    {
        $productor = $this->productorDe($this->crearConvenio($this->sbp));
        $abono = $this->crearAbono($productor);

        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), ['motivo' => 'Duplicado'])->assertOk();
        $this->postJson($this->url("abonos/{$abono->id}/cancelar"), ['motivo' => 'Otra vez'])
            ->assertStatus(422)->assertJsonValidationErrors('abono');

        $this->assertSame('Duplicado', $abono->fresh()->cancelado_motivo);
    }

    public function test_cancelar_reabre_la_liquidacion_que_habia_dejado_pagada_y_libera_el_monto_como_anticipo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        $abonoId = $this->alta($this->cuerpo($productor, 1000, [$this->aplicacion($liq, 1000)]))->assertStatus(201)->json('data.id');
        $this->assertSame('pagada', $liq->fresh()->status);

        $d = $this->postJson($this->url("abonos/{$abonoId}/cancelar"), ['motivo' => 'Transferencia rebotada'])
            ->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertSame('cancelado', $d['status']);
        $this->assertSame('Transferencia rebotada', $d['cancelado_motivo']);
        $this->assertSame([['id' => $liq->id, 'folio' => $liq->folio]], $d['liquidaciones_reabiertas']);
        $this->assertSame('aprobada', $liq->fresh()->status);
        $this->assertSame(0, AbonoAplicacion::count());
        $this->assertEquals([['liquidacion_id' => $liq->id, 'monto' => 1000]], Abono::findOrFail($abonoId)->aplicaciones_canceladas);
        $this->assertSame($this->gestor->id, Abono::findOrFail($abonoId)->cancelado_by);

        $mxn = $this->saldoDe($productor)['MXN'];
        $this->assertSame(1000.0, $mxn['por_pagar']);
        $this->assertSame(0.0, $mxn['anticipos_sin_aplicar']);
    }

    public function test_cancelar_una_aplicacion_parcial_no_reabre_ninguna_liquidacion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        $parcial = $this->alta($this->cuerpo($productor, 400, [$this->aplicacion($liq, 400)]))->json('data.id');

        $this->postJson($this->url("abonos/{$parcial}/cancelar"), ['motivo' => 'Error'])
            ->assertOk()->assertJsonPath('data.liquidaciones_reabiertas', []);

        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_cancelar_no_reaplica_otros_anticipos_automaticamente(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        $aplicado = $this->alta($this->cuerpo($productor, 1000, [$this->aplicacion($liq, 1000)]))->json('data.id');
        $anticipo = $this->alta($this->cuerpo($productor, 500))->assertStatus(201)->json('data.id');

        $this->postJson($this->url("abonos/{$aplicado}/cancelar"), ['motivo' => 'Error'])->assertOk();

        $this->assertSame(0, AbonoAplicacion::count(), 'El anticipo existente no se aplica solo al cancelar.');
        $this->assertSame('aprobada', $liq->fresh()->status);
        $mxn = $this->saldoDe($productor)['MXN'];
        $this->assertSame(1000.0, $mxn['por_pagar']);
        $this->assertSame(500.0, $mxn['anticipos_sin_aplicar']);
        $this->assertSame('aplicado', Abono::findOrFail($anticipo)->status);
    }

    // ---------------------------------------------------------------- liquidaciones pendientes

    public function test_liquidaciones_pendientes_van_de_la_mas_antigua_a_la_mas_nueva_con_su_saldo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otroConvenio = $this->crearConvenio($this->sbp);
        $nueva = $this->aprobada($convenio, 300, ['aprobada_at' => '2026-07-10 09:00:00']);
        $vieja = $this->aprobada($convenio, 1000, ['aprobada_at' => '2026-06-20 09:00:00']);
        $otra = $this->aprobada($otroConvenio, 200, ['aprobada_at' => '2026-06-25 09:00:00']);
        $this->aprobada($convenio, 900, ['status' => 'pagada']);
        $this->aprobada($convenio, 900, ['status' => 'borrador']);
        $this->aprobada($convenio, 900, ['moneda' => 'USD']);
        $productor = $this->productorDe($convenio);
        $abono = $this->crearAbono($productor, ['monto' => 250]);
        AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $vieja->id, 'monto' => 250]);

        $filas = $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productor->id}&moneda=MXN"))
            ->assertOk()->json('data');

        $this->assertSame([$vieja->id, $otra->id, $nueva->id], array_column($filas, 'id'));
        $this->assertSame($vieja->folio, $filas[0]['folio']);
        $this->assertSame(['id' => $convenio->id, 'folio' => $convenio->folio], $filas[0]['convenio']);
        $this->assertSame('2026-06-20', $filas[0]['fecha']);
        $this->assertEquals(1000, $filas[0]['monto_final']);
        $this->assertEquals(250, $filas[0]['aplicado']);
        $this->assertEquals(750, $filas[0]['saldo']);
        $this->assertEquals(0, $filas[1]['aplicado']);

        $soloUno = $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productor->id}&moneda=MXN&convenio_id={$otroConvenio->id}"))
            ->assertOk()->json('data');
        $this->assertSame([$otra->id], array_column($soloUno, 'id'));

        $usd = $this->getJson($this->url("abonos/liquidaciones-pendientes?productor_id={$productor->id}&moneda=USD"))->assertOk()->json('data');
        $this->assertCount(1, $usd);
    }

    public function test_liquidaciones_pendientes_exige_productor_y_moneda(): void
    {
        $this->getJson($this->url('abonos/liquidaciones-pendientes'))->assertStatus(422)->assertJsonValidationErrors(['productor_id', 'moneda']);
    }

    // ---------------------------------------------------------------- listado de abonos

    public function test_listado_de_abonos_con_filtros_y_sus_aplicaciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        $otro = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro', 'tipo' => 'productor', 'is_active' => true]);

        $a1 = $this->alta($this->cuerpo($productor, 400, [$this->aplicacion($liq, 400)], ['fecha' => '2026-06-21']))->json('data.id');
        $a2 = $this->alta($this->cuerpo($productor, 50, [], ['fecha' => '2026-06-22', 'moneda' => 'USD']))->json('data.id');
        $a3 = $this->alta($this->cuerpo($otro, 70, [], ['fecha' => '2026-06-23']))->json('data.id');
        $this->postJson($this->url("abonos/{$a2}/cancelar"), ['motivo' => 'Error'])->assertOk();
        $ajeno = $this->crearAbono($this->productorDe($this->crearConvenio($this->otra)));

        $todos = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertSame([$a3, $a2, $a1], array_column($todos['data'], 'id'));
        $this->assertSame(50, $todos['per_page']);
        $this->assertNotContains($ajeno->id, array_column($todos['data'], 'id'));
        $this->assertSame($liq->folio, $todos['data'][2]['aplicaciones'][0]['folio']);

        $ids = fn (string $q) => array_column($this->getJson($this->url("abonos?{$q}"))->assertOk()->json('data.data'), 'id');
        $this->assertSame([$a2, $a1], $ids("productor_id={$productor->id}"));
        $this->assertSame([$a2], $ids('status=cancelado'));
        $this->assertSame([$a3, $a1], $ids('status=aplicado'));
        $this->assertSame([$a2], $ids('moneda=USD'));
        $this->getJson($this->url('abonos?status=raro'))->assertStatus(422);
    }

    // ---------------------------------------------------------------- productores con saldo

    public function test_productores_con_saldo_ordenados_con_movimiento_primero_y_buscables(): void
    {
        $zeta = $this->crearConvenio($this->sbp);                                     // «Productor de prueba»
        $productorZ = $this->productorDe($zeta);
        $productorZ->update(['nombre' => 'Zeta']);
        $alfa = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Alfa', 'tipo' => 'empaque', 'is_active' => true]);
        $beta = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Beta', 'tipo' => 'productor', 'is_active' => true]);
        $inactivo = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Dormido', 'tipo' => 'productor', 'is_active' => false]);
        $borrado = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Borrado', 'tipo' => 'productor', 'is_active' => true]);
        $borrado->delete();
        $this->crearConvenio($this->otra);

        $this->aprobada($zeta, 1000);
        $this->crearAbono($beta, ['monto' => 80, 'moneda' => 'USD']);

        $filas = $this->getJson($this->url('abonos/productores'))->assertOk()->json('data');

        // Con movimiento primero (por nombre), luego los demás activos por nombre; ni el inactivo ni el borrado sin movimiento.
        $this->assertSame(['Beta', 'Zeta', 'Alfa'], array_column($filas, 'nombre'));
        $this->assertSame('productor', $filas[0]['tipo']);
        $this->assertSame([], $filas[2]['saldos']);

        $this->assertCount(1, $filas[0]['saldos']);
        $this->assertSame('USD', $filas[0]['saldos'][0]['moneda']);
        $this->assertEquals(80, $filas[0]['saldos'][0]['anticipos_sin_aplicar']);

        $zetaFila = $filas[1]['saldos'][0];
        $this->assertSame('MXN', $zetaFila['moneda']);
        $this->assertEquals(1000, $zetaFila['por_pagar']);
        $this->assertEquals(1000, $zetaFila['liquidado']);
        $this->assertEquals(0, $zetaFila['aplicado']);
        foreach (['moneda', 'por_pagar', 'anticipos_sin_aplicar', 'sin_liquidar', 'liquidado', 'aplicado'] as $clave) {
            $this->assertArrayHasKey($clave, $zetaFila);
        }

        $buscado = $this->getJson($this->url('abonos/productores?search=alf'))->assertOk()->json('data');
        $this->assertSame(['Alfa'], array_column($buscado, 'nombre'));

        // Un inactivo con movimiento sí aparece.
        $this->crearAbono($inactivo, ['monto' => 5]);
        $filas = $this->getJson($this->url('abonos/productores'))->json('data');
        $this->assertContains('Dormido', array_column($filas, 'nombre'));
    }

    public function test_productores_con_temporada_solo_cuenta_los_convenios_de_esa_temporada(): void
    {
        $uno = $this->crearConvenio($this->sbp);
        $this->aprobada($uno, 1000);
        $temporadaDos = \App\Models\Abastecimiento\Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $uno->cultivo_id, 'nombre' => 'Otra temporada',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-03-31', 'estado' => 'activa',
        ]);
        $dos = $this->crearConvenio($this->sbp, ['temporada_id' => $temporadaDos->id]);
        $this->aprobada($dos, 250);
        $this->crearAbono($this->productorDe($uno), ['monto' => 90]);

        $total = $this->getJson($this->url('abonos/productores'))->json('data.0.saldos.0');
        $this->assertEquals(1250, $total['por_pagar']);
        $this->assertEquals(90, $total['anticipos_sin_aplicar']);

        $soloDos = $this->getJson($this->url("abonos/productores?temporada_id={$temporadaDos->id}"))->json('data.0.saldos.0');
        $this->assertEquals(250, $soloDos['por_pagar']);
        $this->assertEquals(0, $soloDos['anticipos_sin_aplicar']);
    }

    public function test_productores_no_hace_consultas_por_productor(): void
    {
        $this->crearConvenio($this->sbp);
        $contar = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url('abonos/productores'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $contar(); // calienta permisos y cachés
        $pocos = $contar();

        for ($i = 0; $i < 6; $i++) {
            $p = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => "P{$i}", 'tipo' => 'productor', 'is_active' => true]);
            $c = $this->crearConvenio($this->sbp, ['productor_id' => $p->id]);
            $this->aprobada($c, 100 + $i);
            $this->crearAbono($p, ['monto' => 10]);
        }

        $this->assertSame($pocos, $contar());
    }

    // ---------------------------------------------------------------- estado de cuenta

    public function test_el_estado_de_cuenta_cuadra_con_el_saldo_y_trae_los_movimientos_con_saldo_corriente(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);
        $l1 = $this->aprobada($convenio, 1000, ['aprobada_at' => '2026-06-10 09:00:00']);
        $l2 = $this->aprobada($convenio, 500, ['aprobada_at' => '2026-06-20 09:00:00']);
        $this->crearRecepcion($convenio, ['fecha' => '2026-06-05', 'cantidad' => 3]);   // 300 sin liquidar

        $a1 = $this->alta($this->cuerpo($productor, 600, [$this->aplicacion($l1, 600)], ['fecha' => '2026-06-12', 'referencia' => 'REF-1']))->json('data.id');
        $a2 = $this->alta($this->cuerpo($productor, 700, [$this->aplicacion($l1, 400)], ['fecha' => '2026-06-15']))->json('data.id');   // 300 de anticipo
        $a3 = $this->alta($this->cuerpo($productor, 200, [], ['fecha' => '2026-06-18']))->json('data.id');                              // se cancela
        $this->postJson($this->url("abonos/{$a3}/cancelar"), ['motivo' => 'Cheque devuelto'])->assertOk();

        $d = $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta?moneda=MXN"))->assertOk()->json('data');

        $mxn = $this->saldoDe($productor)['MXN'];
        $this->assertEquals($mxn['liquidado'], $d['indicadores']['liquidado']);
        $this->assertEquals($mxn['aplicado'], $d['indicadores']['abonado']);
        $this->assertEquals($mxn['por_pagar'], $d['indicadores']['por_pagar']);
        $this->assertEquals($mxn['sin_liquidar'], $d['indicadores']['sin_liquidar']);
        $this->assertEquals($mxn['anticipos_sin_aplicar'], $d['indicadores']['anticipos_sin_aplicar']);
        $this->assertEquals([1500, 1000, 500, 300, 300], array_values($d['indicadores']));

        $this->assertSame('MXN', $d['moneda']);
        $this->assertSame(['MXN'], $d['monedas_disponibles']);
        $this->assertSame($productor->id, $d['productor']['id']);
        $this->assertSame('Productor de prueba', $d['productor']['nombre']);
        $this->assertSame($this->sbp->name, $d['empresa']['nombre']);

        $m = $d['movimientos'];
        $this->assertSame([$l1->folio, 'AB-0001', 'AB-0002', 'AB-0003', $l2->folio], array_column($m, 'folio'));
        $this->assertSame(['2026-06-10', '2026-06-12', '2026-06-15', '2026-06-18', '2026-06-20'], array_column($m, 'fecha'));
        $this->assertSame(['liquidacion', 'abono', 'anticipo', 'anticipo', 'liquidacion'], array_column($m, 'tipo'));
        $this->assertSame([false, false, false, true, false], array_map(fn ($x) => (bool) $x['cancelado'], $m));
        $this->assertEquals([1000, 400, -300, -300, 200], array_column($m, 'saldo'));   // el cancelado no mueve el saldo
        $this->assertEquals([1000, 600, 700, 200, 500], array_column($m, 'monto'));

        // El saldo corriente final es lo que se debe menos el saldo a favor (anticipos sin aplicar).
        $this->assertEquals($mxn['por_pagar'] - $mxn['anticipos_sin_aplicar'], end($m)['saldo']);

        $this->assertSame('REF-1', $m[1]['referencia']);
        $this->assertSame('transferencia', $m[1]['metodo_pago']);
        $this->assertSame($l1->folio, $m[1]['aplicaciones'][0]['folio']);
        $this->assertEquals(600, $m[1]['aplicaciones'][0]['monto']);
        $this->assertEquals(0, $m[1]['sin_aplicar']);
        $this->assertEquals(300, $m[2]['sin_aplicar']);
        $this->assertSame($convenio->folio, $m[0]['convenio']['folio']);
        $this->assertEquals(1000, $m[0]['aplicado']);
        $this->assertSame('Cheque devuelto', $m[3]['cancelado_motivo']);
        $this->assertSame($a3, $m[3]['id']);
        $this->assertNotNull($a1);
        $this->assertNotNull($a2);
    }

    public function test_en_el_estado_de_cuenta_los_cargos_van_antes_que_los_abonos_del_mismo_dia(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($convenio);
        $liq = $this->aprobada($convenio, 1000, ['aprobada_at' => '2026-06-20 18:00:00']);
        $this->alta($this->cuerpo($productor, 1000, [$this->aplicacion($liq, 1000)], ['fecha' => '2026-06-20']))->assertStatus(201);

        $m = $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta"))->json('data.movimientos');

        $this->assertSame(['liquidacion', 'abono'], array_column($m, 'tipo'));
        $this->assertEquals([1000, 0], array_column($m, 'saldo'));
    }

    public function test_el_estado_de_cuenta_separa_las_monedas(): void
    {
        $mxn = $this->crearConvenio($this->sbp);
        $productor = $this->productorDe($mxn);
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD']);
        $this->aprobada($mxn, 1000);
        $this->aprobada($usd, 40);
        $this->crearAbono($productor, ['monto' => 15, 'moneda' => 'USD', 'fecha' => '2026-06-25']);

        $sinMoneda = $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta"))->assertOk()->json('data');
        $this->assertSame(['MXN', 'USD'], $sinMoneda['monedas_disponibles']);
        $this->assertSame('MXN', $sinMoneda['moneda']);
        $this->assertEquals(1000, $sinMoneda['indicadores']['por_pagar']);
        $this->assertCount(1, $sinMoneda['movimientos']);

        $dolares = $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta?moneda=USD"))->json('data');
        $this->assertSame('USD', $dolares['moneda']);
        $this->assertEquals(40, $dolares['indicadores']['por_pagar']);
        $this->assertEquals(15, $dolares['indicadores']['anticipos_sin_aplicar']);
        $this->assertCount(2, $dolares['movimientos']);
        $this->assertEquals([40, 25], array_column($dolares['movimientos'], 'saldo'));

        $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta?moneda=EUR"))->assertStatus(422);
    }

    public function test_el_estado_de_cuenta_de_un_productor_sin_movimientos(): void
    {
        $productor = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Nuevo', 'tipo' => 'productor', 'is_active' => true]);

        $d = $this->getJson($this->url("abonos/productores/{$productor->id}/estado-cuenta"))->assertOk()->json('data');

        $this->assertSame([], $d['movimientos']);
        $this->assertSame([], $d['monedas_disponibles']);
        $this->assertSame('MXN', $d['moneda']);
        $this->assertEquals([0, 0, 0, 0, 0], array_values($d['indicadores']));
    }

    // ---------------------------------------------------------------- tiempo real

    public function test_el_alta_y_la_cancelacion_difunden_el_abono_despues_de_confirmar(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 1000);
        $productor = $this->productorDe($convenio);
        Event::fake([AbastecimientoUpdated::class]);

        $id = $this->alta($this->cuerpo($productor, 1000, [$this->aplicacion($liq, 1000)]))->assertStatus(201)->json('data.id');
        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'abono' && $e->action === 'created' && $e->data['id'] === $id && $e->data['folio'] === 'AB-0001',
        );
        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'liquidacion' && $e->data['id'] === $liq->id && $e->data['status'] === 'pagada',
        );

        $this->postJson($this->url("abonos/{$id}/cancelar"), ['motivo' => 'Error'])->assertOk();
        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'abono' && $e->action === 'updated' && $e->data['id'] === $id && $e->data['status'] === 'cancelado',
        );
        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'liquidacion' && $e->data['id'] === $liq->id && $e->data['status'] === 'aprobada',
        );
    }

    public function test_un_422_no_difunde_nada(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->aprobada($convenio, 100);
        Event::fake([AbastecimientoUpdated::class]);

        $this->alta($this->cuerpo($this->productorDe($convenio), 500, [$this->aplicacion($liq, 500)]))->assertStatus(422);

        Event::assertNotDispatched(AbastecimientoUpdated::class);
    }
}
