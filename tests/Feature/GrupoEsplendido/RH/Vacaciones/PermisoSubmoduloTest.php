<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use App\Support\PermisoSubmodulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class PermisoSubmoduloTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    public function test_detecta_el_permiso_concedido_por_aplicacion(): void
    {
        $usuario = User::factory()->create();
        $this->concederVacaciones($usuario, 'view');

        $this->assertTrue(PermisoSubmodulo::tiene($usuario->id, $this->corporativo->id, 'rh', 'gestion', 'vacaciones', 'view'));
        $this->assertFalse(PermisoSubmodulo::tiene($usuario->id, $this->corporativo->id, 'rh', 'gestion', 'vacaciones', 'aprobar'));
        $this->assertFalse(PermisoSubmodulo::tiene($usuario->id, $this->corporativo->id, 'crm', 'gestion', 'vacaciones', 'view'));
        $this->assertFalse(PermisoSubmodulo::tiene(null, $this->corporativo->id, 'rh', 'gestion', 'vacaciones', 'view'));
        $this->assertSame([$usuario->id], PermisoSubmodulo::usuariosCon($this->corporativo->id, 'rh', 'gestion', 'vacaciones', 'view'));
    }

    public function test_una_concesion_cruzada_de_otro_submodulo_no_cuenta(): void
    {
        $vacaciones = $this->submoduloVacaciones();
        $incidencias = Submodule::create(['module_id' => $vacaciones->module_id, 'slug' => 'incidencias', 'name' => 'Incidencias', 'order' => 2, 'is_active' => true]);
        $tipoAjeno = SubmodulePermissionType::create(['submodule_id' => $incidencias->id, 'slug' => 'aprobar', 'name' => 'Aprobar', 'order' => 1, 'is_active' => true]);
        $usuario = User::factory()->create();
        UserSubmodulePermission::create(['user_id' => $usuario->id, 'submodule_id' => $vacaciones->id, 'permission_type_id' => $tipoAjeno->id, 'is_granted' => true]);

        $this->assertFalse(PermisoSubmodulo::tiene($usuario->id, $this->corporativo->id, 'rh', 'gestion', 'vacaciones', 'aprobar'));
    }
}
