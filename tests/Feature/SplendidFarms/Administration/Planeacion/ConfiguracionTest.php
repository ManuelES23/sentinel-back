<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaViaje;
use App\Models\Planeacion\RecetaCombinacion;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Planeacion\TipoEmpaque;
use App\Models\Recipe;
use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuración del Programa semanal (catálogos, grupos, recetas por
 * combinación, rendimientos y cajas por carga) y búsqueda de clientes de SBP.
 */
class ConfiguracionTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion';

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
    }

    private function conPermisos(array $permisos = ['configuracion' => ['view', 'editar']]): void
    {
        Sanctum::actingAs($this->usuarioCon($permisos));
    }

    private function receta(string $codigo, string $nombre, string $tipo = 'empaque'): Recipe
    {
        return Recipe::create([
            'enterprise_id' => $this->empresaSfId(),
            'code' => $codigo,
            'name' => $nombre,
            'recipe_type' => $tipo,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_lee_toda_la_configuracion(): void
    {
        $this->conPermisos(['configuracion' => ['view']]);
        $empaque = $this->receta('R-1', 'Bulk RPC');
        $this->receta('R-2', 'Otra cosa', 'produccion');
        $borrada = $this->receta('R-3', 'Receta borrada');
        $borrada->delete();
        RecetaCombinacion::create([
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_id' => null,
            'recipe_id' => $empaque->id,
        ]);
        ConfiguracionPlaneacion::where('clave', 'cajas_por_carga')->update(['valor' => '1100']);

        $r = $this->getJson(self::URL.'/configuracion')->assertOk()->assertJsonPath('success', true);

        $datos = $r->json('data');
        $this->assertEqualsCanonicalizing(['Texas', 'Nogales', 'Mexicali', 'Nacional'], collect($datos['bodegas'])->pluck('nombre')->all());
        $this->assertSame($this->cultivo->id, collect($datos['presentaciones'])->firstWhere('nombre', 'Bulk')['cultivo_id']);
        $this->assertContains('RPC', collect($datos['tipos_empaque'])->pluck('nombre')->all());

        $amarilloBicolor = collect($datos['grupos'])->firstWhere('nombre', 'Amarillo / Bicolor');
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $amarilloBicolor['variedad_ids'],
        );
        $this->assertSame($this->cultivo->id, $amarilloBicolor['cultivo_id']);

        $this->assertCount(1, $datos['recetas_combinacion']);
        $this->assertSame('Bulk RPC', $datos['recetas_combinacion'][0]['recipe_name']);
        $this->assertSame([['id' => $empaque->id, 'name' => 'Bulk RPC']], $datos['recetas_empaque']);

        $this->assertCount(3, $datos['rendimientos']);
        $this->assertEqualsCanonicalizing(
            ['temporada_id', 'variedad_id', 'minimo', 'maximo'],
            array_keys($datos['rendimientos'][0]),
        );
        $this->assertSame(1100, $datos['cajas_por_carga']);
    }

    public function test_configuracion_trae_temporadas_cultivos_y_variedades(): void
    {
        // Solo `configuracion.view`: no hace falta poder ver el Tablero.
        $this->conPermisos(['configuracion' => ['view']]);
        $grupo = GrupoBalance::where('cultivo_id', $this->cultivo->id)->where('nombre', 'Amarillo / Bicolor')->firstOrFail();
        $otro = Cultivo::create(['nombre' => 'Cultivo sin temporada ni catálogo']);
        $sinGrupo = Variedad::create(['cultivo_id' => $this->cultivo->id, 'nombre' => 'Morado', 'user_id' => $this->autor->id]);

        $datos = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data');

        $this->assertSame([[
            'id' => $this->temporada->id,
            'nombre' => 'OI 2026-2027',
            'cultivo_id' => $this->cultivo->id,
            'locacion' => 'Los Mochis',
            'fecha_inicio' => '2026-11-23',
            'fecha_fin' => '2027-06-05',
        ]], $datos['temporadas']);
        $this->assertSame([['id' => $this->cultivo->id, 'nombre' => 'Elote Dulce']], $datos['cultivos']);
        $this->assertNotContains($otro->id, collect($datos['cultivos'])->pluck('id')->all());

        $this->assertCount(4, $datos['variedades']);
        $amarillo = collect($datos['variedades'])->firstWhere('nombre', 'Amarillo');
        $this->assertSame(
            ['id' => $this->variedades['Amarillo']->id, 'cultivo_id' => $this->cultivo->id, 'nombre' => 'Amarillo', 'grupo_id' => $grupo->id],
            $amarillo,
        );
        // Sin grupo explícito, la clave es la implícita «v{id}», igual que en el programa.
        $this->assertSame('v'.$sinGrupo->id, collect($datos['variedades'])->firstWhere('nombre', 'Morado')['grupo_id']);
    }

    public function test_configuracion_incluye_temporadas_abiertas_que_no_tocan_la_semana_y_excluye_cerradas_y_borradas(): void
    {
        $this->conPermisos(['configuracion' => ['view']]);
        $otro = Cultivo::create(['nombre' => 'Fresa']);
        $campos = ['locacion' => 'Guasave', 'año_inicio' => 2027, 'año_fin' => 2028, 'user_id' => $this->autor->id];
        $lejana = Temporada::create($campos + [
            'cultivo_id' => $otro->id, 'nombre' => 'PV 2027', 'folio_temporada' => $otro->id.'-001',
            'fecha_inicio' => '2027-08-01', 'fecha_fin' => '2028-01-31', 'estado' => 'abierta',
        ]);
        Temporada::create($campos + [
            'cultivo_id' => $otro->id, 'nombre' => 'Cerrada', 'folio_temporada' => $otro->id.'-002',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-05-01', 'estado' => 'cerrada',
        ]);
        $borrada = Temporada::create($campos + [
            'cultivo_id' => $otro->id, 'nombre' => 'Borrada', 'folio_temporada' => $otro->id.'-003',
            'fecha_inicio' => '2027-02-01', 'fecha_fin' => '2027-03-01', 'estado' => 'abierta',
        ]);
        $borrada->delete();
        $zanahoria = Cultivo::create(['nombre' => 'Zanahoria']);
        Presentacion::create(['cultivo_id' => $zanahoria->id, 'nombre' => 'Bolsa 1 kg', 'orden' => 1, 'is_active' => true]);
        Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Albion', 'user_id' => $this->autor->id]);
        Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Variedad borrada', 'user_id' => $this->autor->id])->delete();

        $datos = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data');

        // Ordenadas por fecha de inicio: la de elote (nov 2026) y luego la lejana (ago 2027).
        $this->assertSame([$this->temporada->id, $lejana->id], collect($datos['temporadas'])->pluck('id')->all());
        // Cultivos con temporada abierta o con presentaciones, por nombre.
        $this->assertSame(['Elote Dulce', 'Fresa', 'Zanahoria'], collect($datos['cultivos'])->pluck('nombre')->all());
        $nombres = collect($datos['variedades'])->pluck('nombre')->all();
        $this->assertContains('Albion', $nombres);
        $this->assertNotContains('Variedad borrada', $nombres);
    }

    public function test_sin_permiso_editar_es_403(): void
    {
        $this->conPermisos(['configuracion' => ['view'], 'plan-corte' => ['view', 'capturar']]);

        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 900])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
        $this->postJson(self::URL.'/configuracion/bodegas', ['nombre' => 'Chicago'])->assertForbidden();
        $this->putJson(self::URL.'/configuracion/grupos', ['cultivo_id' => $this->cultivo->id, 'grupos' => []])->assertForbidden();
        $this->assertDatabaseMissing('plan_bodegas_destino', ['nombre' => 'Chicago']);
        $this->assertSame(1040, ConfiguracionPlaneacion::valor('cajas_por_carga', 0));
    }

    public function test_sin_permiso_ver_no_lee_configuracion_ni_clientes(): void
    {
        // Editar la configuración no da `configuracion.view`; los clientes piden `necesidades-venta.view`.
        $this->conPermisos(['configuracion' => ['editar'], 'tablero-semanal' => ['view']]);

        $this->getJson(self::URL.'/configuracion')->assertForbidden();
        $this->getJson(self::URL.'/clientes?q=wal')->assertForbidden();
    }

    public function test_grupo_con_variedad_repetida_es_422(): void
    {
        $this->conPermisos();
        $am = $this->variedades['Amarillo']->id;
        $bi = $this->variedades['Bicolor']->id;

        $antes = GrupoBalance::count();
        $this->putJson(self::URL.'/configuracion/grupos', [
            'cultivo_id' => $this->cultivo->id,
            'grupos' => [
                ['nombre' => 'Uno', 'variedad_ids' => [$am, $bi]],
                ['nombre' => 'Dos', 'variedad_ids' => [$bi]],
            ],
        ])->assertStatus(422);

        // No cambió nada.
        $this->assertSame($antes, GrupoBalance::count());
    }

    public function test_reemplaza_los_grupos_del_cultivo(): void
    {
        $this->conPermisos();
        $am = $this->variedades['Amarillo']->id;
        $bi = $this->variedades['Bicolor']->id;
        $bl = $this->variedades['Blanco']->id;
        $otro = Cultivo::create(['nombre' => 'Otro cultivo']);
        $ajeno = GrupoBalance::create(['cultivo_id' => $otro->id, 'nombre' => 'Ajeno', 'orden' => 0]);

        $existente = $this->grupo('Amarillo / Bicolor');

        $this->putJson(self::URL.'/configuracion/grupos', [
            'cultivo_id' => $this->cultivo->id,
            'grupos' => [
                ['id' => $existente->id, 'nombre' => 'Amarillo y Blanco', 'variedad_ids' => [$am, $bl]],
                ['nombre' => 'Bicolor solo', 'variedad_ids' => [$bi]],
            ],
        ])->assertOk();

        $grupos = GrupoBalance::where('cultivo_id', $this->cultivo->id)->orderBy('orden')->get();
        $this->assertCount(2, $grupos);
        $this->assertSame($existente->id, $grupos[0]->id);
        $this->assertSame('Amarillo y Blanco', $grupos[0]->nombre);
        $this->assertEqualsCanonicalizing([$am, $bl], $grupos[0]->variedades()->pluck('variedades.id')->all());
        $this->assertSame([$bi], $grupos[1]->variedades()->pluck('variedades.id')->all());
        $this->assertTrue(GrupoBalance::whereKey($ajeno->id)->exists());
    }

    public function test_grupo_con_variedad_de_otro_cultivo_es_422(): void
    {
        $this->conPermisos();
        $otro = Cultivo::create(['nombre' => 'Otro cultivo']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'X', 'user_id' => $this->autor->id]);

        $this->putJson(self::URL.'/configuracion/grupos', [
            'cultivo_id' => $this->cultivo->id,
            'grupos' => [['nombre' => 'Mal', 'variedad_ids' => [$ajena->id]]],
        ])->assertStatus(422);
    }

    public function test_rendimiento_minimo_mayor_que_maximo_es_422(): void
    {
        $this->conPermisos();
        $am = $this->variedades['Amarillo']->id;

        $this->putJson(self::URL.'/configuracion/rendimientos', [
            'temporada_id' => $this->temporada->id,
            'filas' => [['variedad_id' => $am, 'minimo' => 700, 'maximo' => 600]],
        ])->assertStatus(422);
        $this->putJson(self::URL.'/configuracion/rendimientos', [
            'temporada_id' => $this->temporada->id,
            'filas' => [['variedad_id' => $am, 'minimo' => 0, 'maximo' => 600]],
        ])->assertStatus(422);

        $this->assertEquals(500, RendimientoVariedad::where('variedad_id', $am)->value('minimo'));
    }

    public function test_guarda_rendimientos_de_la_temporada(): void
    {
        $this->conPermisos();
        $am = $this->variedades['Amarillo']->id;

        $this->putJson(self::URL.'/configuracion/rendimientos', [
            'temporada_id' => $this->temporada->id,
            'filas' => [['variedad_id' => $am, 'minimo' => 520.5, 'maximo' => 620]],
        ])->assertOk();

        $fila = RendimientoVariedad::where('temporada_id', $this->temporada->id)->where('variedad_id', $am)->firstOrFail();
        $this->assertEquals(520.5, $fila->minimo);
        $this->assertEquals(620, $fila->maximo);
        $this->assertSame(3, RendimientoVariedad::count());
    }

    public function test_copiar_rendimientos_entre_temporadas(): void
    {
        $this->conPermisos();
        $destino = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'PV 2027',
            'locacion' => 'Los Mochis',
            'folio_temporada' => $this->cultivo->id.'-002',
            'año_inicio' => 2027,
            'año_fin' => 2027,
            'fecha_inicio' => '2027-06-06',
            'fecha_fin' => '2027-10-30',
            'estado' => 'abierta',
            'user_id' => $this->autor->id,
        ]);

        $this->postJson(self::URL.'/configuracion/rendimientos/copiar', [
            'desde_temporada_id' => $this->temporada->id,
            'hacia_temporada_id' => $destino->id,
        ])->assertOk();

        $copiados = RendimientoVariedad::where('temporada_id', $destino->id)->get()->keyBy('variedad_id');
        $this->assertCount(3, $copiados);
        $this->assertEquals(600, $copiados[$this->variedades['Bicolor']->id]->minimo);
        $this->assertEquals(700, $copiados[$this->variedades['Bicolor']->id]->maximo);
        // El origen sigue igual.
        $this->assertSame(3, RendimientoVariedad::where('temporada_id', $this->temporada->id)->count());

        $this->postJson(self::URL.'/configuracion/rendimientos/copiar', [
            'desde_temporada_id' => $this->temporada->id,
            'hacia_temporada_id' => $this->temporada->id,
        ])->assertStatus(422);
    }

    public function test_cajas_por_carga(): void
    {
        $this->conPermisos();

        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 1200])->assertOk();
        $this->assertSame(1200, ConfiguracionPlaneacion::valor('cajas_por_carga', 1040));
        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 1300])->assertOk();
        $this->assertSame(1300, ConfiguracionPlaneacion::valor('cajas_por_carga', 1040));
        $this->assertSame(1, ConfiguracionPlaneacion::where('clave', 'cajas_por_carga')->count());

        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 0])->assertStatus(422);
        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 5001])->assertStatus(422);
        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 'abc'])->assertStatus(422);
        $this->assertSame(1300, ConfiguracionPlaneacion::valor('cajas_por_carga', 1040));
    }

    public function test_recetas_combinacion_reemplaza_todas_las_filas(): void
    {
        $this->conPermisos();
        $r1 = $this->receta('R-1', 'Bulk RPC');
        $r2 = $this->receta('R-2', 'Tray RPC');
        $bulk = $this->presentacion('Bulk')->id;
        $tray = $this->presentacion('Tray')->id;
        $rpc = $this->tipoEmpaque('RPC')->id;
        RecetaCombinacion::create(['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => null, 'recipe_id' => $r2->id]);

        $this->putJson(self::URL.'/configuracion/recetas-combinacion', ['filas' => [
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'recipe_id' => $r1->id],
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => $this->variedades['Blanco']->id, 'recipe_id' => $r2->id],
            ['presentacion_id' => $tray, 'tipo_empaque_id' => $rpc, 'variedad_id' => null, 'recipe_id' => $r2->id],
        ]])->assertOk();

        $this->assertSame(3, RecetaCombinacion::count());
        $this->assertSame($r1->id, RecetaCombinacion::where('presentacion_id', $bulk)->whereNull('variedad_id')->value('recipe_id'));
    }

    public function test_recetas_combinacion_duplicada_es_422(): void
    {
        $this->conPermisos();
        $r1 = $this->receta('R-1', 'Bulk RPC');
        $r2 = $this->receta('R-2', 'Tray RPC');
        $bulk = $this->presentacion('Bulk')->id;
        $rpc = $this->tipoEmpaque('RPC')->id;
        RecetaCombinacion::create(['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => null, 'recipe_id' => $r2->id]);

        // Duplicado con variedad nula (el índice único de la base no lo detiene).
        $this->putJson(self::URL.'/configuracion/recetas-combinacion', ['filas' => [
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'recipe_id' => $r1->id],
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => null, 'recipe_id' => $r2->id],
        ]])->assertStatus(422);

        // Duplicado con variedad concreta.
        $am = $this->variedades['Amarillo']->id;
        $this->putJson(self::URL.'/configuracion/recetas-combinacion', ['filas' => [
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => $am, 'recipe_id' => $r1->id],
            ['presentacion_id' => $bulk, 'tipo_empaque_id' => $rpc, 'variedad_id' => $am, 'recipe_id' => $r2->id],
        ]])->assertStatus(422);

        // No se tocó lo que había.
        $this->assertSame(1, RecetaCombinacion::count());
        $this->assertSame($r2->id, RecetaCombinacion::first()->recipe_id);
    }

    public function test_recetas_combinacion_solo_acepta_recetas_de_empaque(): void
    {
        $this->conPermisos();
        $otra = $this->receta('R-9', 'No es de empaque', 'produccion');

        $this->putJson(self::URL.'/configuracion/recetas-combinacion', ['filas' => [
            ['presentacion_id' => $this->presentacion('Bulk')->id, 'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id, 'recipe_id' => $otra->id],
        ]])->assertStatus(422);
    }

    public function test_crea_edita_y_desactiva_catalogos(): void
    {
        $this->conPermisos();

        $id = $this->postJson(self::URL.'/configuracion/bodegas', ['nombre' => 'Chicago', 'orden' => 9])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('plan_bodegas_destino', ['id' => $id, 'nombre' => 'Chicago', 'is_active' => 1]);

        $this->putJson(self::URL.'/configuracion/bodegas/'.$id, ['is_active' => false])->assertOk();
        $this->assertFalse(BodegaDestino::find($id)->is_active);

        $pid = $this->postJson(self::URL.'/configuracion/presentaciones', ['nombre' => 'Jumbo', 'cultivo_id' => $this->cultivo->id])
            ->assertCreated()->json('data.id');
        $this->assertSame($this->cultivo->id, Presentacion::find($pid)->cultivo_id);

        // Presentación sin cultivo, nombre repetido y catálogo desconocido.
        $this->postJson(self::URL.'/configuracion/presentaciones', ['nombre' => 'Otra'])->assertStatus(422);
        $this->postJson(self::URL.'/configuracion/presentaciones', ['nombre' => 'Jumbo', 'cultivo_id' => $this->cultivo->id])->assertStatus(422);
        $this->postJson(self::URL.'/configuracion/bodegas', ['nombre' => 'Chicago'])->assertStatus(422);
        $this->postJson(self::URL.'/configuracion/cosas', ['nombre' => 'X'])->assertNotFound();

        $tid = $this->postJson(self::URL.'/configuracion/tipos-empaque', ['nombre' => 'Bolsa'])->assertCreated()->json('data.id');
        $this->putJson(self::URL.'/configuracion/tipos-empaque/'.$tid, ['nombre' => 'Bolsa grande', 'orden' => 4])->assertOk();
        $this->assertSame('Bolsa grande', TipoEmpaque::find($tid)->nombre);

        // Sin uso, se puede borrar.
        $this->deleteJson(self::URL.'/configuracion/tipos-empaque/'.$tid)->assertOk();
        $this->assertNull(TipoEmpaque::find($tid));
    }

    public function test_borrar_bodega_en_uso_es_422(): void
    {
        $this->conPermisos();
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->necesidad($programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0]);
        ProgramaEmbarque::create([
            'programa_id' => $programa->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega('Nogales')->id,
            'tipo' => 'full',
            'dia' => 1,
            'cargas' => 2,
        ]);
        // Un viaje viejo (tabla sin uso) también la ocupa: su llave foránea no dejaría borrarla.
        ProgramaViaje::create([
            'programa_id' => $programa->id,
            'dia' => 1,
            'bodega_id' => $this->bodega('Nacional')->id,
            'cultivo_id' => $this->cultivo->id,
            'tipo' => 'full',
            'cajas_caja_1' => 500,
        ]);

        foreach (['Texas', 'Nogales', 'Nacional'] as $nombre) {
            $this->deleteJson(self::URL.'/configuracion/bodegas/'.$this->bodega($nombre)->id)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Está en uso; desactívalo');
            $this->assertDatabaseHas('plan_bodegas_destino', ['nombre' => $nombre]);
        }

        // Una bodega sin uso sí se borra.
        $this->deleteJson(self::URL.'/configuracion/bodegas/'.$this->bodega('Mexicali')->id)->assertOk();
        $this->assertDatabaseMissing('plan_bodegas_destino', ['nombre' => 'Mexicali']);
    }

    public function test_borrar_presentacion_o_empaque_en_uso_es_422(): void
    {
        $this->conPermisos();
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->necesidad($programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [100, 0, 0, 0, 0, 0]);
        $receta = $this->receta('R-1', 'Tray Cartón');
        RecetaCombinacion::create([
            'presentacion_id' => $this->presentacion('Tray')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('Cartón')->id,
            'variedad_id' => null,
            'recipe_id' => $receta->id,
        ]);

        // Dos en uso por necesidades y dos por una receta de combinación.
        $this->deleteJson(self::URL.'/configuracion/presentaciones/'.$this->presentacion('Bulk')->id)->assertStatus(422);
        $this->deleteJson(self::URL.'/configuracion/presentaciones/'.$this->presentacion('Tray')->id)->assertStatus(422);
        $this->deleteJson(self::URL.'/configuracion/tipos-empaque/'.$this->tipoEmpaque('RPC')->id)->assertStatus(422);
        $this->deleteJson(self::URL.'/configuracion/tipos-empaque/'.$this->tipoEmpaque('Cartón')->id)->assertStatus(422);

        $this->assertSame(1, RecetaCombinacion::count());
        $this->assertSame(1, ProgramaNecesidad::count());
    }

    public function test_la_configuracion_no_emite_evento(): void
    {
        $this->conPermisos();
        Event::fake();
        ProgramaSemanal::create(['lunes' => '2026-11-23']);

        $this->postJson(self::URL.'/configuracion/bodegas', ['nombre' => 'Chicago'])->assertCreated();
        $this->putJson(self::URL.'/configuracion/cajas-por-carga', ['valor' => 1000])->assertOk();

        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_clientes_solo_de_sbp(): void
    {
        $this->conPermisos(['necesidades-venta' => ['view']]);
        $sf = Enterprise::firstOrCreate(['slug' => 'splendidfarms'], ['name' => 'Splendid Farms', 'description' => 'SF', 'is_active' => true]);
        $sbp = Enterprise::create(['slug' => 'splendidbyporvenir', 'name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true]);

        CrmCliente::create(['empresa_id' => $sf->id, 'nombre' => 'Walmart SF']);
        $walmart = CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Walmart']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'El Super']);
        $borrado = CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Walmart viejo']);
        $borrado->delete();

        $r = $this->getJson(self::URL.'/clientes?q=wal')->assertOk();

        $this->assertSame([['id' => $walmart->id, 'nombre' => 'Walmart']], $r->json('data'));
    }

    public function test_clientes_sin_busqueda_devuelve_los_primeros_20_en_orden(): void
    {
        $this->conPermisos(['necesidades-venta' => ['view']]);
        $sbp = Enterprise::create(['slug' => 'splendidbyporvenir', 'name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true]);
        for ($i = 25; $i >= 1; $i--) {
            CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => sprintf('Cliente %02d', $i)]);
        }

        $nombres = collect($this->getJson(self::URL.'/clientes')->assertOk()->json('data'))->pluck('nombre');

        $this->assertCount(20, $nombres);
        $this->assertSame('Cliente 01', $nombres->first());
        $this->assertSame('Cliente 20', $nombres->last());
    }

    public function test_clientes_sin_empresa_sbp_devuelve_lista_vacia(): void
    {
        $this->conPermisos(['necesidades-venta' => ['view']]);

        $this->getJson(self::URL.'/clientes?q=wal')->assertOk()->assertJsonPath('data', []);
    }
}
