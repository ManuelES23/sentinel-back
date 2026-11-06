<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Application;
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

        $this->assertSame(7, $this->conteos($sbp)['submodulos']);
        $this->assertSame(0, $this->conteos($sf)['submodulos']);
        $this->assertSame(
            ['tablero', 'convenios', 'recepciones', 'productores', 'temporadas', 'variedades', 'tipos-carga'],
            Submodule::whereHas('module', fn ($m) => $m->where('slug', 'abastecimiento'))->orderBy('order')->pluck('slug')->all(),
        );
        // El tablero solo tiene "ver".
        $tablero = Submodule::where('slug', 'tablero')->first();
        $this->assertSame(['view'], $tablero->permissionTypes()->pluck('slug')->all());
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
        $this->assertSame(7, $primero['submodulos']);
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
        $this->assertCount(7, $res->json('data'));
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
