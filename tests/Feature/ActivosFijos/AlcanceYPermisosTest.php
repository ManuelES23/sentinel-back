<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\User;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\PermisosActivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class AlcanceYPermisosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();

        Route::middleware(['api', 'auth:sanctum'])->get('/api/{empresa}/administration/activos-fijos/_prueba', function (Request $request) {
            $alcance = app(AlcanceActivos::class);

            return [
                'empresa' => $alcance->empresaActual($request)->slug,
                'corporativo' => $alcance->esCorporativo($request),
                'visibles' => $alcance->empresasVisibles($request)->pluck('slug')->sort()->values(),
                'permisos' => app(PermisosActivos::class)->resumen($request),
            ];
        });
    }

    public function test_fuera_de_ge_solo_ve_su_empresa(): void
    {
        Sanctum::actingAs($this->actingUser);

        $this->getJson('/api/splendidfarms/administration/activos-fijos/_prueba')
            ->assertOk()
            ->assertJsonPath('empresa', 'splendidfarms')
            ->assertJsonPath('corporativo', false)
            ->assertJsonPath('visibles', ['splendidfarms']);
    }

    public function test_ge_ve_todas_las_empresas_con_el_modulo(): void
    {
        $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        \App\Models\Enterprise::create(['name' => 'Canes', 'slug' => 'canes-agro', 'description' => 'Canes', 'is_active' => true]); // sin módulo
        Sanctum::actingAs($this->actingUser);

        $this->getJson('/api/grupoesplendido/administration/activos-fijos/_prueba')
            ->assertOk()
            ->assertJsonPath('corporativo', true)
            ->assertJsonPath('visibles', ['grupoesplendido', 'splendidbyporvenir', 'splendidfarms']);
    }

    public function test_sin_acceso_a_la_empresa_responde_403(): void
    {
        $ajeno = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($ajeno);

        $this->getJson('/api/splendidfarms/administration/activos-fijos/_prueba')->assertForbidden();
    }

    public function test_un_administrador_pasa_sin_acceso_explicito(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/splendidfarms/administration/activos-fijos/_prueba')
            ->assertOk()
            ->assertJsonPath('permisos.activos.delete', true);
    }

    public function test_el_resumen_refleja_las_concesiones(): void
    {
        $lector = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($lector, $this->enterprise, 'activos', ['view']);
        Sanctum::actingAs($lector);

        $this->getJson('/api/splendidfarms/administration/activos-fijos/_prueba')
            ->assertOk()
            ->assertJsonPath('permisos.activos', ['view' => true, 'create' => false, 'edit' => false, 'delete' => false])
            ->assertJsonPath('permisos.tipos_activo', ['view' => false, 'create' => false, 'edit' => false, 'delete' => false])
            ->assertJsonPath('permisos.corporativo', false);
    }

    public function test_escribir_tipos_desde_una_empresa_no_corporativa_nunca_se_permite(): void
    {
        $this->otorgarActivos($this->actingUser, $this->enterprise, 'tipos-activo', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);

        $this->getJson('/api/splendidfarms/administration/activos-fijos/_prueba')
            ->assertJsonPath('permisos.tipos_activo.create', false)
            ->assertJsonPath('permisos.tipos_activo.view', true);
    }
}
