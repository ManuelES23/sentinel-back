<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** GET crm/contactos/de-registro: los contactos que se ofrecen al registrar una actividad. */
class ContactosDeRegistroTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function pedir(string $tipo, int $id)
    {
        return $this->getJson("/api/crm/contactos/de-registro?entidad_tipo={$tipo}&entidad_id={$id}", $this->crmHeaders());
    }

    private function contacto($entidad, string $nombre, bool $principal = false, ?string $cargo = null): CrmContacto
    {
        return CrmContacto::create([
            'empresa_id' => $entidad->empresa_id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'nombre' => $nombre, 'cargo' => $cargo, 'email' => strtolower($nombre).'@example.com', 'telefono' => '667',
            'es_principal' => $principal,
        ]);
    }

    public function test_lista_los_contactos_del_cliente_al_pedir_una_oportunidad_de_prospecto_convertido(): void
    {
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto', 'estatus' => 'calificado']);
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Agrícola Norte', 'estatus' => 'activo', 'prospecto_id' => $prospecto->id,
        ]);
        $this->contacto($cliente, 'Zoe');
        $this->contacto($cliente, 'Beto', true, 'Gerente');
        $this->contacto($cliente, 'Ana');
        $op = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Perdida', 'etapa' => 'perdido',
        ]);

        $res = $this->pedir('oportunidad', $op->id)->assertOk();

        $res->assertJsonPath('data.empresa', ['tipo' => 'cliente', 'id' => $cliente->id, 'nombre' => 'Agrícola Norte']);
        $this->assertSame(['Beto', 'Ana', 'Zoe'], array_column($res->json('data.contactos'), 'nombre'));
        $res->assertJsonPath('data.contactos.0.es_principal', true)
            ->assertJsonPath('data.contactos.0.cargo', 'Gerente')
            ->assertJsonStructure(['data' => ['contactos' => [['id', 'nombre', 'cargo', 'email', 'telefono', 'es_principal']]]]);
    }

    public function test_lista_los_contactos_de_un_prospecto(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver']);
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Huerta Sur', 'estatus' => 'nuevo']);
        $this->contacto($prospecto, 'Carla', true);

        $this->pedir('prospecto', $prospecto->id)->assertOk()
            ->assertJsonPath('data.empresa', ['tipo' => 'prospecto', 'id' => $prospecto->id, 'nombre' => 'Huerta Sur'])
            ->assertJsonCount(1, 'data.contactos');
    }

    public function test_responde_403_sin_ninguno_de_los_cuatro_permisos(): void
    {
        $this->otorgarPermisosCrm('actividades', 'actividades', ['ver', 'editar']);
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver']);
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'P', 'estatus' => 'nuevo']);

        $this->pedir('prospecto', $prospecto->id)->assertForbidden();
    }

    public function test_cada_uno_de_los_permisos_basta(): void
    {
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'P', 'estatus' => 'nuevo']);

        foreach ([['actividades', 'actividades', 'crear'], ['agenda', 'agenda', 'crear'], ['agenda', 'agenda', 'editar'], ['mi-dia', 'mi-dia', 'ver']] as [$modulo, $submodulo, $permiso]) {
            UserSubmodulePermission::query()->delete();
            $this->pedir('prospecto', $prospecto->id)->assertForbidden();
            $this->otorgarPermisosCrm($modulo, $submodulo, [$permiso]);
            $this->pedir('prospecto', $prospecto->id)->assertOk();
        }
    }

    public function test_oportunidad_no_visible_responde_404(): void
    {
        // Vendedor sin equipo: solo ve las suyas.
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id, 'nombre' => 'Yo', 'activo' => true,
        ]);
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'C', 'estatus' => 'activo']);
        $ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ajena', 'etapa' => 'calificado',
        ]);

        $this->pedir('oportunidad', $ajena->id)->assertNotFound();
    }

    public function test_entidad_de_otro_tenant_o_inexistente_responde_404(): void
    {
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $otra = $this->crearOtraEmpresa();
        $ajeno = CrmProspecto::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno', 'estatus' => 'nuevo']);

        $this->pedir('prospecto', $ajeno->id)->assertNotFound();
        $this->pedir('prospecto', 999999)->assertNotFound();
    }

    public function test_empresa_externa_sin_contactos_devuelve_empresa_null_y_lista_vacia(): void
    {
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Externa SA']);

        $this->pedir('empresa_externa', $externa->id)->assertOk()
            ->assertJsonPath('data.empresa', null)
            ->assertJsonPath('data.contactos', []);
    }

    public function test_valida_los_parametros(): void
    {
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);

        $this->getJson('/api/crm/contactos/de-registro?entidad_tipo=nada&entidad_id=1', $this->crmHeaders())->assertStatus(422);
        $this->getJson('/api/crm/contactos/de-registro?entidad_tipo=prospecto', $this->crmHeaders())->assertStatus(422);
    }
}
