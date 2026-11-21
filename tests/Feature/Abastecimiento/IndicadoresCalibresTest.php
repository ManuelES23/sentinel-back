<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Variedad;
use App\Models\CRM\CrmProductoCalibre;
use App\Models\User;
use App\Services\Abastecimiento\CuentaConvenio;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\SaldoProductor;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Indicadores y tablero con lo recibido por calibre (plan 2 de calibres, B4): las líneas de un
 * convenio por calibre salen de los renglones y se valoran con el precio de su calibre.
 * «Hoy» es el martes 14 de julio de 2026; los convenios van del 1 al 28 de junio.
 */
class IndicadoresCalibresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private CrmProductoCalibre $jumbo;

    private CrmProductoCalibre $grande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);

        // La variedad que usan por omisión los convenios de prueba.
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $this->jumbo = $this->crearCalibre($variedad, 'Jumbo');
        $this->grande = $this->crearCalibre($variedad, 'Grande');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Convenio por calibre: Jumbo (pactado 100, $10) y Grande (pactado 50, $20). */
    private function porCalibre(array $over = [], ?float $precioGrande = 20): Convenio
    {
        return $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->jumbo, 'volumen' => 100, 'precio' => 10],
            ['calibre' => $this->grande, 'volumen' => 50, 'precio' => $precioGrande],
        ], $over);
    }

    private function renglon(CrmProductoCalibre $calibre, float $cantidad, ?float $kilos = null): array
    {
        return ['calibre_id' => $calibre->id, 'cantidad' => $cantidad, 'kilos' => $kilos];
    }

    /** Líneas ordenadas para comparar sin depender del orden de la consulta. */
    private function ordenar($lineas): array
    {
        return collect($lineas)->sortBy([['fecha', 'asc'], ['calibre_id', 'asc']])->values()->all();
    }

    private function tableroUrl(int $temporadaId): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/tablero?temporada_id={$temporadaId}";
    }

    public function test_lineas_de_convenio_clasico_no_cambian(): void
    {
        $clasico = $this->crearConvenio($this->sbp);
        $tipo = $clasico->precios->first()->tipo_carga_id;
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-01', 'cantidad' => 10]);
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-01', 'cantidad' => 5]);
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-08', 'cantidad' => 20]);
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-08', 'cantidad' => 99])->delete();
        // Un convenio por calibre en la misma colección no altera las líneas del clásico.
        $calibre = $this->porCalibre();
        $this->crearRecepcionConRenglones($calibre, [$this->renglon($this->jumbo, 7)], ['fecha' => '2026-06-01']);

        $lineas = IndicadoresConvenio::lineas(collect([$clasico, $calibre]))->get($clasico->id);

        $this->assertEquals([
            ['fecha' => '2026-06-01', 'tipo_carga_id' => $tipo, 'calibre_id' => null, 'cantidad' => 15.0],
            ['fecha' => '2026-06-08', 'tipo_carga_id' => $tipo, 'calibre_id' => null, 'cantidad' => 20.0],
        ], $this->ordenar($lineas));
        foreach ($lineas as $linea) {
            $this->assertArrayHasKey('calibre_id', $linea);
            $this->assertNull($linea['calibre_id']);
        }

        // Y sus indicadores siguen siendo los de siempre: 35 cajas × $100.
        $i = IndicadoresConvenio::de($clasico->fresh(['precios', 'metas']));
        $this->assertEquals(35, $i['recibido']);
        $this->assertEquals(3500, $i['valor_recibido']);
        $this->assertSame([], $i['calibres']);
    }

    public function test_lineas_por_calibre_agrupan_por_calibre(): void
    {
        $convenio = $this->porCalibre();
        $tipo = $convenio->precios->first()->tipo_carga_id;
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 10), $this->renglon($this->grande, 5)], ['fecha' => '2026-06-01']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 3)], ['fecha' => '2026-06-01']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->grande, 7)], ['fecha' => '2026-06-08']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->grande, 500)], ['fecha' => '2026-06-08'])->delete();

        // Por kilo: suma los kilos de los renglones.
        $kg = $this->porCalibre(['unidad' => 'kg']);
        $this->crearRecepcionConRenglones($kg, [$this->renglon($this->jumbo, 1, 250.5), $this->renglon($this->grande, 2, 100)], ['fecha' => '2026-06-02']);

        $lineas = IndicadoresConvenio::lineas(collect([$convenio, $kg]));

        $this->assertEquals($this->ordenar([
            ['fecha' => '2026-06-01', 'tipo_carga_id' => $tipo, 'calibre_id' => $this->jumbo->id, 'cantidad' => 13.0],
            ['fecha' => '2026-06-01', 'tipo_carga_id' => $tipo, 'calibre_id' => $this->grande->id, 'cantidad' => 5.0],
            ['fecha' => '2026-06-08', 'tipo_carga_id' => $tipo, 'calibre_id' => $this->grande->id, 'cantidad' => 7.0],
        ]), $this->ordenar($lineas->get($convenio->id)));
        $this->assertEquals($this->ordenar([
            ['fecha' => '2026-06-02', 'tipo_carga_id' => $tipo, 'calibre_id' => $this->jumbo->id, 'cantidad' => 250.5],
            ['fecha' => '2026-06-02', 'tipo_carga_id' => $tipo, 'calibre_id' => $this->grande->id, 'cantidad' => 100.0],
        ]), $this->ordenar($lineas->get($kg->id)));
    }

    public function test_valor_recibido_suma_por_calibre_con_precios_distintos(): void
    {
        $convenio = $this->porCalibre();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 10), $this->renglon($this->grande, 5)], ['fecha' => '2026-06-01']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 3), $this->renglon($this->grande, 7)], ['fecha' => '2026-06-08']);

        $i = IndicadoresConvenio::de($convenio);

        // Jumbo 13 × $10 + Grande 12 × $20 = 130 + 240.
        $this->assertEquals(25, $i['recibido']);
        $this->assertEquals(370, $i['valor_recibido']);
        $this->assertEquals(370, IndicadoresConvenio::valorDe($convenio, IndicadoresConvenio::lineas(collect([$convenio]))->get($convenio->id)));
        $this->assertEquals(370, IndicadoresConvenio::agregado(collect([$convenio]))['valor_recibido']);
    }

    public function test_recibido_y_avance_por_calibre(): void
    {
        $convenio = $this->porCalibre();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 25), $this->renglon($this->grande, 50)], ['fecha' => '2026-06-01']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 15)], ['fecha' => '2026-06-15']);

        $i = IndicadoresConvenio::de($convenio);
        $porCalibre = collect($i['calibres'])->keyBy('calibre_id');

        $this->assertEquals([
            'calibre_id' => $this->jumbo->id, 'nombre' => 'Jumbo', 'pactado' => 100, 'comprometido' => 1000,
            'recibido' => 40, 'avance' => 0.4, 'valor_recibido' => 400,
        ], $porCalibre[$this->jumbo->id]);
        $this->assertEquals(50, $porCalibre[$this->grande->id]['recibido']);
        $this->assertEquals(1.0, $porCalibre[$this->grande->id]['avance']);
        $this->assertEquals(1000, $porCalibre[$this->grande->id]['valor_recibido']);
        // Los totales del convenio son la suma de sus renglones.
        $this->assertEquals(90, $i['recibido']);
        $this->assertEquals(150, $i['pactado']);
        $this->assertEquals(0.6, $i['avance']);
        $this->assertEquals(1400, $i['valor_recibido']);
    }

    public function test_comprometido_es_null_si_falta_el_precio_principal_del_calibre(): void
    {
        $convenio = $this->porCalibre();
        ConvenioPrecio::where('convenio_id', $convenio->id)->where('calibre_id', $this->grande->id)->delete();
        $convenio = $convenio->fresh(['precios', 'metas', 'calibres']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->grande, 5)], ['fecha' => '2026-06-01']);

        $i = IndicadoresConvenio::de($convenio);
        $porCalibre = collect($i['calibres'])->keyBy('calibre_id');

        $this->assertNull($porCalibre[$this->grande->id]['comprometido']);
        $this->assertEquals(1000, $porCalibre[$this->jumbo->id]['comprometido']);
        $this->assertEquals(1000, $i['comprometido']);
        // Sin precio no vale nada, pero sí cuenta como recibido.
        $this->assertEquals(5, $porCalibre[$this->grande->id]['recibido']);
        $this->assertEquals(0, $porCalibre[$this->grande->id]['valor_recibido']);
    }

    public function test_la_cuenta_valora_por_renglon(): void
    {
        $convenio = $this->porCalibre();
        $mixta = $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 2), $this->renglon($this->grande, 3)], ['fecha' => '2026-06-01']);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 100)], ['fecha' => '2026-06-02']);

        // Jumbo 2 × $10 + Grande 3 × $20 = 80 la primera; Jumbo 100 × $10 = 1000 la segunda.
        $detalle = array_column(CuentaConvenio::recepciones($convenio->fresh()), 'valor', 'id');
        $this->assertSame(80.0, $detalle[$mixta->id]);

        $saldo = SaldoProductor::porProductores($this->sbp, [$convenio->productor_id])->get($convenio->productor_id);
        $this->assertSame(1080.0, $saldo['MXN']['valor_recibido']);
        $this->assertSame(1080.0, $saldo['MXN']['por_pagar']);
    }

    public function test_tablero_con_convenios_por_calibre_tiene_consultas_constantes(): void
    {
        $primero = $this->porCalibre();
        $this->crearRecepcionConRenglones($primero, [$this->renglon($this->jumbo, 10), $this->renglon($this->grande, 5)], ['fecha' => '2026-06-01']);
        $temporada = $primero->temporada_id;

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->tableroUrl($temporada))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con1 = $contar();

        for ($i = 0; $i < 4; $i++) {
            $productor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => "Productor {$i}", 'is_active' => true]);
            $extra = $this->porCalibre(['productor_id' => $productor->id]);
            $this->crearRecepcionConRenglones($extra, [$this->renglon($this->jumbo, 4)], ['fecha' => '2026-06-08']);
            $this->crearRecepcionConRenglones($extra, [$this->renglon($this->grande, 2)], ['fecha' => '2026-06-09', 'liquidacion_id' => $this->crearLiquidacion($extra)->id]);
        }
        $this->assertSame(5, Convenio::count());

        $con5 = $contar();

        $this->assertSame($con1, $con5, 'El número de consultas no debe crecer con los convenios por calibre.');
    }

    public function test_excel_con_convenios_por_calibre_tiene_consultas_constantes(): void
    {
        $primero = $this->porCalibre();
        $this->crearRecepcionConRenglones($primero, [$this->renglon($this->jumbo, 10), $this->renglon($this->grande, 5)], ['fecha' => '2026-06-01']);
        $temporada = $primero->temporada_id;

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/api/splendidbyporvenir/administration/abastecimiento/tablero/exportar?temporada_id={$temporada}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con1 = $contar();

        for ($i = 0; $i < 4; $i++) {
            $productor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => "Productor {$i}", 'is_active' => true]);
            $extra = $this->porCalibre(['productor_id' => $productor->id]);
            $this->crearRecepcionConRenglones($extra, [$this->renglon($this->jumbo, 4)], ['fecha' => '2026-06-08']);
            $this->crearRecepcionConRenglones($extra, [$this->renglon($this->grande, 2)], ['fecha' => '2026-06-09']);
        }
        $this->assertSame(5, Convenio::count());

        $con5 = $contar();

        $this->assertSame($con1, $con5, 'El número de consultas del Excel no debe crecer con los convenios por calibre.');
    }

    public function test_tablero_y_excel_responden_200_con_un_convenio_por_calibre_con_recepciones(): void
    {
        $convenio = $this->porCalibre();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 40), $this->renglon($this->grande, 50)], ['fecha' => '2026-06-01']);

        $data = $this->getJson($this->tableroUrl($convenio->temporada_id))->assertOk()->json('data');

        $fila = collect($data['convenios'])->firstWhere('id', $convenio->id);
        $this->assertArrayHasKey('precio_principal', $fila);
        $this->assertNull($fila['precio_principal']);
        $this->assertEquals(90, $fila['indicadores']['recibido']);
        $this->assertEquals(1400, $fila['indicadores']['valor_recibido']);
        $porCalibre = collect($fila['indicadores']['calibres'])->keyBy('calibre_id');
        $this->assertEquals(40, $porCalibre[$this->jumbo->id]['recibido']);
        $this->assertEquals(0.4, $porCalibre[$this->jumbo->id]['avance']);
        $this->assertEquals(1000, $porCalibre[$this->grande->id]['valor_recibido']);
        // 40 × $10 + 50 × $20, todo sin liquidar.
        $this->assertEquals(1400, $data['kpis']['valor_recibido']);
        $this->assertEquals(1400, $data['kpis']['sin_liquidar']);
        $this->assertEquals(2000, $data['kpis']['comprometido']);

        $this->get("/api/splendidbyporvenir/administration/abastecimiento/tablero/exportar?temporada_id={$convenio->temporada_id}")->assertOk();
    }

    /**
     * Escenario del total propio: caja, 4 semanas (1 al 28 de junio), total 7000, apartados Jumbo 1000 y
     * Grande 1000, un tercer calibre sin apartado; precio $100 por calibre y $90 general (salvo `$general` nulo).
     *
     * @return array{0: Convenio, 1: CrmProductoCalibre}
     */
    private function conTotal(array $over = [], ?float $general = 90, ?float $apartadoA = 1000, ?float $apartadoB = 1000): array
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $chico = CrmProductoCalibre::where('nombre', 'Chico')->first() ?? $this->crearCalibre($variedad, 'Chico');

        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->jumbo, 'volumen' => $apartadoA, 'precio' => 100],
            ['calibre' => $this->grande, 'volumen' => $apartadoB, 'precio' => 100],
            ['calibre' => $chico, 'volumen' => null, 'precio' => 100],
        ], array_merge(['volumen_total' => 7000], $over));

        if ($general !== null) {
            ConvenioPrecio::create([
                'convenio_id' => $convenio->id,
                'tipo_carga_id' => $convenio->precios->first()->tipo_carga_id,
                'calibre_id' => null,
                'precio' => $general,
                'vigencia_inicio' => $convenio->fecha_inicio->format('Y-m-d'),
                'es_principal' => true,
            ]);
        }

        return [$convenio->fresh(['precios', 'metas', 'calibres']), $chico];
    }

    public function test_total_propio_sin_recepciones_mide_contra_el_total_y_calcula_el_libre(): void
    {
        [$convenio, $chico] = $this->conTotal();

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(7000, $i['pactado']);
        $this->assertEquals(0, $i['recibido']);
        $this->assertEquals([
            'total' => 7000, 'apartado' => 2000, 'libre' => 5000, 'libre_usado' => 0,
            'libre_restante' => 5000, 'comprometido' => 450000,
        ], $i['libre']);
        // 1000 × 100 + 1000 × 100 + 5000 × 90.
        $this->assertEquals(650000, $i['comprometido']);

        $porCalibre = collect($i['calibres'])->keyBy('calibre_id');
        $this->assertCount(3, $porCalibre);
        $this->assertEquals(1000, $porCalibre[$this->jumbo->id]['pactado']);
        $this->assertEquals(100000, $porCalibre[$this->jumbo->id]['comprometido']);
        $this->assertEquals(0, $porCalibre[$chico->id]['pactado']);
        $this->assertEquals(0, $porCalibre[$chico->id]['comprometido']);
        $this->assertNull($porCalibre[$chico->id]['avance']);

        // El agregado (ponderado entre convenios) suma lo comprometido.
        $g = IndicadoresConvenio::agregado(collect([$convenio]));
        $this->assertEquals(650000, $g['comprometido']);
    }

    public function test_total_propio_con_recepciones_consume_libre_por_el_exceso_y_por_calibres_sin_apartado(): void
    {
        [$convenio, $chico] = $this->conTotal();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 1200), $this->renglon($chico, 500)], ['fecha' => '2026-06-01']);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(1700, $i['recibido']);
        $this->assertEqualsWithDelta(1700 / 7000, $i['avance'], 0.0001);
        // 200 de exceso de Jumbo + 500 de Chico.
        $this->assertEquals(700, $i['libre']['libre_usado']);
        $this->assertEquals(4300, $i['libre']['libre_restante']);
        $porCalibre = collect($i['calibres'])->keyBy('calibre_id');
        $this->assertEquals(1.2, $porCalibre[$this->jumbo->id]['avance']);
        $this->assertNull($porCalibre[$chico->id]['avance']);
        $this->assertEquals(500, $porCalibre[$chico->id]['recibido']);
    }

    public function test_total_propio_con_recepcion_menor_a_los_apartados_no_consume_libre(): void
    {
        [$convenio] = $this->conTotal();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 400), $this->renglon($this->grande, 1000)], ['fecha' => '2026-06-01']);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(0, $i['libre']['libre_usado']);
        $this->assertEquals(5000, $i['libre']['libre_restante']);
    }

    public function test_libre_restante_puede_ser_negativo(): void
    {
        [$convenio, $chico] = $this->conTotal();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($chico, 5200)], ['fecha' => '2026-06-01']);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(5200, $i['libre']['libre_usado']);
        $this->assertEquals(-200, $i['libre']['libre_restante']);
    }

    public function test_total_propio_cumplimiento_y_estado_se_miden_contra_la_serie_total(): void
    {
        [$convenio, $chico] = $this->conTotal();
        // «Hoy» es el martes 16: cerradas las semanas del 1 y del 8 (meta total 1750 cada una).
        $hoy = CarbonImmutable::parse('2026-06-16 10:00', 'America/Mazatlan');
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($chico, 3500)], ['fecha' => '2026-06-01']);

        $i = IndicadoresConvenio::de($convenio, $hoy);

        $this->assertEquals(3500, $i['meta_a_la_fecha']);
        $this->assertEquals(3500, $i['recibido_a_la_fecha']);
        $this->assertEquals(1.0, $i['cumplimiento']);
        $this->assertSame('en_meta', $i['estado']);
        $this->assertSame([1750.0, 1750.0, 1750.0, 1750.0], array_map('floatval', array_column($i['semanas'], 'meta')));

        // Con la mitad recibida va atrasado contra el total (contra los apartados iría adelantado).
        [$atrasado, $chico2] = $this->conTotal();
        $this->crearRecepcionConRenglones($atrasado, [$this->renglon($chico2, 1750)], ['fecha' => '2026-06-01']);
        $j = IndicadoresConvenio::de($atrasado, $hoy);
        $this->assertEquals(0.5, $j['cumplimiento']);
        $this->assertSame('atrasado', $j['estado']);
    }

    public function test_apartados_iguales_al_total_dejan_libre_en_cero(): void
    {
        [$convenio] = $this->conTotal([], 90, 3500, 3500);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(0, $i['libre']['libre']);
        $this->assertEquals(0, $i['libre']['comprometido']);
        $this->assertEquals(7000, $i['libre']['apartado']);
        $this->assertEquals(700000, $i['comprometido']);
    }

    public function test_sin_precio_general_el_libre_queda_sin_comprometido(): void
    {
        [$convenio] = $this->conTotal([], null);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(5000, $i['libre']['libre']);
        $this->assertNull($i['libre']['comprometido']);
        // Solo suman los apartados.
        $this->assertEquals(200000, $i['comprometido']);
    }

    public function test_sin_precio_general_pero_sin_libre_el_comprometido_es_cero(): void
    {
        [$convenio] = $this->conTotal([], null, 3500, 3500);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertEquals(0, $i['libre']['comprometido']);
        $this->assertEquals(700000, $i['comprometido']);
    }

    public function test_el_comprometido_del_libre_aplica_el_porcentaje_del_productor(): void
    {
        [$convenio] = $this->conTotal();
        ConvenioPrecio::where('convenio_id', $convenio->id)->update(['porcentaje_productor' => 50]);

        $i = IndicadoresConvenio::de($convenio->fresh(['precios', 'metas', 'calibres']));

        // 5000 × 90 × 50 %.
        $this->assertEquals(225000, $i['libre']['comprometido']);
        $this->assertEquals(50000 + 50000 + 225000, $i['comprometido']);
    }

    public function test_el_ultimo_calibre_absorbe_el_redondeo_del_reparto_del_total(): void
    {
        [$convenio] = $this->conTotal(['volumen_total' => 10, 'fecha_fin' => '2026-06-21'], 90, 5, 5);

        $i = IndicadoresConvenio::de($convenio);

        $this->assertSame([3.0, 3.0, 4.0], array_map('floatval', array_column($i['semanas'], 'meta')));
        $this->assertEquals(10, $i['pactado']);
        $this->assertEquals(0, $i['libre']['libre']);
    }

    public function test_total_propio_semanal_usa_las_metas_sin_calibre_como_total(): void
    {
        $semanas = ['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22'];
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->jumbo, 'precio' => 100],
            ['calibre' => $this->grande, 'precio' => 100],
        ], [
            'modo_volumen' => 'semanal',
            'metas_total' => array_fill_keys($semanas, 1750),
            'metas_calibre' => [$this->jumbo->id => array_fill_keys($semanas, 250)],
        ]);
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id,
            'tipo_carga_id' => $convenio->precios->first()->tipo_carga_id,
            'calibre_id' => null,
            'precio' => 90,
            'vigencia_inicio' => '2026-06-01',
            'es_principal' => true,
        ]);

        $i = IndicadoresConvenio::de($convenio->fresh(['precios', 'metas', 'calibres']));

        $this->assertEquals(7000, $i['pactado']);
        $this->assertEquals(1000, $i['libre']['apartado']);
        $this->assertEquals(6000, $i['libre']['libre']);
        $this->assertEquals(540000, $i['libre']['comprometido']);
        $this->assertEquals(100000 + 540000, $i['comprometido']);
    }

    public function test_sin_total_propio_el_libre_es_null_y_pactado_es_la_suma(): void
    {
        $convenio = $this->porCalibre();

        $i = IndicadoresConvenio::de($convenio);

        $this->assertArrayHasKey('libre', $i);
        $this->assertNull($i['libre']);
        $this->assertEquals(150, $i['pactado']);
        $this->assertEquals(100, collect($i['calibres'])->firstWhere('calibre_id', $this->jumbo->id)['pactado']);
    }

    public function test_el_tablero_trae_el_bloque_libre_y_no_multiplica_consultas(): void
    {
        [$primero] = $this->conTotal();
        $temporada = $primero->temporada_id;

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->tableroUrl($temporada))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con1 = $contar();

        $data = $this->getJson($this->tableroUrl($temporada))->assertOk()->json('data');
        $fila = collect($data['convenios'])->firstWhere('id', $primero->id);
        $this->assertEquals(5000, $fila['indicadores']['libre']['libre']);

        for ($n = 0; $n < 4; $n++) {
            $productor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => "Productor {$n}", 'is_active' => true]);
            $this->conTotal(['productor_id' => $productor->id]);
        }

        $this->assertSame($con1, $contar(), 'El número de consultas no debe crecer con los convenios con total propio.');
    }
}
