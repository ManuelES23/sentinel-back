<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetReceiptUnit;
use App\Services\ActivosFijos\AltaActivosDesdeCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesAltaCompraFixtures;
use Tests\TestCase;

class AltaCompraServicioTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAltaCompraFixtures;

    private $rec;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAltaCompraFixtures();
        $this->rec = $this->recepcionConfirmada(3, 'L-100');
        $this->ids = FixedAssetReceiptUnit::where('purchase_receipt_id', $this->rec->id)->orderBy('unit_number')->pluck('id')->all();
    }

    private function servicio(): AltaActivosDesdeCompra
    {
        return app(AltaActivosDesdeCompra::class);
    }

    private function filas(array $series = []): array
    {
        return collect($this->ids)->map(fn ($id, $i) => ['id' => $id, 'serial_number' => $series[$i] ?? null])->all();
    }

    public function test_alta_crea_un_activo_por_unidad_con_los_datos_de_la_compra(): void
    {
        $usuario = $this->usuarioActivos();

        $activos = $this->servicio()->alta(
            $this->filas(['SN-1', 'SN-2', null]),
            ['category_id' => $this->tipoActivo->id, 'subcategory_id' => $this->subtipoActivo->id, 'model' => 'Latitude'],
            [$this->empresa->id],
            $usuario,
        );

        $this->assertCount(3, $activos);
        $this->assertSame(['SF-AF-000001', 'SF-AF-000002', 'SF-AF-000003'], $activos->pluck('code')->all());

        $primero = $activos->first()->fresh();
        $this->assertSame($this->insumo->name, $primero->name);
        $this->assertSame($this->empresa->id, $primero->enterprise_id);
        $this->assertEquals(100, (float) $primero->purchase_value);
        $this->assertSame($this->rec->supplier_id, $primero->supplier_id);
        $this->assertSame($this->rec->id, $primero->purchase_receipt_id);
        $this->assertSame($this->almacenA->id, $primero->entity_id);
        $this->assertSame($this->sucursal->id, $primero->branch_id);
        $this->assertSame($this->rec->receipt_date->toDateString(), $primero->purchase_date->toDateString());
        $this->assertSame('SN-1', $primero->serial_number);
        $this->assertSame('Latitude', $primero->model);
        $this->assertNull($activos->last()->serial_number);

        $unidades = FixedAssetReceiptUnit::whereIn('id', $this->ids)->orderBy('unit_number')->get();
        $this->assertSame(['registered', 'registered', 'registered'], $unidades->pluck('status')->all());
        $this->assertSame($activos->pluck('id')->all(), $unidades->pluck('fixed_asset_id')->all());
        $this->assertSame($usuario->id, $unidades->first()->registered_by);
        $this->assertNotNull($unidades->first()->registered_at);
        $this->assertSame('SN-1', $unidades->first()->serial_number);
    }

    public function test_una_unidad_que_ya_no_esta_pendiente_rechaza_todo_el_lote(): void
    {
        FixedAssetReceiptUnit::whereKey($this->ids[1])->update(['status' => 'discarded', 'discarded_reason' => 'x']);

        try {
            $this->servicio()->alta($this->filas(), ['category_id' => $this->tipoActivo->id], [$this->empresa->id], $this->usuarioActivos());
            $this->fail('Debió lanzar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('units', $e->errors());
        }

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(0, FixedAssetReceiptUnit::whereIn('id', $this->ids)->where('status', 'registered')->count());
    }

    public function test_unidades_de_una_empresa_no_visible_dan_404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->servicio()->alta($this->filas(), ['category_id' => $this->tipoActivo->id], [$this->corporativo->id], $this->usuarioActivos());
    }

    public function test_relaciones_invalidas_no_dejan_nada_a_medias(): void
    {
        try {
            // El subtipo no es hijo del tipo indicado.
            $this->servicio()->alta($this->filas(), [
                'category_id' => $this->subtipoActivo->id,
            ], [$this->empresa->id], $this->usuarioActivos());
            $this->fail('Debió lanzar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_id', $e->errors());
        }

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(3, FixedAssetReceiptUnit::whereIn('id', $this->ids)->where('status', 'pending')->count());
    }

    public function test_una_empresa_sin_prefijo_hace_rollback(): void
    {
        $this->empresa->update(['asset_code_prefix' => null]);

        try {
            $this->servicio()->alta($this->filas(), ['category_id' => $this->tipoActivo->id], [$this->empresa->id], $this->usuarioActivos());
            $this->fail('Debió lanzar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertSame(0, FixedAsset::count());
        $this->assertSame(3, FixedAssetReceiptUnit::whereIn('id', $this->ids)->where('status', 'pending')->count());
    }

    public function test_descartar_marca_las_unidades_con_el_motivo(): void
    {
        $usuario = $this->usuarioActivos();

        $descartadas = $this->servicio()->descartar([$this->ids[0], $this->ids[1]], 'Es consumible, no activo', [$this->empresa->id], $usuario);

        $this->assertCount(2, $descartadas);
        $u = FixedAssetReceiptUnit::find($this->ids[0]);
        $this->assertSame('discarded', $u->status);
        $this->assertSame('Es consumible, no activo', $u->discarded_reason);
        $this->assertSame($usuario->id, $u->discarded_by);
        $this->assertNotNull($u->discarded_at);
        $this->assertSame('pending', FixedAssetReceiptUnit::find($this->ids[2])->status);
    }

    public function test_no_se_descarta_una_unidad_ya_registrada(): void
    {
        $this->servicio()->alta([$this->filas()[0]], ['category_id' => $this->tipoActivo->id], [$this->empresa->id], $this->usuarioActivos());

        $this->expectException(ValidationException::class);
        $this->servicio()->descartar([$this->ids[0]], 'Error', [$this->empresa->id], $this->usuarioActivos());
    }
}
