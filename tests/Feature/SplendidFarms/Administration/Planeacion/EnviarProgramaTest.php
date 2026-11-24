<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Mail\Planeacion\ProgramaSemanalMail;
use App\Models\ActivityLog;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\User;
use App\Services\Planeacion\DestinatariosPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * GET planeacion/programa/envio/opciones y POST planeacion/programa/envio:
 * un solo correo con un Excel de una hoja por semana, los destinatarios de la
 * lista guardada en copia oculta. Enviar nunca crea un programa.
 */
class EnviarProgramaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/envio';

    private const OPCIONES = '/api/splendidfarms/administration/planeacion/programa/envio/opciones';

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'sistema@sentinel.test', 'mail.from.name' => 'Sentinel']);
        $this->crearElote();
        $this->usuario = $this->usuarioCon(['tablero-semanal' => ['view', 'enviar']]);
        $this->usuario->update(['name' => 'Ana Planeación']);
        Sanctum::actingAs($this->usuario);

        (new DestinatariosPrograma)->guardar([
            ['correo' => 'productor@campo.test', 'nombre' => 'Productor Uno'],
            ['correo' => 'cliente@venta.test', 'nombre' => ''],
            ['correo' => 'socio@socio.test', 'nombre' => 'Socio'],
        ]);
    }

    /** WK48 con la carga del Excel y WK49 con un solo corte de 5 ha de Blanco el lunes. */
    private function dosSemanas(): void
    {
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $this->corte(ProgramaSemanal::create(['lunes' => '2026-11-30']), 'Blanco', 0, 5);
    }

    private function enviar(array $cuerpo): TestResponse
    {
        return $this->postJson(self::URL, $cuerpo + [
            'lunes' => ['2026-11-23'],
            'destinatarios' => ['productor@campo.test'],
        ]);
    }

    private function correoEnviado(): ProgramaSemanalMail
    {
        $enviados = Mail::sent(ProgramaSemanalMail::class);
        $this->assertCount(1, $enviados);

        return $enviados->first();
    }

    private function libroDe(ProgramaSemanalMail $correo): Spreadsheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'envio_test_').'.xlsx';
        file_put_contents($ruta, $correo->contenidoExcel);
        $libro = IOFactory::load($ruta);
        @unlink($ruta);

        return $libro;
    }

    private function totalDe(Worksheet $hoja, string $texto): mixed
    {
        for ($fila = 1; $fila <= $hoja->getHighestRow(); $fila++) {
            if ($hoja->getCell('C'.$fila)->getValue() === $texto) {
                return $hoja->getCell('N'.$fila)->getValue();
            }
        }
        $this->fail("No hay una fila con «{$texto}».");
    }

    /** @return list<string> */
    private function xlsxTemporales(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
    }

    public function test_envia_dos_semanas_en_un_solo_correo_con_copia_oculta(): void
    {
        Mail::fake();
        $this->dosSemanas();

        $this->enviar([
            'lunes' => ['2026-11-30', '2026-11-23'],
            'destinatarios' => ['productor@campo.test', 'SOCIO@socio.test '],
            'nota' => 'Programa <b>preliminar</b>',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Programa enviado a 2 destinatarios.')
            ->assertJsonPath('data.enviados', 2)
            ->assertJsonPath('data.semanas', ['2026-11-23', '2026-11-30']);

        $correo = $this->correoEnviado();
        $this->assertTrue($correo->hasTo('sistema@sentinel.test'));
        $this->assertCount(1, $correo->to);
        $this->assertTrue($correo->hasBcc('productor@campo.test'));
        $this->assertTrue($correo->hasBcc('socio@socio.test'));
        $this->assertFalse($correo->hasBcc('cliente@venta.test'));
        $this->assertCount(2, $correo->bcc);
        $this->assertSame([], $correo->cc);
        $correo->assertHasSubject('Programa semanal WK48–WK49 · Elote Dulce');

        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48-WK49_2026-11-23.xlsx', ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        // El cuerpo: nota (escapada), semanas, cultivo y quién lo manda; nunca las direcciones.
        $html = $correo->render();
        $this->assertStringContainsString('Programa &lt;b&gt;preliminar&lt;/b&gt;', $html);
        $this->assertStringContainsString('WK48 · 23–29 nov', $html);
        $this->assertStringContainsString('WK49 · 30 nov–6 dic', $html);
        $this->assertStringContainsString('Elote Dulce', $html);
        $this->assertStringContainsString('Enviado por Ana Planeación', $html);
        $this->assertStringNotContainsString('@campo.test', $html);
        $this->assertStringNotContainsString('@socio.test', $html);

        // Una hoja por semana, cada una con SUS cifras.
        $libro = $this->libroDe($correo);
        $this->assertSame(['WK48', 'WK49'], $libro->getSheetNames());
        $this->assertSame('PROGRAMA SEMANA 48', $libro->getSheetByName('WK48')->getCell('F2')->getValue());
        $this->assertSame('PROGRAMA SEMANA 49', $libro->getSheetByName('WK49')->getCell('F2')->getValue());
        $this->assertEquals(70, $this->totalDe($libro->getSheetByName('WK48'), 'Total ha elote dulce'));
        $this->assertEquals(5, $this->totalDe($libro->getSheetByName('WK49'), 'Total ha elote dulce'));
        $this->assertEquals(36483, $this->totalDe($libro->getSheetByName('WK48'), 'TOTAL NECESIDAD'));
        $this->assertEquals(0, $this->totalDe($libro->getSheetByName('WK49'), 'TOTAL NECESIDAD'));
    }

    public function test_un_libro_mezcla_una_semana_sin_domingo_y_otra_con_domingo_cada_hoja_con_su_layout(): void
    {
        Mail::fake();
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $sinDomingo = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->corte($sinDomingo, 'Blanco', 0, 5);
        $sinDomingo->forceFill(['trabaja_domingo' => false, 'domingo_corte' => false, 'domingo_empaque' => false, 'domingo_embarques' => false])->save();

        $this->enviar(['lunes' => ['2026-11-23', '2026-11-30']])->assertOk();

        $libro = $this->libroDe($this->correoEnviado());
        $con = $libro->getSheetByName('WK48');
        $sin = $libro->getSheetByName('WK49');

        $filaDe = function (Worksheet $hoja, string $texto): int {
            for ($f = 1; $f <= $hoja->getHighestRow(); $f++) {
                if ($hoja->getCell('C'.$f)->getValue() === $texto) {
                    return $f;
                }
            }
            $this->fail("No hay una fila con «{$texto}».");
        };
        $encabezado = fn (Worksheet $hoja) => array_map(
            fn ($c) => $hoja->getCell($c.$filaDe($hoja, 'COLOR'))->getValue(),
            ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'],
        );

        // La semana con domingo conserva G–M días y N total; la otra termina en la L y pone el total en la M.
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], $encabezado($con));
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'HA TOTAL', null], $encabezado($sin));
        $this->assertEquals(70, $con->getCell('N'.$filaDe($con, 'Total ha elote dulce'))->getValue());
        $this->assertEquals(5, $sin->getCell('M'.$filaDe($sin, 'Total ha elote dulce'))->getValue());
        $this->assertNull($sin->getCell('N'.$filaDe($sin, 'Total ha elote dulce'))->getValue());
        $this->assertEquals(36483, $con->getCell('N'.$filaDe($con, 'TOTAL NECESIDAD'))->getValue());
        $this->assertEquals(0, $sin->getCell('M'.$filaDe($sin, 'TOTAL NECESIDAD'))->getValue());
    }

    public function test_el_correo_trae_el_domingo_si_algun_proceso_lo_trabaja(): void
    {
        Mail::fake();
        // WK48: solo Empaque con domingo («alguno» = sí). WK49: los tres sin domingo.
        $wk48 = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($wk48);
        $wk48->forceFill(['trabaja_domingo' => true, 'domingo_corte' => false, 'domingo_empaque' => true, 'domingo_embarques' => false])->save();
        $sinDomingo = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $sinDomingo->forceFill(['trabaja_domingo' => false, 'domingo_corte' => false, 'domingo_empaque' => false, 'domingo_embarques' => false])->save();

        $this->enviar(['lunes' => ['2026-11-23', '2026-11-30']])->assertOk();

        $libro = $this->libroDe($this->correoEnviado());
        $encabezado = function (Worksheet $hoja): array {
            for ($f = 1; $f <= $hoja->getHighestRow(); $f++) {
                if ($hoja->getCell('C'.$f)->getValue() === 'COLOR') {
                    return array_map(fn ($c) => $hoja->getCell($c.$f)->getValue(), ['G', 'H', 'I', 'J', 'K', 'L', 'M', 'N']);
                }
            }
            $this->fail('No hay encabezado de días.');
        };

        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'D', 'HA TOTAL'], $encabezado($libro->getSheetByName('WK48')));
        $this->assertSame(['L', 'M', 'X', 'J', 'V', 'S', 'HA TOTAL', null], $encabezado($libro->getSheetByName('WK49')));
    }

    public function test_con_una_semana_el_asunto_lleva_solo_esa(): void
    {
        Mail::fake();
        $this->dosSemanas();

        $this->enviar([])->assertOk()->assertJsonPath('message', 'Programa enviado a 1 destinatario.');

        $correo = $this->correoEnviado();
        $correo->assertHasSubject('Programa semanal WK48 · Elote Dulce');
        $this->assertSame(['WK48'], $this->libroDe($correo)->getSheetNames());
        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48_2026-11-23.xlsx', ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function test_el_archivo_lleva_rango_con_semanas_seguidas_y_lista_con_las_que_no(): void
    {
        Mail::fake();
        $this->dosSemanas();
        ProgramaSemanal::create(['lunes' => '2026-12-14']); // WK51: no es seguida de WK49

        // Seguidas (WK48, WK49): rango, igual que el asunto.
        $this->enviar(['lunes' => ['2026-11-23', '2026-11-30']])->assertOk();
        $correo = Mail::sent(ProgramaSemanalMail::class)->first();
        $correo->assertHasSubject('Programa semanal WK48–WK49 · Elote Dulce');
        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48-WK49_2026-11-23.xlsx', ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        // No seguidas (WK48, WK51): lista en el asunto y en el nombre del archivo.
        Mail::fake();
        $this->enviar(['lunes' => ['2026-11-23', '2026-12-14']])->assertOk();
        $correo = $this->correoEnviado();
        $correo->assertHasSubject('Programa semanal WK48, WK51 · Elote Dulce');
        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48_WK51_2026-11-23.xlsx', ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

        // Tres semanas con hueco (WK48, WK49, WK51): lista completa.
        Mail::fake();
        $this->enviar(['lunes' => ['2026-11-23', '2026-11-30', '2026-12-14']])->assertOk();
        $correo = $this->correoEnviado();
        $correo->assertHasAttachedData($correo->contenidoExcel, 'Programa_WK48_WK49_WK51_2026-11-23.xlsx', ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function test_dos_semanas_con_el_mismo_numero_llevan_el_anio_en_la_hoja(): void
    {
        Mail::fake();
        ProgramaSemanal::create(['lunes' => '2026-11-23']);
        ProgramaSemanal::create(['lunes' => '2027-11-29']);

        $this->enviar(['lunes' => ['2026-11-23', '2027-11-29'], 'cultivo_id' => $this->cultivo->id])->assertOk();

        $correo = $this->correoEnviado();
        $this->assertSame(['WK48 2026', 'WK48 2027'], $this->libroDe($correo)->getSheetNames());
        $correo->assertHasSubject('Programa semanal WK48, WK48 · Elote Dulce');
    }

    public function test_un_lunes_sin_programa_no_lo_crea(): void
    {
        Mail::fake();
        $this->dosSemanas();
        $antes = ProgramaSemanal::count();

        $this->enviar(['lunes' => ['2026-11-23', '2026-12-07']])
            ->assertStatus(422)->assertJsonValidationErrors('lunes.1');

        $this->assertSame($antes, ProgramaSemanal::count());
        Mail::assertNothingSent();
    }

    public function test_una_fecha_que_no_es_lunes_es_422(): void
    {
        Mail::fake();
        $this->dosSemanas();

        $this->enviar(['lunes' => ['2026-11-24']])->assertStatus(422)->assertJsonValidationErrors('lunes.0');
        $this->enviar(['lunes' => ['24/11/2026']])->assertStatus(422)->assertJsonValidationErrors('lunes.0');
        Mail::assertNothingSent();
    }

    public function test_limites_de_semanas_destinatarios_y_nota(): void
    {
        Mail::fake();
        $nueve = [];
        foreach (range(0, 8) as $i) {
            $lunes = date('Y-m-d', strtotime("2026-11-23 +{$i} week"));
            ProgramaSemanal::create(['lunes' => $lunes]);
            $nueve[] = $lunes;
        }

        $this->enviar(['lunes' => $nueve])->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->enviar(['lunes' => []])->assertStatus(422)->assertJsonValidationErrors('lunes');
        $this->enviar(['destinatarios' => []])->assertStatus(422)->assertJsonValidationErrors('destinatarios');
        $this->enviar(['destinatarios' => ['productor@campo.test', 'intruso@fuera.test']])
            ->assertStatus(422)->assertJsonValidationErrors('destinatarios.1');
        $this->enviar(['nota' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('nota');

        Mail::assertNothingSent();
    }

    public function test_lunes_repetidos_se_envian_una_vez(): void
    {
        Mail::fake();
        $this->dosSemanas();

        $this->enviar([
            'lunes' => ['2026-11-23', '2026-11-23', '2026-11-30'],
            'destinatarios' => ['productor@campo.test', 'PRODUCTOR@campo.test'],
        ])->assertOk()
            ->assertJsonPath('data.semanas', ['2026-11-23', '2026-11-30'])
            ->assertJsonPath('data.enviados', 1);

        $correo = $this->correoEnviado();
        $this->assertCount(1, $correo->bcc);
        $this->assertSame(['WK48', 'WK49'], $this->libroDe($correo)->getSheetNames());
    }

    public function test_sin_permiso_de_enviar_es_403_aunque_vea_el_tablero(): void
    {
        Mail::fake();
        $this->dosSemanas();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));

        $this->getJson(self::OPCIONES)->assertForbidden();
        $this->enviar([])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_opciones_trae_los_programas_existentes_mas_nuevos_primero(): void
    {
        foreach (range(0, 13) as $i) {
            ProgramaSemanal::create(['lunes' => date('Y-m-d', strtotime("2026-11-23 +{$i} week"))]);
        }
        $antes = ProgramaSemanal::count();

        $respuesta = $this->getJson(self::OPCIONES)->assertOk()->assertJsonPath('success', true);

        $semanas = $respuesta->json('data.semanas');
        $this->assertCount(12, $semanas);
        $this->assertSame(['lunes' => '2027-02-22', 'semana_iso' => 8], $semanas[0]);
        $this->assertSame(['lunes' => '2026-12-07', 'semana_iso' => 50], $semanas[11]);
        $respuesta->assertJsonPath('data.destinatarios', [
            ['correo' => 'productor@campo.test', 'nombre' => 'Productor Uno'],
            ['correo' => 'cliente@venta.test', 'nombre' => ''],
            ['correo' => 'socio@socio.test', 'nombre' => 'Socio'],
        ]);
        $this->assertSame($antes, ProgramaSemanal::count());
    }

    public function test_si_falla_el_correo_es_422_y_no_deja_el_archivo_temporal(): void
    {
        Mail::extend('falla', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('SMTP caído');
            }

            public function __toString(): string
            {
                return 'falla://';
            }
        });
        config(['mail.mailers.falla' => ['transport' => 'falla'], 'mail.default' => 'falla']);
        $this->dosSemanas();
        $antes = $this->xlsxTemporales();

        $this->enviar([])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No se pudo enviar el correo. Revisa la configuración del servidor de correo.');

        $this->assertSame([], array_values(array_diff($this->xlsxTemporales(), $antes)));
        $this->assertSame(0, ActivityLog::where('action', 'programa_enviado')->count());
    }

    public function test_un_envio_exitoso_no_deja_el_archivo_temporal(): void
    {
        Mail::fake();
        $this->dosSemanas();
        $antes = $this->xlsxTemporales();

        $this->enviar(['lunes' => ['2026-11-23', '2026-11-30']])->assertOk();

        $this->assertSame([], array_values(array_diff($this->xlsxTemporales(), $antes)));
    }

    public function test_deja_la_bitacora_sin_direcciones(): void
    {
        Mail::fake();
        $this->dosSemanas();

        $this->enviar([
            'lunes' => ['2026-11-23', '2026-11-30'],
            'destinatarios' => ['productor@campo.test', 'cliente@venta.test', 'socio@socio.test'],
        ])->assertOk();

        $registro = ActivityLog::where('action', 'programa_enviado')->sole();
        $this->assertSame($this->usuario->id, (int) $registro->user_id);
        $this->assertSame(['2026-11-23', '2026-11-30'], $registro->new_values['semanas']);
        $this->assertSame($this->cultivo->id, $registro->new_values['cultivo_id']);
        $this->assertSame('Elote Dulce', $registro->new_values['cultivo']);
        $this->assertSame(3, $registro->new_values['destinatarios']);
        $this->assertStringNotContainsString('@', json_encode([$registro->old_values, $registro->new_values]));
    }
}
