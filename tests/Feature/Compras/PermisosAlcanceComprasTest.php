<?php

namespace Tests\Feature\Compras;

use App\Models\Branch;
use App\Models\Entity;
use App\Models\Enterprise;
use App\Models\PurchaseOrder;
use App\Models\RequisicionCampo;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Services\Compras\AlcanceCompras;
use App\Services\Compras\PermisosCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class PermisosAlcanceComprasTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    public function test_cotizar_y_confirmar_por_permiso(): void
    {
        $compras = $this->crearUsuarioDeCampo();
        $campo = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->otorgarCotizar($compras);
        $this->otorgarConfirmar($compras);
        $permisos = app(PermisosCompras::class);

        $this->assertTrue($permisos->puedeCotizar($compras, $this->empresa));
        $this->assertTrue($permisos->puedeConfirmar($compras, $this->empresa));
        $this->assertFalse($permisos->puedeCotizar($campo, $this->empresa));
        $this->assertFalse($permisos->puedeConfirmar($campo, $this->empresa));
        $this->assertTrue($permisos->puedeCotizar($this->crearAdmin(), $this->empresa));
        $this->assertSame([$compras->id], $permisos->usuariosQueCotizan($this->empresa)->pluck('id')->all());
    }

    public function test_alcance_filtra_por_almacen_visible(): void
    {
        $encargadoA = $this->crearUsuarioDeCampo([$this->almacenA]);
        $reqA = $this->crearRequisicion($encargadoA, $this->almacenA);
        $reqB = $this->crearRequisicion($encargadoA, $this->almacenB);
        $legado = $this->crearRequisicion($encargadoA, $this->almacenA);
        $legado->update(['almacen_id' => null, 'enterprise_id' => null]);
        $alcance = app(AlcanceCompras::class);

        $ids = $alcance->aplicar(RequisicionCampo::query(), 'almacen_id', $encargadoA, $this->empresa)->pluck('id')->all();
        $this->assertSame([$reqA->id], $ids);
        $this->assertTrue($alcance->puedeVer($encargadoA, $this->empresa, $reqA, 'almacen_id'));
        $this->assertFalse($alcance->puedeVer($encargadoA, $this->empresa, $reqB, 'almacen_id'));
        $this->assertFalse($alcance->puedeVer($encargadoA, $this->empresa, $legado, 'almacen_id'));

        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($compras);
        $todos = $alcance->aplicar(RequisicionCampo::query(), 'almacen_id', $compras, $this->empresa)->pluck('id')->sort()->values()->all();
        $this->assertSame([$reqA->id, $reqB->id, $legado->id], $todos);
    }

    /**
     * Regresión: `operacion-agricola` no existe en splendidbyporvenir, así que
     * `cotizar` es imposible de satisfacer ahí. El permiso `gestionar` en
     * inventario > compras > ordenes-compra es el equivalente no agrícola
     * que le da a Compras acceso de escritura a OC en esa empresa.
     */
    public function test_gestionar_da_acceso_de_escritura_a_oc_en_empresa_sin_operacion_agricola(): void
    {
        $empresa2 = Enterprise::create([
            'name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir',
            'description' => 'Empresa de prueba sin operación agrícola', 'is_active' => true,
        ]);
        $sucursal2 = Branch::create(['enterprise_id' => $empresa2->id, 'code' => 'SUC-SBP', 'name' => 'Matriz SBP', 'slug' => 'matriz-sbp']);
        $almacen2 = Entity::create([
            'branch_id' => $sucursal2->id, 'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-SBP', 'name' => 'Almacén SBP',
        ]);

        $usuarioGestor = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $usuarioGestor->id, 'enterprise_id' => $empresa2->id, 'is_active' => true]);
        UserEntityAccess::create(['user_id' => $usuarioGestor->id, 'entity_id' => $almacen2->id, 'enterprise_id' => $empresa2->id]);
        $this->otorgarGestionar($usuarioGestor, $empresa2);
        $this->crearAprobador('enterprise', $empresa2);

        $headers = ['X-Enterprise-Slug' => 'splendidbyporvenir'];
        $payload = [
            'supplier_id' => $this->proveedor->id,
            'order_date' => now()->toDateString(),
            'almacen_destino_id' => $almacen2->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity_ordered' => 1, 'unit_price' => 10]],
        ];

        // Un usuario sin gestionar/cotizar/ver_todos_almacenes no puede crear OC ahí.
        $usuarioSinPermiso = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create(['user_id' => $usuarioSinPermiso->id, 'enterprise_id' => $empresa2->id, 'is_active' => true]);
        $this->actingAs($usuarioSinPermiso)
            ->postJson('/api/splendidbyporvenir/inventario/compras/ordenes', $payload, $headers)
            ->assertForbidden();

        // El usuario con gestionar SÍ puede crear y mandar a autorizar la OC.
        $creada = $this->actingAs($usuarioGestor)
            ->postJson('/api/splendidbyporvenir/inventario/compras/ordenes', $payload, $headers)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($usuarioGestor)
            ->postJson("/api/splendidbyporvenir/inventario/compras/ordenes/{$creada}/submit", [], $headers)
            ->assertOk();
        $this->assertSame('pending', PurchaseOrder::find($creada)->status);

        // El flujo agrícola existente (cotizar) en splendidfarms sigue intacto.
        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($compras);
        $this->otorgarVerTodos($compras);
        $ordenAgricola = $this->crearOrden(['created_by' => $compras->id]);
        $this->actingAs($compras)
            ->putJson("/api/splendidfarms/inventario/compras/ordenes/{$ordenAgricola->id}", ['notes' => 'Ajuste'], $this->headersEmpresa())
            ->assertOk();
    }
}
