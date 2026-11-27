<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ClienteControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/clientes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        // Este archivo prueba el comportamiento, no la autorización (ver CrmPermisosEnforcementTest).
        $this->otorgarTodosLosPermisosCrm();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Agroindustrias del Valle S.A.',
            'email' => 'contacto@agrovalle.mx',
            'vendedor_id' => $this->vendedor->id,
            'region_id' => $this->region->id,
        ], $overrides);
    }

    /**
     * Regresión: antes de este fix, index() llamaba a ->empresa($empresaId)
     * (un método que no existe en CrmCliente, solo la relación empresa())
     * y toda petición a este endpoint terminaba en 500.
     */
    public function test_puede_listar_clientes_sin_reventar(): void
    {
        CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJson(['success' => true])->assertJsonCount(1, 'data');
    }

    public function test_el_listado_solo_incluye_clientes_de_la_empresa_del_contexto(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente propio']));
        CrmCliente::create($this->validPayload([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Cliente de otra empresa',
            'vendedor_id' => null, 'region_id' => null,
        ]));

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL);

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Cliente propio');
    }

    public function test_puede_crear_un_cliente(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.nombre', 'Agroindustrias del Valle S.A.')
            ->assertJsonPath('data.estatus', 'activo');

        $this->assertDatabaseHas('crm_clientes', [
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Agroindustrias del Valle S.A.',
        ]);
    }

    public function test_requiere_nombre(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, []);

        $response->assertStatus(422)->assertJsonValidationErrors(['nombre']);
    }

    public function test_no_permite_email_duplicado_en_la_misma_empresa(): void
    {
        CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->validPayload([
            'nombre' => 'Otro nombre',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_permite_el_mismo_email_en_empresas_distintas(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        CrmCliente::create($this->validPayload([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => null, 'region_id' => null,
        ]));

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->validPayload());

        $response->assertCreated();
    }

    public function test_no_puede_ver_un_cliente_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $cliente = CrmCliente::create($this->validPayload([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => null, 'region_id' => null,
        ]));

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL."/{$cliente->id}");

        $response->assertStatus(404);
    }

    public function test_puede_actualizar_un_cliente(): void
    {
        $cliente = CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$cliente->id}", [
            'estatus' => 'suspendido',
        ]);

        $response->assertOk()->assertJsonPath('data.estatus', 'suspendido');
    }

    public function test_puede_eliminar_un_cliente_con_soft_delete(): void
    {
        $cliente = CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$cliente->id}");

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('crm_clientes', ['id' => $cliente->id]);
    }

    public function test_puede_reasignar_vendedor(): void
    {
        $cliente = CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id, 'vendedor_id' => null]));

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson(self::BASE_URL."/{$cliente->id}/asignar-vendedor", ['vendedor_id' => $this->vendedor->id]);

        $response->assertOk()->assertJsonPath('data.vendedor.id', $this->vendedor->id);
        $this->assertDatabaseHas('crm_clientes', ['id' => $cliente->id, 'vendedor_id' => $this->vendedor->id]);

        // La reasignación debe quedar registrada como actividad de auditoría
        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class,
            'entidad_id' => $cliente->id,
        ]);
    }

    public function test_rechaza_un_vendedor_de_otra_empresa_al_asignar(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $otraEmpresa->id,
            'nombre' => 'Vendedor ajeno',
            'activo' => true,
        ]);
        $cliente = CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson(self::BASE_URL."/{$cliente->id}/asignar-vendedor", ['vendedor_id' => $vendedorAjeno->id]);

        $response->assertStatus(422)->assertJsonValidationErrors(['vendedor_id']);
    }

    public function test_el_resumen_incluye_metricas_y_pipeline(): void
    {
        $cliente = CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id]));

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL."/{$cliente->id}/resumen");

        $response->assertOk()
            ->assertJsonPath('data.metricas.contactos', 0)
            ->assertJsonPath('data.metricas.oportunidades', 0)
            ->assertJsonPath('data.metricas.valor_pipeline', 0);
    }

    public function test_pendientes_asignar_lista_solo_los_sin_vendedor_de_la_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Zeta Produce', 'clave' => 'ZETA', 'ciudad' => 'Dallas', 'estado' => 'TX']);
        CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Alfa Fresh']);
        CrmCliente::create($this->validPayload(['empresa_id' => $this->enterprise->id, 'nombre' => 'Ya asignado']));
        CrmCliente::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'De otra empresa']);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL.'/pendientes-asignar');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre', 'Alfa Fresh')
            ->assertJsonPath('data.1.clave', 'ZETA')
            ->assertJsonPath('data.1.ciudad', 'Dallas')
            ->assertJsonPath('data.1.estado', 'TX');
    }

    public function test_asigna_vendedor_en_lote_solo_a_los_pendientes(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\CRM\VendedorAsignado::class]);
        $otraEmpresa = $this->crearOtraEmpresa();
        $otroVendedor = \App\Models\CRM\CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
        $a = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'A']);
        $b = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'B']);
        $yaTenia = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'C', 'vendedor_id' => $otroVendedor->id]);
        $ajeno = CrmCliente::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'D']);

        $response = $this->withHeaders($this->crmHeaders())->patchJson(self::BASE_URL.'/asignar-vendedor', [
            'cliente_ids' => [$a->id, $b->id, $yaTenia->id, $ajeno->id],
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.asignados', 2)
            ->assertJsonPath('data.omitidos', 2)
            ->assertJsonPath('data.vendedor.id', $this->vendedor->id);
        $this->assertSame($this->vendedor->id, $a->fresh()->vendedor_id);
        $this->assertSame($this->vendedor->id, $b->fresh()->vendedor_id);
        $this->assertSame($otroVendedor->id, $yaTenia->fresh()->vendedor_id, 'no reasigna al que ya tenía vendedor');
        $this->assertNull($ajeno->fresh()->vendedor_id, 'no toca clientes de otra empresa');

        foreach ([$a, $b] as $cliente) {
            $this->assertDatabaseHas('crm_actividades', [
                'entidad_type' => CrmCliente::class,
                'entidad_id' => $cliente->id,
                'descripcion' => "Vendedor asignado: {$this->vendedor->nombre}",
            ]);
        }
        $this->assertDatabaseMissing('crm_actividades', ['entidad_id' => $yaTenia->id, 'entidad_type' => CrmCliente::class]);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\CRM\VendedorAsignado::class, 1);
    }

    public function test_el_lote_rechaza_un_vendedor_inactivo_o_de_otra_empresa(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'A']);
        $inactivo = \App\Models\CRM\CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Baja', 'activo' => false]);
        $ajeno = \App\Models\CRM\CrmVendedor::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno', 'activo' => true]);

        foreach ([$inactivo, $ajeno] as $vendedor) {
            $this->withHeaders($this->crmHeaders())
                ->patchJson(self::BASE_URL.'/asignar-vendedor', ['cliente_ids' => [$cliente->id], 'vendedor_id' => $vendedor->id])
                ->assertStatus(422)->assertJsonValidationErrors(['vendedor_id']);
        }
        $this->withHeaders($this->crmHeaders())
            ->patchJson(self::BASE_URL.'/asignar-vendedor', ['cliente_ids' => [], 'vendedor_id' => $this->vendedor->id])
            ->assertStatus(422)->assertJsonValidationErrors(['cliente_ids']);
        $this->assertNull($cliente->fresh()->vendedor_id);
    }
}
