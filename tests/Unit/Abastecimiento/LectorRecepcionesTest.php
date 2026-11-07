<?php

namespace Tests\Unit\Abastecimiento;

use App\Services\Abastecimiento\LectorRecepciones;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class LectorRecepcionesTest extends TestCase
{
    use CreatesAbastecimientoFixtures;

    private function lector(): LectorRecepciones
    {
        return new LectorRecepciones();
    }

    private function mensajeDe(callable $accion): string
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('archivo', $e->errors());

            return $e->errors()['archivo'][0];
        }
        $this->fail('Se esperaba una ValidationException en «archivo».');
    }

    public function test_lee_los_renglones_con_el_numero_de_fila_del_excel(): void
    {
        $archivo = $this->crearXlsx([
            ['CV-0001', '2026-07-14', 'Caja empacada', 10, 120.5, 2, 'T-1'],
            ['CV-0002', '2026-07-15', 'Caja empacada', '20', '300', null, null],
        ]);

        $filas = $this->lector()->leer($archivo);

        $this->assertSame([
            ['fila' => 2, 'convenio_folio' => 'CV-0001', 'fecha' => '2026-07-14', 'tipo_carga' => 'Caja empacada',
                'cantidad' => '10', 'kilos' => '120.5', 'rezaga_kg' => '2', 'ticket' => 'T-1'],
            ['fila' => 3, 'convenio_folio' => 'CV-0002', 'fecha' => '2026-07-15', 'tipo_carga' => 'Caja empacada',
                'cantidad' => '20', 'kilos' => '300', 'rezaga_kg' => null, 'ticket' => null],
        ], $filas);
    }

    public function test_encabezados_con_acentos_mayusculas_y_otro_orden(): void
    {
        $archivo = $this->crearXlsx(
            [['T-9', 'Caja empacada', 5, '2026-07-14', 'CV-0007', 50, 1]],
            ['TICKET', 'TIPO DE CARGA', ' cantidad ', 'FECHA', 'folio  del  convenio', 'Kilos', 'rezaga KG'],
        );

        $this->assertSame([
            ['fila' => 2, 'convenio_folio' => 'CV-0007', 'fecha' => '2026-07-14', 'tipo_carga' => 'Caja empacada',
                'cantidad' => '5', 'kilos' => '50', 'rezaga_kg' => '1', 'ticket' => 'T-9'],
        ], $this->lector()->leer($archivo));
    }

    public function test_acepta_encabezados_con_acento_y_sin_las_columnas_opcionales(): void
    {
        $archivo = $this->crearXlsx(
            [['CV-0001', '2026-07-14', 'Caja empacada', 10, 100]],
            ['Folio del convenio', 'Fecha', 'Tipo de carga', 'Cantidad', 'Kilos'],
        );

        $filas = $this->lector()->leer($archivo);

        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]['rezaga_kg']);
        $this->assertNull($filas[0]['ticket']);
    }

    public function test_la_fecha_acepta_numero_de_excel_iso_y_dia_mes_anio(): void
    {
        $serie = (int) FechaExcel::PHPToExcel(new \DateTime('2026-07-14'));
        $archivo = $this->crearXlsx([
            ['CV-1', $serie, 'Caja empacada', 1, 1],
            ['CV-1', '2026-07-14', 'Caja empacada', 1, 1],
            ['CV-1', '14/07/2026', 'Caja empacada', 1, 1],
            ['CV-1', '14-07-2026', 'Caja empacada', 1, 1],
        ]);

        $this->assertSame(
            ['2026-07-14', '2026-07-14', '2026-07-14', '2026-07-14'],
            array_column($this->lector()->leer($archivo), 'fecha'),
        );
    }

    public function test_una_fecha_ilegible_queda_nula_con_el_texto_original(): void
    {
        $archivo = $this->crearXlsx([
            ['CV-1', 'ayer', 'Caja empacada', 1, 1],
            ['CV-1', '31/02/2026', 'Caja empacada', 1, 1],
            ['CV-1', null, 'Caja empacada', 1, 1],
        ]);

        $filas = $this->lector()->leer($archivo);

        $this->assertNull($filas[0]['fecha']);
        $this->assertSame('ayer', $filas[0]['fecha_original']);
        $this->assertNull($filas[1]['fecha']);
        $this->assertSame('31/02/2026', $filas[1]['fecha_original']);
        $this->assertNull($filas[2]['fecha']);
        $this->assertArrayNotHasKey('fecha_original', $filas[2]);
    }

    public function test_la_fecha_acepta_dia_y_mes_sin_cero_y_fecha_hora_iso(): void
    {
        $archivo = $this->crearXlsx(array_map(
            fn ($f) => ['CV-1', $f, 'Caja empacada', 1, 1],
            ['1/7/2026', '2026-7-4', '2026-07-14 00:00:00', '2026-07-14T00:00:00', '5-3-2026'],
        ));

        $this->assertSame(
            ['2026-07-01', '2026-07-04', '2026-07-14', '2026-07-14', '2026-03-05'],
            array_column($this->lector()->leer($archivo), 'fecha'),
        );
    }

    public function test_dia_mes_imposibles_o_mes_primero_siguen_ilegibles(): void
    {
        $archivo = $this->crearXlsx(array_map(
            fn ($f) => ['CV-1', $f, 'Caja empacada', 1, 1],
            ['31/02/2026', '07/14/2026', '2026-13-01', '2026-07-32T00:00:00', '14 de julio'],
        ));

        $filas = $this->lector()->leer($archivo);

        $this->assertSame([null, null, null, null, null], array_column($filas, 'fecha'));
        $this->assertSame(
            ['31/02/2026', '07/14/2026', '2026-13-01', '2026-07-32T00:00:00', '14 de julio'],
            array_column($filas, 'fecha_original'),
        );
    }

    public function test_un_valor_suelto_muy_abajo_no_cuelga_la_lectura(): void
    {
        $filas = array_merge(
            [
                ['CV-1', '2026-07-14', 'Caja empacada', 1, 1],
                ['CV-2', '2026-07-15', 'Caja empacada', 2, 2],
                ['CV-3', '2026-07-16', 'Caja empacada', 3, 3],
            ],
            array_fill(0, 29996, []),
            [array_merge(array_fill(0, 25, null), ['nota suelta'])],
        );
        $archivo = $this->crearXlsx($filas);

        $inicio = microtime(true);
        $leidas = $this->lector()->leer($archivo);

        $this->assertSame([2, 3, 4], array_column($leidas, 'fila'));
        $this->assertLessThan(10, microtime(true) - $inicio);
    }

    public function test_una_hoja_que_declara_cientos_de_miles_de_renglones_da_422_claro_sin_cargarla(): void
    {
        $filas = array_merge(
            [['CV-1', '2026-07-14', 'Caja empacada', 1, 1]],
            array_fill(0, 199998, []),
            [array_merge(array_fill(0, 25, null), ['nota suelta'])],
        );
        $archivo = $this->crearXlsx($filas);

        $inicio = microtime(true);
        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($archivo));

        $this->assertStringContainsString('declara 200001 renglones', $mensaje);
        $this->assertStringContainsString('máximo de '.LectorRecepciones::MAX_FILAS, $mensaje);
        $this->assertLessThan(10, microtime(true) - $inicio);
    }

    public function test_un_archivo_normal_de_2000_renglones_se_lee_completo_y_rapido(): void
    {
        $filas = [];
        for ($i = 1; $i <= LectorRecepciones::MAX_FILAS; $i++) {
            $filas[] = ['CV-0001', '2026-07-14', 'Caja empacada', $i, $i * 2];
        }
        $archivo = $this->crearXlsx($filas);

        $inicio = microtime(true);
        $leidas = $this->lector()->leer($archivo);

        $this->assertCount(LectorRecepciones::MAX_FILAS, $leidas);
        $this->assertSame(LectorRecepciones::MAX_FILAS + 1, end($leidas)['fila']);
        $this->assertLessThan(10, microtime(true) - $inicio);
    }

    public function test_solo_se_carga_la_hoja_elegida_y_su_tamano_es_el_que_cuenta(): void
    {
        // Una hoja grande que NO es la de datos no estorba: se lee «Recepciones» y la otra ni se carga.
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $otra = $libro->getActiveSheet()->setTitle('Otra');
        $otra->setCellValue('A100000', 'nota');
        $datos = $libro->createSheet()->setTitle('Recepciones');
        foreach (LectorRecepciones::ENCABEZADOS as $j => $titulo) {
            $datos->setCellValue([$j + 1, 1], $titulo);
        }
        foreach (['CV-1', '2026-07-14', 'Caja empacada', 3, 30] as $j => $v) {
            $datos->setCellValue([$j + 1, 2], $v);
        }
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
        $this->beforeApplicationDestroyed(fn () => @unlink($ruta));

        $leidas = $this->lector()->leer(new UploadedFile($ruta, 'recepciones.xlsx', null, null, true));

        $this->assertSame(['CV-1'], array_column($leidas, 'convenio_folio'));
    }

    public function test_un_decimal_con_ruido_binario_se_lee_redondeado(): void
    {
        $archivo = $this->crearXlsx([['CV-1', '2026-07-14', 'Caja empacada', 10, 12.1, 0.3, 'T-1']]);
        // El escritor redondea a 15 cifras: se inyecta en el XML el valor con ruido, como lo deja Excel tras una cuenta.
        $zip = new \ZipArchive();
        $zip->open($archivo->getRealPath());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<v>12.1</v>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace(['<v>12.1</v>', '<v>0.3</v>'], ['<v>12.100000000000001</v>', '<v>0.30000000000000004</v>'], $xml));
        $zip->close();

        // Con `precision` alta (php.ini de algunos servidores) el cast a texto del float mostraría el ruido.
        $precision = ini_set('precision', '17');
        try {
            $fila = $this->lector()->leer($archivo)[0];
        } finally {
            ini_set('precision', $precision);
        }

        $this->assertSame('12.1', $fila['kilos']);
        $this->assertSame('0.3', $fila['rezaga_kg']);
    }
    public function test_el_error_de_columnas_nombra_la_hoja_leida(): void
    {
        $archivo = $this->crearXlsx([['x']], ['Algo']);

        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($archivo));

        $this->assertStringContainsString('«Worksheet»', $mensaje);
        $this->assertStringNotContainsString('primera hoja', $mensaje);
    }

    public function test_un_html_con_extension_xlsx_no_se_lee(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'html').'.xlsx';
        file_put_contents($ruta, '<html><body><table><tr><td>Folio del convenio</td><td>Fecha</td><td>Tipo de carga</td><td>Cantidad</td><td>Kilos</td></tr><tr><td>CV-1</td><td>2026-07-14</td><td>Caja</td><td>1</td><td>1</td></tr></table></body></html>');
        $this->beforeApplicationDestroyed(fn () => @unlink($ruta));

        $this->mensajeDe(fn () => $this->lector()->leer(new UploadedFile($ruta, 'recepciones.xlsx', null, null, true)));
    }
    public function test_ignora_renglones_vacios_y_conserva_la_numeracion(): void
    {
        $archivo = $this->crearXlsx([
            ['CV-1', '2026-07-14', 'Caja empacada', 1, 1],
            ['', '  ', '', '', '', '', ''],
            ['CV-2', '2026-07-15', 'Caja empacada', 2, 2],
            ['', '', '', '', '', '', ''],
            ['', '', '', '', '', '', ''],
        ]);

        $filas = $this->lector()->leer($archivo);

        $this->assertSame([2, 4], array_column($filas, 'fila'));
        $this->assertSame(['CV-1', 'CV-2'], array_column($filas, 'convenio_folio'));
    }

    public function test_los_valores_salen_como_texto_sin_validar_el_negocio(): void
    {
        $archivo = $this->crearXlsx([['  cv-1 ', '2026-07-14', ' Caja ', 'diez', '-5', 'x', '  ']]);

        $fila = $this->lector()->leer($archivo)[0];

        $this->assertSame('cv-1', $fila['convenio_folio']);
        $this->assertSame('Caja', $fila['tipo_carga']);
        $this->assertSame('diez', $fila['cantidad']);
        $this->assertSame('-5', $fila['kilos']);
        $this->assertSame('x', $fila['rezaga_kg']);
        $this->assertNull($fila['ticket']);
    }

    public function test_hoja_sin_renglones_da_error_en_espanol(): void
    {
        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($this->crearXlsx([])));

        $this->assertStringContainsString('no tiene renglones', $mensaje);
    }

    public function test_falta_una_columna_obligatoria_y_la_nombra(): void
    {
        $archivo = $this->crearXlsx(
            [['CV-1', '2026-07-14', 'Caja empacada', 100]],
            ['Folio del convenio', 'Fecha', 'Tipo de carga', 'Kilos'],
        );

        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($archivo));

        $this->assertStringContainsString('Cantidad', $mensaje);
        $this->assertStringNotContainsString('Kilos,', $mensaje);
    }

    public function test_nombra_todas_las_columnas_que_faltan(): void
    {
        $archivo = $this->crearXlsx([['x', 'y']], ['Algo', 'Otra cosa']);

        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($archivo));

        foreach (['Folio del convenio', 'Fecha', 'Tipo de carga', 'Cantidad', 'Kilos'] as $columna) {
            $this->assertStringContainsString($columna, $mensaje);
        }
    }

    public function test_un_txt_renombrado_a_xlsx_no_se_lee(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'txt').'.xlsx';
        file_put_contents($ruta, "esto no es un Excel\n\x00\x01\x02 basura");
        $this->beforeApplicationDestroyed(fn () => @unlink($ruta));
        $archivo = new UploadedFile($ruta, 'recepciones.xlsx', null, null, true);

        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($archivo));

        $this->assertMatchesRegularExpression('/No se pudo leer el archivo|Faltan columnas obligatorias/', $mensaje);
    }

    public function test_2001_renglones_pasan_del_maximo(): void
    {
        $filas = array_fill(0, LectorRecepciones::MAX_FILAS + 1, ['CV-1', '2026-07-14', 'Caja empacada', 1, 1]);

        $mensaje = $this->mensajeDe(fn () => $this->lector()->leer($this->crearXlsx($filas)));

        $this->assertStringContainsString((string) LectorRecepciones::MAX_FILAS, $mensaje);
    }

    public function test_2000_renglones_si_se_leen(): void
    {
        $filas = array_fill(0, LectorRecepciones::MAX_FILAS, ['CV-1', '2026-07-14', 'Caja empacada', 1, 1]);

        $leidas = $this->lector()->leer($this->crearXlsx($filas));

        $this->assertCount(2000, $leidas);
        $this->assertSame(2001, $leidas[1999]['fila']);
    }

    public function test_la_plantilla_se_vuelve_a_leer_con_su_renglon_de_ejemplo(): void
    {
        $ruta = $this->lector()->plantilla();

        try {
            $this->assertFileExists($ruta);
            $archivo = new UploadedFile($ruta, 'plantilla.xlsx', null, null, true);
            $filas = $this->lector()->leer($archivo);

            $this->assertCount(1, $filas);
            $this->assertSame(2, $filas[0]['fila']);
            $this->assertSame('2026-07-14', $filas[0]['fecha']);
            $this->assertNotNull($filas[0]['convenio_folio']);
            $this->assertNotNull($filas[0]['tipo_carga']);
            $this->assertNotNull($filas[0]['cantidad']);

            $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
            $this->assertSame('Instrucciones', $libro->getSheet(1)->getTitle());
            $this->assertSame(LectorRecepciones::ENCABEZADOS, array_slice($libro->getSheet(0)->toArray()[0], 0, 7));
        } finally {
            @unlink($ruta);
        }
    }
}
