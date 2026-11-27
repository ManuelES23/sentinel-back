<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\ActivityLog;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DestinatariosPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Ver resumen» del Tablero: GET planeacion/programa/imagenes/semanas y POST
 * planeacion/programa/imagenes. Las imágenes del correo, solo para verlas.
 */
class VerResumenProgramaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa/imagenes';

    private const ENVIO = '/api/splendidfarms/administration/planeacion/programa/envio';

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $this->cargarWk48(ProgramaSemanal::create(['lunes' => '2026-11-23']));
        $this->corte(ProgramaSemanal::create(['lunes' => '2026-11-30']), 'Blanco', 0, 5);
    }

    private function ver(array $lunes, array $extra = []): TestResponse
    {
        return $this->postJson(self::URL, ['lunes' => $lunes] + $extra);
    }

    public function test_quien_ve_el_tablero_ve_las_imagenes_sin_enviar_nada(): void
    {
        Mail::fake();
        $bitacora = ActivityLog::count();
        $programas = ProgramaSemanal::count();

        // Sin lista de correos configurada: no hace falta ninguno.
        $datos = $this->ver(['2026-11-30', '2026-11-23', '2026-11-30'])->assertOk()->json('data');

        $this->assertSame(['2026-11-23', '2026-11-30'], array_column($datos['imagenes'], 'lunes'));
        $this->assertSame('WK48 · 23–29 nov', $datos['imagenes'][0]['etiqueta']);
        $this->assertSame('Programa_WK48_2026-11-23.png', $datos['imagenes'][0]['nombre']);
        $this->assertGreaterThan(0, $datos['imagenes'][0]['ancho']);
        $this->assertStringStartsWith('data:image/png;base64,', $datos['imagenes'][0]['imagen']);
        $png = base64_decode(substr($datos['imagenes'][0]['imagen'], strlen('data:image/png;base64,')));
        $this->assertSame('image/png', getimagesizefromstring($png)['mime']);
        $this->assertNotEmpty($datos['cultivo']);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame($bitacora, ActivityLog::count());
        $this->assertSame($programas, ProgramaSemanal::count()); // no crea la semana anterior de WK48
    }

    public function test_las_semanas_ofrecidas_no_traen_correos(): void
    {
        (new DestinatariosPrograma)->guardar([['correo' => 'productor@campo.test', 'nombre' => 'Productor Uno']]);

        $datos = $this->getJson(self::URL.'/semanas')->assertOk()->json('data');

        $this->assertSame(['semanas'], array_keys($datos));
        $this->assertSame([
            ['lunes' => '2026-11-30', 'semana_iso' => 49],
            ['lunes' => '2026-11-23', 'semana_iso' => 48],
        ], $datos['semanas']);
    }

    public function test_valida_las_semanas_como_el_envio_y_no_crea_programas(): void
    {
        $programas = ProgramaSemanal::count();

        $this->ver([])->assertStatus(422)->assertJsonValidationErrors(['lunes' => 'Elige al menos una semana.']);
        $this->ver(['2026-12-07'])->assertStatus(422)->assertJsonValidationErrors(['lunes.0']);
        $this->ver(['2026-11-24'])->assertStatus(422)->assertJsonValidationErrors(['lunes.0']);
        $this->ver(['2026-11-23'], ['cultivo_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['cultivo_id']);
        $nueve = array_map(fn ($i) => date('Y-m-d', strtotime("2027-01-04 +{$i} weeks")), range(0, 8));
        $this->ver($nueve)->assertStatus(422)->assertJsonValidationErrors(['lunes' => 'Máximo 8 semanas a la vez.']);

        $this->assertSame($programas, ProgramaSemanal::count());
    }

    public function test_sin_permiso_de_ver_el_tablero_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view']]));

        $this->getJson(self::URL.'/semanas')->assertStatus(403);
        $this->ver(['2026-11-23'])->assertStatus(403);
    }

    public function test_ver_muchas_veces_no_gasta_el_limite_de_envio(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'enviar']]));
        (new DestinatariosPrograma)->guardar([['correo' => 'productor@campo.test', 'nombre' => '']]);

        foreach (range(1, 6) as $i) {
            $this->ver(['2026-11-23'])->assertOk();
        }

        $this->postJson(self::ENVIO, ['lunes' => ['2026-11-23'], 'destinatarios' => ['productor@campo.test']])->assertOk();
    }
}
