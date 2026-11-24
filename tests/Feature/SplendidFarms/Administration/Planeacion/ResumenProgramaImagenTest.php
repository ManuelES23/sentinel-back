<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Mail\Planeacion\ProgramaSemanalMail;
use App\Models\ActivityLog;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DestinatariosPrograma;
use App\Services\Planeacion\ExportadorProgramaExcel;
use App\Services\Planeacion\ImagenResumenPrograma;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\ResumenProgramaSemanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hoja resumen del programa en imagen (propuesta A del correo): una PNG por
 * semana con el viernes y el sábado de la semana anterior, dentro del cuerpo
 * y adjunta, y el Excel completo solo si se pide (por omisión sí).
 */
class ResumenProgramaImagenTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/envio';

    private ProgramaSemanal $wk48;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'sistema@sentinel.test', 'mail.from.name' => 'Sentinel']);
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'enviar']]));
        (new DestinatariosPrograma)->guardar([['correo' => 'productor@campo.test', 'nombre' => 'Productor Uno']]);

        $this->wk48 = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($this->wk48);
    }

    /** WK47 con cortes el viernes (2 ha) y el sábado (1 ha) de Bicolor y una carga sencilla a Texas el sábado. */
    private function semanaAnterior(): ProgramaSemanal
    {
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->corte($wk47, 'Bicolor', 4, 2);
        $this->corte($wk47, 'Bicolor', 5, 1);
        $this->corte($wk47, 'Amarillo', 0, 9); // lunes: no sale en la hoja de WK48
        ProgramaEmbarque::create(['programa_id' => $wk47->id, 'cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'sencillo', 'dia' => 5, 'cargas' => 1]);

        return $wk47;
    }

    private function resumen(?ProgramaSemanal $anterior): array
    {
        $servicio = app(ProgramaSemanalService::class);
        $previa = null;
        if ($anterior) {
            $previa = $servicio->semana($anterior, []);
            $servicio->olvidar();
        }
        $semana = $servicio->semana($this->wk48, []);

        return (new ResumenProgramaSemanal)->armar($semana, $previa, $this->cultivo->id, 'Elote Dulce');
    }

    private function fila(array $resumen, ?string $seccion, string $etiqueta): array
    {
        foreach ($resumen['secciones'] as $s) {
            if ($s['titulo'] === $seccion) {
                foreach ($s['filas'] as $f) {
                    if ($f['etiqueta'] === $etiqueta) {
                        return $f;
                    }
                }
            }
        }
        $this->fail("No hay fila «{$etiqueta}» en «{$seccion}».");
    }

    public function test_el_resumen_pone_viernes_y_sabado_anteriores_sin_sumarlos_al_total(): void
    {
        ProgramaEmbarque::create(['programa_id' => $this->wk48->id, 'cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 0, 'cargas' => 2]);
        $r = $this->resumen($this->semanaAnterior());

        $this->assertSame(48, $r['semana']);
        $this->assertSame('23 – 29 nov 2026', $r['rango']);
        $this->assertTrue($r['con_previas']);
        $this->assertCount(9, $r['columnas']); // V y S de WK47 + lunes a domingo
        $this->assertSame(['letra' => 'V', 'dia' => '20', 'fecha' => '2026-11-20', 'previa' => true], $r['columnas'][0]);
        $this->assertSame(['letra' => 'S', 'dia' => '21', 'fecha' => '2026-11-21', 'previa' => true], $r['columnas'][1]);
        $this->assertSame(['letra' => 'L', 'dia' => '23', 'fecha' => '2026-11-23', 'previa' => false], $r['columnas'][2]);

        $bicolor = $this->fila($r, 'Campo · hectáreas', 'Bicolor');
        $this->assertEquals([2, 1, 10, 3, 1.5, 10, 3, 5, 0], $bicolor['valores']);
        $this->assertEquals(32.5, $bicolor['total']);
        $this->assertEquals([0, 0, 7, 5, 5, 0, 3, 1, 0], $this->fila($r, 'Campo · hectáreas', 'Amarillo')['valores']);

        // Totales de la semana = las cuatro cifras = el balance del Tablero.
        $this->assertSame($r['kpis']['produccion'], $this->fila($r, 'Cajas producidas', 'Total')['total']);
        $this->assertSame($r['kpis']['necesidad'], $this->fila($r, 'Necesidad de empaque', 'Total')['total']);
        $this->assertSame($r['kpis']['disponible'], $this->fila($r, null, 'Disponible')['total']);
        $this->assertSame($r['kpis']['produccion'] - $r['kpis']['necesidad'], $r['kpis']['disponible']);
        $this->assertSame(36483, $r['kpis']['necesidad']);
        $this->assertGreaterThan(0, $this->fila($r, 'Cajas producidas', 'Total')['valores'][0]); // viernes anterior con cajas

        // Necesidad por presentación (sin desglose por empaque ni destino).
        $this->assertSame(['Bulk', 'Tray', 'Minicobs', 'Total'], array_column(collect($r['secciones'])->firstWhere('titulo', 'Necesidad de empaque')['filas'], 'etiqueta'));

        // Embarques: celdas [full, sencillo]; la del sábado anterior viene de WK47 y no suma.
        $texas = $this->fila($r, 'Embarques · cargas', 'Texas');
        $this->assertSame([0, 1], $texas['valores'][1]);
        $this->assertSame([2, 0], $texas['valores'][2]);
        $this->assertSame([2, 0], $texas['total']);
        $this->assertSame(2, $r['kpis']['full']);
        $this->assertSame(0, $r['kpis']['sencillo']);

        // Balance por color: grupos del cultivo más el total.
        $total = end($r['por_color']);
        $this->assertSame('Total', $total['nombre']);
        $this->assertSame($r['kpis']['disponible'], $total['disponible']);

        foreach ($r['faltantes'] as $f) {
            $this->assertLessThan(0, $f['cajas']);
        }
    }

    public function test_sin_programa_anterior_las_columnas_previas_van_vacias(): void
    {
        $r = $this->resumen(null);

        $this->assertFalse($r['con_previas']);
        $amarillo = $this->fila($r, 'Campo · hectáreas', 'Amarillo');
        $this->assertNull($amarillo['valores'][0]);
        $this->assertNull($amarillo['valores'][1]);
        $this->assertEquals(21, $amarillo['total']);
    }

    public function test_una_semana_sin_domingo_no_trae_esa_columna(): void
    {
        $this->wk48->forceFill(['trabaja_domingo' => false, 'domingo_corte' => false, 'domingo_empaque' => false, 'domingo_embarques' => false])->save();

        $r = $this->resumen(null);

        $this->assertCount(8, $r['columnas']);
        $this->assertSame('S', end($r['columnas'])['letra']);
        $this->assertSame('23 – 28 nov 2026', $r['rango']);
    }

    public function test_la_imagen_es_un_png_del_ancho_de_sus_columnas(): void
    {
        $r = $this->resumen($this->semanaAnterior());
        $imagen = new ImagenResumenPrograma;

        $png = $imagen->png($r, 'Proyección al 24 nov 2026, 13:20');

        $medidas = getimagesizefromstring($png);
        $this->assertSame('image/png', $medidas['mime']);
        $this->assertSame(2 * (16 * 2 + 176 + 9 * 62 + 80), $medidas[0]);
        $this->assertGreaterThan(600, $medidas[1]);
        $this->assertSame('Programa_WK48_2026-11-23.png', $imagen->nombreArchivo($r));
    }

    public function test_el_correo_lleva_una_imagen_por_semana_y_el_excel_por_omision(): void
    {
        Mail::fake();
        $this->semanaAnterior();
        $this->corte(ProgramaSemanal::create(['lunes' => '2026-11-30']), 'Blanco', 0, 5);

        $this->postJson(self::URL, ['lunes' => ['2026-11-23', '2026-11-30'], 'destinatarios' => ['productor@campo.test']])->assertOk();

        $correo = Mail::sent(ProgramaSemanalMail::class)->sole();
        $this->assertCount(2, $correo->imagenes);
        $this->assertSame(['Programa_WK48_2026-11-23.png', 'Programa_WK49_2026-11-30.png'], array_column($correo->imagenes, 'nombre'));
        foreach ($correo->imagenes as $i) {
            $correo->assertHasAttachedData($i['png'], $i['nombre'], ['mime' => 'image/png']);
        }
        $this->assertNotNull($correo->contenidoExcel);
        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48-WK49_2026-11-23.xlsx', ['mime' => ExportadorProgramaExcel::TIPO_MIME]);

        // WK49 trae como previas el viernes y el sábado de WK48.
        $this->assertTrue($correo->imagenes[1]['resumen']['con_previas']);
        $this->assertSame('2026-11-27', $correo->imagenes[1]['resumen']['columnas'][0]['fecha']);

        $html = $correo->render();
        $this->assertStringContainsString('Producción <b>', $html);
        $this->assertStringContainsString('Necesidad <b>36,483</b>', $html);
        $this->assertStringContainsString('WK48 · 23–29 nov', $html);
        $this->assertStringContainsString('El detalle completo va en el Excel adjunto', $html);
        $this->assertStringContainsString('<img', $html);

        $this->assertTrue(ActivityLog::where('action', 'programa_enviado')->sole()->new_values['excel']);
    }

    public function test_sin_excel_solo_van_las_imagenes_y_no_se_genera_el_libro(): void
    {
        Mail::fake();
        $llamadas = new \ArrayObject;
        $this->app->bind(ExportadorProgramaExcel::class, fn () => new class($llamadas) extends ExportadorProgramaExcel
        {
            public function __construct(private \ArrayObject $llamadas) {}

            public function generarVarias(array $semanas, ?int $cultivoId, ?string $cultivoNombre = null): string
            {
                $this->llamadas[] = true;

                return parent::generarVarias($semanas, $cultivoId, $cultivoNombre);
            }
        });

        $this->postJson(self::URL, ['lunes' => ['2026-11-23'], 'destinatarios' => ['productor@campo.test'], 'incluir_excel' => false])->assertOk();

        $correo = Mail::sent(ProgramaSemanalMail::class)->sole();
        $this->assertNull($correo->contenidoExcel);
        $this->assertNull($correo->nombreArchivo);
        $this->assertCount(1, $correo->attachments());
        $this->assertCount(0, $llamadas);
        $this->assertStringNotContainsString('Excel adjunto', $correo->render());
        $this->assertFalse(ActivityLog::where('action', 'programa_enviado')->sole()->new_values['excel']);
    }

    public function test_incluir_excel_debe_ser_booleano(): void
    {
        Mail::fake();

        $this->postJson(self::URL, ['lunes' => ['2026-11-23'], 'destinatarios' => ['productor@campo.test'], 'incluir_excel' => 'quizá'])
            ->assertStatus(422)->assertJsonValidationErrors('incluir_excel');
        Mail::assertNothingSent();
    }
}
