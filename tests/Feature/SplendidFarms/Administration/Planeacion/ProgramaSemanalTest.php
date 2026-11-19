<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Lote;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\RendimientoVariedad;
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
 * necesidades, empaque por destino, embarques, balance por grupo y etapas de
 * la semana), con la aceptación de WK48 del Excel. Los acuerdos de la junta ya
 * no existen ni afectan el balance.
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
        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
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

        $d = $this->semana('2026-11-23')->assertOk()->json('data');

        $this->assertSame('2026-11-23', $d['programa']['lunes']);
        $this->assertSame(48, $d['programa']['semana_iso']);
        $this->assertSame(['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28'], $d['programa']['dias']);
        $this->assertNull($d['programa']['notas']);
        $this->assertArrayNotHasKey('junta_registrada_at', $d['programa']);
        $this->assertTrue($d['permisos']['tablero']['view']);
        $this->assertFalse($d['permisos']['plan_corte']['capturar']);
        $this->assertSame([$this->temporada->id], array_column($d['temporadas'], 'id'));

        $this->assertEqualsWithDelta(70.0, array_sum(array_column($d['cortes'], 'hectareas')), 0.001);
        $this->assertSame(44225, array_sum(array_column($d['cortes'], 'cajas')));
        $this->assertSame(44225, collect($d['balance'])->sum(fn ($g) => array_sum($g['produccion'])));
        $this->assertSame(36483, collect($d['balance'])->sum(fn ($g) => array_sum($g['necesidad'])));

        $ab = $d['balance'][$this->idAb()];
        $this->assertSame(32675, array_sum($ab['produccion']));
        $this->assertSame(22040, array_sum($ab['necesidad']));
        $this->assertSame(10635, $ab['final']);
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
        $this->assertFalse($caruso['en_crm']);
        $this->assertSame($this->idAb(), (string) $caruso['grupo_id']);
        $this->assertSame([1000, 0, 0, 0, 0, 0], $caruso['cajas']);
    }

    public function test_wk48_sin_acuerdos_da_los_valores_del_excel(): void
    {
        $p = $this->wk48();
        // Acuerdos viejos que quedaron en la base: ya no cuentan para nada.
        $etapa = $this->crearEtapa('Blanco', 4, ['fecha_cosecha_estimada' => '2026-11-30']);
        $vallarta = $p->necesidades()->where('cliente_nombre', 'Vallarta')->firstOrFail();
        ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'adelantar_etapa', 'activo' => true, 'etapa_id' => $etapa->id,
            'temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 2, 'hectareas' => 4,
        ]);
        ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'mover_pedido', 'activo' => true, 'necesidad_id' => $vallarta->id,
            'dia_origen' => 2, 'destino_lunes' => '2026-11-30', 'dia_destino' => 0, 'cajas' => 2000,
        ]);
        ProgramaAcuerdo::create([
            'programa_id' => $p->id, 'tipo' => 'conseguir_producto', 'activo' => true,
            'variedad_id' => $this->variedades['Blanco']->id, 'dia' => 3, 'cajas' => 1000, 'origen_tipo' => 'otro',
        ]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');

        $ab = $d['balance'][$this->idAb()];
        $this->assertSame(10635, $ab['final']);
        $this->assertSame('cubre_inventario', $ab['estado']);
        $blanco = $d['balance'][$this->idBlanco()];
        $this->assertSame(-2893, $blanco['final']);
        $this->assertSame(2, $blanco['primer_dia_falta']);
        $this->assertSame(-4882, $blanco['peor_valor']);

        foreach (['acuerdos', 'opciones_falta', 'viajes', 'logistica', 'cambios', 'sugerencias_etapas'] as $llave) {
            $this->assertArrayNotHasKey($llave, $d);
        }
        foreach ($d['balance'] as $g) {
            $this->assertArrayNotHasKey('final_sin_acuerdos', $g);
        }
        foreach ($d['necesidades'] as $n) {
            $this->assertArrayNotHasKey('receta_id', $n);
            $this->assertArrayNotHasKey('estado_receta', $n);
            $this->assertArrayNotHasKey('movida_desde', $n);
        }

        // El adelanto no le quita hectáreas a la etapa, y WK49 no recibe el pedido movido.
        $this->assertEquals(4.0, collect($d['etapas'])->firstWhere('etapa_id', $etapa->id)['ha_disponibles']);
        $d49 = $this->semana('2026-11-30')->assertOk()->json('data');
        $this->assertSame([], $d49['necesidades']);
        $this->assertSame(-2893, $d49['balance'][$this->idBlanco()]['inicial']);
    }

    public function test_permisos_por_submodulo_en_el_payload(): void
    {
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view', 'capturar'], 'tablero-semanal' => ['view']]));

        $permisos = $this->semana('2026-11-23')->assertOk()->json('data.permisos');

        $this->assertSame([
            'tablero' => ['view' => true, 'editar_notas' => false],
            'plan_corte' => ['view' => false, 'capturar' => false],
            'necesidades' => ['view' => true, 'capturar' => true],
            'embarques' => ['view' => false, 'capturar' => false],
            'resumen' => ['view' => false],
            'configuracion' => ['view' => false, 'editar' => false],
        ], $permisos);
    }

    public function test_cualquier_view_de_las_hojas_abre_la_semana(): void
    {
        foreach (['plan-corte', 'necesidades-venta', 'embarques'] as $submodulo) {
            Sanctum::actingAs($this->usuarioCon([$submodulo => ['view']]));
            $this->semana('2026-11-23')->assertOk();
        }
    }

    public function test_sin_ningun_view_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['resumen-temporada' => ['view'], 'configuracion' => ['view', 'editar']]));

        $this->semana('2026-11-23')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
        $this->assertSame(0, ProgramaSemanal::count());
    }

    public function test_etapas_de_la_semana_con_ha_disponibles_y_proxima(): void
    {
        $proxima = $this->crearEtapa('Blanco', 8, ['fecha_cosecha_estimada' => '2026-11-30']);
        $lejana = $this->crearEtapa('Amarillo', 6, ['fecha_cosecha_estimada' => '2026-12-20']);
        $proyectada = $this->crearEtapa('Bicolor', 5, ['fecha_cosecha_estimada' => '2027-01-20', 'fecha_cosecha_proyectada' => '2026-11-24']);
        $inactiva = $this->crearEtapa('Bicolor', 8, ['fecha_cosecha_estimada' => '2026-11-26', 'is_active' => false]);

        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->corte($wk47, 'Blanco', 0, 5, $proxima);

        $etapas = collect($this->semana('2026-11-23')->assertOk()->json('data.etapas'));

        $this->assertSame([$proyectada->id, $proxima->id, $lejana->id], $etapas->pluck('etapa_id')->all());
        $this->assertNotContains($inactiva->id, $etapas->pluck('etapa_id'));

        $e = $etapas->firstWhere('etapa_id', $proxima->id);
        $this->assertSame($this->temporada->id, $e['temporada_id']);
        $this->assertSame($this->lote->id, $e['lote_id']);
        $this->assertSame('El Carrizo', $e['lote_nombre']);
        $this->assertSame($proxima->nombre, $e['etapa_nombre']);
        $this->assertSame($proxima->codigo, $e['codigo']);
        $this->assertSame($this->variedades['Blanco']->id, $e['variedad_id']);
        $this->assertEquals(8, $e['superficie']);
        $this->assertEquals(3.0, $e['ha_disponibles']);
        $this->assertSame('2026-11-30', $e['fecha_cosecha']);
        $this->assertTrue($e['proxima']);

        $this->assertFalse($etapas->firstWhere('etapa_id', $lejana->id)['proxima']);
        $this->assertSame('2026-11-24', $etapas->firstWhere('etapa_id', $proyectada->id)['fecha_cosecha']);
        $this->assertTrue($etapas->firstWhere('etapa_id', $proyectada->id)['proxima']);
    }

    public function test_empaque_por_destino_y_sin_clasificar(): void
    {
        $this->wk48();

        $empaque = $this->semana('2026-11-23')->assertOk()->json('data.empaque');

        $texas = $this->bodega('Texas')->id;
        $nogales = $this->bodega('Nogales')->id;
        $renglon = collect($empaque)->first(fn ($r) => $r['bodega_id'] === $texas
            && $r['presentacion_id'] === $this->presentacion('Bulk')->id
            && (string) $r['grupo_id'] === $this->idAb()
            && $r['tipo_empaque_id'] === $this->tipoEmpaque('RPC')->id);
        $this->assertNotNull($renglon);
        $this->assertSame('ok', $renglon['clase']);
        $this->assertSame([3460, 0, 0, 0, 0, 0], $renglon['cajas']);

        $sinClasificar = collect($empaque)->where('clase', 'sin_clasificar')->values();
        $this->assertCount(1, $sinClasificar);
        $this->assertNull($sinClasificar[0]['tipo_empaque_id']);
        $this->assertSame($texas, $sinClasificar[0]['bodega_id']);
        $this->assertSame([1000, 0, 0, 0, 0, 0], $sinClasificar[0]['cajas']);
        $this->assertSame($sinClasificar[0], collect($empaque)->last());

        // Los totales por destino suman las necesidades de cada bodega.
        $this->assertSame(7270, $this->sumaEmpaque($empaque, fn ($r) => $r['bodega_id'] === $texas));
        $this->assertSame(29213, $this->sumaEmpaque($empaque, fn ($r) => $r['bodega_id'] === $nogales));
        $this->assertSame(36483, $this->sumaEmpaque($empaque, fn () => true));
        $this->assertSame(14283, $this->sumaEmpaque($empaque, fn ($r) => $r['bodega_id'] === $nogales
            && (string) $r['grupo_id'] === $this->idBlanco() && $r['tipo_empaque_id'] === $this->tipoEmpaque('Cartón')->id));
        foreach ($empaque as $r) {
            $this->assertArrayNotHasKey('receta_id', $r);
        }
    }

    public function test_embarques_y_notas_en_el_payload(): void
    {
        $p = ProgramaSemanal::create(['lunes' => '2026-11-23', 'notas' => 'Revisar Vallarta']);
        $base = ['programa_id' => $p->id, 'cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Nogales')->id];
        ProgramaEmbarque::create($base + ['tipo' => 'sencillo', 'dia' => 2, 'cargas' => 1]);
        ProgramaEmbarque::create($base + ['tipo' => 'full', 'dia' => 0, 'cargas' => 3]);

        $d = $this->semana('2026-11-23')->assertOk()->json('data');

        $this->assertSame('Revisar Vallarta', $d['programa']['notas']);
        $this->assertSame([
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Nogales')->id, 'tipo' => 'full', 'dia' => 0, 'cargas' => 3],
            ['cultivo_id' => $this->cultivo->id, 'bodega_id' => $this->bodega('Nogales')->id, 'tipo' => 'sencillo', 'dia' => 2, 'cargas' => 1],
        ], $d['embarques']);
    }

    public function test_rutas_de_acuerdos_viajes_y_junta_ya_no_existen(): void
    {
        Sanctum::actingAs($this->usuarioCon([
            'tablero-semanal' => ['view', 'editar-notas'],
            'plan-corte' => ['view', 'capturar'],
            'embarques' => ['view', 'capturar'],
        ]));
        $p = ProgramaSemanal::create(['lunes' => '2026-11-23']);

        foreach (['acuerdos', 'viajes', 'registrar-junta'] as $ruta) {
            $status = $this->postJson(self::URL.'/'.$p->id.'/'.$ruta, [])->status();
            $this->assertContains($status, [404, 405], "programa/{id}/{$ruta} respondió {$status}");
        }
        $status = $this->postJson(self::URL.'/'.$p->id.'/cortes/repartir', [])->status();
        $this->assertContains($status, [404, 405], "cortes/repartir respondió {$status}");
    }

    public function test_semana_sin_temporadas_ni_renglones(): void
    {
        $d = $this->semana('2027-08-02')->assertOk()->json('data');

        $this->assertSame([], $d['temporadas']);
        $this->assertSame([], $d['cortes']);
        $this->assertSame([], $d['balance']);
        $this->assertSame([], $d['empaque']);
        $this->assertSame([], $d['necesidades']);
        $this->assertSame([], $d['etapas']);
        $this->assertSame([], $d['embarques']);
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
            $this->necesidad($p, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [100, 0, 0, 0, 0, 0]);
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
        // Cada semana: +700 de producción y −100 de necesidad.
        $this->assertSame(600 * 2, $b3['inicial']);
        $this->assertSame(600 * 11, $b12['inicial']);
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

    public function test_etapas_solo_de_temporadas_abiertas_de_la_semana(): void
    {
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

        $etapas = $this->semana('2026-11-23')->assertOk()->json('data.etapas');

        $this->assertSame([$propia->id], array_column($etapas, 'etapa_id'));
        $this->assertSame([$this->temporada->id], array_column($etapas, 'temporada_id'));
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
}
