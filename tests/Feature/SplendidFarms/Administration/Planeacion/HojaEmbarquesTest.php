<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hoja de embarques por cargas (guardado en lote de celdas cultivo · bodega ·
 * tipo · día) y notas libres de la semana.
 */
class HojaEmbarquesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private const MENSAJE_TEMPORADA = 'El cultivo no tiene una temporada abierta en esta semana.';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function comoLogistica(): void
    {
        Sanctum::actingAs($this->usuarioCon(['embarques' => ['view', 'capturar']]));
    }

    private function urlHoja(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/embarques/hoja';
    }

    private function urlNotas(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/notas';
    }

    private function celda(string $bodega, string $tipo, int $dia, ?int $cargas, ?int $cultivoId = null): array
    {
        return [
            'cultivo_id' => $cultivoId ?? $this->cultivo->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'tipo' => $tipo,
            'dia' => $dia,
            'cargas' => $cargas,
        ];
    }

    private function embarque(string $bodega, string $tipo, int $dia, int $cargas, ?ProgramaSemanal $p = null): ProgramaEmbarque
    {
        return ProgramaEmbarque::create([
            'programa_id' => ($p ?? $this->programa)->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'tipo' => $tipo,
            'dia' => $dia,
            'cargas' => $cargas,
        ]);
    }

    private function guardar(array $celdas, ?ProgramaSemanal $p = null)
    {
        return $this->putJson($this->urlHoja($p), ['celdas' => $celdas]);
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: int}> bodega, tipo, día, cargas. */
    private function guardadas(?ProgramaSemanal $p = null): array
    {
        return ProgramaEmbarque::where('programa_id', ($p ?? $this->programa)->id)
            ->orderBy('bodega_id')->orderBy('tipo')->orderBy('dia')->get()
            ->map(fn ($e) => [$e->bodega->nombre, $e->tipo, $e->dia, $e->cargas])->all();
    }

    // ── Hoja de embarques ───────────────────────────────

    public function test_guarda_cargas_por_celda_y_cero_borra(): void
    {
        $this->comoLogistica();
        $vieja = $this->embarque('Texas', 'full', 1, 4);
        $this->embarque('Nogales', 'sencillo', 2, 2);
        Event::fake([ProgramaSemanalUpdated::class]);

        $respuesta = $this->guardar([
            $this->celda('Texas', 'full', 0, 3),
            $this->celda('Texas', 'full', 1, 0),
            $this->celda('Nogales', 'sencillo', 2, 5),
            $this->celda('Nogales', 'full', 4, 99),
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertNull(ProgramaEmbarque::find($vieja->id));
        $this->assertEqualsCanonicalizing([
            ['Texas', 'full', 0, 3],
            ['Nogales', 'sencillo', 2, 5],
            ['Nogales', 'full', 4, 99],
        ], $this->guardadas());

        // La respuesta trae la semana, como el show del programa.
        $this->assertCount(3, $respuesta->json('data.embarques'));
        $this->assertSame($this->programa->id, $respuesta->json('data.programa.id'));

        // Un solo aviso por la petición completa.
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);
    }

    public function test_cargas_vacias_o_nulas_borran_la_celda(): void
    {
        $this->comoLogistica();
        $this->embarque('Texas', 'full', 1, 4);
        $this->embarque('Texas', 'full', 2, 1);

        $this->putJson($this->urlHoja(), ['celdas' => [
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 1, 'cargas' => null],
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 2, 'cargas' => ''],
        ]])->assertOk();

        $this->assertSame([], $this->guardadas());
    }

    public function test_solo_toca_las_celdas_enviadas(): void
    {
        $this->comoLogistica();
        $this->embarque('Texas', 'full', 0, 3);
        $this->embarque('Texas', 'sencillo', 0, 1);
        $otraSemana = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->embarque('Texas', 'full', 0, 7, $otraSemana);

        $this->guardar([$this->celda('Texas', 'full', 3, 2)])->assertOk();

        $this->assertEqualsCanonicalizing([
            ['Texas', 'full', 0, 3],
            ['Texas', 'sencillo', 0, 1],
            ['Texas', 'full', 3, 2],
        ], $this->guardadas());
        $this->assertSame([['Texas', 'full', 0, 7]], $this->guardadas($otraSemana));
    }

    public function test_actualiza_la_celda_existente_sin_duplicarla(): void
    {
        $this->comoLogistica();
        $this->embarque('Texas', 'full', 0, 3);

        $this->guardar([$this->celda('Texas', 'full', 0, 6)])->assertOk();

        $this->assertSame([['Texas', 'full', 0, 6]], $this->guardadas());
    }

    public function test_sin_cambios_reales_no_emite_aviso(): void
    {
        $this->comoLogistica();
        $this->embarque('Texas', 'full', 0, 3);
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->guardar([
            $this->celda('Texas', 'full', 0, 3),     // mismo valor
            $this->celda('Nogales', 'full', 2, 0),   // borrar lo que no existe
        ])->assertOk();

        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
        $this->assertSame([['Texas', 'full', 0, 3]], $this->guardadas());
    }

    public function test_cultivo_sin_temporada_abierta_en_la_semana_es_422(): void
    {
        $this->comoLogistica();
        // WK47: la temporada OI empieza el 23 nov.
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);

        $this->guardar([$this->celda('Texas', 'full', 0, 2)], $wk47)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['celdas.0.cultivo_id' => self::MENSAJE_TEMPORADA]);
        $this->assertSame([], $this->guardadas($wk47));

        // Una celda buena y otra mala en el mismo lote: no se guarda ninguna.
        $otro = Cultivo::create(['nombre' => 'Pepino']);
        $this->guardar([
            $this->celda('Texas', 'full', 0, 2),
            $this->celda('Texas', 'full', 1, 2, $otro->id),
        ])->assertUnprocessable()->assertJsonValidationErrors(['celdas.1.cultivo_id']);
        $this->assertSame([], $this->guardadas());
    }

    public function test_borrar_y_bajar_se_puede_aunque_ya_no_haya_temporada_ni_bodega_activa(): void
    {
        $this->comoLogistica();
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->embarque('Texas', 'full', 0, 4, $wk47);
        $this->embarque('Texas', 'full', 1, 2, $wk47);
        $this->embarque('Texas', 'sencillo', 2, 5, $wk47);
        $this->bodega('Texas')->update(['is_active' => false]);

        // Borrar una, dejar otra igual y bajar la tercera no depende del catálogo.
        $this->guardar([
            $this->celda('Texas', 'full', 0, 0),
            $this->celda('Texas', 'full', 1, 2),
            $this->celda('Texas', 'sencillo', 2, 3),
        ], $wk47)->assertOk();

        $this->assertEqualsCanonicalizing([
            ['Texas', 'full', 1, 2],
            ['Texas', 'sencillo', 2, 3],
        ], $this->guardadas($wk47));

        // Subir sí exige bodega activa y temporada.
        $this->guardar([$this->celda('Texas', 'sencillo', 2, 4)], $wk47)->assertUnprocessable();
        $this->assertSame(3, ProgramaEmbarque::where('programa_id', $wk47->id)->where('tipo', 'sencillo')->value('cargas'));
    }

    public function test_bodega_desactivada_o_inexistente_es_422_al_capturar(): void
    {
        $this->comoLogistica();
        $this->bodega('Mexicali')->update(['is_active' => false]);

        $this->guardar([$this->celda('Mexicali', 'full', 0, 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['celdas.0.bodega_id']);

        $celda = $this->celda('Texas', 'full', 0, 1);
        $celda['bodega_id'] = 999999;
        $this->guardar([$celda])->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.bodega_id']);
        $this->assertSame([], $this->guardadas());
    }

    public function test_valida_tipo_dia_y_tope_de_cargas(): void
    {
        $this->comoLogistica();

        $this->guardar([array_merge($this->celda('Texas', 'full', 0, 1), ['tipo' => 'doble'])])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.tipo']);
        $this->guardar([$this->celda('Texas', 'full', 6, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.dia']);
        $this->guardar([$this->celda('Texas', 'full', -1, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.dia']);
        $this->guardar([$this->celda('Texas', 'full', 0, 100)])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.cargas']);
        $this->guardar([$this->celda('Texas', 'full', 0, -1)])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.cargas']);
        $this->guardar([array_merge($this->celda('Texas', 'full', 0, 1), ['cargas' => 1.5])])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.cargas']);
        $this->putJson($this->urlHoja(), ['celdas' => [['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['celdas.0.cargas']);

        $this->assertSame([], $this->guardadas());
    }

    public function test_celda_repetida_en_la_misma_peticion_es_422_en_la_segunda(): void
    {
        $this->comoLogistica();

        $this->guardar([
            $this->celda('Texas', 'full', 0, 2),
            $this->celda('Nogales', 'full', 0, 2),
            $this->celda('Texas', 'full', 0, 5),
        ])->assertUnprocessable()->assertJsonValidationErrors(['celdas.2.cargas']);

        $this->assertSame([], $this->guardadas());
    }

    public function test_embarques_exige_capturar(): void
    {
        Sanctum::actingAs($this->usuarioCon(['embarques' => ['view'], 'tablero-semanal' => ['view', 'editar-notas']]));

        $this->guardar([$this->celda('Texas', 'full', 0, 2)])->assertForbidden();
        $this->assertSame([], $this->guardadas());
    }

    public function test_programa_inexistente_es_404(): void
    {
        $this->comoLogistica();

        $this->putJson(self::BASE.'/programa/999999/embarques/hoja', ['celdas' => []])->assertNotFound();
    }

    public function test_hoja_vacia_responde_ok_sin_aviso(): void
    {
        $this->comoLogistica();
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->putJson($this->urlHoja(), [])->assertOk();

        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_el_show_ya_trae_los_embarques_guardados(): void
    {
        $this->comoLogistica();

        $this->guardar([
            $this->celda('Nogales', 'sencillo', 2, 1),
            $this->celda('Nogales', 'full', 0, 3),
        ])->assertOk();

        $d = $this->getJson(self::BASE.'/programa?lunes=2026-11-23')->assertOk()->json('data');
        $nogales = $this->bodega('Nogales')->id;
        $this->assertSame([
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $nogales, 'tipo' => 'full', 'dia' => 0, 'cargas' => 3],
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $nogales, 'tipo' => 'sencillo', 'dia' => 2, 'cargas' => 1],
        ], $d['embarques']);
    }

    // ── Notas de la semana ──────────────────────────────

    public function test_notas_requieren_editar_notas_y_se_ven_en_el_payload(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $this->putJson($this->urlNotas(), ['notas' => 'Revisar Vallarta'])->assertForbidden();
        $this->assertNull($this->programa->fresh()->notas);

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'editar-notas']]));
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->putJson($this->urlNotas(), ['notas' => 'Revisar Vallarta'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.programa.notas', 'Revisar Vallarta');

        $this->assertSame('Revisar Vallarta', $this->programa->fresh()->notas);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // El show ya la devuelve.
        $this->getJson(self::BASE.'/programa?lunes=2026-11-23')
            ->assertOk()->assertJsonPath('data.programa.notas', 'Revisar Vallarta');
    }

    public function test_notas_vacias_o_nulas_las_borran_y_sin_cambio_no_emite_aviso(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'editar-notas']]));
        $this->programa->update(['notas' => 'Algo']);
        Event::fake([ProgramaSemanalUpdated::class]);

        // Mismo texto: no hay cambio, no hay aviso.
        $this->putJson($this->urlNotas(), ['notas' => 'Algo'])->assertOk();
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);

        $this->putJson($this->urlNotas(), ['notas' => null])
            ->assertOk()->assertJsonPath('data.programa.notas', null);
        $this->assertNull($this->programa->fresh()->notas);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // Vacío sobre vacío tampoco avisa.
        $this->putJson($this->urlNotas(), ['notas' => '   '])->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
    }

    public function test_notas_validan_tipo_y_largo(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view', 'editar-notas']]));

        $this->putJson($this->urlNotas(), ['notas' => str_repeat('a', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['notas']);
        $this->putJson($this->urlNotas(), ['notas' => ['x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['notas']);
        $this->putJson($this->urlNotas(), [])
            ->assertUnprocessable()->assertJsonValidationErrors(['notas']);

        $this->putJson($this->urlNotas(), ['notas' => str_repeat('a', 5000)])->assertOk();
    }
}
