<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cruces del domingo por proceso: cada hoja valida el domingo con SU proceso
 * (corte → Corte; necesidades y sobrantes → Empaque; embarques → Embarques) y
 * apagar otro proceso no la afecta; la regla del sábado y «copiar anterior»
 * dependen solo de Empaque; un corte en domingo con Empaque sin domingo suma
 * a la producción y llega al inventario final.
 */
class DomingoCrucesProcesoTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private const MENSAJE_HOJA = 'Esta semana no se trabaja el domingo.';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        Sanctum::actingAs($this->usuarioCon([
            'tablero-semanal' => ['view', 'editar-notas'],
            'plan-corte' => ['view', 'capturar'],
            'necesidades-venta' => ['view', 'capturar'],
            'programa-empaque' => ['view', 'capturar'],
            'embarques' => ['view', 'capturar'],
        ]));
    }

    /**
     * Fija las banderas como lo deja el endpoint: los procesos que no se
     * nombran trabajan el domingo y `trabaja_domingo` guarda «alguno».
     */
    private function fijar(array $banderas, ?ProgramaSemanal $p = null): void
    {
        $banderas += ['corte' => true, 'empaque' => true, 'embarques' => true];
        ($p ?? $this->programa)->forceFill([
            'domingo_corte' => $banderas['corte'],
            'domingo_empaque' => $banderas['empaque'],
            'domingo_embarques' => $banderas['embarques'],
            'trabaja_domingo' => in_array(true, $banderas, true),
        ])->save();
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    private function url(string $hoja): string
    {
        return self::BASE.'/programa/'.$this->programa->id.'/'.$hoja;
    }

    private function idAb(): string
    {
        return (string) $this->grupo('Amarillo / Bicolor')->id;
    }

    private function pedidoTexas(ProgramaSemanal $p, array $cajas): ProgramaNecesidad
    {
        return $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', $cajas);
    }

    private function filaNueva(array $cajas, bool $conCliente): array
    {
        return [
            'clave' => 'n1',
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id],
            'cajas' => $cajas,
        ] + ($conCliente ? ['cliente_id' => null, 'cliente_nombre' => 'Walmart'] : []);
    }

    // ── Hojas: solo SU proceso apagado ─────────────────

    public function test_hoja_de_corte_sigue_a_corte_y_no_a_los_otros(): void
    {
        $etapa = $this->crearEtapa('Amarillo', 10);
        $celdas = ['celdas' => [['temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 6, 'hectareas' => 1.5]]];

        // Solo Corte sin domingo: rechaza.
        $this->fijar(['corte' => false]);
        $this->putJson($this->url('cortes/hoja'), $celdas)
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['celdas.0.hectareas' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, $this->programa->cortes()->count());

        // Empaque y Embarques sin domingo, Corte con domingo: acepta.
        $this->fijar(['empaque' => false, 'embarques' => false]);
        $this->putJson($this->url('cortes/hoja'), $celdas)->assertOk();
        $this->assertSame(1, $this->programa->cortes()->where('dia', 6)->count());
    }

    public function test_hoja_de_necesidades_sigue_a_empaque_y_no_a_los_otros(): void
    {
        $cuerpo = ['nuevas' => [$this->filaNueva([0, 0, 0, 0, 0, 0, 30], true)]];

        $this->fijar(['empaque' => false]);
        $this->putJson($this->url('necesidades/hoja'), $cuerpo)
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['nuevas.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());

        $this->fijar(['corte' => false, 'embarques' => false]);
        $this->putJson($this->url('necesidades/hoja'), $cuerpo)->assertOk();
        $this->assertSame(30, (int) ProgramaNecesidad::where('programa_id', $this->programa->id)->value('cajas_6'));
    }

    public function test_hoja_de_sobrantes_sigue_a_empaque_y_no_a_los_otros(): void
    {
        $cuerpo = ['nuevas' => [$this->filaNueva([0, 0, 0, 0, 0, 0, 40], false)]];

        $this->fijar(['empaque' => false]);
        $this->putJson($this->url('sobrantes/hoja'), $cuerpo)
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['nuevas.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, ProgramaSobrante::where('programa_id', $this->programa->id)->count());

        $this->fijar(['corte' => false, 'embarques' => false]);
        $this->putJson($this->url('sobrantes/hoja'), $cuerpo)->assertOk();
        $this->assertSame(40, (int) ProgramaSobrante::where('programa_id', $this->programa->id)->value('cajas_6'));
    }

    public function test_hoja_de_embarques_sigue_a_embarques_y_no_a_los_otros(): void
    {
        $celdas = ['celdas' => [['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 6, 'cargas' => 2]]];

        $this->fijar(['embarques' => false]);
        $this->putJson($this->url('embarques/hoja'), $celdas)
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['celdas.0.cargas' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, ProgramaEmbarque::where('programa_id', $this->programa->id)->count());

        $this->fijar(['corte' => false, 'empaque' => false]);
        $this->putJson($this->url('embarques/hoja'), $celdas)->assertOk();
        $this->assertSame(1, ProgramaEmbarque::where('programa_id', $this->programa->id)->where('dia', 6)->count());
    }

    public function test_corte_sin_domingo_y_embarques_con_domingo_acepta_embarques_y_rechaza_corte(): void
    {
        $etapa = $this->crearEtapa('Amarillo', 10);
        $this->fijar(['corte' => false, 'empaque' => false]);

        $this->putJson($this->url('embarques/hoja'), ['celdas' => [
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 6, 'cargas' => 1],
        ]])->assertOk();
        $this->putJson($this->url('cortes/hoja'), ['celdas' => [
            ['temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 6, 'hectareas' => 2],
        ]])->assertUnprocessable()->assertJsonPath('errors', ['celdas.0.hectareas' => [self::MENSAJE_HOJA]]);
    }

    // ── Regla del sábado: solo Empaque ─────────────────

    public function test_empaque_sin_domingo_adelanta_al_sabado_aunque_corte_y_embarques_lo_trabajen(): void
    {
        $this->antelacion('Texas', 2);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);
        $this->fijar(['empaque' => false]);

        $d = $this->semana();
        $this->assertTrue($d['programa']['trabaja_domingo']);
        $this->assertSame([0, 0, 0, 0, 0, 1800, 0], $d['balance'][$this->idAb()]['necesidad']);
        $this->assertSame([0, 0, 0, 0, 0, 1800, 0], collect($d['empaque'])->firstWhere('bodega_id', $this->bodega('Texas')->id)['cajas']);
    }

    public function test_corte_sin_domingo_y_empaque_con_domingo_no_adelanta(): void
    {
        $this->antelacion('Texas', 2);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);
        $this->fijar(['corte' => false]);

        $this->assertSame([0, 0, 0, 0, 0, 0, 1800], $this->semana()['balance'][$this->idAb()]['necesidad']);
    }

    public function test_embarques_sin_domingo_y_empaque_con_domingo_no_adelanta(): void
    {
        $this->antelacion('Texas', 2);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);
        $this->fijar(['embarques' => false]);

        $this->assertSame([0, 0, 0, 0, 0, 0, 1800], $this->semana()['balance'][$this->idAb()]['necesidad']);
    }

    public function test_corte_en_domingo_con_empaque_sin_domingo_suma_y_llega_al_inventario_final(): void
    {
        $this->corte($this->programa, 'Amarillo', 6, 2);
        $this->pedidoTexas($this->programa, [0, 0, 0, 0, 0, 0, 500]);
        $this->fijar(['empaque' => false]);

        $b = $this->semana()['balance'][$this->idAb()];

        $this->assertGreaterThan(0, $b['produccion'][6]);
        $this->assertSame(0, array_sum(array_slice($b['produccion'], 0, 6)));
        // La necesidad del domingo pasa al sábado; la producción del domingo se queda en el domingo.
        $this->assertSame([0, 0, 0, 0, 0, 500, 0], $b['necesidad']);
        $this->assertSame($b['acumulado'][5] + $b['produccion'][6], $b['acumulado'][6]);
        $this->assertSame($b['acumulado'][6], $b['final']);
    }

    // ── Copiar la semana anterior: solo Empaque ────────

    public function test_copiar_anterior_sin_domingo_de_empaque_no_trae_el_domingo(): void
    {
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->pedidoTexas($anterior, [100, 0, 0, 0, 0, 0, 300]);
        $this->fijar(['empaque' => false]);

        $this->postJson($this->url('necesidades/copiar-anterior'))
            ->assertOk()
            ->assertJsonPath('data.copiadas', 1)
            ->assertJsonPath('message', 'Se copiaron 1 necesidades de la semana anterior (sin las 300 cajas del domingo).');
        $this->assertSame(0, (int) ProgramaNecesidad::where('programa_id', $this->programa->id)->value('cajas_6'));
    }

    public function test_copiar_anterior_con_domingo_de_empaque_lo_trae_aunque_corte_y_embarques_no(): void
    {
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->pedidoTexas($anterior, [100, 0, 0, 0, 0, 0, 300]);
        $this->fijar(['corte' => false, 'embarques' => false]);

        $this->postJson($this->url('necesidades/copiar-anterior'))
            ->assertOk()
            ->assertJsonPath('message', 'Se copiaron 1 necesidades de la semana anterior.');
        $this->assertSame(300, (int) ProgramaNecesidad::where('programa_id', $this->programa->id)->value('cajas_6'));
    }
}
