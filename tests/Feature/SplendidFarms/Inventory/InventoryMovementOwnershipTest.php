<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

/**
 * Restricciones de ownership por dirección en InventoryMovementController:
 * las entradas, salidas y ajustes solo pueden escribir stock en entidades
 * propias de la empresa; las entidades vinculadas (de otra empresa, vía
 * enterprise_entity) son de solo lectura. Cubre el caso AJUSTE+ (ajuste
 * positivo), cuya entidad operada es el destino.
 */
class InventoryMovementOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/movimientos';

    private Entity $almacenVinculado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();

        $otraEmpresa = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'x']);
        $otraSucursal = Branch::create([
            'enterprise_id' => $otraEmpresa->id,
            'code' => 'SUC-CA',
            'name' => 'Central Caña',
            'slug' => 'central-cana',
        ]);
        $this->almacenVinculado = Entity::create([
            'branch_id' => $otraSucursal->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-VINC',
            'name' => 'Almacén Vinculado',
        ]);

        DB::table('enterprise_entity')->insert([
            'enterprise_id' => $this->empresa->id,
            'entity_id' => $this->almacenVinculado->id,
            'access_level' => 'read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ajustePositivo(int $destino): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, [
            'movement_type_id' => $this->tipoAjusteMas->id,
            'destination_entity_id' => $destino,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 1]],
        ], $this->headersEmpresa());
    }

    public function test_ajuste_positivo_a_entidad_vinculada_da_422(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA, $this->almacenVinculado]));

        $this->ajustePositivo($this->almacenVinculado->id)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Las entradas, salidas y ajustes solo pueden realizarse en entidades propias de la empresa. Las entidades vinculadas son de solo lectura.']);
    }

    public function test_ajuste_positivo_a_almacen_propio_se_permite(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA, $this->almacenVinculado]));

        $this->ajustePositivo($this->almacenA->id)->assertCreated();
    }

    public function test_update_de_ajuste_positivo_a_entidad_vinculada_da_422(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA, $this->almacenVinculado]));

        $id = $this->ajustePositivo($this->almacenA->id)->assertCreated()->json('data.id');

        $this->putJson(self::URL . "/{$id}", [
            'destination_entity_id' => $this->almacenVinculado->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 1]],
        ], $this->headersEmpresa())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Las entradas, salidas y ajustes solo pueden realizarse en entidades propias.']);

        $this->assertSame($this->almacenA->id, InventoryMovement::find($id)->destination_entity_id);
    }
}
