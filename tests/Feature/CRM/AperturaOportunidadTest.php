<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\AperturaOportunidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * POST crm/oportunidades/rapida y AperturaOportunidadService (spec 2026-10-30
 * §5.3): abrir una oportunidad para un cliente o prospecto sin pasar por el
 * formulario completo.
 */
class AperturaOportunidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/oportunidades/rapida';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

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

        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'La Huerta', 'estatus' => 'activo',
        ]);
    }

    private function permisos(array $permisos = ['crear']): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
    }

    private function rapida(array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::URL, $datos);
    }

    private function prospecto(array $datos = []): CrmProspecto
    {
        return CrmProspecto::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Nuevo', 'estatus' => 'nuevo',
        ], $datos));
    }

    public function test_nombre_con_tipo(): void
    {
        $this->permisos();

        $this->rapida(['cliente_id' => $this->cliente->id, 'tipo_producto' => 'Pepper'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'La Huerta · Pepper')
            ->assertJsonPath('data.tipo_producto', 'Pepper')
            ->assertJsonPath('data.cliente.nombre', 'La Huerta')
            ->assertJsonPath('data.monto_origen.tipo', 'sin_monto');
    }

    public function test_nombre_sin_tipo(): void
    {
        $this->permisos();

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'La Huerta')
            ->assertJsonPath('data.tipo_producto', null);
    }

    public function test_etapa_inicial_prospecto(): void
    {
        $this->permisos();
        $prospecto = $this->prospecto();

        $id = $this->rapida(['prospecto_id' => $prospecto->id, 'tipo_producto' => 'Pepper', 'monto_esperado' => 1500.5])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('crm_oportunidades', [
            'id' => $id,
            'empresa_id' => $this->enterprise->id,
            'prospecto_id' => $prospecto->id,
            'cliente_id' => null,
            'etapa' => 'prospecto',
            'monto_esperado' => 1500.5,
        ]);
        // El observer deja la fila inicial del historial de etapas.
        $this->assertDatabaseHas('crm_oportunidad_etapas', ['oportunidad_id' => $id, 'etapa_desde' => null, 'etapa_hasta' => 'prospecto']);
    }

    public function test_el_monto_por_defecto_es_cero(): void
    {
        $this->permisos();

        $this->rapida(['cliente_id' => $this->cliente->id])->assertCreated();

        $this->assertSame('0.00', (string) CrmOportunidad::first()->monto_esperado);
    }

    public function test_difunde_la_oportunidad_creada(): void
    {
        $this->permisos();
        Event::fake([OportunidadUpdated::class]);

        $this->rapida(['cliente_id' => $this->cliente->id])->assertCreated();

        Event::assertDispatched(OportunidadUpdated::class);
    }

    public function test_exige_permiso_crear(): void
    {
        $this->permisos(['ver']);

        $this->rapida(['cliente_id' => $this->cliente->id])->assertForbidden();

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_exige_exactamente_un_cliente_o_prospecto(): void
    {
        $this->permisos();
        $prospecto = $this->prospecto();

        $this->rapida(['tipo_producto' => 'Pepper'])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->rapida(['cliente_id' => $this->cliente->id, 'prospecto_id' => $prospecto->id])
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_cliente_de_otra_empresa_422(): void
    {
        $this->permisos();
        $ajeno = CrmCliente::create([
            'empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno', 'estatus' => 'activo',
        ]);

        $this->rapida(['cliente_id' => $ajeno->id])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_cliente_o_prospecto_eliminado_responde_422_sin_crear_nada(): void
    {
        $this->permisos();
        $prospecto = $this->prospecto();
        $this->cliente->delete();
        $prospecto->delete();

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.cliente_id.0', 'El cliente no existe o fue eliminado.');
        $this->rapida(['prospecto_id' => $prospecto->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.prospecto_id.0', 'El prospecto no existe o fue eliminado.');

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_tipo_producto_sigue_la_regla_de_commodities(): void
    {
        $this->permisos();
        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Pepper Rojo', 'commodity' => 'Pepper', 'activo' => true,
        ]);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
        $this->rapida(['cliente_id' => $this->cliente->id, 'tipo_producto' => 'Mango'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
        $this->rapida(['cliente_id' => $this->cliente->id, 'tipo_producto' => 'Pepper'])->assertCreated();
    }

    public function test_sin_vendedor_asignado_queda_a_quien_captura(): void
    {
        $this->permisos();
        $this->assertNull($this->cliente->vendedor_id);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.vendedor_id', $this->propio->id);
    }

    public function test_cliente_propio_queda_a_quien_captura(): void
    {
        $this->permisos();
        $this->cliente->update(['vendedor_id' => $this->propio->id]);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.vendedor_id', $this->propio->id);
    }

    public function test_cliente_de_otro_vendedor_se_bloquea(): void
    {
        $this->permisos();
        $this->cliente->update(['vendedor_id' => $this->vendedor->id]);

        $this->rapida(['cliente_id' => $this->cliente->id, 'tipo_producto' => 'Pepper'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Este cliente es de Juan Pérez; pide que te lo reasignen.');

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_prospecto_de_otro_vendedor_se_bloquea_con_su_propio_texto(): void
    {
        $this->permisos();
        $prospecto = $this->prospecto(['vendedor_id' => $this->vendedor->id]);

        $this->rapida(['prospecto_id' => $prospecto->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'Este prospecto es de Juan Pérez; pide que te lo reasignen.');
    }

    public function test_el_mensaje_de_ajena_sale_de_la_constante(): void
    {
        $this->assertSame(
            'Este cliente es de {vendedor}; pide que te lo reasignen.',
            AperturaOportunidadService::MENSAJE_AJENA,
        );
    }

    public function test_sin_equipo_no_puede_pedir_otro_vendedor(): void
    {
        $this->permisos();

        $this->rapida(['cliente_id' => $this->cliente->id, 'vendedor_id' => $this->vendedor->id])
            ->assertForbidden();

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_equipo_abre_a_nombre_del_asignado(): void
    {
        $this->permisos(['crear', 'equipo']);
        $this->cliente->update(['vendedor_id' => $this->vendedor->id]);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.vendedor_id', $this->vendedor->id);
    }

    public function test_equipo_puede_elegir_otro_vendedor(): void
    {
        $this->permisos(['crear', 'equipo']);
        $this->cliente->update(['vendedor_id' => $this->vendedor->id]);

        $this->rapida(['cliente_id' => $this->cliente->id, 'vendedor_id' => $this->propio->id])
            ->assertCreated()
            ->assertJsonPath('data.vendedor_id', $this->propio->id);
    }

    public function test_equipo_sin_asignado_ni_pedido_queda_a_quien_captura(): void
    {
        $this->permisos(['crear', 'equipo']);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.vendedor_id', $this->propio->id);
    }

    public function test_equipo_no_puede_usar_un_vendedor_inactivo_ni_de_otra_empresa(): void
    {
        $this->permisos(['crear', 'equipo']);
        $inactivo = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Inactivo', 'activo' => false,
        ]);
        $otraEmpresa = CrmVendedor::create([
            'empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'De otra', 'activo' => true,
        ]);

        $this->rapida(['cliente_id' => $this->cliente->id, 'vendedor_id' => $inactivo->id])
            ->assertStatus(422)->assertJsonValidationErrors('vendedor_id');
        $this->rapida(['cliente_id' => $this->cliente->id, 'vendedor_id' => $otraEmpresa->id])
            ->assertStatus(422)->assertJsonValidationErrors('vendedor_id');

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_equipo_sin_ningun_vendedor_que_resolver_422(): void
    {
        $this->permisos(['crear', 'equipo']);
        $this->propio->update(['activo' => false]);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertStatus(422)->assertJsonValidationErrors('vendedor_id');
    }

    public function test_sin_vendedor_activo_403(): void
    {
        $this->permisos();
        $this->propio->update(['activo' => false]);

        $this->rapida(['cliente_id' => $this->cliente->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu usuario no está vinculado a un vendedor activo.');

        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_prospecto_ya_convertido_responde_422_con_el_cliente(): void
    {
        $this->permisos(['crear', 'equipo']);
        $prospecto = $this->prospecto(['nombre' => 'Rancho Nuevo', 'estatus' => 'calificado']);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id,
            'nombre' => 'Rancho Nuevo SA', 'estatus' => 'activo',
        ]);

        $this->rapida(['prospecto_id' => $prospecto->id, 'tipo_producto' => 'Pepper'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prospecto_id')
            ->assertJsonPath('errors.prospecto_id.0', 'Este prospecto ya es cliente (Rancho Nuevo SA); registra la actividad con el cliente.');

        $this->assertSame(0, CrmOportunidad::count());
    }
}
