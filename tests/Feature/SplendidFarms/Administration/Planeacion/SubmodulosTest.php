<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Application;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Planeación se divide en seis submódulos con permisos propios; la migración
 * traduce los permisos viejos de «Programa semanal». También cubre la tabla de
 * embarques por cargas.
 */
class SubmodulosTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION_VIEJA = '2026_10_28_100200_add_planeacion_programa_semanal_submodule.php';
    private const MIGRACION_MOVER = '2026_10_30_100000_mover_planeacion_a_administracion.php';
    private const MIGRACION_DIVIDIR = '2026_11_11_100100_dividir_planeacion_en_submodulos.php';

    private const SUBMODULOS = [
        'tablero-semanal' => ['view', 'editar-notas'],
        'plan-corte' => ['view', 'capturar'],
        'necesidades-venta' => ['view', 'capturar'],
        'embarques' => ['view', 'capturar'],
        'resumen-temporada' => ['view'],
        'configuracion' => ['view', 'editar'],
    ];

    private function correr(string $archivo): void
    {
        (require base_path('database/migrations/'.$archivo))->up();
    }

    /** Estructura de SF como la dejan las migraciones de la entrega 1: Planeación en Administración. */
    private function sembrarEstructura(): int
    {
        $empresa = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            ['name' => 'Splendid Farms', 'description' => 'Splendid Farms', 'is_active' => true],
        );
        Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $empresa->id],
            ['name' => 'Operación Agrícola', 'description' => 'Operaciones agrícolas', 'icon' => 'Tractor', 'path' => '/splendidfarms/operacion-agricola', 'is_active' => true],
        );

        $this->correr(self::MIGRACION_VIEJA);
        $this->correr(self::MIGRACION_MOVER);

        return (int) DB::table('modules')->where('slug', 'planeacion')->value('id');
    }

    /** @return array<int, string> slugs de permisos concedidos al usuario en el submódulo. */
    private function concedidos(User $usuario, string $submodulo): array
    {
        return DB::table('user_submodule_permissions')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_permissions.submodule_id')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->where('user_submodule_permissions.user_id', $usuario->id)
            ->where('submodules.slug', $submodulo)
            ->where('user_submodule_permissions.is_granted', true)
            ->pluck('submodule_permission_types.slug')
            ->all();
    }

    private function conAcceso(User $usuario, string $submodulo): bool
    {
        return DB::table('user_submodule_access')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_access.submodule_id')
            ->where('user_submodule_access.user_id', $usuario->id)
            ->where('submodules.slug', $submodulo)
            ->where('user_submodule_access.is_active', true)
            ->exists();
    }

    /** Concede permisos viejos de «programa-semanal» a un usuario (con su acceso activo). */
    private function concederViejos(User $usuario, array $slugs): void
    {
        $submoduloId = DB::table('submodules')->where('slug', 'programa-semanal')->value('id');
        DB::table('user_submodule_access')->updateOrInsert(
            ['user_id' => $usuario->id, 'submodule_id' => $submoduloId],
            ['is_active' => true, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
        foreach ($slugs as $slug) {
            $tipoId = DB::table('submodule_permission_types')->where('submodule_id', $submoduloId)->where('slug', $slug)->value('id');
            DB::table('user_submodule_permissions')->insert([
                'user_id' => $usuario->id, 'submodule_id' => $submoduloId, 'permission_type_id' => $tipoId,
                'is_granted' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_crea_los_seis_submodulos_con_sus_permisos(): void
    {
        $moduloId = $this->sembrarEstructura();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);

        $this->correr(self::MIGRACION_DIVIDIR);
        $this->correr(self::MIGRACION_DIVIDIR);

        $nuevos = DB::table('submodules')->where('module_id', $moduloId)->where('slug', '!=', 'programa-semanal')->orderBy('order')->get();
        $this->assertSame(array_keys(self::SUBMODULOS), $nuevos->pluck('slug')->all());
        $this->assertSame(
            ['Tablero semanal', 'Plan de corte', 'Necesidades de venta', 'Embarques', 'Resumen de temporada', 'Configuración'],
            $nuevos->pluck('name')->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $nuevos->pluck('order')->map(fn ($o) => (int) $o)->all());

        foreach (self::SUBMODULOS as $slug => $permisos) {
            $submodulo = $nuevos->firstWhere('slug', $slug);
            $tipos = DB::table('submodule_permission_types')->where('submodule_id', $submodulo->id)->pluck('slug')->all();
            $this->assertEqualsCanonicalizing($permisos, $tipos, $slug);

            foreach ([$admin, $demo] as $usuario) {
                $this->assertEqualsCanonicalizing($permisos, $this->concedidos($usuario, $slug), $slug);
                $this->assertTrue($this->conAcceso($usuario, $slug), $slug);
            }
        }

        $this->assertFalse((bool) DB::table('submodules')->where('slug', 'programa-semanal')->value('is_active'));
        $this->assertSame(1, DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->count());
    }

    public function test_pasa_los_permisos_viejos_a_los_submodulos_nuevos(): void
    {
        $this->sembrarEstructura();
        $ventas = User::factory()->create();
        $coordinador = User::factory()->create();
        $junta = User::factory()->create();
        $negado = User::factory()->create();

        $this->concederViejos($ventas, ['view', 'capturar-ventas']);
        $this->concederViejos($coordinador, ['capturar-corte', 'capturar-logistica', 'configurar']);
        $this->concederViejos($junta, ['acuerdos']);
        // Permiso viejo con is_granted = false: no se traduce.
        $this->concederViejos($negado, ['view']);
        DB::table('user_submodule_permissions')->where('user_id', $negado->id)->update(['is_granted' => false]);

        $this->correr(self::MIGRACION_DIVIDIR);
        $this->correr(self::MIGRACION_DIVIDIR);

        $this->assertSame(['view'], $this->concedidos($ventas, 'tablero-semanal'));
        $this->assertSame(['view'], $this->concedidos($ventas, 'resumen-temporada'));
        $this->assertEqualsCanonicalizing(['view', 'capturar'], $this->concedidos($ventas, 'necesidades-venta'));
        $this->assertSame([], $this->concedidos($ventas, 'plan-corte'));
        $this->assertTrue($this->conAcceso($ventas, 'tablero-semanal'));
        $this->assertTrue($this->conAcceso($ventas, 'resumen-temporada'));
        $this->assertTrue($this->conAcceso($ventas, 'necesidades-venta'));
        $this->assertFalse($this->conAcceso($ventas, 'plan-corte'));
        $this->assertFalse($this->conAcceso($ventas, 'configuracion'));

        $this->assertEqualsCanonicalizing(['view', 'capturar'], $this->concedidos($coordinador, 'plan-corte'));
        $this->assertEqualsCanonicalizing(['view', 'capturar'], $this->concedidos($coordinador, 'embarques'));
        $this->assertEqualsCanonicalizing(['view', 'editar'], $this->concedidos($coordinador, 'configuracion'));
        $this->assertSame([], $this->concedidos($coordinador, 'tablero-semanal'));

        $this->assertSame(['editar-notas'], $this->concedidos($junta, 'tablero-semanal'));
        $this->assertTrue($this->conAcceso($junta, 'tablero-semanal'));

        foreach (array_keys(self::SUBMODULOS) as $slug) {
            $this->assertSame([], $this->concedidos($negado, $slug), $slug);
        }
    }

    public function test_sin_modulo_planeacion_no_falla(): void
    {
        $this->correr(self::MIGRACION_DIVIDIR);

        $this->assertSame(0, DB::table('modules')->where('slug', 'planeacion')->count());
        $this->assertSame(0, DB::table('submodules')->where('slug', 'embarques')->count());
    }

    public function test_down_reactiva_el_viejo_y_borra_los_seis(): void
    {
        $moduloId = $this->sembrarEstructura();
        User::factory()->create(['email' => 'admin@sentinel.com']);

        $this->correr(self::MIGRACION_DIVIDIR);
        (require base_path('database/migrations/'.self::MIGRACION_DIVIDIR))->down();

        $slugs = DB::table('submodules')->where('module_id', $moduloId)->pluck('slug')->all();
        $this->assertSame(['programa-semanal'], $slugs);
        $this->assertTrue((bool) DB::table('submodules')->where('slug', 'programa-semanal')->value('is_active'));
        $this->assertSame(0, DB::table('submodule_permission_types')->whereIn('slug', ['editar-notas', 'capturar', 'editar'])->count());
    }

    public function test_programa_embarques_rechaza_celda_duplicada(): void
    {
        $usuario = User::factory()->create();
        $cultivo = Cultivo::create(['nombre' => 'Elote Dulce']);
        $bodega = BodegaDestino::firstOrCreate(['nombre' => 'Texas'], ['orden' => 1]);
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23', 'notas' => 'Junta del lunes']);

        $celda = ['cultivo_id' => $cultivo->id, 'bodega_id' => $bodega->id, 'tipo' => 'full', 'dia' => 2, 'cargas' => 3, 'created_by' => $usuario->id];
        $programa->embarques()->create($celda);

        $this->assertSame('Junta del lunes', $programa->fresh()->notas);
        $this->assertCount(1, $programa->fresh()->embarques);

        // Otro tipo en el mismo día sí cabe.
        $programa->embarques()->create(array_merge($celda, ['tipo' => 'sencillo']));

        $this->expectException(QueryException::class);
        $programa->embarques()->create(array_merge($celda, ['cargas' => 9]));
    }
}
