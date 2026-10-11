<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetReceiptUnit;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class AltaCompraEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    /** Recepción y renglón mínimos, creados de forma perezosa; PHPUnit usa una instancia nueva por prueba. */
    private ?int $recepcionId = null;
    private ?int $renglonId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
    }

    public function test_las_columnas_nuevas_existen(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'is_fixed_asset'));
        $this->assertTrue(Schema::hasColumns('fixed_assets', ['supplier_id', 'purchase_receipt_id']));
        $this->assertTrue(Schema::hasColumns('fixed_asset_receipt_units', [
            'enterprise_id', 'purchase_receipt_id', 'purchase_receipt_detail_id', 'product_id', 'unit_number',
            'lot_number', 'unit_cost', 'serial_number', 'status', 'fixed_asset_id', 'registered_by', 'registered_at',
            'discarded_reason', 'discarded_by', 'discarded_at',
        ]));
    }

    public function test_el_producto_nace_sin_marca_y_la_marca_se_guarda(): void
    {
        $this->assertFalse((bool) $this->insumo->fresh()->is_fixed_asset);

        $this->insumo->update(['is_fixed_asset' => true]);

        $this->assertTrue($this->insumo->fresh()->is_fixed_asset);
    }

    public function test_el_formulario_de_producto_guarda_la_marca(): void
    {
        Sanctum::actingAs($this->crearAdmin());

        $this->putJson(
            "/api/splendidfarms/inventario/catalogos/articulos/{$this->insumo->id}",
            ['is_fixed_asset' => true],
            $this->headersEmpresa(),
        )->assertOk();

        $this->assertTrue($this->insumo->fresh()->is_fixed_asset);

        $this->putJson(
            "/api/splendidfarms/inventario/catalogos/articulos/{$this->insumo->id}",
            ['is_fixed_asset' => 'no-es-booleano'],
            $this->headersEmpresa(),
        )->assertStatus(422);
    }

    public function test_no_se_repite_el_numero_de_unidad_en_un_renglon(): void
    {
        $this->expectException(QueryException::class);

        // Se arman dos filas con el mismo renglón y número; la segunda choca con el índice único.
        $datos = $this->datosUnidad();
        FixedAssetReceiptUnit::create($datos);
        FixedAssetReceiptUnit::create($datos);
    }

    public function test_vigentes_cuenta_pendientes_y_registradas_con_activo_no_baja(): void
    {
        $pendiente = FixedAssetReceiptUnit::create($this->datosUnidad(['unit_number' => 1]));
        $descartada = FixedAssetReceiptUnit::create($this->datosUnidad(['unit_number' => 2, 'status' => 'discarded']));
        $conActivo = FixedAssetReceiptUnit::create($this->datosUnidad(['unit_number' => 3, 'status' => 'registered']));
        $conBaja = FixedAssetReceiptUnit::create($this->datosUnidad(['unit_number' => 4, 'status' => 'registered']));

        $conActivo->update(['fixed_asset_id' => $this->crearActivo('en_uso')->id]);
        $conBaja->update(['fixed_asset_id' => $this->crearActivo('baja')->id]);

        $ids = FixedAssetReceiptUnit::vigentes()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$pendiente->id, $conActivo->id], $ids);
        $this->assertNotContains($descartada->id, $ids);
    }

    /** Recepción y renglón mínimos para poder insertar unidades. */
    private function datosUnidad(array $extra = []): array
    {
        if ($this->recepcionId === null) {
            $proveedor = \App\Models\Supplier::create(['code' => 'PRV-ESQ', 'business_name' => 'Proveedor Esquema', 'is_active' => true]);
            $oc = \App\Models\PurchaseOrder::create([
                'order_number' => 'OC-ESQ-1', 'enterprise_id' => $this->empresa->id, 'supplier_id' => $proveedor->id,
                'order_date' => now()->toDateString(), 'status' => 'approved', 'currency_code' => 'MXN',
                'created_by' => \App\Models\User::factory()->create()->id,
            ]);
            $rec = \App\Models\PurchaseReceipt::create([
                'receipt_number' => 'REC-ESQ-1', 'purchase_order_id' => $oc->id, 'supplier_id' => $proveedor->id,
                'enterprise_id' => $this->empresa->id, 'almacen_id' => $this->almacenA->id,
                'receipt_date' => now()->toDateString(), 'status' => 'completed',
            ]);
            $det = $rec->details()->create([
                'product_id' => $this->insumo->id, 'quantity_ordered' => 3, 'quantity_received' => 3,
                'quantity_accepted' => 3, 'unit_cost' => 100,
            ]);
            $this->recepcionId = $rec->id;
            $this->renglonId = $det->id;
        }

        return array_merge([
            'enterprise_id' => $this->empresa->id,
            'purchase_receipt_id' => $this->recepcionId,
            'purchase_receipt_detail_id' => $this->renglonId,
            'product_id' => $this->insumo->id,
            'unit_number' => 1,
            'unit_cost' => 100,
            'status' => 'pending',
        ], $extra);
    }

    private function crearActivo(string $status): FixedAsset
    {
        $categoria = \App\Models\AssetCategory::firstOrCreate(
            ['code' => 'TAC-ESQ'],
            ['name' => 'Equipo', 'is_active' => true],
        );

        return FixedAsset::create([
            'enterprise_id' => $this->empresa->id,
            'code' => 'SF-AF-9' . random_int(10000, 99999),
            'name' => 'Activo de prueba',
            'category_id' => $categoria->id,
            'branch_id' => $this->sucursal->id,
            'entity_id' => $this->almacenA->id,
            'status' => $status,
            'is_active' => true,
        ]);
    }
}
