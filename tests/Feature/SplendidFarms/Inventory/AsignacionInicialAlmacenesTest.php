<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserModuleAccess;
use App\Models\UserEntityAccess;
use App\Support\Inventario\AsignacionInicialAlmacenes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class AsignacionInicialAlmacenesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    public function test_registra_permiso_en_reportes_stock_una_sola_vez(): void
    {
        $this->setUpAlmacenFixtures();
        $app = Application::create(['enterprise_id' => $this->empresa->id, 'slug' => 'inventario', 'name' => 'Inventario', 'path' => '/x', 'description' => 'Inventario']);
        $mod = Module::create(['application_id' => $app->id, 'slug' => 'reportes', 'name' => 'Reportes']);
        $sub = Submodule::create(['module_id' => $mod->id, 'slug' => 'stock', 'name' => 'Stock']);

        $servicio = new AsignacionInicialAlmacenes();
        $this->assertSame(1, $servicio->registrarPermiso());
        $this->assertSame(0, $servicio->registrarPermiso());
        $this->assertTrue(SubmodulePermissionType::where('submodule_id', $sub->id)->where('slug', 'ver_todos_almacenes')->exists());
    }

    public function test_asigna_todos_los_almacenes_a_usuarios_con_acceso_a_inventario_u_operacion(): void
    {
        $this->setUpAlmacenFixtures();
        $app = Application::create(['enterprise_id' => $this->empresa->id, 'slug' => 'operacion-agricola', 'name' => 'Operación', 'path' => '/x', 'description' => 'Operación agrícola']);
        $mod = Module::create(['application_id' => $app->id, 'slug' => 'agricola', 'name' => 'Agrícola']);
        $conAcceso = User::factory()->create();
        $sinAcceso = User::factory()->create();
        UserModuleAccess::create(['user_id' => $conAcceso->id, 'module_id' => $mod->id, 'is_active' => true]);

        $servicio = new AsignacionInicialAlmacenes();
        $this->assertSame(2, $servicio->asignar());
        $this->assertSame(0, $servicio->asignar());

        $this->assertEqualsCanonicalizing(
            [$this->almacenA->id, $this->almacenB->id],
            UserEntityAccess::where('user_id', $conAcceso->id)->pluck('entity_id')->all(),
        );
        $this->assertSame(0, UserEntityAccess::where('user_id', $sinAcceso->id)->count());
    }

    public function test_asigna_entidades_vinculadas_pero_no_soft_deleted(): void
    {
        $this->setUpAlmacenFixtures();
        $app = Application::create(['enterprise_id' => $this->empresa->id, 'slug' => 'inventario', 'name' => 'Inventario', 'path' => '/x', 'description' => 'Inventario']);
        $mod = Module::create(['application_id' => $app->id, 'slug' => 'reportes', 'name' => 'Reportes']);
        Submodule::create(['module_id' => $mod->id, 'slug' => 'stock', 'name' => 'Stock']);

        // Crear segunda empresa con almacenes
        $otra = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Empresa de caña']);
        $sucursalOtra = Branch::create([
            'enterprise_id' => $otra->id,
            'code' => 'SUC-02',
            'name' => 'Central Caña',
            'slug' => 'central-cana',
        ]);
        $almacenCanaActivo = Entity::create([
            'branch_id' => $sucursalOtra->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-CANA-ACTIVO',
            'name' => 'Almacén Caña Activo',
        ]);
        $almacenCanaDeleted = Entity::create([
            'branch_id' => $sucursalOtra->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-CANA-DELETED',
            'name' => 'Almacén Caña Deletado',
        ]);

        // Vincular a Splendid Farms
        DB::table('enterprise_entity')->insert([
            ['enterprise_id' => $this->empresa->id, 'entity_id' => $almacenCanaActivo->id, 'access_level' => 'read', 'created_at' => now(), 'updated_at' => now()],
            ['enterprise_id' => $this->empresa->id, 'entity_id' => $almacenCanaDeleted->id, 'access_level' => 'read', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Soft-delete el segundo almacén
        $almacenCanaDeleted->delete();

        // Usuario con acceso a inventario
        $user = User::factory()->create();
        UserModuleAccess::create(['user_id' => $user->id, 'module_id' => $mod->id, 'is_active' => true]);

        // Asignar
        $servicio = new AsignacionInicialAlmacenes();
        $asignadas = $servicio->asignar();

        // Debe asignar los 2 almacenes propios (A, B) + el almacén vinculado activo (Cana Activo)
        // Total: 3
        $this->assertSame(3, $asignadas);

        $accesos = UserEntityAccess::where('user_id', $user->id)->pluck('entity_id')->all();
        sort($accesos);
        $esperados = [$this->almacenA->id, $this->almacenB->id, $almacenCanaActivo->id];
        sort($esperados);
        $this->assertEqualsCanonicalizing($esperados, $accesos);

        // Verificar que el deletado NO está asignado
        $this->assertFalse(UserEntityAccess::where('user_id', $user->id)->where('entity_id', $almacenCanaDeleted->id)->exists());
    }
}
