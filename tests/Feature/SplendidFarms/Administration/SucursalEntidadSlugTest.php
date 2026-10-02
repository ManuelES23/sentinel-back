<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

/**
 * Al editar una sucursal o entidad mandando `slug` vacío, el slug se regenera
 * desde el nombre: debe quedar libre aun contando los borrados (borrado lógico)
 * y nunca guardarse en null (columna NOT NULL).
 */
class SucursalEntidadSlugTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private string $base = '/api/splendidfarms/administration/organizacion';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        Sanctum::actingAs($this->crearAdmin());
    }

    public function test_sucursal_quitar_el_slug_sin_renombrar_conserva_uno_valido(): void
    {
        $actualizada = $this->putJson("{$this->base}/sucursales/{$this->sucursal->id}", ['slug' => null], $this->headersEmpresa())
            ->assertOk()->json('data');

        $this->assertSame('rancho-norte', $actualizada['slug']);
    }

    public function test_sucursal_renombrar_borrando_el_slug_toma_uno_libre(): void
    {
        Branch::create(['enterprise_id' => $this->empresa->id, 'code' => 'SUC-02', 'name' => 'Rancho Sur', 'slug' => 'rancho-sur'])->delete();

        $actualizada = $this->putJson("{$this->base}/sucursales/{$this->sucursal->id}", ['name' => 'Rancho Sur', 'slug' => null], $this->headersEmpresa())
            ->assertOk()->json('data');

        $this->assertSame('rancho-sur-2', $actualizada['slug']);
    }

    public function test_entidad_quitar_el_slug_sin_renombrar_conserva_uno_valido(): void
    {
        $actualizada = $this->putJson("{$this->base}/entidades/{$this->almacenA->id}", ['slug' => null], $this->headersEmpresa())
            ->assertOk()->json('data');

        $this->assertSame('almacen-campo-a', $actualizada['slug']);
    }

    public function test_entidad_renombrar_borrando_el_slug_toma_uno_libre(): void
    {
        $this->almacenB->delete();

        $actualizada = $this->putJson("{$this->base}/entidades/{$this->almacenA->id}", ['name' => 'Almacén Campo B', 'slug' => null], $this->headersEmpresa())
            ->assertOk()->json('data');

        $this->assertSame('almacen-campo-b-2', $actualizada['slug']);
    }
}
