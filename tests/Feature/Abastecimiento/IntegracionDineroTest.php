<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Visibilidad del dinero (solo con `liquidaciones.view`; el administrador siempre) y forma de la ficha del convenio.
 * Las pruebas de «una sola cifra» del saldo (tablero, ficha, productores) las reescribe la cuenta por convenio
 * sobre su propio flujo de pagos.
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
        $n = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/abs_(liquidaciones|abonos|abono_aplicaciones)/', $q['query']))->count();
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

    public function test_productores_oculta_el_saldo_sin_abonos_ni_liquidaciones_view(): void
    {
        $this->conSaldo();
        $this->usuarioCon(['productores']);

        foreach (['productores' => 'data.data.0', 'productores/list' => 'data.0'] as $ruta => $fila) {
            $res = $this->getJson($this->url($ruta))->assertOk();
            $this->assertArrayHasKey('saldo', $res->json($fila), $ruta);
            $this->assertNull($res->json("{$fila}.saldo"), $ruta);
            $this->assertSame(0, $this->consultasDeDinero($ruta), "{$ruta}: no debe consultar el dinero.");
        }
    }

    public function test_productores_muestra_el_saldo_con_abonos_view_o_con_liquidaciones_view(): void
    {
        $this->conSaldo();

        foreach (['abonos', 'liquidaciones'] as $submodulo) {
            $this->usuarioCon(['productores', $submodulo]);

            foreach (['productores' => 'data.data.0', 'productores/list' => 'data.0'] as $ruta => $fila) {
                $saldo = $this->getJson($this->url($ruta))->assertOk()->json("{$fila}.saldo");
                $this->assertIsArray($saldo, "{$submodulo} / {$ruta}");
            }
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
        $this->crearRecepcion($convenio);
        $this->liquidacion($convenio, 500);
    }
}
