<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Application;
use App\Models\ApprovalProcess;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use Database\Seeders\AbastecimientoModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class ModuloYPermisosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private function url(string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/mis-permisos";
    }

    private function conteos(Enterprise $e): array
    {
        $modulo = Module::where('slug', 'abastecimiento')
            ->whereHas('application', fn ($a) => $a->where('enterprise_id', $e->id))->first();
        $subIds = Submodule::where('module_id', $modulo?->id)->pluck('id');

        return [
            'submodulos' => $subIds->count(),
            'tipos_permiso' => SubmodulePermissionType::whereIn('submodule_id', $subIds)->count(),
            'cultivos' => Cultivo::where('enterprise_id', $e->id)->count(),
            'variedades' => Variedad::whereHas('cultivo', fn ($c) => $c->where('enterprise_id', $e->id))->count(),
            'tipos_carga' => TipoCarga::where('enterprise_id', $e->id)->count(),
        ];
    }

    public function test_seeder_crea_el_modulo_solo_en_sbp(): void
    {
        $sbp = Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);
        $sf = Enterprise::create(['name' => 'SF', 'slug' => 'splendidfarms', 'description' => 'SF', 'is_active' => true]);

        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame(8, $this->conteos($sbp)['submodulos']);
        $this->assertSame(0, $this->conteos($sf)['submodulos']);
        $this->assertSame(
            ['tablero', 'convenios', 'recepciones', 'productores', 'temporadas', 'variedades', 'tipos-carga', 'liquidaciones'],
            Submodule::whereHas('module', fn ($m) => $m->where('slug', 'abastecimiento'))->orderBy('order')->pluck('slug')->all(),
        );
        // El tablero solo tiene "ver".
        $tablero = Submodule::where('slug', 'tablero')->first();
        $this->assertSame(['view'], $tablero->permissionTypes()->pluck('slug')->all());
    }

    public function test_seeder_deja_los_permisos_de_la_cuenta_y_quita_los_viejos(): void
    {
        Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);
        $this->seed(AbastecimientoModuleSeeder::class);

        $liq = Submodule::where('slug', 'liquidaciones')->first();
        $this->assertSame('Receipt', $liq->icon);
        $this->assertEqualsCanonicalizing(
            ['view', 'create', 'cancelar', 'liquidar', 'delete', 'carga-inicial'],
            $liq->permissionTypes()->pluck('slug')->all(),
        );
        $this->assertSame('Cancelar pagos', $liq->permissionTypes()->where('slug', 'cancelar')->value('description'));
        $this->assertSame('Liquidar el convenio completo', $liq->permissionTypes()->where('slug', 'liquidar')->value('description'));
        $this->assertNull(Submodule::where('slug', 'abonos')->first(), 'Una siembra limpia ya no crea el submódulo abonos');

        // Se simula lo que dejó la siembra anterior: tipos `aprobar` y `edit` en liquidaciones, el submódulo `abonos`
        // con sus tipos y un usuario con `liquidaciones.aprobar`, `liquidaciones.edit` y `liquidaciones.view`.
        $modulo = Module::where('slug', 'abastecimiento')->first();
        $viejos = [];
        foreach (['aprobar', 'edit'] as $slug) {
            $viejos[$slug] = SubmodulePermissionType::create(['submodule_id' => $liq->id, 'slug' => $slug, 'name' => ucfirst($slug), 'order' => 5]);
        }
        $abo = Submodule::create(['module_id' => $modulo->id, 'slug' => 'abonos', 'name' => 'Abonos', 'icon' => 'Wallet', 'order' => 9, 'is_active' => true]);
        foreach (['view', 'create', 'edit', 'delete', 'cancelar'] as $slug) {
            SubmodulePermissionType::create(['submodule_id' => $abo->id, 'slug' => $slug, 'name' => ucfirst($slug), 'order' => 1]);
        }
        $usuario = User::factory()->create(['role' => 'user']);
        $tipoVer = SubmodulePermissionType::where('submodule_id', $liq->id)->where('slug', 'view')->first();
        foreach ([$viejos['aprobar'], $viejos['edit'], $tipoVer] as $tipo) {
            UserSubmodulePermission::create(['user_id' => $usuario->id, 'submodule_id' => $liq->id, 'permission_type_id' => $tipo->id, 'is_granted' => true]);
        }

        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['view', 'create', 'cancelar', 'liquidar', 'delete', 'carga-inicial'],
            $liq->permissionTypes()->pluck('slug')->all(),
        );
        $this->assertSame(0, SubmodulePermissionType::where('submodule_id', $liq->id)->whereIn('slug', ['aprobar', 'edit'])->count());
        $abo->refresh();
        $this->assertFalse((bool) $abo->is_active, 'El submódulo abonos queda inactivo');
        $this->assertSame(0, SubmodulePermissionType::where('submodule_id', $abo->id)->count());
        $this->assertSame(0, UserSubmodulePermission::where('user_id', $usuario->id)->where('permission_type_id', $viejos['aprobar']->id)->count());
        $this->assertSame(0, UserSubmodulePermission::where('user_id', $usuario->id)->where('permission_type_id', $viejos['edit']->id)->count());
        // Lo que no se retira se conserva.
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $usuario->id)->where('permission_type_id', $tipoVer->id)->count());
    }

    public function test_seeder_desactiva_el_proceso_supply_settlements(): void
    {
        Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);

        // Sin proceso previo, el seeder no lo crea.
        $this->seed(AbastecimientoModuleSeeder::class);
        $this->assertSame(0, ApprovalProcess::where('code', ApprovalProcess::SUPPLY_SETTLEMENTS)->count());

        ApprovalProcess::create([
            'code' => ApprovalProcess::SUPPLY_SETTLEMENTS,
            'name' => 'Liquidaciones de abastecimiento',
            'description' => 'Aprobación de liquidaciones de abastecimiento antes de pagarse',
            'module' => 'splendidbyporvenir/abastecimiento',
            'entity_type' => 'App\Models\Abastecimiento\Liquidacion',
            'requires_approval' => true,
            'is_active' => true,
        ]);

        $this->seed(AbastecimientoModuleSeeder::class);
        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame(1, ApprovalProcess::where('code', ApprovalProcess::SUPPLY_SETTLEMENTS)->count());
        $this->assertFalse((bool) ApprovalProcess::where('code', ApprovalProcess::SUPPLY_SETTLEMENTS)->first()->is_active);
    }

    public function test_mis_permisos_trae_liquidar_y_cancelar(): void
    {
        $this->setUpAbastecimiento();
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view', 'liquidar', 'cancelar']);
        Sanctum::actingAs($this->captor);

        $res = $this->getJson($this->url())->assertOk();

        $this->assertTrue($res->json('data.liquidaciones.view'));
        $this->assertTrue($res->json('data.liquidaciones.liquidar'));
        $this->assertTrue($res->json('data.liquidaciones.cancelar'));
        $this->assertFalse($res->json('data.liquidaciones.create'));
        $this->assertFalse($res->json('data.liquidaciones.delete'));
        $this->assertSame(['view', 'create', 'cancelar', 'liquidar', 'delete', 'carga-inicial'], array_keys($res->json('data.liquidaciones')));
        $this->assertArrayNotHasKey('abonos', $res->json('data'));
    }

    public function test_seeder_crea_los_permisos_de_importar_y_carga_inicial(): void
    {
        $sbp = Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);

        $this->seed(AbastecimientoModuleSeeder::class);

        $importar = SubmodulePermissionType::where('slug', 'importar')
            ->whereHas('submodule', fn ($s) => $s->where('slug', 'recepciones'))->get();
        $this->assertCount(1, $importar);
        $this->assertSame('Importar', $importar[0]->name);
        $this->assertSame('Importar recepciones desde Excel', $importar[0]->description);

        $carga = SubmodulePermissionType::where('slug', 'carga-inicial')
            ->whereHas('submodule', fn ($s) => $s->where('slug', 'liquidaciones'))->get();
        $this->assertCount(1, $carga);
        $this->assertSame('Carga inicial', $carga[0]->name);
        $this->assertSame('Registrar el saldo inicial de un convenio', $carga[0]->description);

        $this->assertSame(
            ['view', 'create', 'edit', 'delete', 'importar'],
            Submodule::where('slug', 'recepciones')->first()->permissionTypes()->orderBy('order')->orderBy('id')->pluck('slug')->all(),
        );

        // Una segunda corrida no duplica ni reactiva lo desactivado.
        $importar[0]->update(['is_active' => false]);
        $antes = $this->conteos($sbp)['tipos_permiso'];
        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame($antes, $this->conteos($sbp)['tipos_permiso']);
        $this->assertSame(1, SubmodulePermissionType::where('slug', 'importar')->count());
        $this->assertSame(1, SubmodulePermissionType::where('slug', 'carga-inicial')->count());
        $this->assertFalse((bool) SubmodulePermissionType::where('slug', 'importar')->value('is_active'), 'No debe reactivar lo desactivado');
    }

    public function test_mis_permisos_trae_importar_y_carga_inicial(): void
    {
        $this->setUpAbastecimiento();
        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['view', 'importar']);
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['carga-inicial']);
        $otro = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($otro, $this->sbp, 'recepciones', ['view']);

        Sanctum::actingAs($this->captor);
        $res = $this->getJson($this->url())->assertOk();
        $this->assertTrue($res->json('data.recepciones.importar'));
        $this->assertTrue($res->json('data.liquidaciones.carga-inicial'));
        $this->assertSame(['view', 'create', 'edit', 'delete', 'importar'], array_keys($res->json('data.recepciones')));

        Sanctum::actingAs($otro);
        $res = $this->getJson($this->url())->assertOk();
        $this->assertFalse($res->json('data.recepciones.importar'));
        $this->assertFalse($res->json('data.liquidaciones.carga-inicial'));
    }

    public function test_seeder_sin_sbp_no_hace_nada(): void
    {
        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame(0, Module::where('slug', 'abastecimiento')->count());
    }

    public function test_seeder_es_idempotente(): void
    {
        $sbp = Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);

        $this->seed(AbastecimientoModuleSeeder::class);
        $primero = $this->conteos($sbp);
        Cultivo::where('nombre', 'Elote')->first()->update(['is_active' => false]);
        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame($primero, $this->conteos($sbp));
        $this->assertSame(8, $primero['submodulos']);
        $this->assertSame(2, $primero['cultivos']);
        $this->assertSame(11, $primero['variedades']);
        $this->assertSame(1, $primero['tipos_carga']);
        $this->assertFalse((bool) Cultivo::where('nombre', 'Elote')->value('is_active'), 'No debe reactivar lo desactivado');
    }

    public function test_mis_permisos_refleja_lo_concedido(): void
    {
        $this->setUpAbastecimiento();
        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view']);
        Sanctum::actingAs($this->captor);

        $res = $this->getJson($this->url())->assertOk()->assertJsonPath('success', true);

        $this->assertTrue($res->json('data.convenios.view'));
        $this->assertFalse($res->json('data.convenios.create'));
        $this->assertFalse($res->json('data.recepciones.view'));
        $this->assertSame(['view'], array_keys($res->json('data.tablero')));
        $this->assertCount(8, $res->json('data'));
    }

    public function test_sin_acceso_a_la_empresa_responde_403(): void
    {
        $this->setUpAbastecimiento();
        Sanctum::actingAs($this->captor);

        // El captor no tiene acceso a la otra empresa (sí sembrada).
        $this->getJson($this->url('otraempresa'))->assertForbidden();
    }

    public function test_empresa_sin_modulo_responde_404(): void
    {
        $this->setUpAbastecimiento();
        $sinModulo = Enterprise::create(['name' => 'Sin', 'slug' => 'sinmodulo', 'description' => 'Sin', 'is_active' => true]);
        Application::create(['enterprise_id' => $sinModulo->id, 'slug' => 'administration', 'name' => 'Administración', 'path' => '/sinmodulo/administration', 'description' => 'administration']);
        UserEnterpriseAccess::create(['user_id' => $this->captor->id, 'enterprise_id' => $sinModulo->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url('sinmodulo'))
            ->assertNotFound()
            ->assertJson(['status' => 'error', 'message' => 'Esta empresa no tiene el módulo de Abastecimiento.']);
    }

    public function test_admin_puede_todo_sin_permisos(): void
    {
        $this->setUpAbastecimiento();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $res = $this->getJson($this->url())->assertOk();

        $this->assertTrue($res->json('data.convenios.delete'));
        $this->assertTrue($res->json('data.tablero.view'));
        $this->assertTrue($res->json('data.tipos-carga.create'));
    }
}
