<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaViaje;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Viajes de logística (Full y Sencillo) del Programa semanal.
 */
class ViajesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function comoLogistico(array $extra = []): void
    {
        Sanctum::actingAs($this->usuarioCon(array_merge(['view', 'capturar-logistica'], $extra)));
    }

    private function url(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/viajes';
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    /** Full del jueves a Nogales con las dos cajas del Excel. */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'dia' => 3,
            'bodega_id' => $this->bodega('Nogales')->id,
            'cultivo_id' => $this->cultivo->id,
            'tipo' => 'full',
            'cajas_caja_1' => 1040,
            'cajas_caja_2' => 980,
            'notas' => 'Sale a las 6',
        ], $cambios);
    }

    private function filaLogistica(array $data, string $bodega): array
    {
        $id = $this->bodega($bodega)->id;
        foreach ($data['logistica'] as $fila) {
            if ($fila['bodega_id'] === $id) {
                return $fila;
            }
        }
        $this->fail("Sin fila de logística para {$bodega}");
    }

    public function test_full_suma_dos_cajas_de_trailer(): void
    {
        $this->comoLogistico();

        $this->postJson($this->url(), $this->datos())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'full')
            ->assertJsonPath('data.cajas_cajas', [1040, 980]);

        $data = $this->semana();
        $this->assertSame(2020, $this->filaLogistica($data, 'Nogales')['planeado'][3]);
        $this->assertSame([1040, 980], $data['viajes'][0]['cajas_cajas']);
    }

    public function test_full_sin_segunda_caja_es_422(): void
    {
        $this->comoLogistico();

        $sin = $this->datos();
        unset($sin['cajas_caja_2']);
        $this->postJson($this->url(), $sin)->assertUnprocessable()->assertJsonValidationErrors('cajas_caja_2');
        $this->postJson($this->url(), $this->datos(['cajas_caja_2' => null]))->assertUnprocessable()->assertJsonValidationErrors('cajas_caja_2');

        $this->assertSame(0, ProgramaViaje::count());
    }

    public function test_sencillo_ignora_segunda_caja(): void
    {
        $this->comoLogistico();

        $this->postJson($this->url(), $this->datos(['tipo' => 'sencillo', 'cajas_caja_2' => 500]))
            ->assertCreated()
            ->assertJsonPath('data.cajas_cajas', [1040]);

        $this->assertNull(ProgramaViaje::firstOrFail()->cajas_caja_2);
        $this->assertSame(1040, $this->filaLogistica($this->semana(), 'Nogales')['planeado'][3]);

        // Sencillo sin segunda caja también es válido.
        $sin = $this->datos(['tipo' => 'sencillo']);
        unset($sin['cajas_caja_2']);
        $this->postJson($this->url(), $sin)->assertCreated();
    }

    public function test_escritura_emite_programa_updated(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->comoLogistico();

        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $this->putJson(self::BASE.'/viajes/'.$id, $this->datos(['cajas_caja_1' => 1000]))->assertOk();
        $this->deleteJson(self::BASE.'/viajes/'.$id)->assertOk();

        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 3);
    }

    public function test_sin_permiso_capturar_logistica_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'capturar-ventas', 'capturar-corte']));
        $viaje = ProgramaViaje::create([
            'programa_id' => $this->programa->id,
            'dia' => 1,
            'bodega_id' => $this->bodega('Nogales')->id,
            'cultivo_id' => $this->cultivo->id,
            'tipo' => 'sencillo',
            'cajas_caja_1' => 900,
        ]);

        $this->postJson($this->url(), $this->datos())->assertForbidden();
        $this->putJson(self::BASE.'/viajes/'.$viaje->id, $this->datos())->assertForbidden();
        $this->deleteJson(self::BASE.'/viajes/'.$viaje->id)->assertForbidden();

        $this->assertSame(1, ProgramaViaje::count());
        $this->assertSame(900, $viaje->fresh()->cajas_caja_1);
    }

    public function test_actualiza_reemplaza_todo_y_pasa_de_full_a_sencillo(): void
    {
        $this->comoLogistico();
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');

        $this->putJson(self::BASE.'/viajes/'.$id, $this->datos([
            'tipo' => 'sencillo',
            'dia' => 4,
            'cajas_caja_1' => 800,
            'cajas_caja_2' => 700,
            'notas' => null,
        ]))->assertOk()->assertJsonPath('data.cajas_cajas', [800]);

        $viaje = ProgramaViaje::findOrFail($id);
        $this->assertSame('sencillo', $viaje->tipo);
        $this->assertSame(4, $viaje->dia);
        $this->assertNull($viaje->cajas_caja_2);
        $this->assertNull($viaje->notas);

        $fila = $this->filaLogistica($this->semana(), 'Nogales');
        $this->assertSame(0, $fila['planeado'][3]);
        $this->assertSame(800, $fila['planeado'][4]);
    }

    public function test_elimina_el_viaje(): void
    {
        $this->comoLogistico();
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');

        $this->deleteJson(self::BASE.'/viajes/'.$id)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, ProgramaViaje::count());
        $this->assertSame([], $this->semana()['viajes']);
    }

    public function test_validaciones_de_campos(): void
    {
        $this->comoLogistico();

        foreach ([-1, 6, 'x'] as $dia) {
            $this->postJson($this->url(), $this->datos(['dia' => $dia]))->assertUnprocessable()->assertJsonValidationErrors('dia');
        }
        $this->postJson($this->url(), $this->datos(['tipo' => 'doble']))->assertUnprocessable()->assertJsonValidationErrors('tipo');

        foreach ([-1, 1.5, 1000000, null] as $cajas) {
            $this->postJson($this->url(), $this->datos(['cajas_caja_1' => $cajas]))->assertUnprocessable()->assertJsonValidationErrors('cajas_caja_1');
        }
        foreach ([-1, 1.5, 1000000] as $cajas) {
            $this->postJson($this->url(), $this->datos(['cajas_caja_2' => $cajas]))->assertUnprocessable()->assertJsonValidationErrors('cajas_caja_2');
        }
        $this->postJson($this->url(), $this->datos(['notas' => str_repeat('a', 256)]))->assertUnprocessable()->assertJsonValidationErrors('notas');

        // Cero cajas es válido.
        $this->postJson($this->url(), $this->datos(['cajas_caja_1' => 0, 'cajas_caja_2' => 0]))->assertCreated();

        // Bodega inexistente o inactiva.
        $this->postJson($this->url(), $this->datos(['bodega_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('bodega_id');
        $bodega = $this->bodega('Nogales');
        $bodega->update(['is_active' => false]);
        $this->postJson($this->url(), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('bodega_id');
        $bodega->update(['is_active' => true]);

        $this->assertSame(1, ProgramaViaje::count());
    }

    public function test_cultivo_debe_ser_de_una_temporada_abierta_de_la_semana(): void
    {
        $this->comoLogistico();

        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $this->postJson($this->url(), $this->datos(['cultivo_id' => $otro->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('cultivo_id');

        // Con temporada abierta del otro cultivo que toca la semana, sí se acepta.
        Temporada::create([
            'cultivo_id' => $otro->id,
            'nombre' => 'Tomate OI',
            'locacion' => 'Culiacán',
            'folio_temporada' => $otro->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2027,
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2027-03-01',
            'estado' => 'abierta',
            'user_id' => $this->autor->id,
        ]);
        $this->postJson($this->url(), $this->datos(['cultivo_id' => $otro->id]))->assertCreated();

        // Una semana sin temporadas abiertas no admite viajes.
        $lejana = ProgramaSemanal::create(['lunes' => '2028-01-03']);
        $this->postJson($this->url($lejana), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('cultivo_id');
    }

    public function test_historial_solo_con_junta_y_sin_cambio_no_registra_ni_emite(): void
    {
        $this->comoLogistico();
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        $this->assertSame(0, ProgramaCambio::count(), 'Antes de la junta todo es borrador');

        $this->programa->update(['junta_registrada_at' => now(), 'junta_registrada_por' => $this->autor->id]);
        Event::fake([ProgramaSemanalUpdated::class]);

        // Mismos valores: ni historial ni aviso.
        $this->putJson(self::BASE.'/viajes/'.$id, $this->datos())->assertOk();
        $this->assertSame(0, ProgramaCambio::count());
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);

        // Cambio real: queda con las mismas llaves antes y después.
        $this->putJson(self::BASE.'/viajes/'.$id, $this->datos(['cajas_caja_1' => 1000]))->assertOk();
        $cambio = ProgramaCambio::firstOrFail();
        $this->assertSame('viaje', $cambio->entidad);
        $this->assertSame('actualizado', $cambio->accion);
        $this->assertSame(1040, $cambio->antes['cajas_caja_1']);
        $this->assertSame(1000, $cambio->despues['cajas_caja_1']);
        $this->assertSame(array_keys($cambio->antes), array_keys($cambio->despues));
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $this->deleteJson(self::BASE.'/viajes/'.$id)->assertOk();
        $this->assertSame(['actualizado', 'borrado'], ProgramaCambio::orderBy('id')->pluck('accion')->all());
    }
}
