<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\User;
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

    private VariedadCalibre $jumbo;

    private VariedadCalibre $grande;

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

    private function renglon(VariedadCalibre $calibre, float $cantidad, ?float $kilos = null): array
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

    public function test_sin_liquidar_valora_por_renglon(): void
    {
        $convenio = $this->porCalibre();
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 2), $this->renglon($this->grande, 3)], ['fecha' => '2026-06-01']);
        $liquidacion = $this->crearLiquidacion($convenio);
        $this->crearRecepcionConRenglones($convenio, [$this->renglon($this->jumbo, 100)], ['fecha' => '2026-06-02', 'liquidacion_id' => $liquidacion->id]);

        $saldo = SaldoProductor::porProductores($this->sbp, [$convenio->productor_id])->get($convenio->productor_id);

        // Solo la libre: Jumbo 2 × $10 + Grande 3 × $20.
        $this->assertEquals(80, $saldo['MXN']['sin_liquidar'] ?? null);
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
}
