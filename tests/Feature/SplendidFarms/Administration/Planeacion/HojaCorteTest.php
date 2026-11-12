<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Lote;
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
 * Hoja de corte de la semana (guardado en lote de celdas etapa × día) y
 * elección de rendimiento de la semana.
 */
class HojaCorteTest extends TestCase
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

    private function comoCapturista(): void
    {
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view', 'capturar']]));
    }

    private function urlHoja(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/cortes/hoja';
    }

    private function urlRendimientos(): string
    {
        return self::BASE.'/programa/'.$this->programa->id.'/rendimientos';
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    /** @return array{temporada_id: int, etapa_id: int, dia: int, hectareas: mixed} */
    private function celda($etapa, int $dia, $hectareas): array
    {
        return ['temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => $dia, 'hectareas' => $hectareas];
    }

    // ── Guardado en lote ────────────────────────────────

    public function test_guarda_varias_celdas_en_una_peticion(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->comoCapturista();
        $amarillo = $this->crearEtapa('Amarillo', 10);
        $blanco = $this->crearEtapa('Blanco', 6);

        $respuesta = $this->putJson($this->urlHoja(), ['celdas' => [
            $this->celda($amarillo, 0, 7),
            $this->celda($amarillo, 2, 2.5),
            $this->celda($blanco, 1, 3),
        ]])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(3, ProgramaCorte::count());
        $this->assertSame($this->variedades['Amarillo']->id, ProgramaCorte::where('etapa_id', $amarillo->id)->first()->variedad_id);

        // La respuesta es el payload completo de la semana, igual que el GET.
        $this->assertCount(3, $respuesta->json('data.cortes'));
        $this->assertSame($this->semana()['cortes'], $respuesta->json('data.cortes'));

        $cortes = collect($this->semana()['cortes']);
        $this->assertSame(3850, $cortes->first(fn ($c) => $c['etapa_id'] === $amarillo->id && $c['dia'] === 0)['cajas']); // 7 ha × 550
        $this->assertSame(2100, $cortes->first(fn ($c) => $c['etapa_id'] === $blanco->id)['cajas']); // 3 ha × 700

        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
    }

    public function test_celda_en_cero_borra_y_duplicados_viejos_se_juntan(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->corte($this->programa, 'Amarillo', 1, 2, $etapa);
        $this->corte($this->programa, 'Amarillo', 1, 1, $etapa);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 4)]])->assertOk();

        $cortes = ProgramaCorte::where('etapa_id', $etapa->id)->get();
        $this->assertCount(1, $cortes);
        $this->assertSame('4.00', $cortes[0]->hectareas);

        // Cero y vacío borran el corte de esa etapa y día.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 0)]])->assertOk();
        $this->assertSame(0, ProgramaCorte::count());

        $this->corte($this->programa, 'Amarillo', 3, 2, $etapa);
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 3, null)]])->assertOk();
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_solo_toca_las_celdas_enviadas(): void
    {
        $this->comoCapturista();
        $primera = $this->crearEtapa('Amarillo', 10);
        $segunda = $this->crearEtapa('Bicolor', 10);
        $this->corte($this->programa, 'Amarillo', 0, 7, $primera);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($segunda, 2, 5)]])->assertOk();

        $this->assertSame(2, ProgramaCorte::count());
        $this->assertSame('7.00', ProgramaCorte::where('etapa_id', $primera->id)->value('hectareas'));
        $this->assertSame('5.00', ProgramaCorte::where('etapa_id', $segunda->id)->value('hectareas'));
    }

    public function test_cambiar_una_celda_no_cuenta_sus_propias_hectareas_contra_la_etapa(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->corte($this->programa, 'Amarillo', 0, 6, $etapa);

        // 10 ha caben porque el corte que se reemplaza no se cuenta contra sí mismo.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, 10)]])->assertOk();
        $this->assertSame('10.00', ProgramaCorte::first()->hectareas);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, 10.5)]])->assertUnprocessable();
        $this->assertSame('10.00', ProgramaCorte::first()->hectareas);
    }

    // ── Tope por etapa ──────────────────────────────────

    public function test_etapa_que_se_pasa_de_su_superficie_no_guarda_nada(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 8);
        $otra = $this->crearEtapa('Blanco', 5);
        $siguiente = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->corte($this->programa, 'Amarillo', 0, 5, $etapa);

        $respuesta = $this->putJson($this->urlHoja($siguiente), ['celdas' => [
            $this->celda($etapa, 1, 4),
            $this->celda($otra, 1, 2),
        ]])->assertUnprocessable()
            ->assertJsonValidationErrors('celdas.0.hectareas')
            ->assertJsonMissingValidationErrors('celdas.1.hectareas');

        $texto = $respuesta->json('errors')['celdas.0.hectareas'][0];
        // 8 ha de superficie − 5 en WK47 = 3 libres; pedir 4 pasa 1.
        $this->assertSame($etapa->codigo.': pasa 1.00 ha de las 3.00 disponibles (superficie 8.00 ha).', $texto);

        $this->assertSame(1, ProgramaCorte::count());
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);

        // Justo lo que queda sí cabe.
        $this->putJson($this->urlHoja($siguiente), ['celdas' => [$this->celda($etapa, 1, 3), $this->celda($otra, 1, 2)]])->assertOk();
        $this->assertSame(3, ProgramaCorte::count());
    }

    public function test_el_tope_suma_las_celdas_de_la_misma_etapa_del_lote(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 8);

        // 5 + 4 en dos días de la misma etapa pasan de 8; el error cae en la segunda.
        $this->putJson($this->urlHoja(), ['celdas' => [
            $this->celda($etapa, 0, 5),
            $this->celda($etapa, 1, 4),
        ]])->assertUnprocessable()
            ->assertJsonValidationErrors('celdas.1.hectareas')
            ->assertJsonMissingValidationErrors('celdas.0.hectareas');

        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_el_tope_usa_dos_decimales(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->corte($this->programa, 'Amarillo', 0, 2.15, $etapa);

        // Quedan 7.85: con 1 decimal diría 7.9 y escribir 7.9 volvería a dar 422.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 7.9)]])->assertUnprocessable();
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 7.85)]])->assertOk();
    }

    public function test_con_la_superficie_reducida_bajar_pasa_y_subir_no(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->corte($this->programa, 'Amarillo', 0, 6, $etapa);
        $etapa->update(['superficie' => 5]); // ahora hay 6 cortadas en 5 ha

        // Bajar de 6 a 4 no hace que se pase más.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, 4)]])->assertOk();
        $this->assertSame('4.00', ProgramaCorte::first()->hectareas);

        // Subir sobre lo libre (5) sí se rechaza.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, 5.5)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.hectareas');
        $this->assertSame('4.00', ProgramaCorte::first()->hectareas);
    }

    public function test_con_otra_semana_rebasando_la_etapa_se_puede_bajar_y_limpiar(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->corte($anterior, 'Amarillo', 0, 6, $etapa);
        $this->corte($this->programa, 'Amarillo', 1, 2, $etapa);
        $etapa->update(['superficie' => 5]); // libres: 5 − 6 = −1

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 1)]])->assertOk();
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 3)]])->assertUnprocessable();
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 1, 0)]])->assertOk();

        $this->assertSame(0, ProgramaCorte::where('programa_id', $this->programa->id)->count());
        $this->assertSame(1, ProgramaCorte::where('programa_id', $anterior->id)->count());
    }

    public function test_una_celda_en_cero_limpia_un_corte_fuera_de_regla(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->corte($this->programa, 'Amarillo', 2, 3, $etapa);
        $this->lote->update(['is_active' => false]);
        $etapa->update(['is_active' => false]);

        // Con valor, la etapa ya no es válida; en cero solo borra.
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 2, 4)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.etapa_id');
        $this->assertSame('3.00', ProgramaCorte::first()->hectareas);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 2, 0)]])->assertOk();
        $this->assertSame(0, ProgramaCorte::count());
    }

    // ── Validación de celdas ────────────────────────────

    public function test_etapa_fuera_de_las_temporadas_de_la_semana_es_422(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
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

        // Temporada que no toca la semana.
        $this->putJson($this->urlHoja(), ['celdas' => [
            ['temporada_id' => $vieja->id, 'etapa_id' => $etapa->id, 'dia' => 0, 'hectareas' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('celdas.0.temporada_id');

        // Etapa de un lote que no está en la temporada.
        $ajeno = Lote::create([
            'productor_id' => $this->lote->productor_id,
            'zona_cultivo_id' => $this->lote->zona_cultivo_id,
            'nombre' => 'Sin temporada',
            'superficie' => 20,
            'is_active' => true,
        ]);
        $deAjeno = $this->crearEtapa('Amarillo', 5, ['lote_id' => $ajeno->id]);
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($deAjeno, 0, 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.etapa_id');

        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_etapa_sin_variedad_o_de_otro_cultivo_es_422(): void
    {
        $this->comoCapturista();
        $sinVariedad = $this->crearEtapa('Amarillo', 5, ['variedad_id' => null]);
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);
        $deOtroCultivo = $this->crearEtapa('Amarillo', 5, ['variedad_id' => $ajena->id]);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($sinVariedad, 0, 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.etapa_id');
        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($deOtroCultivo, 0, 1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.etapa_id');
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_hectareas_son_numericas_de_cero_a_999999_con_dos_decimales(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);

        foreach ([-1, 1.234, 'abc', 1000000] as $ha) {
            $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, $ha)]])
                ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.hectareas');
        }
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_dia_fuera_de_rango_o_repetido_es_422(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);

        foreach ([6, -1] as $dia) {
            $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, $dia, 1)]])
                ->assertUnprocessable()->assertJsonValidationErrors('celdas.0.dia');
        }

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 2, 1), $this->celda($etapa, 2, 2)]])
            ->assertUnprocessable()->assertJsonValidationErrors('celdas.1.dia');
        $this->assertSame(0, ProgramaCorte::count());
    }

    // ── Cortes sin etapa ────────────────────────────────

    public function test_sin_etapa_solo_baja_o_borra(): void
    {
        $this->comoCapturista();
        $corte = $this->corte($this->programa, 'Blanco', 1, 3);

        $this->putJson($this->urlHoja(), ['sin_etapa' => [['corte_id' => $corte->id, 'hectareas' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sin_etapa.0.hectareas');
        $this->assertSame('3.00', $corte->fresh()->hectareas);

        $this->putJson($this->urlHoja(), ['sin_etapa' => [['corte_id' => $corte->id, 'hectareas' => 1]]])->assertOk();
        $this->assertSame('1.00', $corte->fresh()->hectareas);

        $this->putJson($this->urlHoja(), ['sin_etapa' => [['corte_id' => $corte->id, 'hectareas' => 0]]])->assertOk();
        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_sin_etapa_rechaza_cortes_ajenos_o_con_etapa(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $conEtapa = $this->corte($this->programa, 'Amarillo', 0, 2, $etapa);
        $otra = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $deOtra = $this->corte($otra, 'Blanco', 0, 2);

        $this->putJson($this->urlHoja(), ['sin_etapa' => [['corte_id' => $conEtapa->id, 'hectareas' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sin_etapa.0.corte_id');
        $this->putJson($this->urlHoja(), ['sin_etapa' => [['corte_id' => $deOtra->id, 'hectareas' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sin_etapa.0.corte_id');

        $this->assertSame('2.00', $conEtapa->fresh()->hectareas);
        $this->assertSame('2.00', $deOtra->fresh()->hectareas);
    }

    public function test_un_error_en_sin_etapa_no_deja_guardar_las_celdas(): void
    {
        $this->comoCapturista();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $corte = $this->corte($this->programa, 'Blanco', 1, 3);

        $this->putJson($this->urlHoja(), [
            'celdas' => [$this->celda($etapa, 0, 4)],
            'sin_etapa' => [['corte_id' => $corte->id, 'hectareas' => 9]],
        ])->assertUnprocessable();

        $this->assertSame(1, ProgramaCorte::count());
        $this->assertNull(ProgramaCorte::where('etapa_id', $etapa->id)->first());
    }

    // ── Permiso y aviso ─────────────────────────────────

    public function test_sin_permiso_capturar_es_403(): void
    {
        // Ver el plan de corte y capturar necesidades no basta para capturar cortes.
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view'], 'necesidades-venta' => ['view', 'capturar']]));
        $etapa = $this->crearEtapa('Amarillo', 10);

        $this->putJson($this->urlHoja(), ['celdas' => [$this->celda($etapa, 0, 3)]])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
        $this->putJson($this->urlRendimientos(), ['filas' => []])->assertForbidden();

        $this->assertSame(0, ProgramaCorte::count());
    }

    public function test_las_rutas_sueltas_de_corte_ya_no_existen(): void
    {
        $this->comoCapturista();
        $corte = $this->corte($this->programa, 'Blanco', 0, 1);

        $this->postJson(self::BASE.'/programa/'.$this->programa->id.'/cortes', [])->assertNotFound();
        $this->putJson(self::BASE.'/cortes/'.$corte->id, [])->assertNotFound();
        $this->deleteJson(self::BASE.'/cortes/'.$corte->id)->assertNotFound();
        $this->assertSame(1, ProgramaCorte::count());
    }

    // ── Rendimientos ────────────────────────────────────

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

    public function test_rendimientos_emiten_programa_updated(): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $this->comoCapturista();

        $this->putJson($this->urlRendimientos(), ['filas' => [
            ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'modo' => 'maximo'],
        ]])->assertOk();

        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
    }
}
