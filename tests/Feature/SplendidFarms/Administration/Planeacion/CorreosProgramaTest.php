<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Services\Planeacion\DestinatariosPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lista de correos a los que se envía el programa semanal: parámetro
 * `correos_programa` de la configuración (JSON de {correo, nombre}) y su
 * endpoint. Quien edita la configuración la captura; el permiso `enviar` del
 * Tablero semanal es el que dispara el envío (su migración se prueba en
 * SubmodulosTest).
 */
class CorreosProgramaTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion';

    private function editor(): void
    {
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['configuracion' => ['view', 'editar']]));
    }

    private function guardar(mixed $destinatarios)
    {
        return $this->putJson(self::URL.'/configuracion/correos-programa', ['destinatarios' => $destinatarios]);
    }

    private function configuracion(): array
    {
        return $this->getJson(self::URL.'/configuracion')->assertOk()->json('data');
    }

    public function test_sin_parametro_la_configuracion_trae_lista_vacia(): void
    {
        $this->editor();

        $this->assertSame([], $this->configuracion()['correos_programa']);
        $this->assertSame([], (new DestinatariosPrograma)->todos());
    }

    public function test_guarda_y_la_configuracion_lo_devuelve_en_orden_de_captura(): void
    {
        $this->editor();

        $this->guardar([
            ['correo' => 'zeta@mail.com', 'nombre' => 'Zeta'],
            ['correo' => 'ana@mail.com'],
            ['correo' => 'mario@mail.com', 'nombre' => null],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Correos del programa guardados.')
            ->assertJsonPath('data.correos_programa', [
                ['correo' => 'zeta@mail.com', 'nombre' => 'Zeta'],
                ['correo' => 'ana@mail.com', 'nombre' => ''],
                ['correo' => 'mario@mail.com', 'nombre' => ''],
            ]);

        $this->assertSame([
            ['correo' => 'zeta@mail.com', 'nombre' => 'Zeta'],
            ['correo' => 'ana@mail.com', 'nombre' => ''],
            ['correo' => 'mario@mail.com', 'nombre' => ''],
        ], $this->configuracion()['correos_programa']);
    }

    public function test_normaliza_correo_a_minusculas_sin_espacios_y_nombre_recortado(): void
    {
        $this->editor();

        $this->guardar([['correo' => ' Ana@Mail.COM ', 'nombre' => '  Ana López  ']])->assertOk();

        $this->assertSame([['correo' => 'ana@mail.com', 'nombre' => 'Ana López']], (new DestinatariosPrograma)->todos());
        $this->assertSame(
            json_encode([['correo' => 'ana@mail.com', 'nombre' => 'Ana López']], JSON_UNESCAPED_UNICODE),
            ConfiguracionPlaneacion::valor(DestinatariosPrograma::CLAVE),
        );
    }

    public function test_correo_repetido_o_repetido_por_mayusculas_es_422(): void
    {
        $this->editor();

        $this->guardar([
            ['correo' => 'ana@mail.com'],
            ['correo' => 'beto@mail.com'],
            ['correo' => ' ANA@mail.com '],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destinatarios.2.correo' => 'Ese correo ya está en la lista.']);

        $this->assertNull(ConfiguracionPlaneacion::valor(DestinatariosPrograma::CLAVE));
    }

    public function test_formato_invalido_es_422_por_posicion(): void
    {
        $this->editor();

        foreach (['ana', 'ana@', '@mail.com', 'ana@mail', '', '   ', 'a b@mail.com'] as $malo) {
            $this->guardar([['correo' => 'ok@mail.com'], ['correo' => $malo]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['destinatarios.1.correo' => 'Escribe un correo válido.']);
        }

        $this->guardar([['nombre' => 'Sin correo']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destinatarios.0.correo' => 'Escribe un correo válido.']);
        $this->guardar(['ana@mail.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destinatarios.0.correo' => 'Escribe un correo válido.']);
        $this->guardar([['correo' => ['ana@mail.com']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destinatarios.0.correo' => 'Escribe un correo válido.']);

        $this->assertNull(ConfiguracionPlaneacion::valor(DestinatariosPrograma::CLAVE));
    }

    public function test_nombre_que_no_es_texto_o_es_muy_largo_es_422(): void
    {
        $this->editor();

        $this->guardar([['correo' => 'ana@mail.com', 'nombre' => ['x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['destinatarios.0.nombre']);
        $this->guardar([['correo' => 'ana@mail.com', 'nombre' => str_repeat('a', 101)]])
            ->assertStatus(422)->assertJsonValidationErrors(['destinatarios.0.nombre']);
    }

    public function test_maximo_50_correos(): void
    {
        $this->editor();
        $lista = fn (int $n) => array_map(fn ($i) => ['correo' => "persona{$i}@mail.com"], range(1, $n));

        $this->guardar($lista(51))
            ->assertStatus(422)
            ->assertJsonPath('errors.destinatarios.0', 'Máximo 50 correos.');
        $this->assertNull(ConfiguracionPlaneacion::valor(DestinatariosPrograma::CLAVE));

        $this->guardar($lista(50))->assertOk();
        $this->assertCount(50, $this->configuracion()['correos_programa']);
    }

    public function test_la_lista_vacia_es_valida_y_borra_todo(): void
    {
        $this->editor();
        $this->guardar([['correo' => 'ana@mail.com']])->assertOk();

        $this->guardar([])->assertOk()->assertJsonPath('data.correos_programa', []);

        $this->assertSame([], $this->configuracion()['correos_programa']);
    }

    public function test_exige_la_lista_como_arreglo(): void
    {
        $this->editor();

        $this->putJson(self::URL.'/configuracion/correos-programa', [])->assertStatus(422)->assertJsonValidationErrors(['destinatarios']);
        $this->guardar('ana@mail.com')->assertStatus(422)->assertJsonValidationErrors(['destinatarios']);
    }

    public function test_sin_configuracion_editar_es_403_y_no_guarda(): void
    {
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['configuracion' => ['view'], 'tablero-semanal' => ['view', 'editar-notas']]));

        $this->guardar([['correo' => 'ana@mail.com']])->assertForbidden();

        $this->assertNull(ConfiguracionPlaneacion::valor(DestinatariosPrograma::CLAVE));
    }

    public function test_cualquiera_con_configuracion_view_ve_la_lista(): void
    {
        $this->crearElote();
        ConfiguracionPlaneacion::create(['clave' => DestinatariosPrograma::CLAVE, 'valor' => json_encode([['correo' => 'ana@mail.com', 'nombre' => 'Ana']])]);
        Sanctum::actingAs($this->usuarioCon(['configuracion' => ['view']]));

        $this->assertSame([['correo' => 'ana@mail.com', 'nombre' => 'Ana']], $this->configuracion()['correos_programa']);

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $this->getJson(self::URL.'/configuracion')->assertForbidden();
    }

    public function test_un_valor_guardado_corrupto_se_lee_como_lista_vacia_o_se_filtra(): void
    {
        ConfiguracionPlaneacion::create(['clave' => DestinatariosPrograma::CLAVE, 'valor' => 'no es json']);
        $this->assertSame([], (new DestinatariosPrograma)->todos());

        ConfiguracionPlaneacion::where('clave', DestinatariosPrograma::CLAVE)->update(['valor' => json_encode([
            ['correo' => 'Ana@Mail.com', 'nombre' => 'Ana'],
            'basura',
            ['nombre' => 'sin correo'],
            ['correo' => 'ana@mail.com', 'nombre' => 'Repetida'],
        ])]);
        $this->assertSame([['correo' => 'ana@mail.com', 'nombre' => 'Ana']], (new DestinatariosPrograma)->todos());
    }

    public function test_permiso_enviar_en_el_payload_solo_con_el_permiso(): void
    {
        $this->crearElote();

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'enviar']]));
        $this->assertTrue($this->getJson(self::URL.'/programa?lunes=2026-11-23')->assertOk()->json('data.permisos.tablero.enviar'));

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $permisos = $this->getJson(self::URL.'/programa?lunes=2026-11-23')->assertOk()->json('data.permisos.tablero');
        $this->assertSame(['view' => true, 'editar_notas' => false, 'enviar' => false], $permisos);
    }
}
