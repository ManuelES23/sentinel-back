<?php

namespace Tests\Feature\CRM;

use App\Exceptions\CRM\VendedorNoVinculadoException;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\VendedorActualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class VendedorActualServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private VendedorActualService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->servicio = app(VendedorActualService::class);
    }

    private function crearVendedorPropio(bool $activo = true): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
            'activo' => $activo,
        ]);
    }

    public function test_sin_id_devuelve_el_vendedor_propio(): void
    {
        $propio = $this->crearVendedorPropio();

        $this->assertSame($propio->id, $this->servicio->resolver($this->enterprise->id, $this->actingUser, null)->id);
        $this->assertSame($propio->id, $this->servicio->resolver($this->enterprise->id, $this->actingUser, $propio->id)->id);
    }

    public function test_sin_vendedor_propio_lanza_excepcion_422(): void
    {
        $this->expectException(VendedorNoVinculadoException::class);

        $this->servicio->resolver($this->enterprise->id, $this->actingUser, null);
    }

    public function test_un_vendedor_inactivo_no_cuenta_como_propio(): void
    {
        $this->crearVendedorPropio(false);
        $this->expectException(VendedorNoVinculadoException::class);

        $this->servicio->resolver($this->enterprise->id, $this->actingUser, null);
    }

    public function test_pedir_otro_vendedor_sin_equipo_da_403(): void
    {
        $this->crearVendedorPropio();
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver']);

        try {
            $this->servicio->resolver($this->enterprise->id, $this->actingUser, $this->vendedor->id);
            $this->fail('Debió abortar con 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_con_equipo_puede_pedir_otro_vendedor_de_la_empresa(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);

        $this->assertTrue($this->servicio->puedeVerEquipo($this->enterprise->id));
        $this->assertSame(
            $this->vendedor->id,
            $this->servicio->resolver($this->enterprise->id, $this->actingUser, $this->vendedor->id)->id,
        );
    }

    public function test_con_equipo_un_vendedor_de_otra_empresa_da_404(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);
        $otra = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno']);

        try {
            $this->servicio->resolver($this->enterprise->id, $this->actingUser, $ajeno->id);
            $this->fail('Debió abortar con 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
