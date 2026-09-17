<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserModuleAccess;
use App\Models\UserEntityAccess;
use App\Support\Inventario\AsignacionInicialAlmacenes;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
