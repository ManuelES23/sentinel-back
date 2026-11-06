<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\User;
use App\Services\Abastecimiento\AplicadorAbonos;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Plan 2, Task 11: el saldo por pagar sale de UNA sola definición (`SaldoProductor`) y aparece igual en el
 * tablero, la ficha del convenio, la lista de productores y Abonos. Anti-objetivo: «dos números distintos».
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

    private function liquidacion(Convenio $convenio, float $monto, string $status = 'aprobada'): Liquidacion
    {
        return $this->crearLiquidacion($convenio, [
            'status' => $status, 'monto_calculado' => $monto, 'monto_final' => $monto,
            'aprobada_at' => in_array($status, ['aprobada', 'pagada'], true) ? now() : null,
        ]);
    }

    private function abonar(Liquidacion $liq, float $monto): Abono
    {
        $abono = $this->crearAbono(Productor::findOrFail($liq->productor_id), ['monto' => $monto, 'moneda' => $liq->moneda]);
        DB::transaction(fn () => app(AplicadorAbonos::class)->aplicar($abono, [['liquidacion_id' => $liq->id, 'monto' => $monto]]));

        return $abono;
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

    // ---------------------------------------------------------------- una sola cifra

    public function test_tablero_ficha_productores_y_abonos_dan_la_misma_cifra(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->liquidacion($convenio, 1000);
        $this->abonar($liq, 400);

        $tablero = $this->tablero($convenio->temporada_id);
        $this->assertEqualsWithDelta(600, $tablero['kpis']['saldo_por_pagar'], 0.001);
        $this->assertArrayHasKey('sin_liquidar', $tablero['kpis']);

        $ficha = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk()->json('data');
        $this->assertEqualsWithDelta(600, $ficha['indicadores']['por_pagar'], 0.001);

        $lista = $this->getJson($this->url('productores'))->assertOk()->json('data.data');
        $this->assertEqualsWithDelta(600, $this->porMoneda($lista[0]['saldo'], 'MXN')['por_pagar'], 0.001);

        $selector = $this->getJson($this->url('productores/list'))->assertOk()->json('data');
        $this->assertEqualsWithDelta(600, $this->porMoneda($selector[0]['saldo'], 'MXN')['por_pagar'], 0.001);

        $abonos = $this->getJson($this->url('abonos/productores'))->assertOk()->json('data');
        $this->assertEqualsWithDelta(600, $this->porMoneda($abonos[0]['saldos'], 'MXN')['por_pagar'], 0.001);
    }

    public function test_un_convenio_de_otra_temporada_cambia_al_productor_pero_no_al_tablero(): void
    {
        $uno = $this->crearConvenio($this->sbp);
        $this->abonar($this->liquidacion($uno, 1000), 400);

        $dos = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->otraTemporada($uno)->id, 'productor_id' => $uno->productor_id,
            'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-30',
            'metas' => ['2026-10-05' => 100],
        ]);
        $this->liquidacion($dos, 250);

        // Tablero de la primera temporada: solo 600; el de la segunda: solo 250.
        $this->assertEqualsWithDelta(600, $this->tablero($uno->temporada_id)['kpis']['saldo_por_pagar'], 0.001);
        $this->assertEqualsWithDelta(250, $this->tablero($dos->temporada_id)['kpis']['saldo_por_pagar'], 0.001);

        // El productor suma las dos; las fichas, cada una lo suyo.
        $fila = $this->getJson($this->url('productores'))->assertOk()->json('data.data.0');
        $this->assertEqualsWithDelta(850, $this->porMoneda($fila['saldo'], 'MXN')['por_pagar'], 0.001);
        $abonos = $this->getJson($this->url('abonos/productores'))->assertOk()->json('data');
        $this->assertEqualsWithDelta(850, $this->porMoneda($abonos[0]['saldos'], 'MXN')['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(600, $this->getJson($this->url("convenios/{$uno->id}"))->json('data.indicadores.por_pagar'), 0.001);
        $this->assertEqualsWithDelta(250, $this->getJson($this->url("convenios/{$dos->id}"))->json('data.indicadores.por_pagar'), 0.001);
    }

    public function test_el_tablero_no_mezcla_monedas(): void
    {
        $mxn = $this->crearConvenio($this->sbp);
        $this->abonar($this->liquidacion($mxn, 1000), 400);
        $usd = $this->crearConvenio($this->sbp, [
            'moneda' => 'USD', 'productor_id' => $this->productorNuevo('Exportador USD')->id,
        ]);
        $this->liquidacion($usd, 500);

        $this->assertEqualsWithDelta(600, $this->tablero($mxn->temporada_id, '&moneda=MXN')['kpis']['saldo_por_pagar'], 0.001);
        $en_usd = $this->tablero($mxn->temporada_id, '&moneda=USD');
        $this->assertSame('USD', $en_usd['moneda']);
        $this->assertEqualsWithDelta(500, $en_usd['kpis']['saldo_por_pagar'], 0.001);

        // Un productor con convenios en las dos monedas ve una fila por moneda.
        $mixto = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'productor_id' => $mxn->productor_id]);
        $this->liquidacion($mixto, 70);
        $fila = collect($this->getJson($this->url('productores'))->json('data.data'))->firstWhere('id', $mxn->productor_id);
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

    // ---------------------------------------------------------------- aviso

    public function test_el_aviso_cuenta_solo_las_liquidaciones_en_revision_de_la_temporada_y_moneda(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->liquidacion($convenio, 100, 'en_revision');
        $this->liquidacion($convenio, 100, 'en_revision');
        $this->liquidacion($convenio, 100, 'borrador');
        $this->liquidacion($convenio, 100, 'aprobada');
        $this->liquidacion($convenio, 100, 'cancelada');
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'productor_id' => $this->productorNuevo('USD')->id]);
        $this->liquidacion($usd, 100, 'en_revision');
        $otra = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->otraTemporada($convenio)->id, 'productor_id' => $convenio->productor_id,
            'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-30', 'metas' => ['2026-10-05' => 100],
        ]);
        $this->liquidacion($otra, 100, 'en_revision');

        $textos = fn (array $d) => collect($d['avisos'])->where('tipo', 'liquidaciones_en_revision')->pluck('texto')->all();

        $this->assertSame(['2 liquidaciones esperando revisión'], $textos($this->tablero($convenio->temporada_id, '&moneda=MXN')));
        $this->assertSame(['1 liquidación esperando revisión'], $textos($this->tablero($convenio->temporada_id, '&moneda=USD')));
        $this->assertSame(['1 liquidación esperando revisión'], $textos($this->tablero($otra->temporada_id)));
    }

    public function test_sin_liquidaciones_en_revision_no_hay_aviso(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->liquidacion($convenio, 100, 'aprobada');

        $this->assertSame([], collect($this->tablero($convenio->temporada_id)['avisos'])->where('tipo', 'liquidaciones_en_revision')->all());
    }

    // ---------------------------------------------------------------- ficha del convenio

    public function test_la_ficha_trae_el_resumen_de_liquidaciones_y_conserva_valor_recibido(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio, ['cantidad' => 10]);
        $vieja = $this->liquidacion($convenio, 300, 'cancelada');
        $nueva = $this->liquidacion($convenio, 1000, 'aprobada');

        $data = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk()->json('data');

        $this->assertArrayHasKey('valor_recibido', $data['indicadores']);
        $this->assertEqualsWithDelta(1000, $data['indicadores']['valor_recibido'], 0.001);
        $this->assertSame([$nueva->id, $vieja->id], array_column($data['liquidaciones'], 'id'));
        $this->assertSame(
            ['id', 'folio', 'status', 'periodo_inicio', 'periodo_fin', 'monto_final', 'moneda'],
            array_keys($data['liquidaciones'][0]),
        );
        $this->assertSame('cancelada', $data['liquidaciones'][1]['status']);
        // La cancelada no entra al por pagar.
        $this->assertEqualsWithDelta(1000, $data['indicadores']['por_pagar'], 0.001);
    }

    public function test_de_convenio_coincide_con_el_productor_cuando_tiene_un_solo_convenio(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->abonar($this->liquidacion($convenio, 1000), 400);
        $this->liquidacion($convenio, 80, 'pagada');
        $this->liquidacion($convenio, 999, 'en_revision');

        $propio = SaldoProductor::deConvenio($convenio);
        $productor = SaldoProductor::porProductores($this->sbp, [$convenio->productor_id])->get($convenio->productor_id)['MXN'];

        $this->assertSame('MXN', $propio['moneda']);
        $this->assertSame($productor['liquidado'], $propio['liquidado']);
        $this->assertSame($productor['aplicado'], $propio['aplicado']);
        $this->assertSame($productor['por_pagar'], $propio['por_pagar']);
        $this->assertSame(680.0, $propio['por_pagar']);
    }

    public function test_por_pagar_de_temporada_coincide_con_la_suma_de_los_productores(): void
    {
        $uno = $this->crearConvenio($this->sbp);
        $this->abonar($this->liquidacion($uno, 1000), 400);
        $dos = $this->crearConvenio($this->sbp, ['productor_id' => $this->productorNuevo('Segundo')->id]);
        $this->liquidacion($dos, 250);
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD', 'productor_id' => $this->productorNuevo('Tercero')->id]);
        $this->liquidacion($usd, 90);

        $porProductor = SaldoProductor::porProductores($this->sbp, null, $uno->temporada_id)
            ->flatMap(fn (array $monedas) => array_values($monedas))
            ->groupBy('moneda')->map(fn ($filas) => round($filas->sum('por_pagar'), 2))->sortKeys()->all();

        $this->assertSame($porProductor, SaldoProductor::porPagarDeTemporada($this->sbp, $uno->temporada_id));
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
        $this->abonar($this->liquidacion($convenio, 500), 100);
    }
}
