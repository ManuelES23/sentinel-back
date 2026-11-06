<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\User;
use App\Services\Abastecimiento\IndicadoresConvenio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Indicadores de un convenio (cumplimiento, proyección, valor) con los datos
 * del prototipo aprobado. «Hoy» es el martes 14 de julio de 2026 (semana ISO 29).
 */
class IndicadoresConvenioTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    /**
     * Tope documentado de consultas de GET convenios, sea cual sea el número de convenios.
     * Medido con un administrador: empresa + módulo + temporada + convenios + productor + variedad + precios +
     * tipo de carga + metas + recepciones agrupadas = 10 (igual con 2 o con 10 convenios). Si crece, revisar que no sea por convenio.
     */
    private const TOPE_CONSULTAS_LISTADO = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $hoy = $this->hoy();
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
    }

    private function url(string $ruta = 'convenios', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    /** Metas semanales a partir de un lunes: [lunes => cantidad]. */
    private function metasDesde(string $lunes, array $cantidades): array
    {
        $metas = [];
        foreach ($cantidades as $i => $cantidad) {
            $metas[CarbonImmutable::parse($lunes)->addWeeks($i)->toDateString()] = $cantidad;
        }

        return $metas;
    }

    /** CV-001 del prototipo: semanas 26 a 34, $185 por caja. */
    private function cv001(): Convenio
    {
        $c = $this->crearConvenio($this->sbp, [
            'precio' => 185,
            'fecha_inicio' => '2026-06-22', 'fecha_fin' => '2026-08-23',
            'metas' => $this->metasDesde('2026-06-22', [2000, 2500, 2500, 3000, 3500, 3500, 3000, 2500, 1500]),
        ]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-23', 'cantidad' => 1980]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-30', 'cantidad' => 2210]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-07', 'cantidad' => 2650]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 1300]);

        return Convenio::findOrFail($c->id);
    }

    /** CV-003 del prototipo: semanas 27 a 35, $170 por caja. */
    private function cv003(): Convenio
    {
        $c = $this->crearConvenio($this->sbp, [
            'precio' => 170,
            'fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-08-30',
            'metas' => $this->metasDesde('2026-06-29', [1500, 1500, 2000, 2000, 2000, 2000, 2000, 1500, 500]),
        ]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-30', 'cantidad' => 1180]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-07', 'cantidad' => 920]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 400]);

        return Convenio::findOrFail($c->id);
    }

    // ---- Indicadores de un convenio ----

    public function test_cv001_en_meta(): void
    {
        $i = IndicadoresConvenio::de($this->cv001(), $this->hoy());

        $this->assertEqualsWithDelta(24000, $i['pactado'], 0.001);
        $this->assertEqualsWithDelta(8140, $i['recibido'], 0.001);
        $this->assertEqualsWithDelta(7000, $i['meta_a_la_fecha'], 0.001);
        $this->assertEqualsWithDelta(6840, $i['recibido_a_la_fecha'], 0.001);
        $this->assertEqualsWithDelta(0.977, $i['cumplimiento'], 0.001);
        $this->assertSame('en_meta', $i['estado']);
        $this->assertEqualsWithDelta(8140 / 24000, $i['avance'], 0.001);
        $this->assertSame('2026-07-13', $i['semana_actual']['lunes']);
        $this->assertSame(29, $i['semana_actual']['numero']);
        $this->assertEqualsWithDelta(3000, $i['semana_actual']['meta'], 0.001);
        $this->assertEqualsWithDelta(1300, $i['semana_actual']['recibido'], 0.001);
        $this->assertEqualsWithDelta(4440000, $i['comprometido'], 0.01);
        $this->assertEqualsWithDelta(1505900, $i['valor_recibido'], 0.01);
        // 6840 + (6840 / 3 semanas cerradas) × 6 semanas que faltan (29 a 34).
        $this->assertEqualsWithDelta(20520, $i['proyeccion'], 0.001);
    }

    public function test_semanas_del_convenio(): void
    {
        $i = IndicadoresConvenio::de($this->cv001(), $this->hoy());

        $this->assertCount(9, $i['semanas']);
        $this->assertSame(
            ['lunes' => '2026-06-22', 'numero' => 26, 'meta' => 2000.0, 'recibido' => 1980.0, 'cerrada' => true],
            $i['semanas'][0],
        );
        $this->assertTrue($i['semanas'][2]['cerrada']);
        $this->assertFalse($i['semanas'][3]['cerrada']);
        $this->assertSame('2026-08-17', $i['semanas'][8]['lunes']);
    }

    public function test_cv003_atrasado(): void
    {
        $i = IndicadoresConvenio::de($this->cv003(), $this->hoy());

        $this->assertEqualsWithDelta(3000, $i['meta_a_la_fecha'], 0.001);
        $this->assertEqualsWithDelta(2100, $i['recibido_a_la_fecha'], 0.001);
        $this->assertEqualsWithDelta(0.70, $i['cumplimiento'], 0.001);
        $this->assertSame('atrasado', $i['estado']);
        // 2100 + 1050 (promedio de las 2 semanas cerradas) × 7 semanas que faltan.
        $this->assertEqualsWithDelta(9450, $i['proyeccion'], 0.001);
    }

    public function test_por_iniciar(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => '2026-07-20', 'fecha_fin' => '2026-08-09',
            'metas' => $this->metasDesde('2026-07-20', [100, 100, 100]),
        ]);

        $i = IndicadoresConvenio::de($c, $this->hoy());

        $this->assertSame('por_iniciar', $i['estado']);
        $this->assertNull($i['cumplimiento']);
        $this->assertEqualsWithDelta(0, $i['recibido'], 0.001);
    }

    public function test_borrador_con_inicio_pasado_sigue_por_iniciar_y_suspendido_se_calcula(): void
    {
        // Mismo avance que CV-003 (70 %, atrasado) pero aún en borrador: no opera todavía.
        $borrador = $this->cv003();
        $borrador->update(['status' => 'borrador']);
        $i = IndicadoresConvenio::de($borrador->fresh(), $this->hoy());
        $this->assertSame('por_iniciar', $i['estado']);
        $this->assertNull($i['cumplimiento']); // un borrador no tiene cumplimiento

        // Un borrador ya vencido también es «por iniciar».
        $vencido = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28']);
        $this->assertSame('por_iniciar', IndicadoresConvenio::de($vencido, $this->hoy())['estado']);

        // Suspendido sigue calculándose con los umbrales.
        $suspendido = $this->cv003();
        $suspendido->update(['status' => 'suspendido']);
        $this->assertSame('atrasado', IndicadoresConvenio::de($suspendido->fresh(), $this->hoy())['estado']);
    }

    public function test_sin_semanas_cerradas_cumplimiento_null(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => '2026-07-13', 'fecha_fin' => '2026-07-26',
            'metas' => $this->metasDesde('2026-07-13', [100, 100]),
        ]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 50]);

        $i = IndicadoresConvenio::de(Convenio::findOrFail($c->id), $this->hoy());

        $this->assertNull($i['cumplimiento']);
        $this->assertSame('en_meta', $i['estado']);
        $this->assertEqualsWithDelta(0, $i['meta_a_la_fecha'], 0.001);
        $this->assertEqualsWithDelta(50, $i['proyeccion'], 0.001);
    }

    public function test_estado_finalizado_cancelado_y_fuera_de_fechas(): void
    {
        $vencido = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28',
        ]);
        $this->assertSame('finalizado', IndicadoresConvenio::de($vencido, $this->hoy())['estado']);

        $cancelado = $this->cv003();
        $cancelado->update(['status' => 'cancelado']);
        $this->assertSame('cancelado', IndicadoresConvenio::de($cancelado->fresh(), $this->hoy())['estado']);

        $cerrado = $this->cv001();
        $cerrado->update(['status' => 'finalizado']);
        $this->assertSame('finalizado', IndicadoresConvenio::de($cerrado->fresh(), $this->hoy())['estado']);
    }

    public function test_kilos_cuentan_cuando_el_convenio_es_por_kilo(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'unidad' => 'kg', 'precio' => 10,
            'fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-07-12',
            'metas' => $this->metasDesde('2026-06-29', [1000, 1000]),
        ]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-30', 'cantidad' => 20, 'kilos' => 850.5]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-07', 'cantidad' => 30, 'kilos' => 1100]);

        $i = IndicadoresConvenio::de(Convenio::findOrFail($c->id), $this->hoy());

        $this->assertEqualsWithDelta(1950.5, $i['recibido'], 0.001);
        $this->assertEqualsWithDelta(0.975, $i['cumplimiento'], 0.001);
        $this->assertEqualsWithDelta(19505, $i['valor_recibido'], 0.01);
    }

    public function test_valor_recibido_usa_precio_vigente_de_cada_recepcion(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'precio' => 185,
            'fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-07-19',
            'metas' => $this->metasDesde('2026-06-29', [1000, 1000, 1000]),
        ]);
        ConvenioPrecio::create([
            'convenio_id' => $c->id,
            'tipo_carga_id' => $c->precios->first()->tipo_carga_id,
            'precio' => 190,
            'vigencia_inicio' => '2026-07-06',
            'es_principal' => true,
        ]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-05', 'cantidad' => 100]); // domingo: 185
        $this->crearRecepcion($c, ['fecha' => '2026-07-06', 'cantidad' => 100]); // lunes: 190

        $i = IndicadoresConvenio::de(Convenio::findOrFail($c->id), $this->hoy());

        $this->assertEqualsWithDelta(100 * 185 + 100 * 190, $i['valor_recibido'], 0.01);
        // Lo comprometido usa el precio principal vigente al inicio del convenio.
        $this->assertEqualsWithDelta(3000 * 185, $i['comprometido'], 0.01);
    }

    public function test_consignacion_aplica_porcentaje(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'modalidad' => 'consignacion', 'precio' => 150,
            'fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-07-12',
            'metas' => $this->metasDesde('2026-06-29', [200, 200]),
        ]);
        $c->precios->first()->update(['porcentaje_productor' => 65]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-30', 'cantidad' => 100]);

        $i = IndicadoresConvenio::de(Convenio::findOrFail($c->id), $this->hoy());

        $this->assertEqualsWithDelta(400 * 97.5, $i['comprometido'], 0.01);
        $this->assertEqualsWithDelta(100 * 97.5, $i['valor_recibido'], 0.01);
    }

    public function test_agregado_pondera_por_valor(): void
    {
        $a = $this->cv001();
        $b = $this->cv003();

        $g = IndicadoresConvenio::agregado(collect([$a, $b]), $this->hoy());

        // (6840 × 185 + 2100 × 170) / (7000 × 185 + 3000 × 170) = 1,622,400 / 1,805,000
        $this->assertEqualsWithDelta(1622400 / 1805000, $g['cumplimiento'], 0.0001);
        $this->assertEqualsWithDelta(4440000 + 2550000, $g['comprometido'], 0.01);
        $this->assertEqualsWithDelta(1505900 + 2500 * 170, $g['valor_recibido'], 0.01);
        $this->assertSame(1, $g['abajo_de_meta']);
        $this->assertSame(2, $g['con_avance']);
    }

    public function test_agregado_no_mezcla_monedas_y_vacio_no_tiene_cumplimiento(): void
    {
        $mxn = $this->cv001();
        $usd = $this->crearConvenio($this->sbp, ['moneda' => 'USD']);

        try {
            IndicadoresConvenio::agregado(collect([$mxn, $usd]), $this->hoy());
            $this->fail('Debió rechazar monedas distintas.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('moneda', $e->getMessage());
        }

        $vacio = IndicadoresConvenio::agregado(collect(), $this->hoy());
        $this->assertNull($vacio['cumplimiento']);
        $this->assertSame(0, $vacio['con_avance']);
    }

    // ---- API ----

    public function test_listado_trae_indicadores_sin_semanas(): void
    {
        $this->cv001();
        $this->cv003();
        $temporada = Convenio::first()->temporada_id;

        $res = $this->getJson($this->url('convenios')."?temporada_id={$temporada}")->assertOk();

        $res->assertJsonPath('success', true);
        $this->assertCount(2, $res->json('data'));
        $primero = $res->json('data.0');
        $this->assertSame('en_meta', $primero['indicadores']['estado']);
        $this->assertArrayNotHasKey('semanas', $primero['indicadores']);
        $this->assertEqualsWithDelta(0.977, $primero['indicadores']['cumplimiento'], 0.001);
        $this->assertSame('Productor de prueba', $primero['productor']['nombre']);
        $this->assertArrayHasKey('nombre', $primero['variedad']);
        $this->assertEqualsWithDelta(185, $primero['precio_principal']['precio'], 0.001);
        $this->assertArrayHasKey('tipo_carga', $primero['precio_principal']);
    }

    public function test_listado_exige_temporada_y_filtra(): void
    {
        $a = $this->cv001();
        $b = $this->cv003();
        $b->update(['status' => 'suspendido']);

        $this->getJson($this->url('convenios'))->assertStatus(422)->assertJsonValidationErrors('temporada_id');

        $t = $a->temporada_id;
        $porStatus = $this->getJson($this->url('convenios')."?temporada_id={$t}&status=suspendido")->assertOk();
        $this->assertSame([$b->id], array_column($porStatus->json('data'), 'id'));

        $porEstado = $this->getJson($this->url('convenios')."?temporada_id={$t}&estado=atrasado")->assertOk();
        $this->assertSame([$b->id], array_column($porEstado->json('data'), 'id'));

        $porBusqueda = $this->getJson($this->url('convenios')."?temporada_id={$t}&search=".$a->folio)->assertOk();
        $this->assertSame([$a->id], array_column($porBusqueda->json('data'), 'id'));

        $this->getJson($this->url('convenios')."?temporada_id={$t}&status=inventado")->assertStatus(422);
    }

    public function test_listado_no_hace_consultas_por_convenio(): void
    {
        $this->cv001();
        $this->cv003();
        $temporada = Convenio::first()->temporada_id;

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url('convenios')."?temporada_id={$temporada}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con2 = $contar();

        for ($i = 0; $i < 8; $i++) {
            $c = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-07-26']);
            $this->crearRecepcion($c, ['fecha' => '2026-07-01', 'cantidad' => 10]);
            $this->crearRecepcion($c, ['fecha' => '2026-07-08', 'cantidad' => 20]);
        }
        $this->assertSame(10, Convenio::count());

        $con10 = $contar();

        $this->assertSame($con2, $con10, 'El número de consultas no debe crecer con los convenios.');
        $this->assertLessThanOrEqual(self::TOPE_CONSULTAS_LISTADO, $con10);
    }

    public function test_ficha_trae_semanas_precios_metas_y_diez_recepciones(): void
    {
        $c = $this->cv001();
        for ($d = 1; $d <= 12; $d++) {
            $this->crearRecepcion($c, ['fecha' => sprintf('2026-07-%02d', $d), 'cantidad' => 1]);
        }

        $res = $this->getJson($this->url("convenios/{$c->id}"))->assertOk();

        $data = $res->json('data');
        $this->assertSame($c->id, $data['id']);
        $this->assertCount(9, $data['indicadores']['semanas']);
        $this->assertCount(9, $data['metas']);
        $this->assertSame('tipo_carga', array_keys(array_intersect_key($data['precios'][0], ['tipo_carga' => 1]))[0]);
        $this->assertCount(10, $data['recepciones']);
        // Las más recientes primero: la del 14 de julio y luego la del 12.
        $this->assertSame('2026-07-14', $data['recepciones'][0]['fecha']);
        $this->assertSame('2026-07-12', $data['recepciones'][1]['fecha']);
        $this->assertEqualsWithDelta(8140 + 12, $data['indicadores']['recibido'], 0.001);
    }

    public function test_ficha_y_listado_respetan_empresa_y_permiso(): void
    {
        $ajeno = $this->crearConvenio($this->otra);
        $this->getJson($this->url("convenios/{$ajeno->id}"))->assertNotFound();
        $this->getJson($this->url("convenios/{$ajeno->id}", 'otraempresa'))->assertOk();

        $propio = $this->crearConvenio($this->sbp);
        $this->getJson($this->url("convenios/{$propio->id}", 'otraempresa'))->assertNotFound();

        Sanctum::actingAs($this->captor);
        $this->getJson($this->url("convenios/{$propio->id}"))->assertForbidden();
        $this->getJson($this->url('convenios')."?temporada_id={$propio->temporada_id}")->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view']);
        $this->getJson($this->url("convenios/{$propio->id}"))->assertOk();
        // Una temporada de otra empresa no es válida para el listado.
        $this->getJson($this->url('convenios')."?temporada_id={$ajeno->temporada_id}")->assertStatus(422);
    }
}
