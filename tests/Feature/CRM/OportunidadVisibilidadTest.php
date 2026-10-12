<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Lectura de oportunidades: index/show exigen oportunidades.ver, y quien no
 * tiene oportunidades.equipo solo ve las de su propio CrmVendedor.
 */
class OportunidadVisibilidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const BASE_URL = '/api/crm/oportunidades';

    private CrmVendedor $propio;

    private CrmOportunidad $oportunidadPropia;

    private CrmOportunidad $oportunidadAjena;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario',
            'activo' => true,
        ]);

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente',
            'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);

        $this->oportunidadPropia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia',
        ]);
        $this->oportunidadAjena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena',
        ]);
    }

    private function nombres($response): array
    {
        return collect($response->json('data'))->pluck('nombre')->sort()->values()->all();
    }

    public function test_listar_sin_permiso_ver_responde_403(): void
    {
        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_ver_una_oportunidad_sin_permiso_ver_responde_403(): void
    {
        $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL."/{$this->oportunidadPropia->id}")
            ->assertForbidden();
    }

    public function test_el_permiso_de_otro_submodulo_no_basta(): void
    {
        $this->otorgarPermisosCrm('clientes', 'clientes', ['ver']);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_sin_equipo_el_listado_se_limita_al_vendedor_propio(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk();
        $this->assertSame(['Propia'], $this->nombres($response));
    }

    public function test_sin_equipo_pedir_el_propio_vendedor_explicitamente_funciona(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL.'?vendedor_id='.$this->propio->id);

        $response->assertOk();
        $this->assertSame(['Propia'], $this->nombres($response));
    }

    public function test_sin_equipo_pedir_otro_vendedor_responde_403(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL.'?vendedor_id='.$this->vendedor->id)
            ->assertForbidden();
    }

    public function test_sin_equipo_y_sin_vendedor_vinculado_responde_403(): void
    {
        $this->propio->delete();
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_sin_equipo_un_vendedor_inactivo_tampoco_cuenta_como_propio(): void
    {
        $this->propio->update(['activo' => false]);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_sin_equipo_show_de_una_oportunidad_propia_funciona_y_de_otra_responde_403(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL."/{$this->oportunidadPropia->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Propia');

        $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL."/{$this->oportunidadAjena->id}")
            ->assertForbidden();
    }

    public function test_con_equipo_el_listado_incluye_a_todos_los_vendedores(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk();
        $this->assertSame(['Ajena', 'Propia'], $this->nombres($response));
    }

    public function test_con_equipo_puede_filtrar_por_cualquier_vendedor(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL.'?vendedor_id='.$this->vendedor->id);

        $response->assertOk();
        $this->assertSame(['Ajena'], $this->nombres($response));
    }

    public function test_con_equipo_show_de_una_oportunidad_ajena_funciona(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL."/{$this->oportunidadAjena->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Ajena');
    }

    public function test_equipo_sin_ver_no_da_acceso(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_un_permiso_ver_revocado_no_cuenta(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        UserSubmodulePermission::where('user_id', $this->actingUser->id)->update(['is_granted' => false]);

        $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL)->assertForbidden();
    }

    public function test_el_aislamiento_por_empresa_se_mantiene_con_equipo(): void
    {
        $otra = $this->crearOtraEmpresa();
        CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'De otra empresa',
        ]);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $this->assertSame(['Ajena', 'Propia'], $this->nombres($response));
    }

    public function test_el_filtro_por_cliente_respeta_la_limitacion_al_vendedor_propio(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL.'?cliente_id='.$this->oportunidadAjena->cliente_id);

        $this->assertSame(['Propia'], $this->nombres($response));
    }

    public function test_el_seeder_crea_el_permiso_equipo_de_oportunidades(): void
    {
        (new \Database\Seeders\CrmPermisosSeeder)->run();

        $this->assertTrue(
            SubmodulePermissionType::where('slug', 'equipo')
                ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))
                ->where('is_active', true)
                ->exists()
        );
    }
}
