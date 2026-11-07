<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\User;
use App\Services\Abastecimiento\SaldoProductor;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;
use ZipArchive;

/** Excel del tablero (plan 3, tarea 7). «Hoy» es el martes 14 de julio de 2026 (semana ISO 29). */
class ExcelTableroTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $query = '', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/tablero/exportar".$query;
    }

    private function urlJson(int $temporadaId, string $extra = ''): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/tablero?temporada_id={$temporadaId}".$extra;
    }

    private function lunes(int $n): string
    {
        return CarbonImmutable::parse('2026-06-22')->addWeeks($n - 26)->toDateString();
    }

    private function productor(string $nombre, string $tipo = 'productor'): int
    {
        return Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'tipo' => $tipo, 'is_active' => true])->id;
    }

    private function metas(int $desde, array $cantidades): array
    {
        $metas = [];
        foreach ($cantidades as $i => $cantidad) {
            $metas[$this->lunes($desde + $i)] = $cantidad;
        }

        return $metas;
    }

    private function recibir(Convenio $c, array $porSemana, bool $kilos = false): void
    {
        foreach ($porSemana as $semana => $cantidad) {
            $this->crearRecepcion($c, [
                'fecha' => CarbonImmutable::parse($this->lunes($semana))->addDay()->toDateString(),
                'cantidad' => $cantidad,
                'kilos' => $kilos ? $cantidad : null,
            ]);
        }
    }

    /** Un convenio con metas semanales (cajas) y otro de modo total (kg, consignación) en MXN. */
    private function dosConvenios(): array
    {
        $semanal = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Empaque Huerta Real', 'empaque'),
            'precio' => 185, 'fecha_inicio' => $this->lunes(26), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(26, [2000, 2500, 2500, 3000, 3500, 3500, 3000, 2500, 1500]),
        ]);
        $this->recibir($semanal, [26 => 1980, 27 => 2210, 28 => 2650, 29 => 1300]);

        $total = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Agrícola Los Laureles'),
            'modalidad' => 'consignacion', 'unidad' => 'kg', 'precio' => 9.5,
            'fecha_inicio' => $this->lunes(18), 'fecha_fin' => '2026-07-26',
            'modo_volumen' => 'total', 'volumen_total' => 180000, 'metas' => [],
        ]);
        $total->precios->first()->update(['porcentaje_productor' => 70]);
        $this->recibir($total, [18 => 9800, 19 => 12400, 20 => 14100, 29 => 5200], kilos: true);

        return [$semanal, $total];
    }

    private function convenioUsd(int $temporadaId): Convenio
    {
        $usd = $this->crearConvenio($this->sbp, [
            'temporada_id' => $temporadaId, 'moneda' => 'USD', 'precio' => 12,
            'productor_id' => $this->productor('Exportadora del Valle'),
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-02',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($usd, [27 => 100, 28 => 100]);

        return $usd;
    }

    /** Descarga el Excel y lo abre con PhpSpreadsheet. */
    private function descargar(int $temporadaId, string $extra = ''): Spreadsheet
    {
        $res = $this->get($this->url("?temporada_id={$temporadaId}{$extra}"));
        $res->assertOk();

        return $this->abrir($res->baseResponse);
    }

    private function abrir(BinaryFileResponse $respuesta): Spreadsheet
    {
        $ruta = $respuesta->getFile()->getPathname();
        $this->assertFileExists($ruta);
        $this->assertSame('PK', substr((string) file_get_contents($ruta), 0, 2), 'No es un .xlsx (zip).');

        return IOFactory::load($ruta);
    }

    /** Filas de una hoja como arreglos asociativos por encabezado (valores crudos). */
    private function filas(Worksheet $hoja): array
    {
        $datos = $hoja->toArray(null, false, false, false);
        $encabezados = array_shift($datos);

        return array_values(array_filter(array_map(
            fn (array $fila) => array_combine($encabezados, array_slice($fila, 0, count($encabezados))),
            $datos,
        ), fn (array $fila) => array_filter($fila, fn ($v) => $v !== null && $v !== '') !== []));
    }

    private function fechaDe(mixed $serie): string
    {
        return FechaExcel::excelToDateTimeObject($serie)->format('Y-m-d');
    }

    // ---- Contenido ----

    public function test_descarga_un_xlsx_valido_con_las_cuatro_hojas(): void
    {
        [$semanal] = $this->dosConvenios();

        $res = $this->get($this->url("?temporada_id={$semanal->temporada_id}"));

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $res->headers->get('Content-Type'));
        $libro = $this->abrir($res->baseResponse);
        $this->assertSame(['Resumen', 'Convenios', 'Semanas', 'Avisos'], $libro->getSheetNames());
    }

    public function test_el_resumen_trae_los_mismos_valores_que_el_tablero(): void
    {
        [$semanal] = $this->dosConvenios();
        $tablero = $this->getJson($this->urlJson($semanal->temporada_id))->assertOk()->json('data');
        $kpis = $tablero['kpis'];

        $hoja = $this->descargar($semanal->temporada_id)->getSheetByName('Resumen');
        $v = [];
        foreach ($hoja->toArray(null, false, false, false) as $fila) {
            $v[(string) $fila[0]] = $fila[1];
        }

        $this->assertSame('Splendid by Porvenir', $v['Empresa']);
        $this->assertSame('Temporada de prueba', $v['Temporada']);
        $this->assertSame('MXN', $v['Moneda']);
        $this->assertEquals(29, $v['Semana actual']);
        $this->assertSame('2026-07-13', $this->fechaDe($v['Lunes de la semana actual']));
        $this->assertEqualsWithDelta($kpis['cumplimiento'], $v['Cumplimiento'], 0.00001);
        $this->assertEqualsWithDelta($kpis['comprometido'], $v['Comprometido (MXN)'], 0.001);
        $this->assertEqualsWithDelta($kpis['valor_recibido'], $v['Valor recibido (MXN)'], 0.001);
        $this->assertEqualsWithDelta($kpis['sin_liquidar'], $v['Sin liquidar (MXN)'], 0.001);
        $this->assertEqualsWithDelta($kpis['saldo_por_pagar'], $v['Saldo por pagar (MXN)'], 0.001);
        $this->assertEquals($kpis['abajo_de_meta'], $v['Convenios abajo de meta']);
        $this->assertEquals($kpis['con_avance'], $v['Convenios con avance']);
    }

    public function test_convenios_con_los_valores_del_tablero_numeros_y_fechas_reales(): void
    {
        [$semanal, $total] = $this->dosConvenios();
        $tablero = $this->getJson($this->urlJson($semanal->temporada_id))->assertOk()->json('data');
        $libro = $this->descargar($semanal->temporada_id);
        $hoja = $libro->getSheetByName('Convenios');

        $filas = $this->filas($hoja);
        $this->assertSame(array_column($tablero['convenios'], 'folio'), array_column($filas, 'Folio'));

        foreach ($tablero['convenios'] as $i => $c) {
            $f = $filas[$i];
            $ind = $c['indicadores'];
            $this->assertSame($c['productor']['nombre'], $f['Productor']);
            $this->assertSame('Mango', $f['Cultivo']);
            $this->assertSame($c['variedad']['nombre'], $f['Variedad']);
            $this->assertSame($c['fecha_inicio'], $this->fechaDe($f['Inicio']));
            $this->assertSame($c['fecha_fin'], $this->fechaDe($f['Fin']));
            $this->assertEqualsWithDelta($ind['pactado'], $f['Pactado'], 0.001);
            $this->assertEqualsWithDelta($ind['recibido'], $f['Recibido'], 0.001);
            $this->assertEqualsWithDelta($ind['avance'], $f['Avance'], 0.00001);
            $this->assertEqualsWithDelta($ind['meta_a_la_fecha'], $f['Meta a la fecha'], 0.001);
            $this->assertEqualsWithDelta($ind['cumplimiento'], $f['Cumplimiento'], 0.00001);
            $this->assertEqualsWithDelta($ind['proyeccion'], $f['Proyección'], 0.001);
            $this->assertIsNumeric($f['Pactado']);
        }

        // Modalidad, unidad y estado en español legible; el de modo total reparte su volumen.
        $porFolio = array_column($filas, null, 'Folio');
        $this->assertSame('Consignación', $porFolio[$total->folio]['Modalidad']);
        $this->assertSame('Kg', $porFolio[$total->folio]['Unidad']);
        $this->assertEqualsWithDelta(180000, $porFolio[$total->folio]['Pactado'], 0.01);
        $this->assertSame('Compra directa', $porFolio[$semanal->folio]['Modalidad']);
        $this->assertSame('Cajas', $porFolio[$semanal->folio]['Unidad']);
        $this->assertContains($porFolio[$semanal->folio]['Estado'], ['Atrasado', 'En riesgo', 'En meta', 'Adelantado', 'Por iniciar', 'Finalizado']);

        // Las fechas son números de Excel con formato de fecha, no texto.
        $celda = $hoja->getCell('G2');
        $this->assertSame(DataType::TYPE_NUMERIC, $celda->getDataType());
        $this->assertTrue(FechaExcel::isDateTime($celda));
        $this->assertTrue($hoja->getStyle('A1')->getFont()->getBold());
        $this->assertGreaterThan(8, $hoja->getColumnDimension('B')->getWidth());
    }

    public function test_semanas_trae_meta_y_recibido_por_convenio_y_marca_la_semana_actual(): void
    {
        [$semanal, $total] = $this->dosConvenios();
        $hoja = $this->descargar($semanal->temporada_id)->getSheetByName('Semanas');
        $filas = $this->filas($hoja);

        $deSemanal = array_values(array_filter($filas, fn ($f) => $f['Folio'] === $semanal->folio));
        $deTotal = array_values(array_filter($filas, fn ($f) => $f['Folio'] === $total->folio));

        $this->assertEqualsWithDelta(array_sum($this->metas(26, [2000, 2500, 2500, 3000, 3500, 3500, 3000, 2500, 1500])), array_sum(array_column($deSemanal, 'Meta')), 0.01);
        $this->assertEqualsWithDelta(1980 + 2210 + 2650 + 1300, array_sum(array_column($deSemanal, 'Recibido')), 0.01);
        $this->assertEqualsWithDelta(180000, array_sum(array_column($deTotal, 'Meta')), 0.01);
        $this->assertEqualsWithDelta(9800 + 12400 + 14100 + 5200, array_sum(array_column($deTotal, 'Recibido')), 0.01);

        $primera = $deSemanal[0];
        $this->assertSame($this->lunes(26), $this->fechaDe($primera['Lunes']));
        $this->assertEquals(26, $primera['Semana']);
        $this->assertEqualsWithDelta(1980 / 2000, $primera['% de la meta'], 0.0001);

        // Solo la semana 29 (el lunes 13 de julio) está marcada, en cada convenio.
        $marcadas = array_values(array_filter($filas, fn ($f) => $f['Semana actual'] === 'Sí'));
        $this->assertCount(2, $marcadas);
        foreach ($marcadas as $m) {
            $this->assertSame('2026-07-13', $this->fechaDe($m['Lunes']));
        }
    }

    public function test_avisos_con_los_textos_del_tablero(): void
    {
        $this->dosConvenios();
        $atrasado = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Mangos del Pacífico'),
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-30',
            'metas' => $this->metas(27, [1500, 1500, 2000, 2000]),
        ]);
        $this->recibir($atrasado, [27 => 1180, 28 => 920, 29 => 400]);
        $tablero = $this->getJson($this->urlJson($atrasado->temporada_id))->assertOk()->json('data');
        $this->assertNotEmpty($tablero['avisos']);

        $filas = $this->filas($this->descargar($atrasado->temporada_id)->getSheetByName('Avisos'));

        $this->assertSame(array_column($tablero['avisos'], 'texto'), array_column($filas, 'Aviso'));
        $folios = array_column($tablero['convenios'], 'folio', 'id');
        $this->assertSame(
            array_map(fn (array $a) => $a['convenio_id'] === null ? null : $folios[$a['convenio_id']], $tablero['avisos']),
            array_map(fn ($f) => $f['Convenio'], $filas),
        );
        $this->assertSame('Atrasado', $filas[0]['Tipo']);
        $this->assertContains($atrasado->folio, array_column($filas, 'Convenio'));
    }

    // ---- Moneda ----

    public function test_la_moneda_pedida_manda_y_no_se_mezclan(): void
    {
        [$semanal] = $this->dosConvenios();
        $usd = $this->convenioUsd($semanal->temporada_id);

        $mxn = $this->descargar($semanal->temporada_id, '&moneda=MXN');
        $enUsd = $this->descargar($semanal->temporada_id, '&moneda=USD');

        $this->assertNotContains($usd->folio, array_column($this->filas($mxn->getSheetByName('Convenios')), 'Folio'));
        $this->assertSame([$usd->folio], array_column($this->filas($enUsd->getSheetByName('Convenios')), 'Folio'));
        $this->assertSame([$usd->folio], array_values(array_unique(array_column($this->filas($enUsd->getSheetByName('Semanas')), 'Folio'))));
        $this->assertSame('USD', $enUsd->getSheetByName('Resumen')->getCell('B5')->getValue());
        $this->assertSame('MXN', $mxn->getSheetByName('Resumen')->getCell('B5')->getValue());
        // Sin moneda pedida: la primera de la temporada (MXN).
        $this->assertSame('MXN', $this->descargar($semanal->temporada_id)->getSheetByName('Resumen')->getCell('B5')->getValue());
    }

    // ---- Por pagar ----

    public function test_por_pagar_solo_con_liquidaciones_view_y_con_el_saldo_correcto(): void
    {
        [$semanal, $total] = $this->dosConvenios();
        $liq = $this->crearLiquidacion($semanal, [
            'status' => 'aprobada', 'monto_calculado' => 1000, 'monto_final' => 1000, 'aprobada_at' => '2026-07-10 10:00:00',
        ]);
        $abono = $this->crearAbono(Productor::findOrFail($semanal->productor_id), ['monto' => 400]);
        AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => 400]);

        // Admin: la columna existe, con lo liquidado menos lo aplicado.
        $filas = array_column($this->filas($this->descargar($semanal->temporada_id)->getSheetByName('Convenios')), null, 'Folio');
        $this->assertArrayHasKey('Por pagar (MXN)', $filas[$semanal->folio]);
        $this->assertEqualsWithDelta(600, $filas[$semanal->folio]['Por pagar (MXN)'], 0.001);
        $this->assertEqualsWithDelta(0, $filas[$total->folio]['Por pagar (MXN)'], 0.001);
        $this->assertEqualsWithDelta(SaldoProductor::deConvenio($semanal)['por_pagar'], $filas[$semanal->folio]['Por pagar (MXN)'], 0.001);

        // Con tablero.view pero sin liquidaciones.view la columna no existe.
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'tablero', ['view']);
        $hoja = $this->descargar($semanal->temporada_id)->getSheetByName('Convenios');
        $this->assertArrayNotHasKey('Por pagar (MXN)', $this->filas($hoja)[0]);
        $this->assertSame('Estado', $hoja->getCell('O1')->getValue());
        $this->assertNull($hoja->getCell('P1')->getValue());

        // Con liquidaciones.view reaparece.
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        $filas = array_column($this->filas($this->descargar($semanal->temporada_id)->getSheetByName('Convenios')), null, 'Folio');
        $this->assertEqualsWithDelta(600, $filas[$semanal->folio]['Por pagar (MXN)'], 0.001);
    }

    public function test_por_pagar_de_convenios_en_lote_coincide_con_el_de_cada_convenio(): void
    {
        [$semanal, $total] = $this->dosConvenios();
        $this->crearLiquidacion($semanal, ['status' => 'aprobada', 'monto_calculado' => 700, 'monto_final' => 700]);
        $this->crearLiquidacion($total, ['status' => 'pagada', 'monto_calculado' => 300, 'monto_final' => 300]);
        $this->crearLiquidacion($total, ['status' => 'borrador', 'monto_calculado' => 999, 'monto_final' => 999]);

        $lote = SaldoProductor::porPagarDeConvenios($this->sbp, [$semanal->id, $total->id, $semanal->id]);

        $this->assertSame([$semanal->id, $total->id], array_keys($lote));
        $this->assertEqualsWithDelta(SaldoProductor::deConvenio($semanal)['por_pagar'], $lote[$semanal->id], 0.001);
        $this->assertEqualsWithDelta(SaldoProductor::deConvenio($total)['por_pagar'], $lote[$total->id], 0.001);
        $this->assertSame([], SaldoProductor::porPagarDeConvenios($this->sbp, []));
    }

    // ---- Permisos y validación ----

    public function test_sin_tablero_view_es_403(): void
    {
        [$semanal] = $this->dosConvenios();
        Sanctum::actingAs($this->captor);

        $this->get($this->url("?temporada_id={$semanal->temporada_id}"))->assertForbidden();

        // Otros permisos no bastan.
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        $this->get($this->url("?temporada_id={$semanal->temporada_id}"))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'tablero', ['view']);
        $this->get($this->url("?temporada_id={$semanal->temporada_id}"))->assertOk();
    }

    public function test_temporada_de_otra_empresa_o_inexistente_es_404_y_los_filtros_se_validan(): void
    {
        $propio = $this->crearConvenio($this->sbp);
        $ajeno = $this->crearConvenio($this->otra);

        $this->getJson($this->url("?temporada_id={$ajeno->temporada_id}"))->assertNotFound();
        $this->getJson($this->url('?temporada_id=999999'))->assertNotFound();
        // Desde la URL de otra empresa tampoco vale la temporada de SBP.
        $this->getJson($this->url("?temporada_id={$propio->temporada_id}", 'otraempresa'))->assertNotFound();
        $this->getJson($this->url())->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url("?temporada_id={$propio->temporada_id}&moneda=EUR"))->assertStatus(422)->assertJsonValidationErrors('moneda');
    }

    public function test_temporada_sin_convenios_da_el_archivo_con_encabezados(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $c->delete();

        $libro = $this->descargar($c->temporada_id);

        $this->assertSame(['Resumen', 'Convenios', 'Semanas', 'Avisos'], $libro->getSheetNames());
        foreach (['Convenios' => 'Folio', 'Semanas' => 'Folio', 'Avisos' => 'Tipo'] as $nombre => $primero) {
            $hoja = $libro->getSheetByName($nombre);
            $this->assertSame($primero, $hoja->getCell('A1')->getValue());
            $this->assertSame(1, $hoja->getHighestRow(), "La hoja {$nombre} no debe traer renglones.");
        }
        $this->assertSame('Tablero de abastecimiento', $libro->getSheetByName('Resumen')->getCell('A1')->getValue());
    }

    // ---- Seguridad y nombre del archivo ----

    public function test_los_textos_del_usuario_nunca_son_formulas(): void
    {
        $peligrosos = ['=HYPERLINK("http://x.test","clic")', '+1+1', '-2+3', '@SUM(1,1)'];
        $convenios = [];
        foreach ($peligrosos as $i => $nombre) {
            $convenios[$nombre] = $this->crearConvenio($this->sbp, [
                'productor_id' => $this->productor($nombre),
                'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-02',
                'metas' => $this->metas(27, [100, 100, 100, 100]),
            ]);
        }
        $temporadaId = array_values($convenios)[0]->temporada_id;
        Temporada::find($temporadaId)->update(['nombre' => '=cmd|calc']);

        $res = $this->get($this->url("?temporada_id={$temporadaId}"))->assertOk();
        $ruta = $res->baseResponse->getFile()->getPathname();

        // Ninguna hoja trae elementos <f> (fórmulas).
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (str_starts_with($nombre, 'xl/worksheets/')) {
                $this->assertStringNotContainsString('<f>', (string) $zip->getFromIndex($i), "{$nombre} trae una fórmula.");
            }
        }
        $zip->close();

        $libro = IOFactory::load($ruta);
        $hoja = $libro->getSheetByName('Convenios');
        $leidos = [];
        foreach ($hoja->toArray(null, false, false, false) as $i => $fila) {
            if ($i > 0) {
                $leidos[] = $fila[1];
            }
        }
        $this->assertEqualsCanonicalizing($peligrosos, $leidos);
        for ($fila = 2; $fila <= 5; $fila++) {
            $celda = $hoja->getCell("B{$fila}");
            $this->assertSame(DataType::TYPE_STRING, $celda->getDataType(), "B{$fila} debe ser texto.");
            $this->assertTrue($hoja->getStyle("B{$fila}")->getQuotePrefix());
        }
        $this->assertSame('=cmd|calc', $libro->getSheetByName('Resumen')->getCell('B4')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $libro->getSheetByName('Resumen')->getCell('B4')->getDataType());
    }

    public function test_el_nombre_del_archivo_no_lleva_caracteres_invalidos(): void
    {
        $c = $this->crearConvenio($this->sbp);
        Temporada::find($c->temporada_id)->update(['nombre' => 'Temporada: 2026/27 "Ñandú" <A>\\B?']);

        $res = $this->get($this->url("?temporada_id={$c->temporada_id}"))->assertOk();
        $disposicion = (string) $res->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposicion);
        $this->assertStringContainsString('filename=Abastecimiento_Tablero_Temporada_2026_27_Nandu_A_B_2026-07-14.xlsx', $disposicion);
        $this->assertDoesNotMatchRegularExpression('/[:\/\\\\"<>?*]/', str_replace('attachment; filename=', '', $disposicion));

        // Una temporada con solo símbolos cae al id.
        Temporada::find($c->temporada_id)->update(['nombre' => '???']);
        $res = $this->get($this->url("?temporada_id={$c->temporada_id}"))->assertOk();
        $this->assertStringContainsString("filename=Abastecimiento_Tablero_{$c->temporada_id}_2026-07-14.xlsx", (string) $res->headers->get('Content-Disposition'));
    }

    // ---- Consultas ----

    public function test_las_consultas_no_crecen_con_los_convenios(): void
    {
        [$semanal] = $this->dosConvenios();
        $temporada = $semanal->temporada_id;
        $this->crearLiquidacion($semanal, ['status' => 'aprobada', 'monto_final' => 500, 'monto_calculado' => 500]);

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($this->url("?temporada_id={$temporada}"))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con2 = $contar();

        for ($i = 0; $i < 8; $i++) {
            $extra = $this->crearConvenio($this->sbp, [
                'temporada_id' => $temporada, 'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-02',
                'productor_id' => $this->productor("Productor extra {$i}"),
                'metas' => $this->metas(27, [100, 100, 100, 100]),
            ]);
            $this->recibir($extra, [27 => 80, 28 => 120]);
            $this->crearLiquidacion($extra, ['status' => 'aprobada', 'monto_final' => 100, 'monto_calculado' => 100]);
        }
        $this->assertSame(10, Convenio::count());

        $this->assertSame($con2, $contar(), 'El número de consultas no debe crecer con los convenios.');
    }
}
