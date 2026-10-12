<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\VisibilidadOportunidades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Sabor «alcance» (no aborta) de la regla de visibilidad de oportunidades,
 * el que usan las fichas de cliente y prospecto.
 */
class VisibilidadOportunidadesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

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
    }

    private function servicio(): VisibilidadOportunidades
    {
        return app(VisibilidadOportunidades::class);
    }

    private function visibles(): array
    {
        $alcance = $this->servicio()->alcance($this->enterprise->id);

        return $alcance->aplicar(CrmOportunidad::query())->orderBy('nombre')->pluck('nombre')->all();
    }

    private function sembrarOportunidades(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id, 'vendedor_id' => $this->propio->id, 'nombre' => 'Propia']);
        CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena']);
        $otra = $this->crearOtraEmpresa();
        CrmOportunidad::create(['empresa_id' => $otra->id, 'vendedor_id' => $this->propio->id, 'nombre' => 'Otra empresa']);
    }

    public function test_sin_ver_no_ve_nada(): void
    {
        $this->sembrarOportunidades();
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $alcance = $this->servicio()->alcance($this->enterprise->id);

        $this->assertFalse($alcance->puedeVer);
        $this->assertTrue($alcance->ninguna());
        $this->assertSame([], $this->visibles());
    }

    public function test_con_equipo_ve_todas_las_de_su_empresa(): void
    {
        $this->sembrarOportunidades();
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $alcance = $this->servicio()->alcance($this->enterprise->id);

        $this->assertTrue($alcance->todas);
        $this->assertNull($alcance->vendedorId);
        $this->assertSame(['Ajena', 'Propia'], $this->visibles());
    }

    public function test_sin_equipo_solo_las_de_su_vendedor_activo(): void
    {
        $this->sembrarOportunidades();
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $alcance = $this->servicio()->alcance($this->enterprise->id);

        $this->assertFalse($alcance->todas);
        $this->assertSame($this->propio->id, $alcance->vendedorId);
        $this->assertSame(['Propia'], $this->visibles());
    }

    public function test_sin_equipo_y_con_vendedor_inactivo_no_ve_nada(): void
    {
        $this->sembrarOportunidades();
        $this->propio->update(['activo' => false]);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $alcance = $this->servicio()->alcance($this->enterprise->id);

        $this->assertTrue($alcance->puedeVer);
        $this->assertNull($alcance->vendedorId);
        $this->assertTrue($alcance->ninguna());
        $this->assertSame([], $this->visibles());
    }
}
