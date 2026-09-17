<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Enterprise;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class MovimientosPorAlmacenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/movimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
    }

    private function movimiento(int $origenId): InventoryMovement
    {
        return InventoryMovement::create([
            'document_number' => 'OUT-TEST-' . $origenId,
            'movement_type_id' => $this->tipoSalida->id,
            'source_entity_id' => $origenId,
            'movement_date' => now(),
            'status' => 'pending',
        ]);
    }

    public function test_index_solo_lista_movimientos_de_almacenes_visibles(): void
    {
        $this->movimiento($this->almacenA->id);
        $this->movimiento($this->almacenB->id);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $response = $this->getJson(self::URL, $this->headersEmpresa());

        $response->assertOk();
        $docs = collect($response->json('data.data'))->pluck('document_number');
        $this->assertTrue($docs->contains('OUT-TEST-' . $this->almacenA->id));
        $this->assertFalse($docs->contains('OUT-TEST-' . $this->almacenB->id));
    }

    public function test_usuario_sin_almacenes_no_ve_movimientos(): void
    {
        $this->movimiento($this->almacenA->id);
        Sanctum::actingAs($this->crearUsuarioDeCampo());

        $response = $this->getJson(self::URL, $this->headersEmpresa());

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_sin_header_da_422_y_empresa_ajena_da_403(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));
        Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'x']);

        $this->getJson(self::URL)->assertStatus(422);
        $this->getJson(self::URL, ['X-Enterprise-Slug' => 'canes-agro'])->assertStatus(403);
    }

    public function test_store_de_salida_en_almacen_no_asignado_da_403(): void
    {
        $this->darStock($this->almacenB, $this->insumo, 10);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL, [
            'movement_type_id' => $this->tipoSalida->id,
            'source_entity_id' => $this->almacenB->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 1]],
        ], $this->headersEmpresa())->assertStatus(403);
    }

    public function test_store_de_salida_en_almacen_asignado_funciona(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 10);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL, [
            'movement_type_id' => $this->tipoSalida->id,
            'source_entity_id' => $this->almacenA->id,
            'details' => [['product_id' => $this->insumo->id, 'quantity' => 1]],
        ], $this->headersEmpresa())->assertCreated();
    }

    public function test_show_approve_cancel_y_destroy_de_almacen_ajeno(): void
    {
        $this->darStock($this->almacenB, $this->insumo, 10);
        $mov = $this->movimiento($this->almacenB->id);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));
        $h = $this->headersEmpresa();

        $this->getJson(self::URL . "/{$mov->id}", $h)->assertNotFound();
        $this->postJson(self::URL . "/{$mov->id}/approve", [], $h)->assertForbidden();
        $this->postJson(self::URL . "/{$mov->id}/cancel", [], $h)->assertForbidden();
        $this->deleteJson(self::URL . "/{$mov->id}", [], $h)->assertNotFound();
    }

    public function test_ver_todos_puede_consultar_cualquier_almacen(): void
    {
        $mov = $this->movimiento($this->almacenB->id);
        $user = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($user);
        Sanctum::actingAs($user);

        $this->getJson(self::URL . "/{$mov->id}", $this->headersEmpresa())->assertOk();
    }

    public function test_entidades_accesibles_solo_devuelve_visibles(): void
    {
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $response = $this->getJson('/api/splendidfarms/inventario/operaciones/entidades-accesibles', $this->headersEmpresa());

        $response->assertOk();
        $this->assertSame([$this->almacenA->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_approve_entrada_en_almacen_asignado_funciona(): void
    {
        $mov = InventoryMovement::create([
            'document_number' => 'IN-TEST-A',
            'movement_type_id' => $this->tipoEntrada->id,
            'destination_entity_id' => $this->almacenA->id,
            'movement_date' => now(),
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL . "/{$mov->id}/approve", [], $this->headersEmpresa())->assertOk();
    }

    public function test_approve_entrada_en_almacen_no_asignado_da_403(): void
    {
        $mov = InventoryMovement::create([
            'document_number' => 'IN-TEST-B',
            'movement_type_id' => $this->tipoEntrada->id,
            'destination_entity_id' => $this->almacenB->id,
            'movement_date' => now(),
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL . "/{$mov->id}/approve", [], $this->headersEmpresa())->assertStatus(403);
    }

    public function test_approve_transferencia_hacia_almacen_visible_no_es_403(): void
    {
        $this->darStock($this->almacenB, $this->insumo, 50);
        $mov = InventoryMovement::create([
            'document_number' => 'TR-TEST-1',
            'movement_type_id' => $this->tipoTransferencia->id,
            'source_entity_id' => $this->almacenB->id,
            'destination_entity_id' => $this->almacenA->id,
            'movement_date' => now(),
            'status' => 'pending',
            'metadata' => ['stock_deducted_at_creation' => true],
        ]);
        InventoryMovementDetail::create([
            'movement_id' => $mov->id,
            'product_id' => $this->insumo->id,
            'quantity' => 5,
            'base_quantity' => 5,
            'conversion_factor' => 1,
            'unit_cost' => 10,
            'total_cost' => 50,
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $response = $this->postJson(self::URL . "/{$mov->id}/approve", [], $this->headersEmpresa());

        $this->assertNotEquals(403, $response->status());
    }

    public function test_cancel_entrada_en_almacen_no_asignado_da_403(): void
    {
        $mov = InventoryMovement::create([
            'document_number' => 'IN-TEST-C',
            'movement_type_id' => $this->tipoEntrada->id,
            'destination_entity_id' => $this->almacenB->id,
            'movement_date' => now(),
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->postJson(self::URL . "/{$mov->id}/cancel", [], $this->headersEmpresa())->assertStatus(403);
    }

    public function test_cancel_transferencia_desde_almacen_visible_no_es_403(): void
    {
        $mov = InventoryMovement::create([
            'document_number' => 'TR-TEST-2',
            'movement_type_id' => $this->tipoTransferencia->id,
            'source_entity_id' => $this->almacenA->id,
            'destination_entity_id' => $this->almacenB->id,
            'movement_date' => now(),
            'status' => 'pending',
            'metadata' => ['stock_deducted_at_creation' => true],
        ]);
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $response = $this->postJson(self::URL . "/{$mov->id}/cancel", [], $this->headersEmpresa());

        $this->assertNotEquals(403, $response->status());
    }
}
