<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\User;
use App\Services\Abastecimiento\CuentaConvenio;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Una sola cifra del saldo (tablero, ficha del convenio, productores y cuenta del convenio salen de
 * `CuentaConvenio`), visibilidad del dinero (solo con `liquidaciones.view`; el administrador siempre) y forma
 * de la ficha del convenio.
 */
class IntegracionDineroTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $ruta): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    private function liquidacion(Convenio $convenio, float $monto, string $status = 'liquidada'): Liquidacion
    {
        return $this->crearLiquidacion($convenio, [
            'status' => $status, 'monto_calculado' => $monto, 'monto_final' => $monto,
            'liquidada_at' => $status === 'liquidada' ? now() : null,
        ]);
    }

    private function otraTemporada(Convenio $base): Temporada
    {
        return Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $base->cultivo_id, 'nombre' => 'Temporada siguiente',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-03-31', 'estado' => 'activa',
        ]);
    }

    private function productorNuevo(string $nombre): Productor
    {
        return Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'tipo' => 'productor', 'is_active' => true]);
    }

    private function tablero(int $temporadaId, string $query = ''): array
    {
        return $this->getJson($this->url("tablero?temporada_id={$temporadaId}{$query}"))->assertOk()->json('data');
    }

    /** Saldo de la moneda dentro de un arreglo `[{moneda, por_pagar, ...}]`. */
    private function porMoneda(array $saldos, string $moneda): ?array
    {
        return collect($saldos)->firstWhere('moneda', $moneda);
    }

    /** Convenio con una recepción de 1000 y un pago por recepción de 400: por pagar 600. */
    private function conPago(array $over = []): Convenio
    {
        $convenio = $this->crearConvenio($this->sbp, $over);
        $recepcion = $this->crearRecepcion($convenio, ['cantidad' => 10]);
        $this->crearPago($convenio, [], [$recepcion->id => 400]);

        return $convenio;
    }

    public function test_tablero_ficha_productores_y_cuenta_dan_la_misma_cifra(): void
    {
        $convenio = $this->conPago();

        $tablero = $this->tablero($convenio->temporada_id);
        $this->assertEqualsWithDelta(600, $tablero['kpis']['saldo_por_pagar'], 0.001);

        $ficha = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk()->json('data');
        $this->assertEqualsWithDelta(600, $ficha['indicadores']['por_pagar'], 0.001);

        $lista = $this->getJson($this->url('productores'))->assertOk()->json('data.data');
        $this->assertSame(['moneda', 'valor_recibido', 'pagado', 'por_pagar'], array_keys($lista[0]['saldo'][0]));
        $mxn = $this->porMoneda($lista[0]['saldo'], 'MXN');
        $this->assertEqualsWithDelta(1000, $mxn['valor_recibido'], 0.001);
        $this->assertEqualsWithDelta(400, $mxn['pagado'], 0.001);
        $this->assertEqualsWithDelta(600, $mxn['por_pagar'], 0.001);

        $selector = $this->getJson($this->url('productores/list'))->assertOk()->json('data');
        $this->assertEqualsWithDelta(600, $this->porMoneda($selector[0]['saldo'], 'MXN')['por_pagar'], 0.001);

        $cuenta = CuentaConvenio::totales(new EloquentCollection([Convenio::findOrFail($convenio->id)]))[$convenio->id];
        $this->assertSame(600.0, $cuenta['por_pagar']);
    }

    public function test_un_convenio_de_otra_temporada_cambia_al_productor_pero_no_al_tablero(): void
    {
        $uno = $this->conPago();
        $dos = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->otraTemporada($uno)->id, 'productor_id' => $uno->productor_id,
            'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-30',
            'metas' => ['2026-10-05' => 100],
        ]);
        $this->crearPago($dos, ['tipo' => 'saldo_inicial', 'monto' => 250]);

        // Tablero de la primera temporada: solo 600; el de la segunda: solo 250.
        $this->assertEqualsWithDelta(600, $this->tablero($uno->temporada_id)['kpis']['saldo_por_pagar'], 0.001);
        $this->assertEqualsWithDelta(250, $this->tablero($dos->temporada_id)['kpis']['saldo_por_pagar'], 0.001);

        // El productor suma las dos; las fichas, cada una lo suyo.
        $fila = $this->getJson($this->url('productores'))->assertOk()->json('data.data.0');
        $this->assertEqualsWithDelta(850, $this->porMoneda($fila['saldo'], 'MXN')['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(600, $this->getJson($this->url("convenios/{$uno->id}"))->json('data.indicadores.por_pagar'), 0.001);
        $this->assertEqualsWithDelta(250, $this->getJson($this->url("convenios/{$dos->id}"))->json('data.indicadores.por_pagar'), 0.001);
    }

    public function test_el_tablero_no_mezcla_monedas(): void
    {
        $mxn = $this->conPago();
        $usd = $this->crearConvenio($this->sbp, [
            'moneda' => 'USD', 'precio' => 50, 'productor_id' => $this->productorNuevo('Exportador USD')->id,
        ]);
        $this->crearRecepcion($usd, ['cantidad' => 10]);

        $this->assertEqualsWithDelta(600, $this->tablero($mxn->temporada_id, '&moneda=MXN')['kpis']['saldo_por_pagar'], 0.001);
        $enUsd = $this->tablero($mxn->temporada_id, '&moneda=USD');
        $this->assertSame('USD', $enUsd['moneda']);
        $this->assertEqualsWithDelta(500, $enUsd['kpis']['saldo_por_pagar'], 0.001);

        // Un productor con convenios en las dos monedas ve una fila por moneda.
        $mixto = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'precio' => 7, 'productor_id' => $mxn->productor_id]);
        $this->crearRecepcion($mixto, ['cantidad' => 10]);
        $fila = collect($this->getJson($this->url('productores'))->json('data.data'))->firstWhere('id', $mxn->productor_id);
        $this->assertSame(['MXN', 'USD'], array_column($fila['saldo'], 'moneda'));
        $this->assertEqualsWithDelta(600, $this->porMoneda($fila['saldo'], 'MXN')['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(70, $this->porMoneda($fila['saldo'], 'USD')['por_pagar'], 0.001);
    }

    public function test_sin_movimiento_el_saldo_es_un_arreglo_vacio_y_el_tablero_marca_cero(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $this->assertSame(0.0, (float) $this->tablero($convenio->temporada_id)['kpis']['saldo_por_pagar']);
        $this->assertSame([], $this->getJson($this->url('productores'))->json('data.data.0.saldo'));
        $this->assertSame(0.0, (float) $this->getJson($this->url("convenios/{$convenio->id}"))->json('data.indicadores.por_pagar'));
    }

    // ---------------------------------------------------------------- ficha del convenio

    public function test_la_ficha_trae_el_resumen_de_liquidaciones_sin_periodos_y_conserva_valor_recibido(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio, ['cantidad' => 10]);
        $vieja = $this->liquidacion($convenio, 300, 'cancelada');
        $nueva = $this->liquidacion($convenio, 1000, 'liquidada');

        $data = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk()->json('data');

        $this->assertArrayHasKey('valor_recibido', $data['indicadores']);
        $this->assertEqualsWithDelta(1000, $data['indicadores']['valor_recibido'], 0.001);
        $this->assertSame([$nueva->id, $vieja->id], array_column($data['liquidaciones'], 'id'));
        $this->assertSame(
            ['id', 'folio', 'status', 'liquidada_at', 'monto_final', 'moneda'],
            array_keys($data['liquidaciones'][0]),
        );
        $this->assertSame('liquidada', $data['liquidaciones'][0]['status']);
        $this->assertNotNull($data['liquidaciones'][0]['liquidada_at']);
        $this->assertSame('cancelada', $data['liquidaciones'][1]['status']);
        $this->assertNull($data['liquidaciones'][1]['liquidada_at']);
        // El monto es número (como el resto del dinero del módulo), no la cadena del cast decimal.
        foreach ($data['liquidaciones'] as $fila) {
            $this->assertTrue(is_int($fila['monto_final']) || is_float($fila['monto_final']), 'monto_final debe ser numérico');
        }
        $this->assertEqualsWithDelta(1000, $data['liquidaciones'][0]['monto_final'], 0.001);
        $this->assertEqualsWithDelta(300, $data['liquidaciones'][1]['monto_final'], 0.001);
    }

    // ---------------------------------------------------------------- visibilidad del dinero

    /** Usuario sin rol de administrador con `view` en los submódulos pedidos. */
    private function usuarioCon(array $submodulos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        foreach ($submodulos as $submodulo) {
            $this->otorgarAbs($usuario, $this->sbp, $submodulo, ['view']);
        }
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /** Consultas SQL que tocan las tablas del dinero durante la petición. */
    private function consultasDeDinero(string $ruta): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url($ruta))->assertOk();
        $n = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/abs_(liquidaciones|abonos|abono_recepciones|abono_aplicaciones)/', $q['query']))->count();
        DB::disableQueryLog();

        return $n;
    }

    private function conSaldo(): Convenio
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio);
        $this->liquidacion($convenio, 1000);

        return $convenio;
    }

    public function test_productores_oculta_el_saldo_sin_liquidaciones_view_aunque_tenga_abonos_view(): void
    {
        $this->conSaldo();

        // Ni sin permisos de dinero ni con el viejo abonos.view: solo liquidaciones.view abre el saldo.
        foreach ([['productores'], ['productores', 'abonos']] as $submodulos) {
            $this->usuarioCon($submodulos);

            foreach (['productores' => 'data.data.0', 'productores/list' => 'data.0'] as $ruta => $fila) {
                $res = $this->getJson($this->url($ruta))->assertOk();
                $this->assertArrayHasKey('saldo', $res->json($fila), $ruta);
                $this->assertNull($res->json("{$fila}.saldo"), $ruta);
                $this->assertSame(0, $this->consultasDeDinero($ruta), "{$ruta}: no debe consultar el dinero.");
            }
        }
    }

    public function test_productores_muestra_el_saldo_con_liquidaciones_view(): void
    {
        $this->conSaldo();
        $this->usuarioCon(['productores', 'liquidaciones']);

        foreach (['productores' => 'data.data.0', 'productores/list' => 'data.0'] as $ruta => $fila) {
            $saldo = $this->getJson($this->url($ruta))->assertOk()->json("{$fila}.saldo");
            $this->assertIsArray($saldo, $ruta);
            $this->assertEqualsWithDelta(1000, $this->porMoneda($saldo, 'MXN')['por_pagar'], 0.001, $ruta);
        }
    }

    public function test_productores_muestra_el_saldo_al_administrador(): void
    {
        $this->conSaldo();

        $this->assertIsArray($this->getJson($this->url('productores'))->json('data.data.0.saldo'));
        $this->assertIsArray($this->getJson($this->url('productores/list'))->json('data.0.saldo'));
    }

    public function test_la_ficha_oculta_el_dinero_sin_liquidaciones_view(): void
    {
        $convenio = $this->conSaldo();

        // Ni siquiera abonos.view lo abre: solo liquidaciones.view.
        $this->usuarioCon(['convenios', 'abonos']);
        $res = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk();
        $this->assertArrayHasKey('por_pagar', $res->json('data.indicadores'));
        $this->assertNull($res->json('data.indicadores.por_pagar'));
        $this->assertArrayHasKey('liquidaciones', $res->json('data'));
        $this->assertNull($res->json('data.liquidaciones'));
        $this->assertEqualsWithDelta(100 * 10, $res->json('data.indicadores.valor_recibido'), 0.001);
        $this->assertSame(0, $this->consultasDeDinero("convenios/{$convenio->id}"));
    }

    public function test_la_ficha_muestra_el_dinero_con_liquidaciones_view_y_al_administrador(): void
    {
        $convenio = $this->conSaldo();

        $this->usuarioCon(['convenios', 'liquidaciones']);
        $res = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk();
        $this->assertNotNull($res->json('data.indicadores.por_pagar'));
        $this->assertCount(1, $res->json('data.liquidaciones'));

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $res = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk();
        $this->assertNotNull($res->json('data.indicadores.por_pagar'));
        $this->assertCount(1, $res->json('data.liquidaciones'));
    }

    public function test_sin_liquidaciones_la_ficha_con_permiso_trae_lista_vacia_y_no_null(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->usuarioCon(['convenios', 'liquidaciones']);

        $res = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk();
        $this->assertSame([], $res->json('data.liquidaciones'));
        $this->assertSame(0.0, (float) $res->json('data.indicadores.por_pagar'));
    }

    public function test_el_tablero_muestra_el_saldo_a_quien_solo_ve_el_tablero(): void
    {
        $convenio = $this->conSaldo();
        $this->usuarioCon(['tablero']);

        $data = $this->tablero($convenio->temporada_id);
        $this->assertArrayHasKey('saldo_por_pagar', $data['kpis']);
        $this->assertNotNull($data['kpis']['saldo_por_pagar']);
    }

    // ---------------------------------------------------------------- consultas

    public function test_la_lista_de_productores_no_hace_consultas_por_productor(): void
    {
        $contar = function (string $ruta): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url($ruta))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        for ($i = 0; $i < 2; $i++) {
            $this->conMovimiento("Productor {$i}");
        }
        $paginaConDos = $contar('productores');
        $listaConDos = $contar('productores/list');

        for ($i = 2; $i < 12; $i++) {
            $this->conMovimiento("Productor {$i}");
        }
        $this->assertSame(12, Productor::count());

        $this->assertSame($paginaConDos, $contar('productores'), 'productores: las consultas no deben crecer con los productores.');
        $this->assertSame($listaConDos, $contar('productores/list'), 'productores/list: las consultas no deben crecer con los productores.');
    }

    private function conMovimiento(string $nombre): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['productor_id' => $this->productorNuevo($nombre)->id]);
        $recepcion = $this->crearRecepcion($convenio);
        $this->crearPago($convenio, [], [$recepcion->id => 300]);
        $this->crearPago($convenio, ['tipo' => 'saldo_inicial', 'monto' => 20]);
        $this->liquidacion($convenio, 500);
    }
}
