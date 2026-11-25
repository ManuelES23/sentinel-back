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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planeación se divide en seis submódulos con permisos propios; la migración
 * traduce los permisos viejos de «Programa semanal». También cubre la tabla de
 * embarques por cargas.
 */
class SubmodulosTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const MIGRACION_VIEJA = '2026_10_28_100200_add_planeacion_programa_semanal_submodule.php';
    private const MIGRACION_MOVER = '2026_10_30_100000_mover_planeacion_a_administracion.php';
    private const MIGRACION_DIVIDIR = '2026_11_11_100100_dividir_planeacion_en_submodulos.php';
    private const MIGRACION_EMPAQUE = '2026_11_20_110100_add_programa_empaque_submodulo.php';
    private const MIGRACION_AVANCE = '2026_11_20_130000_add_avance_empaque_submodulo.php';
    private const MIGRACION_ENVIAR = '2026_11_21_100000_add_enviar_permission_to_tablero_semanal.php';
    private const MIGRACION_AVANCES_COSECHA_EMBARQUES = '2026_11_25_110000_add_avance_cosecha_y_embarques_submodulos.php';
    private const MIGRACION_ACORDAR = '2026_11_25_120000_add_acordar_permission_to_tablero_semanal.php';

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

    public function test_acceso_inactivo_al_submodulo_viejo_no_recibe_nada_en_los_nuevos(): void
    {
        $this->sembrarEstructura();
        $desactivado = User::factory()->create();

        $this->concederViejos($desactivado, ['view', 'capturar-ventas', 'configurar']);
        // El panel le apagó el acceso, pero sus filas de permisos siguen con is_granted = true.
        DB::table('user_submodule_access')
            ->where('user_id', $desactivado->id)
            ->update(['is_active' => false]);

        $this->correr(self::MIGRACION_DIVIDIR);

        foreach (array_keys(self::SUBMODULOS) as $slug) {
            $this->assertSame([], $this->concedidos($desactivado, $slug), $slug);
            $this->assertFalse($this->conAcceso($desactivado, $slug), $slug);
        }
        $this->assertSame(0, DB::table('user_submodule_access')
            ->join('submodules', 'submodules.id', '=', 'user_submodule_access.submodule_id')
            ->where('user_submodule_access.user_id', $desactivado->id)
            ->where('submodules.slug', '!=', 'programa-semanal')
            ->count());
    }

    public function test_una_segunda_corrida_no_reactiva_lo_revocado_a_mano(): void
    {
        $this->sembrarEstructura();
        $usuario = User::factory()->create();
        $this->concederViejos($usuario, ['view', 'capturar-ventas']);

        $this->correr(self::MIGRACION_DIVIDIR);

        // El panel revoca a mano el acceso a Resumen y el permiso de capturar en Necesidades.
        $resumenId = DB::table('submodules')->where('slug', 'resumen-temporada')->value('id');
        $necesidadesId = DB::table('submodules')->where('slug', 'necesidades-venta')->value('id');
        DB::table('user_submodule_access')
            ->where('user_id', $usuario->id)->where('submodule_id', $resumenId)
            ->update(['is_active' => false]);
        $capturarId = DB::table('submodule_permission_types')->where('submodule_id', $necesidadesId)->where('slug', 'capturar')->value('id');
        DB::table('user_submodule_permissions')
            ->where('user_id', $usuario->id)->where('permission_type_id', $capturarId)
            ->update(['is_granted' => false]);
        $creado = DB::table('user_submodule_access')
            ->where('user_id', $usuario->id)->where('submodule_id', $necesidadesId)->value('created_at');

        $this->correr(self::MIGRACION_DIVIDIR);

        $this->assertFalse($this->conAcceso($usuario, 'resumen-temporada'));
        $this->assertSame(['view'], $this->concedidos($usuario, 'necesidades-venta'));
        $this->assertSame($creado, DB::table('user_submodule_access')
            ->where('user_id', $usuario->id)->where('submodule_id', $necesidadesId)->value('created_at'));
        // Lo que no se tocó sigue igual.
        $this->assertSame(['view'], $this->concedidos($usuario, 'tablero-semanal'));
        $this->assertTrue($this->conAcceso($usuario, 'tablero-semanal'));
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

    public function test_crea_el_submodulo_programa_empaque_despues_de_necesidades(): void
    {
        $moduloId = $this->sembrarEstructura();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);
        $this->correr(self::MIGRACION_DIVIDIR);
        $ordenNecesidades = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'necesidades-venta')->value('order');
        $ordenEmbarquesAntes = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order');

        $this->correr(self::MIGRACION_EMPAQUE);
        $this->correr(self::MIGRACION_EMPAQUE);

        $submodulos = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'programa-empaque')->get();
        $this->assertCount(1, $submodulos);
        $empaque = $submodulos[0];
        $this->assertSame('Programa de empaque', $empaque->name);
        $this->assertSame('Empaque y destino del sobrante de la semana', $empaque->description);
        $this->assertSame('PackageOpen', $empaque->icon);
        $this->assertTrue((bool) $empaque->is_active);
        $this->assertSame($ordenNecesidades + 1, (int) $empaque->order);

        // Los siguientes se recorren una posición, una sola vez aunque se corra dos veces.
        $this->assertSame($ordenEmbarquesAntes + 1, (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order'));
        $this->assertSame(
            ['tablero-semanal', 'plan-corte', 'necesidades-venta', 'programa-empaque', 'embarques', 'resumen-temporada', 'configuracion'],
            DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->orderBy('order')->pluck('slug')->all(),
        );

        $permisos = DB::table('submodule_permission_types')->where('submodule_id', $empaque->id)->orderBy('order')->get();
        $this->assertSame(['view', 'capturar'], $permisos->pluck('slug')->all());
        $this->assertSame(['Ver', 'Capturar'], $permisos->pluck('name')->all());
        $this->assertSame(['Ver el programa de empaque', 'Capturar el empaque del sobrante'], $permisos->pluck('description')->all());

        foreach ([$admin, $demo] as $usuario) {
            $this->assertEqualsCanonicalizing(['view', 'capturar'], $this->concedidos($usuario, 'programa-empaque'));
            $this->assertTrue($this->conAcceso($usuario, 'programa-empaque'));
        }
    }

    public function test_programa_empaque_sin_planeacion_no_falla(): void
    {
        $this->correr(self::MIGRACION_EMPAQUE);

        $this->assertSame(0, DB::table('submodules')->where('slug', 'programa-empaque')->count());
    }

    public function test_programa_empaque_espera_a_que_exista_necesidades_de_venta(): void
    {
        $moduloId = $this->sembrarEstructura();
        User::factory()->create(['email' => 'admin@sentinel.com']);

        // Planeación sin los seis submódulos (la división todavía no corrió): no hace nada ni falla.
        $this->correr(self::MIGRACION_EMPAQUE);

        $this->assertSame(0, DB::table('submodules')->where('slug', 'programa-empaque')->count());
        $this->assertSame(['programa-semanal'], DB::table('submodules')->where('module_id', $moduloId)->pluck('slug')->all());

        // Con la división ya corrida y vuelta a correr, lo crea justo después de «Necesidades de venta».
        $this->correr(self::MIGRACION_DIVIDIR);
        $ordenNecesidades = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'necesidades-venta')->value('order');
        $this->correr(self::MIGRACION_EMPAQUE);

        $empaque = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'programa-empaque')->first();
        $this->assertNotNull($empaque);
        $this->assertSame($ordenNecesidades + 1, (int) $empaque->order);
        $ordenes = DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->pluck('order')->all();
        $this->assertSame(count($ordenes), count(array_unique($ordenes)), 'Los órdenes no se repiten.');
    }

    public function test_down_de_programa_empaque_lo_quita_y_recorre_los_siguientes(): void
    {
        $moduloId = $this->sembrarEstructura();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $this->correr(self::MIGRACION_DIVIDIR);
        $ordenEmbarquesAntes = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order');

        $this->correr(self::MIGRACION_EMPAQUE);
        (require base_path('database/migrations/'.self::MIGRACION_EMPAQUE))->down();

        $this->assertSame(0, DB::table('submodules')->where('slug', 'programa-empaque')->count());
        $this->assertSame([], $this->concedidos($admin, 'programa-empaque'));
        $this->assertFalse($this->conAcceso($admin, 'programa-empaque'));
        $this->assertSame($ordenEmbarquesAntes, (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order'));
        $this->assertSame(0, DB::table('submodule_permission_types')->where('description', 'Ver el programa de empaque')->count());
    }

    public function test_payload_trae_permisos_de_programa_empaque(): void
    {
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['programa-empaque' => ['view']]));

        $respuesta = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23');

        $respuesta->assertOk();
        $this->assertTrue($respuesta->json('data.permisos.programa_empaque.view'));
        $this->assertFalse($respuesta->json('data.permisos.programa_empaque.capturar'));
    }

    /** Concede permisos de un submódulo de Planeación con su acceso (sin pasar por la migración). */
    private function conceder(User $usuario, string $submodulo, array $slugs, bool $granted = true, bool $accesoActivo = true): void
    {
        $submoduloId = DB::table('submodules')->where('slug', $submodulo)->value('id');
        DB::table('user_submodule_access')->updateOrInsert(
            ['user_id' => $usuario->id, 'submodule_id' => $submoduloId],
            ['is_active' => $accesoActivo, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
        foreach ($slugs as $slug) {
            $tipoId = DB::table('submodule_permission_types')->where('submodule_id', $submoduloId)->where('slug', $slug)->value('id');
            DB::table('user_submodule_permissions')->insert([
                'user_id' => $usuario->id, 'submodule_id' => $submoduloId, 'permission_type_id' => $tipoId,
                'is_granted' => $granted, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function conAccesoAlModulo(User $usuario, int $moduloId): bool
    {
        return DB::table('user_module_access')->where('user_id', $usuario->id)->where('module_id', $moduloId)->where('is_active', true)->exists();
    }

    /** Planeación con los siete submódulos de antes de «Avance de empaque». */
    private function sembrarHastaEmpaque(): int
    {
        $moduloId = $this->sembrarEstructura();
        $this->correr(self::MIGRACION_DIVIDIR);
        $this->correr(self::MIGRACION_EMPAQUE);

        return $moduloId;
    }

    public function test_crea_el_submodulo_avance_empaque_despues_de_programa_empaque(): void
    {
        $moduloId = $this->sembrarHastaEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);
        $ordenEmpaque = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'programa-empaque')->value('order');
        $ordenEmbarquesAntes = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order');

        $this->correr(self::MIGRACION_AVANCE);
        $this->correr(self::MIGRACION_AVANCE);

        $submodulos = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-empaque')->get();
        $this->assertCount(1, $submodulos);
        $avance = $submodulos[0];
        $this->assertSame('Avance de empaque', $avance->name);
        $this->assertSame('Empaque real contra el programa', $avance->description);
        $this->assertSame('ClipboardCheck', $avance->icon);
        $this->assertTrue((bool) $avance->is_active);
        $this->assertSame($ordenEmpaque + 1, (int) $avance->order);

        // Los siguientes se recorren una posición, una sola vez aunque se corra dos veces.
        $this->assertSame($ordenEmbarquesAntes + 1, (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order'));
        $this->assertSame(
            ['tablero-semanal', 'plan-corte', 'necesidades-venta', 'programa-empaque', 'avance-empaque', 'embarques', 'resumen-temporada', 'configuracion'],
            DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->orderBy('order')->pluck('slug')->all(),
        );

        $permisos = DB::table('submodule_permission_types')->where('submodule_id', $avance->id)->orderBy('order')->get();
        $this->assertSame(['view'], $permisos->pluck('slug')->all());
        $this->assertSame(['Ver'], $permisos->pluck('name')->all());
        $this->assertSame(['Ver el avance de empaque'], $permisos->pluck('description')->all());

        foreach ([$admin, $demo] as $usuario) {
            $this->assertSame(['view'], $this->concedidos($usuario, 'avance-empaque'));
            $this->assertTrue($this->conAcceso($usuario, 'avance-empaque'));
            $this->assertTrue($this->conAccesoAlModulo($usuario, $moduloId));
        }
    }

    public function test_avance_empaque_sin_planeacion_no_falla(): void
    {
        $this->correr(self::MIGRACION_AVANCE);

        $this->assertSame(0, DB::table('submodules')->where('slug', 'avance-empaque')->count());
    }

    public function test_avance_empaque_falla_claro_si_falta_programa_empaque(): void
    {
        $moduloId = $this->sembrarEstructura();
        User::factory()->create(['email' => 'admin@sentinel.com']);
        $this->correr(self::MIGRACION_DIVIDIR);

        // Con Planeación pero sin «Programa de empaque» NO puede quedar como corrida sin crear nada
        // (en producción `migrate --path` no la repetiría): falla con un mensaje claro y no escribe.
        try {
            $this->correr(self::MIGRACION_AVANCE);
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2026_11_20_110100_add_programa_empaque_submodulo', $e->getMessage());
        }
        $this->assertSame(0, DB::table('submodules')->where('slug', 'avance-empaque')->count());

        // Ya corrida la de Programa de empaque, se puede volver a correr y lo crea en su lugar.
        $this->correr(self::MIGRACION_EMPAQUE);
        $this->correr(self::MIGRACION_AVANCE);

        $ordenEmpaque = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'programa-empaque')->value('order');
        $this->assertSame($ordenEmpaque + 1, (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-empaque')->value('order'));
        $ordenes = DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->pluck('order')->all();
        $this->assertSame(count($ordenes), count(array_unique($ordenes)), 'Los órdenes no se repiten.');
    }

    public function test_avance_empaque_se_concede_a_quien_ya_tenia_tablero_view(): void
    {
        $moduloId = $this->sembrarHastaEmpaque();
        $conTablero = User::factory()->create();
        $sinTablero = User::factory()->create();
        $tableroRevocado = User::factory()->create();
        $tableroSinAcceso = User::factory()->create();
        $soloNotas = User::factory()->create();
        $this->conceder($conTablero, 'tablero-semanal', ['view']);
        $this->conceder($sinTablero, 'plan-corte', ['view', 'capturar']);
        $this->conceder($tableroRevocado, 'tablero-semanal', ['view'], granted: false);
        $this->conceder($tableroSinAcceso, 'tablero-semanal', ['view'], accesoActivo: false);
        $this->conceder($soloNotas, 'tablero-semanal', ['editar-notas']);

        $this->correr(self::MIGRACION_AVANCE);
        $this->correr(self::MIGRACION_AVANCE);

        $this->assertSame(['view'], $this->concedidos($conTablero, 'avance-empaque'));
        $this->assertTrue($this->conAcceso($conTablero, 'avance-empaque'));
        $this->assertTrue($this->conAccesoAlModulo($conTablero, $moduloId));
        // Sus permisos de antes no cambian.
        $this->assertSame(['view'], $this->concedidos($conTablero, 'tablero-semanal'));

        foreach ([$sinTablero, $tableroRevocado, $tableroSinAcceso, $soloNotas] as $usuario) {
            $this->assertSame([], $this->concedidos($usuario, 'avance-empaque'));
            $this->assertFalse($this->conAcceso($usuario, 'avance-empaque'));
        }
        $this->assertFalse($this->conAccesoAlModulo($sinTablero, $moduloId));
    }

    public function test_avance_empaque_no_reactiva_lo_revocado_a_mano(): void
    {
        $this->sembrarHastaEmpaque();
        $usuario = User::factory()->create();
        $this->conceder($usuario, 'tablero-semanal', ['view']);
        $this->correr(self::MIGRACION_AVANCE);

        // El panel revoca a mano el acceso y el permiso de Avance de empaque.
        $avanceId = DB::table('submodules')->where('slug', 'avance-empaque')->value('id');
        DB::table('user_submodule_access')->where('user_id', $usuario->id)->where('submodule_id', $avanceId)->update(['is_active' => false]);
        DB::table('user_submodule_permissions')->where('user_id', $usuario->id)->where('submodule_id', $avanceId)->update(['is_granted' => false]);

        $this->correr(self::MIGRACION_AVANCE);

        $this->assertFalse($this->conAcceso($usuario, 'avance-empaque'));
        $this->assertSame([], $this->concedidos($usuario, 'avance-empaque'));
    }

    public function test_down_de_avance_empaque_lo_quita_y_recorre_los_siguientes(): void
    {
        $moduloId = $this->sembrarHastaEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $ordenEmbarquesAntes = (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order');

        $this->correr(self::MIGRACION_AVANCE);
        (require base_path('database/migrations/'.self::MIGRACION_AVANCE))->down();

        $this->assertSame(0, DB::table('submodules')->where('slug', 'avance-empaque')->count());
        $this->assertSame([], $this->concedidos($admin, 'avance-empaque'));
        $this->assertFalse($this->conAcceso($admin, 'avance-empaque'));
        $this->assertSame($ordenEmbarquesAntes, (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order'));
        $this->assertSame(0, DB::table('submodule_permission_types')->where('description', 'Ver el avance de empaque')->count());
    }

    public function test_enviar_crea_el_permiso_en_tablero_semanal_sin_duplicar(): void
    {
        $this->sembrarHastaEmpaque();
        $tableroId = DB::table('submodules')->where('slug', 'tablero-semanal')->value('id');
        $this->assertSame(0, DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->where('slug', 'enviar')->count());
        $ordenes = DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->pluck('order')->all();

        $this->correr(self::MIGRACION_ENVIAR);
        $this->correr(self::MIGRACION_ENVIAR);

        $tipo = DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->where('slug', 'enviar')->get();
        $this->assertCount(1, $tipo);
        $this->assertSame('Enviar por correo', $tipo[0]->name);
        $this->assertSame(max($ordenes) + 1, (int) $tipo[0]->order);
        $this->assertSame(0, DB::table('submodules')->where('slug', 'enviar')->count(), 'No crea un submódulo.');
    }

    public function test_enviar_se_concede_a_admin_demo_y_a_quien_edita_la_configuracion(): void
    {
        $moduloId = $this->sembrarHastaEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);
        $editor = User::factory()->create();
        $soloVer = User::factory()->create();
        $editorRevocado = User::factory()->create();
        $editorSinAcceso = User::factory()->create();
        $soloTablero = User::factory()->create();
        $this->conceder($editor, 'configuracion', ['view', 'editar']);
        $this->conceder($soloVer, 'configuracion', ['view']);
        $this->conceder($editorRevocado, 'configuracion', ['editar'], granted: false);
        $this->conceder($editorSinAcceso, 'configuracion', ['editar'], accesoActivo: false);
        $this->conceder($soloTablero, 'tablero-semanal', ['view', 'editar-notas']);

        $this->correr(self::MIGRACION_ENVIAR);
        $this->correr(self::MIGRACION_ENVIAR);

        foreach ([$admin, $demo] as $usuario) {
            $this->assertSame(['enviar'], $this->concedidos($usuario, 'tablero-semanal'));
            $this->assertTrue($this->conAcceso($usuario, 'tablero-semanal'));
            $this->assertTrue($this->conAccesoAlModulo($usuario, $moduloId));
        }
        $this->assertSame(['enviar'], $this->concedidos($editor, 'tablero-semanal'));
        // Lo de antes no cambia: no se le abre el Tablero ni se tocan sus otros permisos.
        $this->assertSame(['view', 'editar'], $this->concedidos($editor, 'configuracion'));
        $this->assertFalse($this->conAcceso($editor, 'tablero-semanal'));

        foreach ([$soloVer, $editorRevocado, $editorSinAcceso] as $usuario) {
            $this->assertNotContains('enviar', $this->concedidos($usuario, 'tablero-semanal'));
        }
        $this->assertSame(['view', 'editar-notas'], $this->concedidos($soloTablero, 'tablero-semanal'));
        // Una sola fila de `enviar` por persona (idempotente).
        $this->assertSame(1, DB::table('user_submodule_permissions')
            ->join('submodule_permission_types', 'submodule_permission_types.id', '=', 'user_submodule_permissions.permission_type_id')
            ->where('user_submodule_permissions.user_id', $editor->id)->where('submodule_permission_types.slug', 'enviar')->count());
    }

    public function test_enviar_no_reactiva_lo_revocado_y_reafirma_admin(): void
    {
        $this->sembrarHastaEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $editor = User::factory()->create();
        $this->conceder($editor, 'configuracion', ['editar']);
        $this->correr(self::MIGRACION_ENVIAR);

        // El panel revoca `enviar` a mano a los dos.
        $tableroId = DB::table('submodules')->where('slug', 'tablero-semanal')->value('id');
        DB::table('user_submodule_permissions')->whereIn('user_id', [$admin->id, $editor->id])
            ->where('submodule_id', $tableroId)->update(['is_granted' => false]);

        $this->correr(self::MIGRACION_ENVIAR);

        $this->assertSame([], $this->concedidos($editor, 'tablero-semanal'), 'Una fila revocada no se reactiva.');
        $this->assertSame(['enviar'], $this->concedidos($admin, 'tablero-semanal'), 'admin@ se reafirma.');
    }

    public function test_enviar_sin_el_modulo_planeacion_no_hace_nada(): void
    {
        $this->correr(self::MIGRACION_ENVIAR);

        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'enviar')->count());
    }

    public function test_enviar_falla_claro_si_planeacion_existe_pero_falta_el_tablero_semanal(): void
    {
        // Antes era un `return` silencioso: `migrate --path` la anotaba como corrida sin crear nada
        // y el permiso nunca existía. Ahora lanza (como Avance de empaque) y no escribe.
        $moduloId = $this->sembrarEstructura();
        DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'tablero-semanal')->delete();

        try {
            $this->correr(self::MIGRACION_ENVIAR);
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2026_11_11_100100', $e->getMessage());
            $this->assertStringContainsString('Enviar por correo', $e->getMessage());
        }
        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'enviar')->count());

        // Ya corrida la que crea el Tablero semanal, se puede volver a correr y crea el permiso.
        $this->correr(self::MIGRACION_DIVIDIR);
        $this->correr(self::MIGRACION_ENVIAR);
        $this->assertSame(1, DB::table('submodule_permission_types')->where('slug', 'enviar')->count());
    }

    public function test_down_de_enviar_quita_el_permiso_y_sus_concesiones(): void
    {
        $this->sembrarHastaEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $this->conceder($admin, 'tablero-semanal', ['view']);
        $this->correr(self::MIGRACION_ENVIAR);

        (require base_path('database/migrations/'.self::MIGRACION_ENVIAR))->down();

        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'enviar')->count());
        $this->assertSame(['view'], $this->concedidos($admin, 'tablero-semanal'));
    }

    public function test_payload_trae_permisos_de_avance_empaque(): void
    {
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon(['avance-empaque' => ['view'], 'tablero-semanal' => ['view']]));

        $respuesta = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23');

        $respuesta->assertOk();
        $this->assertSame(['view' => true], $respuesta->json('data.permisos.avance_empaque'));

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $sin = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23');
        $this->assertSame(['view' => false], $sin->json('data.permisos.avance_empaque'));
    }

    /** Planeación con los ocho submódulos de antes de «Avance de cosecha» y «Avance de embarques». */
    private function sembrarHastaAvanceEmpaque(): int
    {
        $moduloId = $this->sembrarHastaEmpaque();
        $this->correr(self::MIGRACION_AVANCE);

        return $moduloId;
    }

    private function slugsActivos(int $moduloId): array
    {
        return DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->orderBy('order')->pluck('slug')->all();
    }

    public function test_crea_avance_cosecha_y_avance_embarques_en_su_lugar(): void
    {
        $moduloId = $this->sembrarHastaAvanceEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);

        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);
        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);

        $this->assertSame(
            ['tablero-semanal', 'plan-corte', 'avance-cosecha', 'necesidades-venta', 'programa-empaque', 'avance-empaque', 'embarques', 'avance-embarques', 'resumen-temporada', 'configuracion'],
            $this->slugsActivos($moduloId),
        );
        $ordenes = DB::table('submodules')->where('module_id', $moduloId)->where('is_active', true)->pluck('order')->all();
        $this->assertSame(count($ordenes), count(array_unique($ordenes)), 'Los órdenes no se repiten.');

        $cosecha = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-cosecha')->first();
        $this->assertSame('Avance de cosecha', $cosecha->name);
        $this->assertSame('Cosecha real contra el plan de corte', $cosecha->description);
        $this->assertSame('Wheat', $cosecha->icon);
        $this->assertTrue((bool) $cosecha->is_active);

        $embarques = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-embarques')->first();
        $this->assertSame('Avance de embarques', $embarques->name);
        $this->assertSame('Embarques reales contra las cargas programadas', $embarques->description);
        $this->assertSame('Route', $embarques->icon);
        $this->assertTrue((bool) $embarques->is_active);

        foreach (['avance-cosecha' => 'Ver', 'avance-embarques' => 'Ver'] as $slug => $nombre) {
            $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $slug)->value('id');
            $tipos = DB::table('submodule_permission_types')->where('submodule_id', $submoduloId)->orderBy('order')->get();
            $this->assertSame(['view'], $tipos->pluck('slug')->all(), $slug);
            $this->assertSame([$nombre], $tipos->pluck('name')->all(), $slug);

            foreach ([$admin, $demo] as $usuario) {
                $this->assertSame(['view'], $this->concedidos($usuario, $slug), $slug);
                $this->assertTrue($this->conAcceso($usuario, $slug), $slug);
                $this->assertTrue($this->conAccesoAlModulo($usuario, $moduloId));
            }
        }
    }

    public function test_avance_cosecha_y_embarques_se_conceden_a_quien_ya_tenia_tablero_view(): void
    {
        $moduloId = $this->sembrarHastaAvanceEmpaque();
        $conTablero = User::factory()->create();
        $sinTablero = User::factory()->create();
        $tableroRevocado = User::factory()->create();
        $tableroSinAcceso = User::factory()->create();
        $soloNotas = User::factory()->create();
        $this->conceder($conTablero, 'tablero-semanal', ['view']);
        $this->conceder($sinTablero, 'plan-corte', ['view', 'capturar']);
        $this->conceder($tableroRevocado, 'tablero-semanal', ['view'], granted: false);
        $this->conceder($tableroSinAcceso, 'tablero-semanal', ['view'], accesoActivo: false);
        $this->conceder($soloNotas, 'tablero-semanal', ['editar-notas']);

        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);
        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);

        foreach (['avance-cosecha', 'avance-embarques'] as $slug) {
            $this->assertSame(['view'], $this->concedidos($conTablero, $slug), $slug);
            $this->assertTrue($this->conAcceso($conTablero, $slug), $slug);

            foreach ([$sinTablero, $tableroRevocado, $tableroSinAcceso, $soloNotas] as $usuario) {
                $this->assertSame([], $this->concedidos($usuario, $slug), $slug);
                $this->assertFalse($this->conAcceso($usuario, $slug), $slug);
            }
        }
        $this->assertTrue($this->conAccesoAlModulo($conTablero, $moduloId));
        // Sus permisos de antes no cambian.
        $this->assertSame(['view'], $this->concedidos($conTablero, 'tablero-semanal'));
        $this->assertFalse($this->conAccesoAlModulo($sinTablero, $moduloId));
    }

    public function test_avance_cosecha_y_embarques_no_reactivan_lo_revocado(): void
    {
        $this->sembrarHastaAvanceEmpaque();
        $usuario = User::factory()->create();
        $this->conceder($usuario, 'tablero-semanal', ['view']);
        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);

        foreach (['avance-cosecha', 'avance-embarques'] as $slug) {
            $submoduloId = DB::table('submodules')->where('slug', $slug)->value('id');
            DB::table('user_submodule_access')->where('user_id', $usuario->id)->where('submodule_id', $submoduloId)->update(['is_active' => false]);
            DB::table('user_submodule_permissions')->where('user_id', $usuario->id)->where('submodule_id', $submoduloId)->update(['is_granted' => false]);
        }

        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);

        foreach (['avance-cosecha', 'avance-embarques'] as $slug) {
            $this->assertFalse($this->conAcceso($usuario, $slug), $slug);
            $this->assertSame([], $this->concedidos($usuario, $slug), $slug);
        }
    }

    public function test_avance_cosecha_y_embarques_sin_planeacion_no_hacen_nada(): void
    {
        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);

        $this->assertSame(0, DB::table('submodules')->whereIn('slug', ['avance-cosecha', 'avance-embarques'])->count());
    }

    public function test_avance_cosecha_y_embarques_fallan_claro_sin_plan_corte(): void
    {
        $moduloId = $this->sembrarHastaAvanceEmpaque();
        User::factory()->create(['email' => 'admin@sentinel.com']);
        $orden = DB::table('submodules')->where('module_id', $moduloId)->pluck('order', 'slug')->all();
        DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'plan-corte')->delete();

        // Sin «Plan de corte» no puede quedar anotada como corrida sin crear nada: lanza y no escribe.
        try {
            $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('plan-corte', $e->getMessage());
        }
        $this->assertSame(0, DB::table('submodules')->whereIn('slug', ['avance-cosecha', 'avance-embarques'])->count());
        $this->assertSame($orden['embarques'], (int) DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->value('order'));

        // Tampoco crea «Avance de cosecha» a medias si falta «Embarques».
        DB::table('submodules')->insert([
            'module_id' => $moduloId, 'slug' => 'plan-corte', 'name' => 'Plan de corte', 'order' => $orden['plan-corte'],
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'embarques')->delete();
        try {
            $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('embarques', $e->getMessage());
        }
        $this->assertSame(0, DB::table('submodules')->whereIn('slug', ['avance-cosecha', 'avance-embarques'])->count());
    }

    public function test_down_de_avance_cosecha_y_embarques_los_quita_y_recorre(): void
    {
        $moduloId = $this->sembrarHastaAvanceEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $antes = DB::table('submodules')->where('module_id', $moduloId)->pluck('order', 'slug')->all();

        $this->correr(self::MIGRACION_AVANCES_COSECHA_EMBARQUES);
        (require base_path('database/migrations/'.self::MIGRACION_AVANCES_COSECHA_EMBARQUES))->down();

        $this->assertSame(0, DB::table('submodules')->whereIn('slug', ['avance-cosecha', 'avance-embarques'])->count());
        foreach (['avance-cosecha', 'avance-embarques'] as $slug) {
            $this->assertSame([], $this->concedidos($admin, $slug));
            $this->assertFalse($this->conAcceso($admin, $slug));
        }
        $this->assertSame($antes, DB::table('submodules')->where('module_id', $moduloId)->pluck('order', 'slug')->all());
        $this->assertSame(0, DB::table('submodule_permission_types')->whereIn('description', ['Ver el avance de cosecha', 'Ver el avance de embarques'])->count());
    }

    public function test_acordar_solo_a_admin_y_demo_sin_duplicar(): void
    {
        $moduloId = $this->sembrarHastaAvanceEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $demo = User::factory()->create(['email' => 'demo@sentinel.com']);
        $editor = User::factory()->create();
        $conTablero = User::factory()->create();
        $this->conceder($editor, 'configuracion', ['view', 'editar']);
        $this->conceder($conTablero, 'tablero-semanal', ['view', 'editar-notas']);
        $tableroId = DB::table('submodules')->where('slug', 'tablero-semanal')->value('id');
        $ordenes = DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->pluck('order')->all();

        $this->correr(self::MIGRACION_ACORDAR);
        $this->correr(self::MIGRACION_ACORDAR);

        $tipo = DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->where('slug', 'acordar')->get();
        $this->assertCount(1, $tipo);
        $this->assertSame('Acordar plan', $tipo[0]->name);
        $this->assertSame('Guardar la copia del plan acordado en la junta', $tipo[0]->description);
        $this->assertSame(max($ordenes) + 1, (int) $tipo[0]->order);
        $this->assertSame(0, DB::table('submodules')->where('slug', 'acordar')->count(), 'No crea un submódulo.');

        foreach ([$admin, $demo] as $usuario) {
            $this->assertSame(['acordar'], $this->concedidos($usuario, 'tablero-semanal'));
            $this->assertTrue($this->conAcceso($usuario, 'tablero-semanal'));
            $this->assertTrue($this->conAccesoAlModulo($usuario, $moduloId));
            $this->assertSame(1, DB::table('user_submodule_permissions')->where('user_id', $usuario->id)->where('permission_type_id', $tipo[0]->id)->count());
        }
        // Nadie más lo recibe: ni quien edita la Configuración ni quien ya veía el Tablero.
        $this->assertNotContains('acordar', $this->concedidos($editor, 'tablero-semanal'));
        $this->assertSame(['view', 'editar-notas'], $this->concedidos($conTablero, 'tablero-semanal'));
        $this->assertSame(0, DB::table('user_submodule_permissions')->where('permission_type_id', $tipo[0]->id)->whereNotIn('user_id', [$admin->id, $demo->id])->count());
    }

    public function test_acordar_sin_planeacion_no_hace_nada_y_falla_claro_sin_tablero(): void
    {
        $this->correr(self::MIGRACION_ACORDAR);
        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'acordar')->count());

        $moduloId = $this->sembrarEstructura();
        DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'tablero-semanal')->delete();
        try {
            $this->correr(self::MIGRACION_ACORDAR);
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2026_11_11_100100', $e->getMessage());
        }
        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'acordar')->count());
    }

    public function test_down_de_acordar_quita_permiso_y_concesiones(): void
    {
        $this->sembrarHastaAvanceEmpaque();
        $admin = User::factory()->create(['email' => 'admin@sentinel.com']);
        $this->conceder($admin, 'tablero-semanal', ['view']);
        $this->correr(self::MIGRACION_ACORDAR);
        $this->assertContains('acordar', $this->concedidos($admin, 'tablero-semanal'));

        (require base_path('database/migrations/'.self::MIGRACION_ACORDAR))->down();

        $this->assertSame(0, DB::table('submodule_permission_types')->where('slug', 'acordar')->count());
        $this->assertSame(['view'], $this->concedidos($admin, 'tablero-semanal'));
    }

    public function test_payload_trae_permisos_de_avances_y_acordar(): void
    {
        $this->crearElote();
        Sanctum::actingAs($this->usuarioCon([
            'tablero-semanal' => ['view', 'acordar'],
            'avance-cosecha' => ['view'],
            'avance-embarques' => ['view'],
        ]));

        $respuesta = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23');

        $respuesta->assertOk();
        $this->assertTrue($respuesta->json('data.permisos.avance_cosecha.view'));
        $this->assertTrue($respuesta->json('data.permisos.avance_embarques.view'));
        $this->assertTrue($respuesta->json('data.permisos.tablero.acordar'));

        Sanctum::actingAs($this->usuarioCon(['tablero-semanal' => ['view']]));
        $sin = $this->getJson('/api/splendidfarms/administration/planeacion/programa?lunes=2026-11-23');
        $this->assertFalse($sin->json('data.permisos.avance_cosecha.view'));
        $this->assertFalse($sin->json('data.permisos.avance_embarques.view'));
        $this->assertFalse($sin->json('data.permisos.tablero.acordar'));
    }

    public function test_los_ayudantes_de_datos_reales_arman_filas_validas(): void
    {
        $this->crearElote();
        $this->usuarioCon([]);
        $etapa = $this->crearEtapa('Amarillo', 10);
        $programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);

        $cierre = $this->cierre($etapa, '2026-11-24', 3.5);
        $this->assertSame($this->temporada->id, $cierre->temporada_id);
        $this->assertSame('cerrado', $cierre->status);
        $this->assertSame($etapa->lote_id, $cierre->lote_id);
        $this->assertSame($this->temporada->id, $this->cierre(null, '2026-11-24', 1)->temporada_id);

        $otra = $this->otroCultivo();
        $this->assertSame($otra->id, $this->cierre(null, '2026-11-24', 1, ['temporada_id' => $otra->id])->temporada_id);
        $this->assertSame('abierta', $otra->estado);
        $this->assertSame('Tomate', $otra->cultivo->nombre);

        $salida = $this->salidaCampo($etapa, '2026-11-24', 12000.5);
        $this->assertSame('12000.50', $salida->fresh()->peso_neto_kg);
        $this->assertFalse($salida->fresh()->eliminado);
        $this->assertNull($this->salidaCampo(null, '2026-11-24', 1)->etapa_id);

        $carga = $this->cargaPlan($programa, 'Texas', 'full', 2, 3);
        $this->assertSame(3, $carga->fresh()->cargas);

        $embarque = $this->embarqueReal('2026-11-25', 'entregado', 'Texas');
        $this->assertSame(1000, $embarque->fresh()->total_cajas);
        $this->assertNotSame($embarque->folio_embarque, $this->embarqueReal('2026-11-25', 'programado', null)->folio_embarque);

        $consignatario = $this->consignatario('Walmart Texas', 'Texas');
        $this->assertSame('Texas', $consignatario->fresh()->bodega);
        $this->assertNull($this->consignatario('Otro', null)->bodega);
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
