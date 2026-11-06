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

        $this->assertSame(9, $this->conteos($sbp)['submodulos']);
        $this->assertSame(0, $this->conteos($sf)['submodulos']);
        $this->assertSame(
            ['tablero', 'convenios', 'recepciones', 'productores', 'temporadas', 'variedades', 'tipos-carga', 'liquidaciones', 'abonos'],
            Submodule::whereHas('module', fn ($m) => $m->where('slug', 'abastecimiento'))->orderBy('order')->pluck('slug')->all(),
        );
        // El tablero solo tiene "ver".
        $tablero = Submodule::where('slug', 'tablero')->first();
        $this->assertSame(['view'], $tablero->permissionTypes()->pluck('slug')->all());
    }

    public function test_seeder_crea_liquidaciones_y_abonos_con_sus_permisos(): void
    {
        Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);

        $this->seed(AbastecimientoModuleSeeder::class);

        $liq = Submodule::where('slug', 'liquidaciones')->first();
        $abo = Submodule::where('slug', 'abonos')->first();
        $this->assertSame('Receipt', $liq->icon);
        $this->assertSame('Wallet', $abo->icon);
        $this->assertSame(
            ['view', 'create', 'edit', 'delete', 'aprobar', 'carga-inicial'],
            $liq->permissionTypes()->orderBy('order')->orderBy('id')->pluck('slug')->all(),
        );
        $this->assertSame(
            ['view', 'create', 'edit', 'delete', 'cancelar'],
            $abo->permissionTypes()->orderBy('order')->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_seeder_crea_el_proceso_supply_settlements_una_sola_vez_y_no_lo_reactiva(): void
    {
        Enterprise::create(['name' => 'SBP', 'slug' => 'splendidbyporvenir', 'description' => 'SBP', 'is_active' => true]);

        $this->seed(AbastecimientoModuleSeeder::class);

        $proceso = ApprovalProcess::where('code', ApprovalProcess::SUPPLY_SETTLEMENTS)->get();
        $this->assertCount(1, $proceso);
        $this->assertSame('supply_settlements', $proceso[0]->code);
        $this->assertSame('Liquidaciones de abastecimiento', $proceso[0]->name);
        $this->assertTrue($proceso[0]->requires_approval);
        $this->assertTrue($proceso[0]->is_active);

        $proceso[0]->update(['is_active' => false]);
        $this->seed(AbastecimientoModuleSeeder::class);

        $this->assertSame(1, ApprovalProcess::where('code', 'supply_settlements')->count());
        $this->assertFalse(ApprovalProcess::where('code', 'supply_settlements')->first()->is_active, 'No debe reactivar lo desactivado');
        $this->assertSame(0, ApprovalProcess::where('code', 'supply_settlements')->first()->steps()->count());
    }

    public function test_mis_permisos_trae_aprobar_y_cancelar(): void
    {
        $this->setUpAbastecimiento();
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view', 'aprobar']);
        $this->otorgarAbs($this->captor, $this->sbp, 'abonos', ['cancelar']);
        Sanctum::actingAs($this->captor);

        $res = $this->getJson($this->url())->assertOk();

        $this->assertTrue($res->json('data.liquidaciones.view'));
        $this->assertTrue($res->json('data.liquidaciones.aprobar'));
        $this->assertFalse($res->json('data.liquidaciones.create'));
        $this->assertTrue($res->json('data.abonos.cancelar'));
        $this->assertFalse($res->json('data.abonos.view'));
        $this->assertSame(['view', 'create', 'edit', 'delete', 'aprobar', 'carga-inicial'], array_keys($res->json('data.liquidaciones')));
        $this->assertSame(['view', 'create', 'edit', 'delete', 'cancelar'], array_keys($res->json('data.abonos')));
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
        $this->assertSame('Registrar liquidaciones de carga inicial', $carga[0]->description);

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
        $this->assertSame(9, $primero['submodulos']);
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
        $this->assertCount(9, $res->json('data'));
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
