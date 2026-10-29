<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Models\Application;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\TipoEmpaque;
use App\Models\Temporada;
use App\Models\User;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Esquema de Planeación → Programa semanal: el submódulo con sus 6 permisos y
 * la siembra de catálogos de elote. Las migraciones de datos corren en la BD
 * vacía de las pruebas sin nada que sembrar, así que aquí se crea el contexto
 * y se vuelve a invocar su up() (también prueba que son idempotentes).
 */
class EsquemaYSubmoduloTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION_SUBMODULO = '2026_10_28_100200_add_planeacion_programa_semanal_submodule.php';
    private const MIGRACION_CATALOGOS = '2026_10_28_100300_seed_planeacion_catalogos_elote.php';

    private const PERMISOS = [
        'view', 'capturar-corte', 'capturar-ventas', 'capturar-logistica', 'acuerdos', 'configurar',
    ];

    private function correr(string $archivo): void
    {
        (require base_path('database/migrations/'.$archivo))->up();
    }

    private function sembrarEstructuraSf(): void
    {
        $empresa = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            ['name' => 'Splendid Farms', 'description' => 'Splendid Farms', 'is_active' => true],
        );

        Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $empresa->id],
            ['name' => 'Operación Agrícola', 'description' => 'Operaciones agrícolas', 'icon' => 'Tractor', 'path' => '/splendidfarms/operacion-agricola', 'is_active' => true],
        );
    }

    private function sembrarElote(): Cultivo
    {
        $usuario = User::factory()->create();
        $cultivo = Cultivo::create(['nombre' => 'Elote Dulce']);
        foreach (['Amarillo', 'Bicolor', 'Blanco'] as $nombre) {
            Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => $nombre, 'user_id' => $usuario->id]);
        }

        return $cultivo;
    }

    public function test_submodulo_y_permisos_existen_y_son_idempotentes(): void
    {
        $this->sembrarEstructuraSf();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);

        $this->correr(self::MIGRACION_SUBMODULO);
        $this->correr(self::MIGRACION_SUBMODULO);

        $aplicacionId = DB::table('applications')->where('slug', 'operacion-agricola')->value('id');
        $modulos = DB::table('modules')->where('application_id', $aplicacionId)->where('slug', 'planeacion')->get();
        $this->assertCount(1, $modulos);
        $this->assertSame('Planeación', $modulos[0]->name);

        $submodulos = DB::table('submodules')->where('module_id', $modulos[0]->id)->where('slug', 'programa-semanal')->get();
        $this->assertCount(1, $submodulos);
        $submoduloId = $submodulos[0]->id;

        $tipos = DB::table('submodule_permission_types')->where('submodule_id', $submoduloId)->pluck('slug')->all();
        $this->assertEqualsCanonicalizing(self::PERMISOS, $tipos);

        $concedidos = DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->where('user_submodule_permissions.user_id', $admin->id)
            ->where('user_submodule_permissions.submodule_id', $submoduloId)
            ->where('user_submodule_permissions.is_granted', true)
            ->pluck('submodule_permission_types.slug')
            ->all();
        $this->assertEqualsCanonicalizing(self::PERMISOS, $concedidos);

        $this->assertSame(1, DB::table('user_submodule_access')->where('user_id', $admin->id)->where('submodule_id', $submoduloId)->count());
        $this->assertSame(1, DB::table('user_module_access')->where('user_id', $admin->id)->where('module_id', $modulos[0]->id)->count());
    }

    public function test_submodulo_sin_aplicacion_no_falla(): void
    {
        $this->correr(self::MIGRACION_SUBMODULO);

        $this->assertSame(0, DB::table('modules')->where('slug', 'planeacion')->count());
    }

    public function test_catalogos_de_elote_se_siembran(): void
    {
        $cultivo = $this->sembrarElote();

        $this->correr(self::MIGRACION_CATALOGOS);
        $this->correr(self::MIGRACION_CATALOGOS);

        $this->assertSame(
            ['Texas', 'Nogales', 'Mexicali', 'Nacional'],
            BodegaDestino::orderBy('orden')->pluck('nombre')->all(),
        );
        $this->assertSame(
            ['Bulk', 'Tray', 'Minicobs', 'Tray 5 pz'],
            Presentacion::where('cultivo_id', $cultivo->id)->orderBy('orden')->pluck('nombre')->all(),
        );
        $this->assertSame(
            ['RPC', 'Cartón', 'DPC'],
            TipoEmpaque::orderBy('orden')->pluck('nombre')->all(),
        );

        $grupos = GrupoBalance::where('cultivo_id', $cultivo->id)->with('variedades')->orderBy('orden')->get();
        $this->assertSame(['Amarillo / Bicolor', 'Blanco'], $grupos->pluck('nombre')->all());
        $this->assertEqualsCanonicalizing(['Amarillo', 'Bicolor'], $grupos[0]->variedades->pluck('nombre')->all());
        $this->assertSame(['Blanco'], $grupos[1]->variedades->pluck('nombre')->all());

        $this->assertEquals(1040, ConfiguracionPlaneacion::valor('cajas_por_carga', null));
        $this->assertSame(1, DB::table('plan_configuracion')->where('clave', 'cajas_por_carga')->count());
    }

    public function test_sin_cultivo_elote_la_siembra_no_falla(): void
    {
        $this->correr(self::MIGRACION_CATALOGOS);

        $this->assertSame(4, BodegaDestino::count());
        $this->assertSame(3, TipoEmpaque::count());
        $this->assertEquals(1040, ConfiguracionPlaneacion::valor('cajas_por_carga', null));
        $this->assertSame(0, Presentacion::count());
        $this->assertSame(0, GrupoBalance::count());
    }

    public function test_la_siembra_no_pisa_un_valor_ya_editado(): void
    {
        ConfiguracionPlaneacion::where('clave', 'cajas_por_carga')->update(['valor' => '1100']);

        $this->correr(self::MIGRACION_CATALOGOS);

        $this->assertEquals(1100, ConfiguracionPlaneacion::valor('cajas_por_carga', null));
        $this->assertSame(50, ConfiguracionPlaneacion::valor('no_existe', 50));
    }

    public function test_el_esquema_del_programa_y_sus_relaciones_funcionan(): void
    {
        $usuario = User::factory()->create();
        $cultivo = $this->sembrarElote();
        $this->correr(self::MIGRACION_CATALOGOS);
        $temporada = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => 'Elote 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31',
            'user_id' => $usuario->id,
        ]);
        $amarillo = Variedad::where('nombre', 'Amarillo')->first();
        $bicolor = Variedad::where('nombre', 'Bicolor')->first();
        $bodega = BodegaDestino::where('nombre', 'Texas')->first();
        $presentacion = Presentacion::where('nombre', 'Bulk')->first();

        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
        $this->assertSame('2026-11-23', $programa->fresh()->lunes->toDateString());

        $programa->cortes()->create([
            'temporada_id' => $temporada->id, 'variedad_id' => $amarillo->id,
            'dia' => 0, 'hectareas' => 12.5, 'created_by' => $usuario->id,
        ]);
        $necesidad = $programa->necesidades()->create([
            'bodega_id' => $bodega->id, 'cliente_nombre' => 'Cliente libre', 'cultivo_id' => $cultivo->id,
            'presentacion_id' => $presentacion->id, 'cajas_0' => 100, 'cajas_3' => 40,
        ]);
        $necesidad->variedades()->sync([$amarillo->id, $bicolor->id]);
        $programa->viajes()->create([
            'dia' => 1, 'bodega_id' => $bodega->id, 'cultivo_id' => $cultivo->id,
            'tipo' => 'full', 'cajas_caja_1' => 500, 'cajas_caja_2' => 540,
        ]);
        $programa->acuerdos()->create([
            'tipo' => 'mover_pedido', 'descripcion' => 'Mover a la otra semana', 'necesidad_id' => $necesidad->id,
            'dia_origen' => 0, 'destino_lunes' => '2026-11-30', 'dia_destino' => 2, 'cajas' => 50,
        ]);
        $programa->rendimientos()->create(['temporada_id' => $temporada->id, 'variedad_id' => $amarillo->id, 'modo' => 'exacto', 'valor_exacto' => 900]);
        $programa->inventariosIniciales()->create(['grupo_clave' => 'v'.$amarillo->id, 'cajas' => 300]);
        $programa->juntas()->create(['registrada_por' => $usuario->id, 'foto' => ['pizarron.jpg']]);
        $programa->cambios()->create(['entidad' => 'corte', 'entidad_id' => 1, 'accion' => 'creado', 'despues' => ['dia' => 0], 'user_id' => $usuario->id]);

        $programa = $programa->fresh();
        $this->assertCount(1, $programa->cortes);
        $this->assertCount(1, $programa->necesidades);
        $this->assertCount(1, $programa->viajes);
        $this->assertCount(1, $programa->acuerdos);
        $this->assertCount(1, $programa->rendimientos);
        $this->assertCount(1, $programa->inventariosIniciales);
        $this->assertCount(1, $programa->juntas);
        $this->assertCount(1, $programa->cambios);
        $this->assertNotNull($programa->cambios[0]->created_at);
        $this->assertSame(['pizarron.jpg'], $programa->juntas[0]->foto);
        $this->assertSame([100, 0, 0, 40, 0, 0], $programa->necesidades[0]->cajasPorDia());
        $this->assertEqualsCanonicalizing(['Amarillo', 'Bicolor'], $programa->necesidades[0]->variedades->pluck('nombre')->all());
        $this->assertTrue($programa->acuerdos[0]->activo);
    }
}
