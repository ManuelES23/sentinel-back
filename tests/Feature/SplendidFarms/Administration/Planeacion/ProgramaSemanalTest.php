<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Lote;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaViaje;
use App\Models\Planeacion\RecetaCombinacion;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Recipe;
use App\Models\Temporada;
use App\Services\Planeacion\ProgramaSemanalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET planeacion/programa: armado de la semana (corte, producción,
 * necesidades, empaque, logística, balance por grupo y opciones de falta),
 * con la aceptación de WK48 del Excel (spec §7).
 */
class ProgramaSemanalTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion/programa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['view']));
    }

    private function semana(string $lunes): TestResponse
    {
        return $this->getJson(self::URL.'?lunes='.$lunes);
    }

    private function wk48(): ProgramaSemanal
    {
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->cargarWk48($programa);

        return $programa;
    }

    private function idAb(): string
    {
        return (string) $this->grupo('Amarillo / Bicolor')->id;
    }

    private function idBlanco(): string
    {
        return (string) $this->grupo('Blanco')->id;
    }

    /** Suma, día por día, los arreglos de cajas de los renglones que cumplen $filtro. */
    private function sumaEmpaque(array $empaque, callable $filtro): int
    {
        return (int) collect($empaque)->filter($filtro)->sum(fn ($r) => array_sum($r['cajas']));
    }

    public function test_wk48_reproduce_el_excel(): void
    {
        $this->wk48();

        $r = $this->semana('2026-11-23')->assertOk();
        $d = $r->json('data');

        $this->assertSame('2026-11-23', $d['programa']['lunes']);
        $this->assertSame(48, $d['programa']['semana_iso']);
        $this->assertSame(['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28'], $d['programa']['dias']);
        $this->assertTrue($d['permisos']['view']);
        $this->assertFalse($d['permisos']['capturar_corte']);
        $this->assertSame([$this->temporada->id], array_column($d['temporadas'], 'id'));

        $this->assertEqualsWithDelta(70.0, array_sum(array_column($d['cortes'], 'hectareas')), 0.001);
        $this->assertSame(44225, array_sum(array_column($d['cortes'], 'cajas')));
        $this->assertSame(44225, collect($d['balance'])->sum(fn ($g) => array_sum($g['produccion'])));
        $this->assertSame(36483, collect($d['balance'])->sum(fn ($g) => array_sum($g['necesidad'])));

        $ab = $d['balance'][$this->idAb()];
        $this->assertSame(32675, array_sum($ab['produccion']));
        $this->assertSame(22040, array_sum($ab['necesidad']));
        $this->assertSame(10635, $ab['final']);
        $this->assertSame(10635, $ab['final_sin_acuerdos']);
        $this->assertSame('cubre_inventario', $ab['estado']);

        $blanco = $d['balance'][$this->idBlanco()];
        $this->assertSame(11550, array_sum($blanco['produccion']));
        $this->assertSame(14443, array_sum($blanco['necesidad']));
        $this->assertSame(-2893, $blanco['final']);
        $this->assertSame('falta', $blanco['estado']);
        $this->assertSame(2, $blanco['primer_dia_falta']);
        $this->assertSame(-3334, $blanco['acumulado'][2]);
        $this->assertSame(3, $blanco['peor_dia']);
        $this->assertSame(-4882, $blanco['peor_valor']);
        $this->assertSame([2100, 1400, 2450, 0, 2800, 2800], $blanco['produccion_por_temporada'][$this->temporada->id]);

        $caruso = collect($d['necesidades'])->firstWhere('cliente_nombre', 'Caruso');
        $this->assertSame('sin_empaque', $caruso['estado_receta']);
        $this->assertFalse($caruso['en_crm']);
        $this->assertSame($this->idAb(), (string) $caruso['grupo_id']);

        $logTexas = collect($d['logistica'])->firstWhere('bodega_id', $this->bodega('Texas')->id);
        $this->assertSame([7270, 0, 0, 0, 0, 0], $logTexas['pedido']);
        $this->assertSame([0, 0, 0, 0, 0, 0], $logTexas['planeado']);
    }

    public function test_empaque_sin_clasificar_y_sin_receta(): void
    {
        $receta = Recipe::create([
            'enterprise_id' => $this->empresaSfId(),
            'code' => 'EMP-BULK-RPC',
            'name' => 'Bulk RPC elote',
            'recipe_type' => 'empaque',
            'output_quantity' => 1,
            'status' => 'active',
        ]);
        RecetaCombinacion::create([
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_id' => null,
            'recipe_id' => $receta->id,
        ]);
        $this->wk48();

        $d = $this->semana('2026-11-23')->assertOk()->json('data');
        $empaque = $d['empaque'];

        $es = fn (string $pres, string $grupo, ?string $tipo) => fn ($r) => $r['presentacion_id'] === $this->presentacion($pres)->id
            && (string) $r['grupo_id'] === $grupo
            && $r['tipo_empaque_id'] === ($tipo ? $this->tipoEmpaque($tipo)->id : null);

        $ab = $this->idAb();
        $bl = $this->idBlanco();
        $this->assertSame(14330, $this->sumaEmpaque($empaque, $es('Bulk', $ab, 'RPC')));
        $this->assertSame(4060, $this->sumaEmpaque($empaque, $es('Bulk', $ab, 'Cartón')));
        $this->assertSame(14283, $this->sumaEmpaque($empaque, $es('Bulk', $bl, 'Cartón')));
        $this->assertSame(2500, $this->sumaEmpaque($empaque, $es('Tray', $ab, 'RPC')));
        $this->assertSame(160, $this->sumaEmpaque($empaque, $es('Tray', $bl, 'RPC')));
        $this->assertSame(150, $this->sumaEmpaque($empaque, $es('Minicobs', $ab, 'RPC')));
        $this->assertSame(1000, $this->sumaEmpaque($empaque, fn ($r) => $r['clase'] === 'sin_clasificar'));

        foreach ($empaque as $renglon) {
            if ($renglon['tipo_empaque_id'] === null) {
                $this->assertSame('sin_clasificar', $renglon['clase']);
            } elseif ($renglon['presentacion_id'] === $this->presentacion('Bulk')->id && $renglon['tipo_empaque_id'] === $this->tipoEmpaque('RPC')->id) {
                $this->assertSame('ok', $renglon['clase']);
                $this->assertSame($receta->id, $renglon['receta_id']);
                $this->assertSame('Bulk RPC elote', $renglon['receta_nombre']);
            } else {
                $this->assertSame('sin_receta', $renglon['clase']);
                $this->assertNull($renglon['receta_id']);
            }
        }

        // El renglón de Ama/Bi (dos variedades) toma la receta sin variedad.
        $walmartAmaBi = collect($d['necesidades'])->first(fn ($n) => count($n['variedad_ids']) === 2);
        $this->assertSame('ok', $walmartAmaBi['estado_receta']);
        $this->assertSame($receta->id, $walmartAmaBi['receta_id']);

        $this->assertSame(-2893, $d['balance'][$bl]['final']);
    }

    public function test_receta_por_variedad_tiene_prioridad_sobre_la_general(): void
    {
        $general = Recipe::create(['enterprise_id' => $this->empresaSfId(), 'code' => 'R-GEN', 'name' => 'General', 'recipe_type' => 'empaque', 'output_quantity' => 1, 'status' => 'active']);
        $blanco = Recipe::create(['enterprise_id' => $this->empresaSfId(), 'code' => 'R-BCO', 'name' => 'Solo Blanco', 'recipe_type' => 'empaque', 'output_quantity' => 1, 'status' => 'active']);
        $base = ['presentacion_id' => $this->presentacion('Bulk')->id, 'tipo_empaque_id' => $this->tipoEmpaque('Cartón')->id];
        RecetaCombinacion::create($base + ['variedad_id' => null, 'recipe_id' => $general->id]);
        RecetaCombinacion::create($base + ['variedad_id' => $this->variedades['Blanco']->id, 'recipe_id' => $blanco->id]);
        $this->wk48();

        $necesidades = collect($this->semana('2026-11-23')->assertOk()->json('data.necesidades'));

        $this->assertSame($blanco->id, $necesidades->firstWhere('cliente_nombre', 'Vallarta')['receta_id']);
        $vestaAmarillo = $necesidades->first(fn ($n) => $n['cliente_nombre'] === 'Vesta' && $n['variedad_ids'] === [$this->variedades['Amarillo']->id]);
        $this->assertSame($general->id, $vestaAmarillo['receta_id']);
    }

    public function test_semana_sin_temporadas_ni_renglones(): void
    {
        $d = $this->semana('2027-08-02')->assertOk()->json('data');

        $this->assertSame([], $d['temporadas']);
        $this->assertSame([], $d['cortes']);
        $this->assertSame([], $d['balance']);
        $this->assertSame([], $d['empaque']);
        $this->assertSame([], $d['opciones_falta']);
        $this->assertSame([], $d['necesidades']);
        $this->assertSame([], $d['sugerencias_etapas']);
        $this->assertSame(1040, $d['catalogos']['cajas_por_carga']);
        $this->assertSame(1, ProgramaSemanal::whereDate('lunes', '2027-08-02')->count());
    }

    public function test_abrir_dos_veces_la_misma_semana_no_duplica_el_programa(): void
    {
        $this->semana('2026-11-23')->assertOk();
        $id = $this->semana('2026-11-23')->assertOk()->json('data.programa.id');

        $this->assertSame(1, ProgramaSemanal::count());
        $this->assertSame(ProgramaSemanal::first()->id, $id);
    }

    public function test_lunes_invalido_es_422(): void
    {
        $this->semana('2026-11-24')->assertStatus(422);
        $this->semana('no-es-fecha')->assertStatus(422);
        $this->getJson(self::URL)->assertStatus(422);
        $this->assertSame(0, ProgramaSemanal::count());
    }

    public function test_sin_permiso_view_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['capturar-ventas']));

        $this->semana('2026-11-23')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción del Programa semanal.');
    }

    public function test_inventario_se_arrastra_de_la_semana_anterior(): void
    {
        $this->wk48();

        $blanco = $this->idBlanco();
        $d = $this->semana('2026-11-30')->assertOk()->json('data');
        $this->assertSame(-2893, $d['balance'][$blanco]['inicial']);
        $this->assertSame(-2893, $d['balance'][$blanco]['final']);
        $this->assertSame(10635, $d['balance'][$this->idAb()]['inicial']);

        $wk49 = ProgramaSemanal::whereDate('lunes', '2026-11-30')->firstOrFail();
        ProgramaInventarioInicial::create(['programa_id' => $wk49->id, 'grupo_clave' => $blanco, 'cajas' => 500]);

        $d = $this->semana('2026-11-30')->assertOk()->json('data');
        $this->assertSame(500, $d['balance'][$blanco]['inicial']);
        $this->assertSame(10635, $d['balance'][$this->idAb()]['inicial']);

        // La cadena sigue: WK50 hereda el final de WK49 (500, sin movimientos).
        $this->assertSame(500, $this->semana('2026-12-07')->json("data.balance.{$blanco}.inicial"));
    }

    public function test_la_cadena_de_semanas_no_multiplica_las_consultas(): void
    {
        $lunes = Carbon::parse('2026-11-23');
        for ($i = 0; $i < 12; $i++) {
            $p = ProgramaSemanal::create(['lunes' => $lunes->copy()->addWeeks($i)->toDateString()]);
            $this->corte($p, 'Blanco', 0, 1);
            $n = $this->necesidad($p, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [100, 0, 0, 0, 0, 0]);
            ProgramaAcuerdo::create(['programa_id' => $p->id, 'tipo' => 'mover_pedido', 'activo' => true, 'necesidad_id' => $n->id,
                'dia_origen' => 0, 'destino_lunes' => $lunes->copy()->addWeeks($i + 1)->toDateString(), 'dia_destino' => 0, 'cajas' => 50]);
        }

        $consultas = function (string $fecha): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $balance = $this->semana($fecha)->assertOk()->json("data.balance.{$this->idBlanco()}");
            DB::disableQueryLog();

            return [count(DB::getQueryLog()), $balance];
        };

        // Una petición previa para que ambas mediciones partan igual (la ruta conserva el controlador).
        $this->semana('2026-11-23')->assertOk();
        [$corta, $b3] = $consultas('2026-12-07');      // cadena de 3 semanas
        [$larga, $b12] = $consultas('2027-02-08');     // cadena de 12 semanas

        $this->assertSame($corta, $larga);
        // Cada semana: +700 de producción, −50 que se quedan (100 − 50 movidas) y −50 que llegan de la previa.
        $this->assertSame(650 + 600, $b3['inicial']);
        $this->assertSame(650 + 600 * 10, $b12['inicial']);
    }

    public function test_una_semana_sin_programa_deja_pasar_el_inventario(): void
    {
        $this->wk48();
        $blanco = $this->idBlanco();

        // WK50 se abre sin que exista WK49: hereda el final de WK48.
        $this->assertSame(-2893, $this->semana('2026-12-07')->assertOk()->json("data.balance.{$blanco}.inicial"));

        // Abrir WK49 (vacía) no cambia WK50.
        $this->assertSame(-2893, $this->semana('2026-11-30')->assertOk()->json("data.balance.{$blanco}.inicial"));
        $this->assertSame(-2893, $this->semana('2026-12-07')->assertOk()->json("data.balance.{$blanco}.inicial"));
        $this->assertSame(3, ProgramaSemanal::count());
    }

    public function test_olvidar_descarta_lo_calculado_en_la_peticion(): void
    {
        $servicio = app(ProgramaSemanalService::class);
        $p = $servicio->programaDe('2026-11-23');
        $blanco = $this->idBlanco();

        $this->assertSame(0, $servicio->balanceDe($p)[$blanco]['final']);

        $this->corte($p, 'Blanco', 0, 1);
        $this->assertSame(0, $servicio->balanceDe($p)[$blanco]['final']); // memo de la petición

        $servicio->olvidar();
        $this->assertSame(700, $servicio->balanceDe($p->fresh())[$blanco]['final']);
    }

    public function test_un_viaje_sencillo_solo_cuenta_su_primera_caja(): void
    {
        $p = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $base = ['programa_id' => $p->id, 'bodega_id' => $this->bodega('Texas')->id, 'cultivo_id' => $this->cultivo->id];
        ProgramaViaje::create($base + ['dia' => 0, 'tipo' => 'sencillo', 'cajas_caja_1' => 1000, 'cajas_caja_2' => 999]);
        ProgramaViaje::create($base + ['dia' => 1, 'tipo' => 'full', 'cajas_caja_1' => 1040, 'cajas_caja_2' => 980]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');

        $fila = collect($d['logistica'])->firstWhere('bodega_id', $this->bodega('Texas')->id);
        $this->assertSame([1000, 2020, 0, 0, 0, 0], $fila['planeado']);
        $this->assertSame([1000], collect($d['viajes'])->firstWhere('dia', 0)['cajas_cajas']);
    }

    public function test_conseguir_producto_sin_variedad_no_suma_ni_reporta_efecto(): void
    {
        $p = $this->wk48();
        $acuerdo = ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'conseguir_producto', 'activo' => true,
            'variedad_id' => null, 'dia' => 3, 'cajas' => 1000, 'origen_tipo' => 'otro',
        ]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');

        $this->assertSame(-2893, $d['balance'][$this->idBlanco()]['final']);
        $this->assertSame(10635, $d['balance'][$this->idAb()]['final']);
        $efecto = collect($d['acuerdos'])->firstWhere('id', $acuerdo->id)['efecto'];
        $this->assertSame(['grupo_id' => null, 'dia' => 3, 'cajas' => 0], $efecto);
    }

    public function test_rendimiento_elegido(): void
    {
        $p = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->corte($p, 'Blanco', 0, 1);
        $blancoId = $this->variedades['Blanco']->id;

        $leer = function () use ($blancoId) {
            $d = $this->semana('2026-11-23')->assertOk()->json('data');
            $rend = collect($d['rendimientos'])->first(fn ($r) => $r['temporada_id'] === $this->temporada->id && $r['variedad_id'] === $blancoId);

            return [$rend, $d['cortes'][0]['cajas']];
        };

        [$rend, $cajas] = $leer();
        $this->assertSame('medio', $rend['modo']);
        $this->assertEquals(650, $rend['minimo']);
        $this->assertEquals(750, $rend['maximo']);
        $this->assertEquals(700, $rend['valor_usado']);
        $this->assertSame(700, $cajas);

        $eleccion = ProgramaRendimiento::create(['programa_id' => $p->id, 'temporada_id' => $this->temporada->id, 'variedad_id' => $blancoId, 'modo' => 'minimo']);
        [$rend, $cajas] = $leer();
        $this->assertEquals(650, $rend['valor_usado']);
        $this->assertSame(650, $cajas);

        $eleccion->update(['modo' => 'maximo']);
        [$rend, $cajas] = $leer();
        $this->assertSame(750, $cajas);

        $eleccion->update(['modo' => 'exacto', 'valor_exacto' => 720]);
        [$rend, $cajas] = $leer();
        $this->assertSame('exacto', $rend['modo']);
        $this->assertEquals(720, $rend['valor_exacto']);
        $this->assertEquals(720, $rend['valor_usado']);
        $this->assertSame(720, $cajas);

        RendimientoVariedad::where('variedad_id', $blancoId)->delete();
        [$rend, $cajas] = $leer();
        $this->assertNull($rend['minimo']);
        $this->assertNull($rend['valor_usado']);
        $this->assertSame(0, $cajas);
    }

    public function test_sugiere_etapas_de_la_semana_con_ha_disponibles(): void
    {
        $parcial = $this->crearEtapa('Blanco', 10, ['fecha_cosecha_estimada' => '2026-12-20', 'fecha_cosecha_proyectada' => '2026-11-25']);
        $llena = $this->crearEtapa('Amarillo', 5, ['fecha_cosecha_estimada' => '2026-11-24']);
        $proyectadaFuera = $this->crearEtapa('Bicolor', 8, ['fecha_cosecha_estimada' => '2026-11-26', 'fecha_cosecha_proyectada' => '2026-12-15']);
        $inactiva = $this->crearEtapa('Bicolor', 8, ['fecha_cosecha_estimada' => '2026-11-26', 'is_active' => false]);

        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->corte($wk49, 'Blanco', 1, 4, $parcial);
        $this->corte($wk49, 'Amarillo', 0, 5, $llena);

        $sugerencias = collect($this->semana('2026-11-23')->assertOk()->json('data.sugerencias_etapas'));

        $this->assertSame([$parcial->id], $sugerencias->pluck('etapa_id')->all());
        $s = $sugerencias->first();
        $this->assertSame($this->temporada->id, $s['temporada_id']);
        $this->assertSame('El Carrizo', $s['lote_nombre']);
        $this->assertSame('2026-11-25', $s['fecha_cosecha']);
        $this->assertEquals(10, $s['superficie']);
        $this->assertEquals(4, $s['ha_asignadas']);
        $this->assertEquals(6, $s['ha_disponibles']);
        $this->assertNotContains($proyectadaFuera->id, $sugerencias->pluck('etapa_id'));
        $this->assertNotContains($inactiva->id, $sugerencias->pluck('etapa_id'));
    }

    public function test_clientes_previos(): void
    {
        $this->wk48();
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->necesidad($wk49, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 3000, 0, 0, 0]);

        $previos = collect($this->semana('2026-11-30')->assertOk()->json('data.clientes_previos'));

        $nogales = $this->bodega('Nogales')->id;
        $this->assertTrue($previos->contains(fn ($c) => $c['cliente_nombre'] === 'Shapiro' && $c['bodega_id'] === $nogales && $c['cliente_id'] === null));
        $this->assertFalse($previos->contains(fn ($c) => $c['cliente_nombre'] === 'Vallarta'));
        $this->assertCount(1, $previos->where('cliente_nombre', 'Vesta'));
        $this->assertTrue($previos->contains(fn ($c) => $c['cliente_nombre'] === 'Walmart' && $c['bodega_id'] === $this->bodega('Texas')->id));

        // Solo cuentan las 4 semanas previas: en WK53 (28 dic) WK48 ya quedó fuera y WK49 no.
        $previos = collect($this->semana('2026-12-28')->assertOk()->json('data.clientes_previos'));
        $this->assertFalse($previos->contains(fn ($c) => $c['cliente_nombre'] === 'Shapiro'));
        $this->assertSame(['Vallarta'], $previos->pluck('cliente_nombre')->all());
    }

    public function test_opciones_de_falta_para_blanco(): void
    {
        $this->wk48();
        $etapa = $this->crearEtapa('Blanco', 4, ['fecha_cosecha_estimada' => '2026-11-30']);
        $this->crearEtapa('Blanco', 6, ['fecha_cosecha_estimada' => '2026-12-20']); // fuera de los 14 días

        $d = $this->semana('2026-11-23')->assertOk()->json('data');
        $opciones = $d['opciones_falta'];

        $this->assertArrayHasKey($this->idBlanco(), $opciones);
        $this->assertArrayNotHasKey($this->idAb(), $opciones);

        $blanco = $opciones[$this->idBlanco()];
        $this->assertSame(2, $blanco['dia']);
        $this->assertCount(1, $blanco['etapas']);
        $this->assertSame($etapa->id, $blanco['etapas'][0]['etapa_id']);
        $this->assertEquals(4, $blanco['etapas'][0]['ha_disponibles']);
        $this->assertSame('Vallarta', $blanco['pedidos'][0]['cliente_nombre']);
        $this->assertSame(6584, $blanco['pedidos'][0]['cajas']);
        $this->assertCount(1, $blanco['pedidos']);
    }

    public function test_opciones_de_falta_solo_con_etapas_de_temporadas_de_la_semana(): void
    {
        $this->wk48();
        $propia = $this->crearEtapa('Blanco', 4, ['fecha_cosecha_estimada' => '2026-11-30']);

        // Otra temporada del elote, abierta, que empieza después del sábado: no toca WK48.
        $bajio = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Bajío 2026-2027',
            'locacion' => 'Bajío',
            'folio_temporada' => $this->cultivo->id.'-002',
            'año_inicio' => 2026,
            'año_fin' => 2027,
            'fecha_inicio' => '2026-12-01',
            'fecha_fin' => '2027-04-30',
            'estado' => 'abierta',
            'user_id' => $this->autor->id,
        ]);
        $lote = Lote::create([
            'productor_id' => $this->lote->productor_id,
            'zona_cultivo_id' => $this->lote->zona_cultivo_id,
            'nombre' => 'La Loma',
            'superficie' => 100,
            'is_active' => true,
        ]);
        $bajio->lotes()->attach($lote->id, ['cultivo_id' => $this->cultivo->id, 'is_active' => true]);
        $this->crearEtapa('Blanco', 5, ['lote_id' => $lote->id, 'fecha_cosecha_estimada' => '2026-12-02']);

        $etapas = $this->semana('2026-11-23')->assertOk()->json("data.opciones_falta.{$this->idBlanco()}.etapas");

        // Guardar un adelanto de la otra temporada daría 422: no se ofrece.
        $this->assertSame([$propia->id], array_column($etapas, 'etapa_id'));
        $this->assertSame([$this->temporada->id], array_column($etapas, 'temporada_id'));
    }

    public function test_opciones_de_falta_no_proponen_mas_cajas_de_las_movibles(): void
    {
        $p = $this->wk48();
        $shapiro = $p->necesidades()->where('cliente_nombre', 'Shapiro')->firstOrFail();
        $vallarta = $p->necesidades()->where('cliente_nombre', 'Vallarta')->firstOrFail();
        // Shapiro pasa 1,000 cajas del jueves al miércoles: el miércoles tiene 1,000 efectivas, pero ninguna capturada.
        ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'mover_pedido', 'activo' => true, 'necesidad_id' => $shapiro->id,
            'dia_origen' => 3, 'destino_lunes' => '2026-11-23', 'dia_destino' => 2, 'cajas' => 1000,
        ]);
        // Vallarta ya manda 2,000 del miércoles a WK49: le quedan 4,584 por mover.
        ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'mover_pedido', 'activo' => true, 'necesidad_id' => $vallarta->id,
            'dia_origen' => 2, 'destino_lunes' => '2026-11-30', 'dia_destino' => 0, 'cajas' => 2000,
        ]);

        $blanco = $this->semana('2026-11-23')->assertOk()->json("data.opciones_falta.{$this->idBlanco()}");

        $this->assertSame(2, $blanco['dia']);
        $this->assertSame([['necesidad_id' => $vallarta->id, 'cliente_nombre' => 'Vallarta', 'cajas' => 4584]], $blanco['pedidos']);
    }

    public function test_adelantar_etapa_suma_produccion_y_cuenta_como_asignada(): void
    {
        $p = $this->wk48();
        $etapa = $this->crearEtapa('Blanco', 4, ['fecha_cosecha_estimada' => '2026-11-30']);
        $acuerdo = ProgramaAcuerdo::create([
            'programa_id' => $p->id,
            'tipo' => 'adelantar_etapa',
            'activo' => true,
            'descripcion' => 'Adelantar 4 ha de Blanco al miércoles',
            'etapa_id' => $etapa->id,
            'temporada_id' => $this->temporada->id,
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 2,
            'hectareas' => 4,
        ]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');
        $blanco = $d['balance'][$this->idBlanco()];
        $this->assertSame(-93, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);
        $this->assertSame(2450 + 2800, $blanco['produccion'][2]);

        $a = collect($d['acuerdos'])->firstWhere('id', $acuerdo->id);
        $this->assertSame($this->idBlanco(), (string) $a['efecto']['grupo_id']);
        $this->assertSame(2, $a['efecto']['dia']);
        $this->assertSame(2800, $a['efecto']['cajas']);

        // La etapa ya no se sugiere en WK49: sus hectáreas cuentan como cortadas.
        $this->assertSame([], $this->semana('2026-11-30')->json('data.sugerencias_etapas'));
        $this->assertEquals(0.0, app(\App\Services\Planeacion\ProgramaSemanalService::class)->haDisponibles($etapa->id));
        $this->assertEquals(4.0, app(\App\Services\Planeacion\ProgramaSemanalService::class)->haDisponibles($etapa->id, null, $acuerdo->id));

        // Inactivo: se revierte.
        $acuerdo->update(['activo' => false]);
        $this->assertSame(-2893, $this->semana('2026-11-23')->json("data.balance.{$this->idBlanco()}.final"));
    }

    public function test_conseguir_producto_y_mover_pedido_a_otra_semana(): void
    {
        $p = $this->wk48();
        $vallarta = $p->necesidades()->where('cliente_nombre', 'Vallarta')->firstOrFail();
        $mover = ProgramaAcuerdo::create([
            'programa_id' => $p->id,
            'tipo' => 'mover_pedido',
            'activo' => true,
            'necesidad_id' => $vallarta->id,
            'dia_origen' => 2,
            'destino_lunes' => '2026-11-30',
            'dia_destino' => 0,
            'cajas' => 2000,
        ]);
        ProgramaAcuerdo::create([
            'programa_id' => $p->id,
            'tipo' => 'conseguir_producto',
            'activo' => true,
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 3,
            'cajas' => 1000,
            'origen_tipo' => 'productor',
            'origen_texto' => 'Productor externo',
        ]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');
        $blanco = $d['balance'][$this->idBlanco()];
        // -2893 + 2000 (pedido movido fuera) + 1000 (conseguido) = 107
        $this->assertSame(107, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);
        $this->assertSame(6584 - 2000, $blanco['necesidad'][2]);
        $this->assertSame(1000, $blanco['produccion_por_temporada']['sin_temporada'][3]);
        $fila = collect($d['necesidades'])->firstWhere('id', $vallarta->id);
        $this->assertSame([0, 0, 6584, 0, 0, 0], $fila['cajas']);
        $this->assertSame([0, 0, 4584, 0, 0, 0], $fila['cajas_efectivas']);

        $d49 = $this->semana('2026-11-30')->assertOk()->json('data');
        $movida = collect($d49['necesidades'])->firstWhere('id', -$mover->id);
        $this->assertNotNull($movida);
        $this->assertSame(['lunes' => '2026-11-23', 'acuerdo_id' => $mover->id], $movida['movida_desde']);
        $this->assertSame([2000, 0, 0, 0, 0, 0], $movida['cajas']);
        $this->assertSame('Vallarta', $movida['cliente_nombre']);
        $this->assertSame(2000, $d49['balance'][$this->idBlanco()]['necesidad'][0]);
        // Inicial de WK49 = final efectivo de WK48 (107); el lunes se llevan 2000.
        $this->assertSame(107, $d49['balance'][$this->idBlanco()]['inicial']);
        $this->assertSame(107 - 2000, $d49['balance'][$this->idBlanco()]['final']);
        $this->assertSame(107, $d49['balance'][$this->idBlanco()]['final_sin_acuerdos']);
    }
}
