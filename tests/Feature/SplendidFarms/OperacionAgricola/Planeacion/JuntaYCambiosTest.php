<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaJunta;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\User;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Junta de la semana (foto del programa), historial de cambios posteriores a
 * la junta, evento de tiempo real e inventario inicial manual.
 */
class JuntaYCambiosTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/planeacion/programa';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($this->programa);
    }

    private function registrarJunta(array $permisos = ['view', 'acuerdos']): TestResponse
    {
        Sanctum::actingAs($this->usuarioCon($permisos));

        return $this->postJson(self::URL.'/'.$this->programa->id.'/registrar-junta');
    }

    public function test_registrar_junta_guarda_foto_y_fecha(): void
    {
        $this->registrarJunta()->assertOk()->assertJsonPath('success', true);

        $programa = $this->programa->fresh();
        $this->assertNotNull($programa->junta_registrada_at);
        $this->assertNotNull($programa->junta_registrada_por);

        $junta = ProgramaJunta::where('programa_id', $programa->id)->firstOrFail();
        $this->assertSame($programa->junta_registrada_por, $junta->registrada_por);
        $this->assertSame('2026-11-23', $junta->foto['programa']['lunes']);
        $this->assertSame(-2893, $junta->foto['balance'][(string) $this->grupo('Blanco')->id]['final']);
        $this->assertSame(36483, collect($junta->foto['balance'])->sum(fn ($g) => array_sum($g['necesidad'])));

        // La semana ya trae la junta registrada.
        $this->getJson(self::URL.'?lunes=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.programa.junta_registrada_por', $programa->junta_registrada_por);
    }

    public function test_registrar_junta_exige_permiso_acuerdos(): void
    {
        $this->registrarJunta(['view', 'configurar'])->assertForbidden();

        $this->assertNull($this->programa->fresh()->junta_registrada_at);
        $this->assertSame(0, ProgramaJunta::count());
    }

    public function test_volver_a_registrar_conserva_las_juntas_y_manda_la_ultima(): void
    {
        $this->registrarJunta()->assertOk();
        $primera = $this->programa->fresh()->junta_registrada_at;

        $this->travel(2)->hours();
        $this->postJson(self::URL.'/'.$this->programa->id.'/registrar-junta')->assertOk();

        $this->assertSame(2, ProgramaJunta::where('programa_id', $this->programa->id)->count());
        $this->assertTrue($this->programa->fresh()->junta_registrada_at->gt($primera));
    }

    public function test_registrar_sin_junta_no_guarda_cambios(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);

        $necesidad = $this->programa->necesidades()->first();
        app(RegistroCambiosPrograma::class)->registrar(
            $this->programa, 'necesidad', $necesidad->id, 'actualizado', ['cajas_2' => 6584], ['cajas_2' => 5584],
        );

        $this->assertSame(0, ProgramaCambio::count());
        Event::assertDispatched(ProgramaSemanalUpdated::class, 1);
    }

    public function test_con_junta_se_guardan_cambios_y_salen_en_el_payload(): void
    {
        $this->registrarJunta()->assertOk();
        $this->programa->refresh();
        $usuario = User::find($this->programa->junta_registrada_por);

        app(RegistroCambiosPrograma::class)->registrar(
            $this->programa, 'necesidad', 5, 'actualizado', ['cajas_2' => 6584], ['cajas_2' => 5584],
        );

        $this->assertSame(1, ProgramaCambio::count());

        $cambios = $this->getJson(self::URL.'?lunes=2026-11-23')->assertOk()->json('data.cambios');
        $this->assertCount(1, $cambios);
        $this->assertSame('actualizado', $cambios[0]['accion']);
        $this->assertSame('necesidad', $cambios[0]['entidad']);
        $this->assertSame($usuario->name, $cambios[0]['usuario']);
        $this->assertStringContainsString('6584', $cambios[0]['resumen']);
    }

    public function test_emitir_solo_emite_el_evento(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);

        app(RegistroCambiosPrograma::class)->emitir($this->programa);

        $this->assertSame(0, ProgramaCambio::count());
        Event::assertDispatched(
            ProgramaSemanalUpdated::class,
            fn ($e) => $e->data['programa_id'] === $this->programa->id && $e->data['lunes'] === '2026-11-23',
        );
    }

    public function test_registrar_junta_emite_el_evento(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->registrarJunta()->assertOk();

        Event::assertDispatched(ProgramaSemanalUpdated::class, 1);
    }

    public function test_evento_cabe_en_reverb(): void
    {
        $evento = new ProgramaSemanalUpdated('2026-11-23', 12345);

        $this->assertSame('programa.updated', $evento->broadcastAs());
        $this->assertSame(
            'private-module.splendidfarms.operacion-agricola.planeacion',
            $evento->broadcastOn()[0]->name,
        );
        $this->assertSame(['lunes' => '2026-11-23', 'programa_id' => 12345], $evento->broadcastWith()['data']);
        $this->assertLessThan(10000, strlen(json_encode($evento->broadcastWith())));
    }

    public function test_inventario_inicial_manual(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        Sanctum::actingAs($this->usuarioCon(['view', 'configurar']));
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

    public function test_inventario_inicial_rechaza_variedad_con_grupo(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'configurar']));
        $clave = 'v'.$this->variedades['Amarillo']->id;

        // Amarillo pertenece a un grupo: su clave implícita no es válida.
        $this->putJson(self::URL.'/'.$this->programa->id.'/inventario-inicial', ['grupo_clave' => $clave, 'cajas' => 10])
            ->assertStatus(422);
    }

    public function test_inventario_inicial_valida_grupo_cajas_y_permiso(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'configurar']));
        $url = self::URL.'/'.$this->programa->id.'/inventario-inicial';
        $blanco = (string) $this->grupo('Blanco')->id;

        $this->putJson($url, ['grupo_clave' => '999999', 'cajas' => 10])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => 'xyz', 'cajas' => 10])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 'mucho'])->assertStatus(422);
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => -5])->assertStatus(422);

        Sanctum::actingAs($this->usuarioCon(['view', 'capturar-corte']));
        $this->putJson($url, ['grupo_clave' => $blanco, 'cajas' => 10])->assertForbidden();
    }

    public function test_con_junta_el_inventario_inicial_queda_en_el_historial(): void
    {
        $this->registrarJunta(['view', 'acuerdos', 'configurar'])->assertOk();
        $blanco = (string) $this->grupo('Blanco')->id;

        $this->putJson(self::URL.'/'.$this->programa->id.'/inventario-inicial', ['grupo_clave' => $blanco, 'cajas' => 3000])->assertOk();

        $cambio = ProgramaCambio::firstOrFail();
        $this->assertSame('inventario_inicial', $cambio->entidad);
        $this->assertSame('creado', $cambio->accion);
        $this->assertSame(3000, $cambio->despues['cajas']);
    }
}
