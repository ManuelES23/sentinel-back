<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class VacacionesPermisosApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
    }

    public function test_devuelve_solo_lo_que_el_usuario_tiene(): void
    {
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'view', 'aprobar');
        Sanctum::actingAs($rh);

        $this->getJson('/api/grupoesplendido/rh/vacaciones/mis-permisos')->assertOk()->assertExactJson([
            'success' => true,
            'data' => ['view' => true, 'create' => false, 'edit' => false, 'aprobar' => true],
        ]);
    }

    public function test_un_usuario_sin_nada_recibe_todo_en_falso_y_no_un_403(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/grupoesplendido/rh/vacaciones/mis-permisos')->assertOk()
            ->assertJsonPath('data', ['view' => false, 'create' => false, 'edit' => false, 'aprobar' => false]);
    }
}
