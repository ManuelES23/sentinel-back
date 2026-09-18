<?php

namespace Tests\Feature\Compras;

use App\Models\RequisicionCotizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class CotizacionesTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private $compras;
    private $req;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
        $this->compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($this->compras);
        $this->otorgarVerTodos($this->compras);
        $this->req = $this->crearRequisicion($this->crearUsuarioDeCampo([$this->almacenA]), $this->almacenA, 'enviada', 10);
    }

    private function url(string $sufijo = ''): string
    {
        return "/api/splendidfarms/operacion-agricola/agricola/requisiciones/{$this->req->id}/cotizaciones{$sufijo}";
    }

    private function cotizar(int $supplierId, float $precio, bool $disponible = true): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->compras)->postJson($this->url(), [
            'supplier_id' => $supplierId,
            'fecha' => now()->toDateString(),
            'dias_entrega' => 5,
            'detalles' => [[
                'requisicion_detalle_id' => $this->req->detalles->first()->id,
                'disponible' => $disponible, 'cantidad' => 10, 'precio_unitario' => $precio, 'tax_rate' => 16,
            ]],
        ], $this->headersEmpresa());
    }

    public function test_registra_cotizacion_calcula_totales_y_pasa_a_en_cotizacion(): void
    {
        $this->cotizar($this->proveedor->id, 100)->assertCreated();

        $cot = RequisicionCotizacion::first();
        $this->assertEquals(1000, (float) $cot->subtotal);
        $this->assertEquals(160, (float) $cot->iva);
        $this->assertEquals(1160, (float) $cot->total);
        $this->assertSame('en_cotizacion', $this->req->fresh()->status);
    }

    public function test_validaciones(): void
    {
        $this->proveedor2->update(['is_active' => false]);
        $this->cotizar($this->proveedor2->id, 100)->assertStatus(422)->assertJsonValidationErrors(['supplier_id']);
        $this->cotizar($this->proveedor->id, 100, false)->assertStatus(422)->assertJsonValidationErrors(['detalles']);

        $otra = $this->crearRequisicion($this->compras, $this->almacenA, 'enviada');
        $this->actingAs($this->compras)->postJson($this->url(), [
            'supplier_id' => $this->proveedor->id, 'fecha' => now()->toDateString(),
            'detalles' => [['requisicion_detalle_id' => $otra->detalles->first()->id, 'disponible' => true, 'cantidad' => 1, 'precio_unitario' => 1]],
        ], $this->headersEmpresa())->assertStatus(422);

        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->actingAs($ing)->getJson($this->url(), $this->headersEmpresa())->assertForbidden();
    }

    public function test_ganadora_unica_y_eliminarla_regresa_a_en_cotizacion(): void
    {
        $a = $this->cotizar($this->proveedor->id, 100)->json('data.id');
        $b = $this->cotizar($this->proveedor2->id, 90)->json('data.id');

        $this->actingAs($this->compras)->postJson($this->url("/$a/ganadora"), [], $this->headersEmpresa())->assertOk();
        $this->actingAs($this->compras)->postJson($this->url("/$b/ganadora"), [], $this->headersEmpresa())->assertOk();

        $this->assertSame([$b], RequisicionCotizacion::where('es_ganadora', true)->pluck('id')->all());
        $this->assertSame('cotizada', $this->req->fresh()->status);

        $this->actingAs($this->compras)->deleteJson($this->url("/$b"), [], $this->headersEmpresa())->assertOk();
        $this->assertSame('en_cotizacion', $this->req->fresh()->status);
        $this->assertCount(1, $this->actingAs($this->compras)->getJson($this->url(), $this->headersEmpresa())->json('data'));
    }

    public function test_bloqueada_tras_generar_orden_y_archivo_pdf(): void
    {
        Storage::fake('public');
        $id = $this->cotizar($this->proveedor->id, 100)->json('data.id');

        $this->actingAs($this->compras)->post($this->url("/$id/archivo"), [
            'archivo' => UploadedFile::fake()->create('cot.pdf', 200, 'application/pdf'),
        ], $this->headersEmpresa())->assertOk();
        $path = RequisicionCotizacion::find($id)->archivo_path;
        Storage::disk('public')->assertExists($path);

        $this->req->update(['status' => 'orden_generada']);
        $this->cotizar($this->proveedor2->id, 90)->assertStatus(422);
        $this->actingAs($this->compras)->deleteJson($this->url("/$id"), [], $this->headersEmpresa())->assertStatus(422);
    }
}
