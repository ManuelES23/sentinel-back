<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Domingo opcional por semana: el interruptor `trabaja_domingo`, la regla del
 * sábado (sin domingo, lo que se empacaría el domingo se empaca el sábado) y
 * el rechazo de valores en el domingo de las cuatro hojas y de «copiar anterior».
 */
class DomingoOpcionalTest extends TestCase
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
    }

    private function comoPlaneador(): void
    {
        Sanctum::actingAs($this->usuarioCon([
            'tablero-semanal' => ['view', 'editar-notas'],
            'plan-corte' => ['view', 'capturar'],
            'necesidades-venta' => ['view', 'capturar'],
            'programa-empaque' => ['view', 'capturar'],
            'embarques' => ['view', 'capturar'],
        ]));
    }

    private function sinDomingo(?ProgramaSemanal $p = null): void
    {
        ($p ?? $this->programa)->forceFill(['trabaja_domingo' => false])->save();
    }

    private function domingo(bool $valor, ?ProgramaSemanal $p = null): TestResponse
    {
        return $this->putJson(self::BASE.'/programa/'.($p ?? $this->programa)->id.'/domingo', ['trabaja_domingo' => $valor]);
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    private function idAb(): string
    {
        return (string) $this->grupo('Amarillo / Bicolor')->id;
    }

    private function pedidoTexas(ProgramaSemanal $p, array $cajas): ProgramaNecesidad
    {
        return $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', $cajas);
    }

    private function sobrante(array $cajas, ?ProgramaSemanal $p = null): ProgramaSobrante
    {
        $attrs = [
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
        ];
        foreach (array_values($cajas) as $dia => $valor) {
            $attrs["cajas_{$dia}"] = $valor;
        }

        return $this->crearSobrante($p ?? $this->programa, $attrs, [$this->variedades['Amarillo']->id]);
    }

    private function embarque(string $bodega, int $dia, int $cargas, string $tipo = 'full'): ProgramaEmbarque
    {
        return ProgramaEmbarque::create([
            'programa_id' => $this->programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'tipo' => $tipo,
            'dia' => $dia,
            'cargas' => $cargas,
        ]);
    }

    // ── Valor por omisión y payload ─────────────────────

    public function test_una_semana_nueva_trabaja_el_domingo_y_el_payload_lo_trae(): void
    {
        $this->comoPlaneador();

        $d = $this->semana('2026-12-07');

        $this->assertTrue($d['programa']['trabaja_domingo']);
        $this->assertTrue((bool) ProgramaSemanal::whereDate('lunes', '2026-12-07')->value('trabaja_domingo'));
        $this->assertTrue($this->semana()['programa']['trabaja_domingo']);
    }

    public function test_el_payload_dice_false_en_una_semana_sin_domingo(): void
    {
        $this->comoPlaneador();
        $this->sinDomingo();

        $this->assertFalse($this->semana()['programa']['trabaja_domingo']);
    }

    // ── Interruptor ─────────────────────────────────────

    public function test_apaga_y_vuelve_a_prender_el_domingo(): void
    {
        $this->comoPlaneador();
        // Ceros en el domingo no cuentan como datos.
        $this->pedidoTexas($this->programa, [100, 0, 0, 0, 0, 0, 0]);
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->domingo(false)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'La semana ahora no se trabaja el domingo.')
            ->assertJsonPath('data.trabaja_domingo', false);
        $this->assertFalse((bool) $this->programa->fresh()->trabaja_domingo);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // Mismo valor: 200 sin aviso.
        $this->domingo(false)->assertOk()->assertJsonPath('data.trabaja_domingo', false);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $this->domingo(true)
            ->assertOk()
            ->assertJsonPath('message', 'La semana ahora se trabaja el domingo.')
            ->assertJsonPath('data.trabaja_domingo', true);
        $this->assertTrue((bool) $this->programa->fresh()->trabaja_domingo);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 2);

        $this->domingo(true)->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 2);
    }

    public function test_sin_editar_notas_no_cambia_el_domingo(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));

        $this->domingo(false)->assertForbidden();
        $this->assertTrue((bool) $this->programa->fresh()->trabaja_domingo);
    }

    public function test_el_valor_es_obligatorio_y_booleano(): void
    {
        $this->comoPlaneador();

        $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/domingo', [])->assertUnprocessable()->assertJsonValidationErrors('trabaja_domingo');
        $this->domingoCrudo('quizá')->assertUnprocessable()->assertJsonValidationErrors('trabaja_domingo');
    }

    private function domingoCrudo(mixed $valor): TestResponse
    {
        return $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/domingo', ['trabaja_domingo' => $valor]);
    }

    private function assertNoApaga(string $mensaje): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->domingo(false)
            ->assertUnprocessable()
            ->assertJsonPath('errors.trabaja_domingo.0', $mensaje);
        $this->assertTrue((bool) $this->programa->fresh()->trabaja_domingo);
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_no_apaga_con_un_corte_en_domingo(): void
    {
        $this->comoPlaneador();
        $this->corte($this->programa, 'Amarillo', 6, 2);
        $this->corte($this->programa, 'Amarillo', 5, 3); // otro día no cuenta

        $this->assertNoApaga('El domingo ya tiene datos: 1 corte. Vacíalos antes de apagarlo.');
    }

    public function test_no_apaga_con_una_necesidad_en_domingo(): void
    {
        $this->comoPlaneador();
        $this->pedidoTexas($this->programa, [0, 0, 0, 0, 0, 0, 300]);

        $this->assertNoApaga('El domingo ya tiene datos: 1 necesidad. Vacíalos antes de apagarlo.');
    }

    public function test_no_apaga_con_un_sobrante_en_domingo(): void
    {
        $this->comoPlaneador();
        $this->sobrante([0, 0, 0, 0, 0, 0, 40]);

        $this->assertNoApaga('El domingo ya tiene datos: 1 sobrante. Vacíalos antes de apagarlo.');
    }

    public function test_no_apaga_con_un_embarque_en_domingo(): void
    {
        $this->comoPlaneador();
        $this->embarque('Texas', 6, 2);

        $this->assertNoApaga('El domingo ya tiene datos: 1 embarque. Vacíalos antes de apagarlo.');
    }

    public function test_no_apaga_y_nombra_todo_lo_que_hay_en_domingo(): void
    {
        $this->comoPlaneador();
        $this->corte($this->programa, 'Amarillo', 6, 2);
        $this->corte($this->programa, 'Blanco', 6, 1.5);
        foreach (['Walmart', 'Caruso', 'Twin Garden'] as $cliente) {
            $this->necesidad($this->programa, 'Texas', $cliente, 'Bulk', ['Amarillo'], 'RPC', [0, 0, 0, 0, 0, 0, 10]);
        }
        $this->sobrante([0, 0, 0, 0, 0, 0, 40]);
        $this->embarque('Texas', 6, 2);
        $this->embarque('Nogales', 6, 1, 'sencillo');

        $this->assertNoApaga('El domingo ya tiene datos: 2 cortes, 3 necesidades, 1 sobrante y 2 embarques. Vacíalos antes de apagarlo.');
    }

    public function test_dos_tipos_de_dato_se_unen_con_y(): void
    {
        $this->comoPlaneador();
        $this->sobrante([0, 0, 0, 0, 0, 0, 40]);
        $this->sobrante([0, 0, 0, 0, 0, 0, 10]);
        $this->embarque('Texas', 6, 2);

        $this->assertNoApaga('El domingo ya tiene datos: 2 sobrantes y 1 embarque. Vacíalos antes de apagarlo.');
    }

    // ── Regla del sábado ────────────────────────────────

    public function test_pedido_del_martes_siguiente_con_dos_dias_se_empaca_el_domingo_o_el_sabado(): void
    {
        $this->comoPlaneador();
        $this->antelacion('Texas', 2);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);

        // Con domingo, como hoy: el domingo de WK48.
        $d = $this->semana();
        $this->assertSame([0, 0, 0, 0, 0, 0, 1800], $d['balance'][$this->idAb()]['necesidad']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 1800], collect($d['empaque'])->firstWhere('bodega_id', $this->bodega('Texas')->id)['cajas']);

        // Sin domingo: el sábado.
        $this->sinDomingo();
        $d = $this->semana();
        $this->assertSame([0, 0, 0, 0, 0, 1800, 0], $d['balance'][$this->idAb()]['necesidad']);
        $this->assertSame([0, 0, 0, 0, 0, 1800, 0], collect($d['empaque'])->firstWhere('bodega_id', $this->bodega('Texas')->id)['cajas']);

        // WK49 no lo cuenta en ninguno de los dos casos (se empaca antes de su lunes).
        $this->assertSame(0, array_sum($this->semana('2026-11-30')['balance'][$this->idAb()]['necesidad']));
    }

    public function test_pedido_del_lunes_siguiente_con_un_dia_se_empaca_el_sabado_sin_domingo(): void
    {
        $this->comoPlaneador();
        $this->antelacion('Texas', 1);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [900, 0, 0, 0, 0, 0, 0]);

        $this->assertSame([0, 0, 0, 0, 0, 0, 900], $this->semana()['balance'][$this->idAb()]['necesidad']);

        $this->sinDomingo();
        $this->assertSame([0, 0, 0, 0, 0, 900, 0], $this->semana()['balance'][$this->idAb()]['necesidad']);
    }

    public function test_el_domingo_de_la_semana_siguiente_no_cambia_lo_de_esta(): void
    {
        $this->comoPlaneador();
        $this->antelacion('Texas', 2);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);
        // Solo cuenta el interruptor de la semana en que se empaca.
        $this->sinDomingo($wk49);

        $this->assertSame([0, 0, 0, 0, 0, 0, 1800], $this->semana()['balance'][$this->idAb()]['necesidad']);
    }

    public function test_balance_sin_domingo_termina_en_el_sabado_y_llega_igual_al_lunes_siguiente(): void
    {
        $this->comoPlaneador();
        $this->antelacion('Texas', 2);
        $this->corte($this->programa, 'Amarillo', 5, 6);
        $this->corte($this->programa, 'Bicolor', 2, 4);
        $this->pedidoTexas($this->programa, [0, 0, 0, 500, 0, 0, 0]);
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->pedidoTexas($wk49, [0, 1800, 0, 0, 0, 0, 0]);

        $con = $this->semana()['balance'][$this->idAb()];
        $inicialConDomingo = $this->semana('2026-11-30')['balance'][$this->idAb()]['inicial'];

        $this->sinDomingo();
        $sin = $this->semana()['balance'][$this->idAb()];
        $inicialSinDomingo = $this->semana('2026-11-30')['balance'][$this->idAb()]['inicial'];

        // La producción no cambia (no hay cortes en domingo); la necesidad del domingo pasa al sábado.
        $this->assertSame($con['produccion'], $sin['produccion']);
        $this->assertSame(0, $sin['necesidad'][6]);
        $this->assertSame($con['necesidad'][5] + $con['necesidad'][6], $sin['necesidad'][5]);
        $this->assertSame(1800, $sin['necesidad'][5]);
        $this->assertSame(array_sum($con['necesidad']), array_sum($sin['necesidad']));
        // Inventario final = el del sábado y el mismo que con domingo.
        $this->assertSame($sin['acumulado'][5], $sin['final']);
        $this->assertSame($sin['acumulado'][5], $sin['acumulado'][6]);
        $this->assertSame($con['final'], $sin['final']);
        // Y llega igual al lunes siguiente.
        $this->assertSame($inicialConDomingo, $inicialSinDomingo);
        $this->assertSame($sin['final'], $inicialSinDomingo);
    }

    // ── Hojas ───────────────────────────────────────────

    public function test_hoja_de_corte_rechaza_el_domingo_sin_domingo(): void
    {
        $this->comoPlaneador();
        $etapa = $this->crearEtapa('Amarillo', 10);
        $url = self::BASE.'/programa/'.$this->programa->id.'/cortes/hoja';
        $celda = fn (int $dia, $ha) => ['temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => $dia, 'hectareas' => $ha];
        $this->sinDomingo();

        $this->putJson($url, ['celdas' => [$celda(5, 2), $celda(6, 1.5)]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['celdas.1.hectareas' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, $this->programa->cortes()->count());

        $this->putJson($url, ['celdas' => [$celda(5, 2), $celda(6, 0)]])->assertOk();
        $this->putJson($url, ['celdas' => [$celda(6, null)]])->assertOk();
        $this->assertSame(1, $this->programa->cortes()->count());
    }

    public function test_hoja_de_corte_acepta_el_domingo_con_domingo(): void
    {
        $this->comoPlaneador();
        $etapa = $this->crearEtapa('Amarillo', 10);

        $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/cortes/hoja', ['celdas' => [
            ['temporada_id' => $this->temporada->id, 'etapa_id' => $etapa->id, 'dia' => 6, 'hectareas' => 1.5],
        ]])->assertOk();
        $this->assertSame(1, $this->programa->cortes()->where('dia', 6)->count());
    }

    private function nuevaNecesidad(array $cajas): array
    {
        return [
            'clave' => 'n1',
            'bodega_id' => $this->bodega('Nogales')->id,
            'cliente_id' => null,
            'cliente_nombre' => 'Walmart',
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id],
            'cajas' => $cajas,
        ];
    }

    public function test_hoja_de_necesidades_rechaza_el_domingo_sin_domingo(): void
    {
        $this->comoPlaneador();
        $url = self::BASE.'/programa/'.$this->programa->id.'/necesidades/hoja';
        $existente = $this->pedidoTexas($this->programa, [100, 0, 0, 0, 0, 0, 0]);
        $this->sinDomingo();

        $this->putJson($url, ['nuevas' => [$this->nuevaNecesidad([0, 850, 0, 0, 0, 0, 5])]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['nuevas.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->putJson($url, ['cambios' => [['id' => $existente->id, 'cajas' => ['6' => 10]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['cambios.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->assertSame(1, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
        $this->assertSame(0, (int) $existente->fresh()->cajas_6);

        $this->putJson($url, [
            'nuevas' => [$this->nuevaNecesidad([0, 850, 0, 0, 0, 0, 0])],
            'cambios' => [['id' => $existente->id, 'cajas' => ['0' => 200, '6' => 0]]],
        ])->assertOk();
        $this->assertSame(200, (int) $existente->fresh()->cajas_0);
        $this->assertSame(2, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
    }

    public function test_hoja_de_sobrantes_rechaza_el_domingo_sin_domingo(): void
    {
        $this->comoPlaneador();
        $url = self::BASE.'/programa/'.$this->programa->id.'/sobrantes/hoja';
        $existente = $this->sobrante([10, 0, 0, 0, 0, 0, 0]);
        $nueva = fn (array $cajas) => [
            'clave' => 'n1',
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id],
            'cajas' => $cajas,
        ];
        $this->sinDomingo();

        $this->putJson($url, ['nuevas' => [$nueva([0, 850, 0, 0, 0, 0, 40])]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['nuevas.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->putJson($url, ['cambios' => [['id' => $existente->id, 'cajas' => ['6' => 3]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['cambios.0.cajas.6' => [self::MENSAJE_HOJA]]);
        $this->assertSame(1, ProgramaSobrante::where('programa_id', $this->programa->id)->count());

        $this->putJson($url, [
            'nuevas' => [$nueva([0, 850, 0, 0, 0, 0, 0])],
            'cambios' => [['id' => $existente->id, 'cajas' => ['6' => 0]]],
        ])->assertOk();
        $this->assertSame(2, ProgramaSobrante::where('programa_id', $this->programa->id)->count());
    }

    public function test_hoja_de_embarques_rechaza_el_domingo_sin_domingo(): void
    {
        $this->comoPlaneador();
        $url = self::BASE.'/programa/'.$this->programa->id.'/embarques/hoja';
        $celda = fn (int $dia, $cargas) => ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => $dia, 'cargas' => $cargas];
        $this->sinDomingo();

        $this->putJson($url, ['celdas' => [$celda(5, 2), $celda(6, 1)]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['celdas.1.cargas' => [self::MENSAJE_HOJA]]);
        $this->assertSame(0, ProgramaEmbarque::where('programa_id', $this->programa->id)->count());

        $this->putJson($url, ['celdas' => [$celda(5, 2), $celda(6, 0)]])->assertOk();
        $this->putJson($url, ['celdas' => [$celda(6, null)]])->assertOk();
        $this->assertSame(1, ProgramaEmbarque::where('programa_id', $this->programa->id)->count());
    }

    public function test_hoja_de_embarques_acepta_el_domingo_con_domingo(): void
    {
        $this->comoPlaneador();

        $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/embarques/hoja', ['celdas' => [
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Texas')->id, 'tipo' => 'full', 'dia' => 6, 'cargas' => 2],
        ]])->assertOk();
        $this->assertSame(1, ProgramaEmbarque::where('programa_id', $this->programa->id)->where('dia', 6)->count());
    }

    // ── Copiar la semana anterior ───────────────────────

    public function test_copiar_anterior_a_una_semana_sin_domingo_no_trae_el_domingo(): void
    {
        $this->comoPlaneador();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->pedidoTexas($anterior, [100, 0, 0, 0, 0, 0, 300]);
        $this->pedidoTexas($anterior, [0, 0, 0, 0, 0, 0, 50]);
        $this->sinDomingo();

        $this->postJson(self::BASE.'/programa/'.$this->programa->id.'/necesidades/copiar-anterior')
            ->assertOk()
            ->assertJsonPath('data.copiadas', 2);

        $copias = ProgramaNecesidad::where('programa_id', $this->programa->id)->orderBy('id')->get();
        $this->assertSame([100, 0, 0, 0, 0, 0, 0], array_map('intval', $copias[0]->cajasPorDia()));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], array_map('intval', $copias[1]->cajasPorDia()));
    }

    public function test_copiar_anterior_con_domingo_trae_el_domingo(): void
    {
        $this->comoPlaneador();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->pedidoTexas($anterior, [100, 0, 0, 0, 0, 0, 300]);

        $this->postJson(self::BASE.'/programa/'.$this->programa->id.'/necesidades/copiar-anterior')->assertOk();

        $this->assertSame(300, (int) ProgramaNecesidad::where('programa_id', $this->programa->id)->value('cajas_6'));
    }
}
