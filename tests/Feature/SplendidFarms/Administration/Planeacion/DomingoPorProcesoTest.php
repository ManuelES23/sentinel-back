<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use App\Services\Planeacion\DomingoPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Domingo por proceso: las banderas `domingo_corte`, `domingo_empaque` y
 * `domingo_embarques`, su carga inicial desde `trabaja_domingo`, el payload
 * (`programa.domingo` y `trabaja_domingo` = alguno) y el endpoint
 * `PUT programa/{p}/domingo` con `{ corte?, empaque?, embarques? }`.
 */
class DomingoPorProcesoTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private const MIGRACION = 'migrations/2026_11_23_160000_add_domingo_por_proceso_to_programas_semanales.php';

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

    private function cambiar(array $cuerpo): TestResponse
    {
        return $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/domingo', $cuerpo);
    }

    private function banderas(?ProgramaSemanal $p = null): array
    {
        $fila = ($p ?? $this->programa)->fresh();

        return [
            'corte' => $fila->domingo_corte,
            'empaque' => $fila->domingo_empaque,
            'embarques' => $fila->domingo_embarques,
        ];
    }

    private function fijar(array $banderas): void
    {
        $this->programa->forceFill(collect($banderas)->mapWithKeys(fn ($v, $k) => ["domingo_{$k}" => $v])->all())->save();
    }

    private function semana(): array
    {
        return $this->getJson(self::BASE.'/programa?lunes=2026-11-23')->assertOk()->json('data');
    }

    private function sobrante(array $cajas): ProgramaSobrante
    {
        $attrs = [
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
        ];
        foreach (array_values($cajas) as $dia => $valor) {
            $attrs["cajas_{$dia}"] = $valor;
        }

        return $this->crearSobrante($this->programa, $attrs, [$this->variedades['Amarillo']->id]);
    }

    private function embarque(int $dia, int $cargas, string $tipo = 'full'): ProgramaEmbarque
    {
        return ProgramaEmbarque::create([
            'programa_id' => $this->programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Texas')->id,
            'tipo' => $tipo,
            'dia' => $dia,
            'cargas' => $cargas,
        ]);
    }

    /** Datos en el domingo de los tres procesos. */
    private function datosEnLosTres(): void
    {
        $this->corte($this->programa, 'Amarillo', 6, 2);
        $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 0, 0, 0, 0, 30]);
        $this->embarque(6, 1);
    }

    // ── Migración ───────────────────────────────────────

    public function test_la_migracion_llena_los_tres_desde_trabaja_domingo(): void
    {
        $sinDomingo = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $migracion = require database_path(self::MIGRACION);

        // Estado anterior a la migración: solo existe `trabaja_domingo`.
        $migracion->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('programas_semanales', 'domingo_corte'));
        DB::table('programas_semanales')->where('id', $sinDomingo->id)->update(['trabaja_domingo' => false]);

        $migracion->up();

        $fila = fn (int $id) => (array) DB::table('programas_semanales')->where('id', $id)
            ->first(['domingo_corte', 'domingo_empaque', 'domingo_embarques']);
        $this->assertEquals(['domingo_corte' => 1, 'domingo_empaque' => 1, 'domingo_embarques' => 1], $fila($this->programa->id));
        $this->assertEquals(['domingo_corte' => 0, 'domingo_empaque' => 0, 'domingo_embarques' => 0], $fila($sinDomingo->id));

        // Repetible: volver a correrla no pisa lo que ya se cambió por proceso.
        DB::table('programas_semanales')->where('id', $this->programa->id)->update(['domingo_empaque' => false]);
        $migracion->up();
        $this->assertEquals(0, $fila($this->programa->id)['domingo_empaque']);
    }

    public function test_una_semana_nueva_trabaja_los_tres(): void
    {
        $nueva = ProgramaSemanal::create(['lunes' => '2026-12-07']);

        $this->assertTrue($nueva->domingo_corte && $nueva->domingo_empaque && $nueva->domingo_embarques);
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas($nueva));
    }

    // ── Modelo ──────────────────────────────────────────

    public function test_el_modelo_lee_cada_proceso_y_alguno(): void
    {
        $this->assertSame(['corte', 'empaque', 'embarques'], ProgramaSemanal::PROCESOS);

        $this->fijar(['corte' => false, 'empaque' => true, 'embarques' => false]);
        $p = $this->programa->fresh();
        $this->assertFalse($p->trabajaDomingo('corte'));
        $this->assertTrue($p->trabajaDomingo('empaque'));
        $this->assertFalse($p->trabajaDomingo('embarques'));
        $this->assertTrue($p->trabajaAlgunDomingo());

        $this->fijar(['empaque' => false]);
        $this->assertFalse($this->programa->fresh()->trabajaAlgunDomingo());

        $this->expectException(InvalidArgumentException::class);
        $p->trabajaDomingo('ventas');
    }

    // ── Payload ─────────────────────────────────────────

    public function test_el_payload_trae_los_tres_y_alguno(): void
    {
        $this->comoPlaneador();
        $this->fijar(['corte' => false, 'empaque' => true, 'embarques' => false]);

        $programa = $this->semana()['programa'];
        $this->assertSame(['corte' => false, 'empaque' => true, 'embarques' => false], $programa['domingo']);
        $this->assertTrue($programa['trabaja_domingo']);

        $this->fijar(['empaque' => false]);
        $programa = $this->semana()['programa'];
        $this->assertSame(['corte' => false, 'empaque' => false, 'embarques' => false], $programa['domingo']);
        $this->assertFalse($programa['trabaja_domingo']);
    }

    // ── Endpoint: forma y validación ────────────────────

    public function test_apaga_solo_empaque(): void
    {
        $this->comoPlaneador();
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->cambiar(['empaque' => false])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Domingo: Corte sí, Empaque no, Embarques sí.')
            ->assertJsonPath('data', ['trabaja_domingo' => true, 'domingo' => ['corte' => true, 'empaque' => false, 'embarques' => true]]);

        $this->assertSame(['corte' => true, 'empaque' => false, 'embarques' => true], $this->banderas());
        // La columna conservada guarda «alguno».
        $this->assertTrue($this->programa->fresh()->trabaja_domingo);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
    }

    public function test_apaga_corte_y_embarques_con_un_solo_aviso(): void
    {
        $this->comoPlaneador();
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->cambiar(['corte' => false, 'embarques' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Domingo: Corte no, Empaque sí, Embarques no.')
            ->assertJsonPath('data.domingo', ['corte' => false, 'empaque' => true, 'embarques' => false])
            ->assertJsonPath('data.trabaja_domingo', true);

        $this->assertSame(['corte' => false, 'empaque' => true, 'embarques' => false], $this->banderas());
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        // Apagar el último deja «alguno» en false.
        $this->cambiar(['empaque' => false])->assertOk()->assertJsonPath('data.trabaja_domingo', false);
        $this->assertFalse($this->programa->fresh()->trabaja_domingo);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 2);
    }

    public function test_la_forma_vieja_fija_los_tres(): void
    {
        $this->comoPlaneador();
        $this->fijar(['corte' => false]);

        $this->cambiar(['trabaja_domingo' => false])
            ->assertOk()
            ->assertJsonPath('data', ['trabaja_domingo' => false, 'domingo' => ['corte' => false, 'empaque' => false, 'embarques' => false]]);
        $this->assertSame(['corte' => false, 'empaque' => false, 'embarques' => false], $this->banderas());

        $this->cambiar(['trabaja_domingo' => true])->assertOk()->assertJsonPath('data.trabaja_domingo', true);
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas());
    }

    public function test_mismo_valor_es_200_sin_aviso(): void
    {
        $this->comoPlaneador();
        $this->fijar(['empaque' => false]);
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->cambiar(['empaque' => false, 'corte' => true])
            ->assertOk()
            ->assertJsonPath('data.domingo', ['corte' => true, 'empaque' => false, 'embarques' => true]);
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_cuerpo_vacio_es_422(): void
    {
        $this->comoPlaneador();

        $this->cambiar([])->assertUnprocessable()->assertJsonValidationErrors('domingo');
        $this->putJson(self::BASE.'/programa/'.$this->programa->id.'/domingo', ['otra' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('domingo');
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas());
    }

    public function test_tipos_invalidos_son_422(): void
    {
        $this->comoPlaneador();

        $this->cambiar(['corte' => 'quizá'])->assertUnprocessable()->assertJsonValidationErrors('corte');
        $this->cambiar(['empaque' => [true]])->assertUnprocessable()->assertJsonValidationErrors('empaque');
        $this->cambiar(['embarques' => 2])->assertUnprocessable()->assertJsonValidationErrors('embarques');
        $this->cambiar(['trabaja_domingo' => 'no sé'])->assertUnprocessable()->assertJsonValidationErrors('trabaja_domingo');
        // No se mezclan las dos formas.
        $this->cambiar(['trabaja_domingo' => false, 'corte' => false])->assertUnprocessable()->assertJsonValidationErrors('trabaja_domingo');
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas());
    }

    public function test_sin_editar_notas_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));

        $this->cambiar(['empaque' => false])->assertForbidden();
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas());
    }

    // ── Endpoint: datos en el domingo ───────────────────

    private function assertNoApaga(array $cuerpo, array $errores): void
    {
        Event::fake([ProgramaSemanalUpdated::class]);
        $antes = $this->banderas();

        $this->cambiar($cuerpo)->assertUnprocessable()->assertJsonPath('errors', $errores);
        $this->assertSame($antes, $this->banderas());
        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_apagar_corte_con_cortes_en_domingo_nombra_solo_los_cortes(): void
    {
        $this->comoPlaneador();
        $this->datosEnLosTres();
        $this->corte($this->programa, 'Blanco', 6, 1);
        $this->corte($this->programa, 'Bicolor', 5, 3); // otro día no cuenta

        $this->assertNoApaga(['corte' => false], [
            'corte' => ['El domingo de Corte ya tiene datos: 2 cortes. Vacíalos antes de apagarlo.'],
        ]);
    }

    public function test_apagar_empaque_con_necesidades_o_sobrantes(): void
    {
        $this->comoPlaneador();
        $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [0, 0, 0, 0, 0, 0, 30]);

        $this->assertNoApaga(['empaque' => false], [
            'empaque' => ['El domingo de Empaque ya tiene datos: 1 necesidad. Vacíalos antes de apagarlo.'],
        ]);

        $this->sobrante([0, 0, 0, 0, 0, 0, 10]);
        $this->sobrante([0, 0, 0, 0, 0, 0, 5]);
        $this->assertNoApaga(['empaque' => false], [
            'empaque' => ['El domingo de Empaque ya tiene datos: 1 necesidad y 2 sobrantes. Vacíalos antes de apagarlo.'],
        ]);
    }

    public function test_apagar_empaque_solo_con_sobrantes(): void
    {
        $this->comoPlaneador();
        $this->sobrante([0, 0, 0, 0, 0, 0, 10]);

        $this->assertNoApaga(['empaque' => false], [
            'empaque' => ['El domingo de Empaque ya tiene datos: 1 sobrante. Vacíalos antes de apagarlo.'],
        ]);
    }

    public function test_apagar_embarques_con_cargas(): void
    {
        $this->comoPlaneador();
        $this->embarque(6, 2);
        $this->embarque(6, 0, 'sencillo'); // sin cargas no cuenta

        $this->assertNoApaga(['embarques' => false], [
            'embarques' => ['El domingo de Embarques ya tiene datos: 1 embarque. Vacíalos antes de apagarlo.'],
        ]);
    }

    public function test_apagar_dos_con_datos_nombra_cada_uno_y_no_cambia_ninguno(): void
    {
        $this->comoPlaneador();
        $this->datosEnLosTres();

        $this->assertNoApaga(['corte' => false, 'embarques' => false], [
            'corte' => ['El domingo de Corte ya tiene datos: 1 corte. Vacíalos antes de apagarlo.'],
            'embarques' => ['El domingo de Embarques ya tiene datos: 1 embarque. Vacíalos antes de apagarlo.'],
        ]);
    }

    public function test_apagar_un_proceso_con_datos_de_otro_en_el_domingo_si_pasa(): void
    {
        $this->comoPlaneador();
        $this->corte($this->programa, 'Amarillo', 6, 2);
        $this->embarque(6, 1);

        $this->cambiar(['empaque' => false])->assertOk();

        // Con cortes y sobrantes en el domingo, Embarques se apaga si no tiene cargas.
        ProgramaEmbarque::where('programa_id', $this->programa->id)->delete();
        $this->sobrante([0, 0, 0, 0, 0, 0, 10]);
        $this->cambiar(['embarques' => false])->assertOk();

        $this->assertSame(['corte' => true, 'empaque' => false, 'embarques' => false], $this->banderas());
    }

    public function test_encender_no_tiene_restriccion(): void
    {
        $this->comoPlaneador();
        $this->fijar(['corte' => false, 'empaque' => false, 'embarques' => false]);
        $this->datosEnLosTres();

        $this->cambiar(['corte' => true, 'empaque' => true, 'embarques' => true])
            ->assertOk()
            ->assertJsonPath('data.trabaja_domingo', true);
        $this->assertSame(['corte' => true, 'empaque' => true, 'embarques' => true], $this->banderas());
    }

    // ── Servicio ────────────────────────────────────────

    public function test_exigir_celdas_lee_la_bandera_de_su_proceso(): void
    {
        $this->fijar(['corte' => false, 'empaque' => true, 'embarques' => true]);

        // Cero o vacío siempre pasan; con valor, solo si su proceso trabaja el domingo.
        DomingoPrograma::exigirCeldas($this->programa->id, ['celdas.0.hectareas' => 0, 'celdas.1.hectareas' => null], 'corte');
        DomingoPrograma::exigirCeldas($this->programa->id, ['celdas.0.cargas' => 2], 'embarques');
        DomingoPrograma::exigirCeldas($this->programa->id, ['nuevas.0.cajas.6' => 40], 'empaque');

        try {
            DomingoPrograma::exigirCeldas($this->programa->id, ['celdas.0.hectareas' => 0, 'celdas.3.hectareas' => 1.5], 'corte');
            $this->fail('Debió rechazar el domingo de corte.');
        } catch (ValidationException $e) {
            $this->assertSame(['celdas.3.hectareas' => ['Esta semana no se trabaja el domingo.']], $e->errors());
        }
    }

    public function test_datos_del_domingo_por_proceso(): void
    {
        $this->datosEnLosTres();
        $this->sobrante([0, 0, 0, 0, 0, 0, 10]);

        $this->assertSame(['1 corte'], DomingoPrograma::datosDelDomingo($this->programa->id, 'corte'));
        $this->assertSame(['1 necesidad', '1 sobrante'], DomingoPrograma::datosDelDomingo($this->programa->id, 'empaque'));
        $this->assertSame(['1 embarque'], DomingoPrograma::datosDelDomingo($this->programa->id, 'embarques'));

        $vacia = ProgramaSemanal::create(['lunes' => '2026-12-07']);
        $this->assertSame([], DomingoPrograma::datosDelDomingo($vacia->id, 'corte'));

        $this->expectException(InvalidArgumentException::class);
        DomingoPrograma::datosDelDomingo($this->programa->id, 'ventas');
    }
}
