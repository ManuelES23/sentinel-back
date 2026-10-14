<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAssetDisposal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class SalidaEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
    }

    public function test_la_tabla_y_sus_columnas_existen(): void
    {
        $this->assertTrue(Schema::hasTable('fixed_asset_disposals'));

        foreach ([
            'enterprise_id', 'fixed_asset_id', 'type', 'disposal_date', 'reason', 'authorized_by_name',
            'previous_status', 'asset_snapshot', 'purchase_value', 'accumulated_depreciation', 'book_value',
            'sale_price', 'result_amount', 'buyer_name', 'buyer_address', 'buyer_identification',
            'payment_method', 'place', 'claim_reference', 'destination_enterprise_id', 'destination_asset_id',
            'signed_document_path', 'signed_uploaded_at', 'signed_uploaded_by', 'created_by', 'created_by_name',
            'reversed_at', 'reversed_by', 'reversal_reason',
        ] as $columna) {
            $this->assertTrue(Schema::hasColumn('fixed_asset_disposals', $columna), $columna);
        }
    }

    public function test_el_modelo_castea_los_valores_y_oculta_la_ruta_privada(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->crearSalidaDirecta($activo, ['signed_document_path' => 'fixed-asset-disposals/1/carta.pdf']);

        $arreglo = $salida->fresh()->toArray();

        $this->assertArrayNotHasKey('signed_document_path', $arreglo);
        $this->assertTrue($arreglo['tiene_carta_firmada']);
        $this->assertTrue($arreglo['vigente']);
        $this->assertSame('2026-05-20', $arreglo['disposal_date']);
        $this->assertSame('11100.00', $arreglo['book_value']);
        $this->assertSame(['code' => $activo->code, 'name' => $activo->name], $arreglo['asset_snapshot']);
    }

    public function test_salida_vigente_ignora_las_revertidas_y_el_historial_las_trae_todas(): void
    {
        $activo = $this->crearActivoDepreciable();
        $vieja = $this->crearSalidaDirecta($activo, ['reversed_at' => now(), 'reversal_reason' => 'Error']);
        $actual = $this->crearSalidaDirecta($activo);

        $this->assertSame($actual->id, $activo->fresh()->salidaVigente->id);
        $this->assertSame([$actual->id, $vieja->id], $activo->fresh()->salidas->pluck('id')->all());
        $this->assertFalse($vieja->fresh()->vigente);
        $this->assertSame([$actual->id], FixedAssetDisposal::vigentes()->pluck('id')->all());
    }

    public function test_el_tipo_se_limita_a_la_lista(): void
    {
        $activo = $this->crearActivoDepreciable();

        $this->expectException(QueryException::class);
        $this->crearSalidaDirecta($activo, ['type' => 'regalo']);
    }
}
