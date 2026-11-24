<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\ActivityLog;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DestinatariosPrograma;
use App\Services\Planeacion\ExportadorProgramaExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST planeacion/programa/envio/vista-previa: el correo tal como saldría
 * (asunto, adjuntos y cuerpo con las imágenes incrustadas), sin enviarlo,
 * sin generar el Excel y sin bitácora.
 */
class VistaPreviaProgramaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/envio/vista-previa';

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'sistema@sentinel.test', 'mail.from.name' => 'Sentinel']);
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'enviar']]));
        (new DestinatariosPrograma)->guardar([
            ['correo' => 'productor@campo.test', 'nombre' => 'Productor Uno'],
            ['correo' => 'socio@socio.test', 'nombre' => 'Socio'],
        ]);
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $this->corte(ProgramaSemanal::create(['lunes' => '2026-11-30']), 'Blanco', 0, 5);
    }

    public function test_devuelve_el_correo_sin_enviarlo_ni_generar_el_excel(): void
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

        $respuesta = $this->postJson(self::URL, [
            'lunes' => ['2026-11-30', '2026-11-23'],
            'destinatarios' => ['productor@campo.test', 'socio@socio.test'],
            'nota' => 'Programa <b>preliminar</b>',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asunto', 'Programa semanal WK48–WK49 · Elote Dulce')
            ->assertJsonPath('data.remitente', 'Sentinel')
            ->assertJsonPath('data.destinatarios', 2)
            ->assertJsonPath('data.semanas', ['WK48 · 23–29 nov', 'WK49 · 30 nov–6 dic'])
            ->assertJsonPath('data.adjuntos.0.nombre', 'Programa_WK48_2026-11-23.png')
            ->assertJsonPath('data.adjuntos.0.tipo', 'png')
            ->assertJsonPath('data.adjuntos.1.nombre', 'Programa_WK49_2026-11-30.png')
            ->assertJsonPath('data.adjuntos.2', ['nombre' => 'Programa_WK48-WK49_2026-11-23.xlsx', 'tipo' => 'xlsx', 'tamano' => null]);

        $this->assertGreaterThan(1000, $respuesta->json('data.adjuntos.0.tamano'));
        $html = $respuesta->json('data.html');
        $this->assertSame(2, substr_count($html, 'data:image/png;base64,'), 'las dos imágenes van incrustadas');
        $this->assertStringContainsString('Programa &lt;b&gt;preliminar&lt;/b&gt;', $html);
        $this->assertStringContainsString('Necesidad <b>36,483</b>', $html);
        $this->assertStringContainsString('El detalle completo va en el Excel adjunto', $html);
        $this->assertStringNotContainsString('@campo.test', $html);

        Mail::assertNothingSent();
        $this->assertCount(0, $llamadas);
        $this->assertSame(0, ActivityLog::where('action', 'programa_enviado')->count());
    }

    public function test_sin_excel_no_lo_anuncia(): void
    {
        $respuesta = $this->postJson(self::URL, [
            'lunes' => ['2026-11-23'],
            'destinatarios' => ['productor@campo.test'],
            'incluir_excel' => false,
        ])->assertOk()->assertJsonCount(1, 'data.adjuntos');

        $this->assertStringNotContainsString('Excel adjunto', $respuesta->json('data.html'));
    }

    public function test_valida_igual_que_enviar(): void
    {
        $this->postJson(self::URL, ['lunes' => ['2026-12-07'], 'destinatarios' => ['productor@campo.test']])
            ->assertStatus(422)->assertJsonValidationErrors('lunes.0');
        $this->postJson(self::URL, ['lunes' => ['2026-11-23'], 'destinatarios' => ['intruso@fuera.test']])
            ->assertStatus(422)->assertJsonValidationErrors('destinatarios.0');
    }

    public function test_sin_permiso_de_enviar_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));

        $this->postJson(self::URL, ['lunes' => ['2026-11-23'], 'destinatarios' => ['productor@campo.test']])->assertForbidden();
    }
}
