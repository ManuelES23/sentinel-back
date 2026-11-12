<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Evento de tiempo real del Programa semanal e inventario inicial manual
 * (Configuración: `configuracion.editar`).
 */
class TiempoRealYInventarioTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa';

    private const CONFIGURADOR = ['configuracion' => ['view', 'editar'], 'tablero-semanal' => ['view']];

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($this->programa);
    }

    public function test_emitir_solo_emite_el_evento(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);

        app(RegistroCambiosPrograma::class)->emitir($this->programa);

        Event::assertDispatched(
            ProgramaSemanalUpdated::class,
            fn ($e) => $e->data['programa_id'] === $this->programa->id && $e->data['lunes'] === '2026-11-23',
        );
    }

    public function test_evento_cabe_en_reverb(): void
    {
        $evento = new ProgramaSemanalUpdated('2026-11-23', 12345);

        $this->assertSame('programa.updated', $evento->broadcastAs());
        $this->assertSame(
            'private-module.splendidfarms.administration.planeacion',
            $evento->broadcastOn()[0]->name,
        );
        $this->assertSame(['lunes' => '2026-11-23', 'programa_id' => 12345], $evento->broadcastWith()['data']);
        $this->assertLessThan(10000, strlen(json_encode($evento->broadcastWith())));
    }

    public function test_inventario_inicial_manual(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));
        $blanco = (string) $this->grupo('Blanco')->id;
        $url = self::URL.'/'.$this->programa->id.'/inventario-inicial';

        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 3000])->assertOk();

        $this->assertSame(3000, ProgramaInventarioInicial::where('programa_id', $this->programa->id)->where('grupo_clave', $blanco)->value('cajas'));
        $balance = $this->getJson(self::URL.'?lunes=2026-11-23')->json('data.balance');
        $this->assertSame(3000, $balance[$blanco]['inicial']);
        $this->assertSame(3000 - 2893, $balance[$blanco]['final']);
        Event::assertDispatched(ProgramaSemanalUpdated::class);

        // Editar el mismo grupo actualiza, no duplica.
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 500])->assertOk();
        $this->assertSame(1, ProgramaInventarioInicial::where('programa_id', $this->programa->id)->count());
    }

    public function test_quitar_el_inventario_inicial_manual_vuelve_al_arrastre(): void
    {
        $blanco = (string) $this->grupo('Blanco')->id;
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $url = self::URL.'/'.$wk49->id.'/inventario-inicial';
        $balanceWk49 = fn () => $this->getJson(self::URL.'?lunes=2026-11-30')->assertOk()->json("data.balance.{$blanco}");

        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));

        // Sin captura: arrastra el final de WK48.
        $g = $balanceWk49();
        $this->assertSame('arrastrado', $g['inicial_origen']);
        $this->assertSame(-2893, $g['inicial']);
        $this->assertSame(-2893, $g['inicial_arrastrado']);

        // Capturado a mano: fija el inicial y sigue diciendo cuánto se arrastraría.
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 0])->assertOk()
            ->assertJsonPath('data.balance.inicial_origen', 'capturado');
        $g = $balanceWk49();
        $this->assertSame('capturado', $g['inicial_origen']);
        $this->assertSame(0, $g['inicial']);
        $this->assertSame(-2893, $g['inicial_arrastrado']);

        // Quitarlo (cajas null): se borra el renglón y vuelve a arrastrar.
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => null])->assertOk()
            ->assertJsonPath('data.cajas', null)
            ->assertJsonPath('data.balance.inicial', -2893)
            ->assertJsonPath('data.balance.inicial_origen', 'arrastrado');
        $this->assertSame(0, ProgramaInventarioInicial::where('programa_id', $wk49->id)->count());
        $this->assertSame('arrastrado', $balanceWk49()['inicial_origen']);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // Quitar otra vez no escribe ni avisa.
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => null])->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // `cajas` debe venir (null para quitar), y quitar también exige `configuracion.editar`.
        $this->putJson($url, ['grupo_clave' => $blanco])->assertStatus(422);
        Sanctum::actingAs($this->usuarioCon(['configuracion' => ['view'], 'plan-corte' => ['view', 'capturar']]));
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => null])->assertForbidden();
    }

    public function test_inventario_inicial_rechaza_variedad_con_grupo(): void
    {
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));
        $clave = 'v'.$this->variedades['Amarillo']->id;

        // Amarillo pertenece a un grupo: su clave implícita no es válida.
        $this->putJson(self::URL.'/'.$this->programa->id.'/inventario-inicial', ['grupo_clave' => $clave, 'cajas' => 10])
            ->assertStatus(422);
    }

    public function test_inventario_inicial_valida_grupo_cajas_y_permiso(): void
    {
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));
        $url = self::URL.'/'.$this->programa->id.'/inventario-inicial';
        $blanco = (string) $this->grupo('Blanco')->id;

        $this->putJson($url, ['grupo_clave' => '999999', 'cajas' => 10])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => 'xyz', 'cajas' => 10])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 'mucho'])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => -5])->assertStatus(422);

        Sanctum::actingAs($this->usuarioCon(['configuracion' => ['view'], 'plan-corte' => ['view', 'capturar']]));
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 10])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
        $this->assertSame(0, ProgramaInventarioInicial::count());
    }

    public function test_inventario_inicial_sin_cambio_de_valor_no_escribe_ni_avisa(): void
    {
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));
        $blanco = (string) $this->grupo('Blanco')->id;
        $url = self::URL.'/'.$this->programa->id.'/inventario-inicial';

        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 3000])->assertOk();
        $actualizado = ProgramaInventarioInicial::firstOrFail()->updated_at;
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->travel(5)->minutes();
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 3000])->assertOk()->assertJsonPath('data.cajas', 3000);

        $this->assertEquals($actualizado, ProgramaInventarioInicial::firstOrFail()->updated_at);
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_inventario_inicial_normaliza_ceros_a_la_izquierda(): void
    {
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));
        $blanco = (string) $this->grupo('Blanco')->id;
        $url = self::URL.'/'.$this->programa->id.'/inventario-inicial';

        $this->putJson($url, ['grupo_clave' => '00'.$blanco, 'cajas' => 3000])
            ->assertOk()
            ->assertJsonPath('data.grupo_clave', $blanco);

        $this->assertSame([$blanco], ProgramaInventarioInicial::pluck('grupo_clave')->all());
        $this->assertSame(3000, $this->getJson(self::URL.'?lunes=2026-11-23')->json('data.balance')[$blanco]['inicial']);
    }

    public function test_si_la_difusion_falla_la_escritura_responde_200(): void
    {
        Log::spy();
        // Un difusor que siempre falla, como un Reverb caído.
        config(['broadcasting.default' => 'falla', 'broadcasting.connections.falla' => ['driver' => 'falla']]);
        app(BroadcastManager::class)->extend('falla', fn () => new class extends NullBroadcaster
        {
            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new \RuntimeException('Reverb caído');
            }
        });
        Sanctum::actingAs($this->usuarioCon(self::CONFIGURADOR));

        $this->putJson(self::URL.'/'.$this->programa->id.'/inventario-inicial', ['grupo_clave' => (string) $this->grupo('Blanco')->id, 'cajas' => 10])
            ->assertOk();
        $this->assertSame(1, ProgramaInventarioInicial::count());
        // El fallo sí se intentó emitir y quedó anotado.
        Log::shouldHaveReceived('warning')->once();
    }
}
