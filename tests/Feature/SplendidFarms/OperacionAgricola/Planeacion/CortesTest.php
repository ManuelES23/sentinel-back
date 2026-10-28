<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Lote;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Captura de corte (renglones de hectáreas por día) y elección de rendimiento
 * de la semana del Programa semanal.
 */
class CortesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/planeacion';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function comoCapturista(array $extra = []): void
    {
        Sanctum::actingAs($this->usuarioCon(array_merge(['view', 'capturar-corte'], $extra)));
    }

    private function urlCortes(?ProgramaSemanal $p = null, string $sufijo = ''): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/cortes'.$sufijo;
    }

    private function urlRendimientos(): string
    {
        return self::BASE.'/programa/'.$this->programa->id.'/rendimientos';
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    public function test_crea_corte_de_etapa_y_cuenta_cajas(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);

        $this->postJson($this->urlCortes(), [
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa->id,
            'dia' => 0,
            'hectareas' => 7,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.variedad_id', $this->variedades['Amarillo']->id)
            ->assertJsonPath('data.sin_etapa', false);

        $cortes = $this->semana()['cortes'];
        $this->assertCount(1, $cortes);
        $this->assertSame(3850, $cortes[0]['cajas']); // 7 ha × 550
        $this->assertSame($etapa->id, $cortes[0]['etapa_id']);
        $this->assertSame($this->variedades['Amarillo']->id, $cortes[0]['variedad_id']);
    }

    public function test_corte_no_excede_superficie_entre_semanas(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $siguiente = ProgramaSemanal::create(['lunes' => '2026-11-30']);

        $this->postJson($this->urlCortes(), [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 0, 'hectareas' => 6,
        ])->assertCreated();

        $this->postJson($this->urlCortes($siguiente), [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 1, 'hectareas' => 5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('hectareas')
            ->assertJsonPath('errors.hectareas.0', 'La etapa tiene 4.0 ha disponibles');

        $this->assertSame(1, ProgramaCorte::count());

        // Justo lo que queda sí cabe.
        $this->postJson($this->urlCortes($siguiente), [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 1, 'hectareas' => 4,
        ])->assertCreated();
    }

    public function test_editar_un_corte_no_cuenta_sus_propias_hectareas(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $corte = $this->corte($this->programa, 'Amarillo', 0, 6, $etapa);

        // 10 ha caben porque el corte editado no se cuenta contra sí mismo.
        $this->putJson(self::BASE.'/cortes/'.$corte->id, [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 2, 'hectareas' => 10, 'notas' => 'Ajuste',
        ])->assertOk()->assertJsonPath('data.dia', 2);

        $corte->refresh();
        $this->assertSame(2, $corte->dia);
        $this->assertSame('10.00', $corte->hectareas);
        $this->assertSame('Ajuste', $corte->notas);

        $this->putJson(self::BASE.'/cortes/'.$corte->id, [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 2, 'hectareas' => 10.5,
        ])->assertUnprocessable()->assertJsonPath('errors.hectareas.0', 'La etapa tiene 10.0 ha disponibles');
    }

    public function test_corte_sin_etapa_requiere_variedad_del_cultivo(): void
    {
        $this->comoCapturista();
        $base = ['temporada_id' => $this->temporada->id, 'dia' => 3, 'hectareas' => 2.5];

        $this->postJson($this->urlCortes(), $base)->assertUnprocessable()->assertJsonValidationErrors('variedad_id');

        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);
        $this->postJson($this->urlCortes(), $base + ['variedad_id' => $ajena->id])
            ->assertUnprocessable()->assertJsonValidationErrors('variedad_id');

        $this->postJson($this->urlCortes(), $base + ['variedad_id' => $this->variedades['Blanco']->id])
            ->assertCreated()->assertJsonPath('data.sin_etapa', true);

        $corte = $this->semana()['cortes'][0];
        $this->assertTrue($corte['sin_etapa']);
        $this->assertNull($corte['etapa_id']);
        $this->assertSame(2.5, $corte['hectareas']);
        $this->assertSame(1750, $corte['cajas']); // 2.5 ha × 700 (medio de Blanco)
    }

    public function test_la_temporada_debe_estar_abierta_en_la_semana(): void
    {
        $this->comoCapturista();
        $vieja = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'OI 2025-2026',
            'locacion' => 'Los Mochis',
            'folio_temporada' => $this->cultivo->id.'-002',
            'año_inicio' => 2025,
            'año_fin' => 2026,
            'fecha_inicio' => '2025-11-01',
            'fecha_fin' => '2026-06-01',
            'estado' => 'abierta',
            'user_id' => $this->autor->id,
        ]);

        $this->postJson($this->urlCortes(), [
            'temporada_id' => $vieja->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 0, 'hectareas' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('temporada_id');
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_la_etapa_debe_ser_de_un_lote_activo_de_la_temporada(): void
    {
        $this->comoCapturista();
        $ajeno = Lote::create([
            'productor_id' => $this->lote->productor_id,
            'zona_cultivo_id' => $this->lote->zona_cultivo_id,
            'nombre' => 'Sin temporada',
            'superficie' => 20,
            'is_active' => true,
        ]);
        $etapa = $this->crearEtapa('Amarillo', 5, ['lote_id' => $ajeno->id]);

        $this->postJson($this->urlCortes(), [
            'temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 0, 'hectareas' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('etapa_id');
    }

    public function test_hectareas_deben_ser_positivas_con_dos_decimales(): void
    {
        $this->comoCapturista();
        $base = ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 0];

        foreach ([0, -1, 1.234, 'abc'] as $ha) {
            $this->postJson($this->urlCortes(), $base + ['hectareas' => $ha])
                ->assertUnprocessable()->assertJsonValidationErrors('hectareas');
        }
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_repartir_crea_varios_cortes_y_valida_la_suma(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Bicolor', 10);
        $datos = fn (array $reparto) => [
            'etapa_id' => $etapa->id, 'temporada_id' => $this->temporada->id, 'reparto' => $reparto,
        ];

        // La suma (11) rebasa las 10 ha aunque cada parte quepa sola.
        $this->postJson($this->urlCortes(null, '/repartir'), $datos([['dia' => 0, 'hectareas' => 6], ['dia' => 1, 'hectareas' => 5]]))
            ->assertUnprocessable()->assertJsonPath('errors.hectareas.0', 'La etapa tiene 10.0 ha disponibles');
        $this->assertSame(0, ProgramaCorte::count());

        $this->postJson($this->urlCortes(null, '/repartir'), $datos([['dia' => 0, 'hectareas' => 6], ['dia' => 1, 'hectareas' => 4]]))
            ->assertCreated()->assertJsonCount(2, 'data');

        $this->assertSame(2, ProgramaCorte::where('etapa_id', $etapa->id)->count());
        $this->assertSame($this->variedades['Bicolor']->id, ProgramaCorte::first()->variedad_id);
    }

    public function test_repartir_es_atomico(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Bicolor', 10);

        $this->postJson($this->urlCortes(null, '/repartir'), [
            'etapa_id' => $etapa->id,
            'temporada_id' => $this->temporada->id,
            'reparto' => [['dia' => 0, 'hectareas' => 2], ['dia' => 6, 'hectareas' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('reparto.1.dia');

        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_dia_fuera_de_rango_es_422(): void
    {
        $this->comoCapturista();

        foreach ([6, -1] as $dia) {
            $this->postJson($this->urlCortes(), [
                'temporada_id' => $this->temporada->id,
                'variedad_id' => $this->variedades['Blanco']->id,
                'dia' => $dia,
                'hectareas' => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors('dia');
        }
    }

    public function test_borrar_un_corte(): void
    {
        $this->comoCapturista();
        $corte = $this->corte($this->programa, 'Blanco', 1, 3);

        $this->deleteJson(self::BASE.'/cortes/'.$corte->id)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, ProgramaCorte::count());
        $this->assertSame([], $this->semana()['cortes']);
    }

    public function test_con_junta_cada_escritura_deja_historial_con_las_mismas_llaves(): void
    {
        $this->comoCapturista();
        $this->programa->update(['junta_registrada_at' => now()]);
        $datos = [
            'temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 1, 'hectareas' => 3,
        ];

        $id = $this->postJson($this->urlCortes(), $datos)->assertCreated()->json('data.id');
        $this->putJson(self::BASE.'/cortes/'.$id, array_merge($datos, ['hectareas' => 4]))->assertOk();
        $this->deleteJson(self::BASE.'/cortes/'.$id)->assertOk();

        $cambios = ProgramaCambio::where('entidad', 'corte')->orderBy('id')->get();
        $this->assertSame(['creado', 'actualizado', 'borrado'], $cambios->pluck('accion')->all());
        $this->assertSame($id, $cambios[0]->entidad_id);
        $this->assertNull($cambios[0]->antes);
        $llaves = array_keys($cambios[0]->despues);
        $this->assertSame($llaves, array_keys($cambios[1]->antes));
        $this->assertSame($llaves, array_keys($cambios[1]->despues));
        $this->assertSame($llaves, array_keys($cambios[2]->antes));
        $this->assertNull($cambios[2]->despues);
        $this->assertEquals(3, $cambios[1]->antes['hectareas']);
        $this->assertEquals(4, $cambios[1]->despues['hectareas']);
    }

    public function test_editar_un_corte_usa_el_programa_del_corte(): void
    {
        $this->comoCapturista();
        $otra = ProgramaSemanal::create(['lunes' => '2026-11-30', 'junta_registrada_at' => now()]);
        $corte = ProgramaCorte::create([
            'programa_id' => $otra->id,
            'temporada_id' => $this->temporada->id,
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 0,
            'hectareas' => 1,
        ]);

        $this->putJson(self::BASE.'/cortes/'.$corte->id, [
            'temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 4, 'hectareas' => 2,
        ])->assertOk();

        $this->assertSame($otra->id, ProgramaCambio::where('entidad', 'corte')->value('programa_id'));
    }

    public function test_rendimiento_exacto_sin_valor_es_422(): void
    {
        $this->comoCapturista();
        $fila = ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Amarillo']->id, 'modo' => 'exacto'];

        $this->putJson($this->urlRendimientos(), ['filas' => [$fila]])
            ->assertUnprocessable()->assertJsonValidationErrors('filas.0.valor_exacto');
        $this->putJson($this->urlRendimientos(), ['filas' => [$fila + ['valor_exacto' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('filas.0.valor_exacto');
        $this->putJson($this->urlRendimientos(), ['filas' => [array_merge($fila, ['modo' => 'otro'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('filas.0.modo');

        $this->assertSame(0, ProgramaRendimiento::count());
    }

    public function test_rendimientos_se_guardan_por_semana_y_cambian_las_cajas(): void
    {
        $this->comoCapturista();
        $this->corte($this->programa, 'Amarillo', 0, 2);
        $amarillo = $this->variedades['Amarillo']->id;
        $fila = fn (array $extra) => ['temporada_id' => $this->temporada->id, 'variedad_id' => $amarillo] + $extra;

        $this->putJson($this->urlRendimientos(), ['filas' => [$fila(['modo' => 'maximo'])]])->assertOk();
        $this->assertSame(1200, $this->semana()['cortes'][0]['cajas']); // 2 ha × 600

        // Se actualiza la misma fila (no se duplica) y el valor exacto manda.
        $this->putJson($this->urlRendimientos(), ['filas' => [$fila(['modo' => 'exacto', 'valor_exacto' => 580.5])]])->assertOk();
        $this->assertSame(1, ProgramaRendimiento::count());
        $this->assertSame(1161, $this->semana()['cortes'][0]['cajas']);

        // Pasar a otro modo borra el valor exacto guardado.
        $this->putJson($this->urlRendimientos(), ['filas' => [$fila(['modo' => 'minimo', 'valor_exacto' => 999])]])->assertOk();
        $this->assertNull(ProgramaRendimiento::first()->valor_exacto);
        $this->assertSame(1000, $this->semana()['cortes'][0]['cajas']);

        // Otra semana no se ve afectada.
        $otra = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->assertSame(0, ProgramaRendimiento::where('programa_id', $otra->id)->count());
    }

    public function test_rendimiento_de_variedad_ajena_a_la_temporada_es_422(): void
    {
        $this->comoCapturista();
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);

        $this->putJson($this->urlRendimientos(), ['filas' => [
            ['temporada_id' => $this->temporada->id, 'variedad_id' => $ajena->id, 'modo' => 'medio'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('filas.0.variedad_id');
    }

    public function test_escritura_emite_programa_updated(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->comoCapturista();
        $datos = ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 1, 'hectareas' => 3];

        $id = $this->postJson($this->urlCortes(), $datos)->assertCreated()->json('data.id');
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $this->putJson(self::BASE.'/cortes/'.$id, $datos)->assertOk();
        $this->deleteJson(self::BASE.'/cortes/'.$id)->assertOk();
        $this->putJson($this->urlRendimientos(), ['filas' => [
            ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'modo' => 'maximo'],
        ]])->assertOk();

        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 4);
    }

    public function test_sin_permiso_capturar_corte_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'capturar-ventas']));
        $corte = $this->corte($this->programa, 'Blanco', 0, 1);
        $datos = ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 1, 'hectareas' => 3];

        $this->postJson($this->urlCortes(), $datos)->assertForbidden();
        $this->putJson(self::BASE.'/cortes/'.$corte->id, $datos)->assertForbidden();
        $this->deleteJson(self::BASE.'/cortes/'.$corte->id)->assertForbidden();
        $this->postJson($this->urlCortes(null, '/repartir'), ['etapa_id' => 1, 'temporada_id' => 1, 'reparto' => []])->assertForbidden();
        $this->putJson($this->urlRendimientos(), ['filas' => []])->assertForbidden();

        $this->assertSame(1, ProgramaCorte::count());
        $this->assertSame(0, ProgramaRendimiento::count());
    }
}
