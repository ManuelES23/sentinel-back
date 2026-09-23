<?php

namespace Tests\Feature\Compras;

use App\Models\Product;
use App\Models\RequisicionCampo;
use App\Models\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class RequisicionesCampoTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    private const URL = '/api/splendidfarms/operacion-agricola/agricola/requisiciones';
    private const COMPRAS_URL = '/api/splendidfarms/administration/compras/requisiciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    private function payload(int $almacenId, ?int $productId = null): array
    {
        return [
            'temporada_id' => $this->temporada->id,
            'almacen_id' => $almacenId,
            'fecha_solicitud' => now()->toDateString(),
            'prioridad' => 'media',
            'detalles' => [['product_id' => $productId ?? $this->insumo->id, 'cantidad' => 20]],
        ];
    }

    public function test_crea_con_empresa_almacen_y_nombre_del_catalogo(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);

        $res = $this->actingAs($ing)->postJson(self::URL, $this->payload($this->almacenA->id), $this->headersEmpresa());

        $res->assertCreated();
        $req = RequisicionCampo::first();
        $this->assertSame($this->empresa->id, $req->enterprise_id);
        $this->assertSame($this->almacenA->id, $req->almacen_id);
        $this->assertSame('Clorotalonil 720', $req->detalles->first()->nombre_producto);
        $this->assertSame($this->unidad->id, $req->detalles->first()->unit_id);
    }

    public function test_no_crea_en_almacen_ajeno(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);

        $this->actingAs($ing)->postJson(self::URL, $this->payload($this->almacenB->id), $this->headersEmpresa())
            ->assertForbidden();
    }

    public function test_rechaza_articulo_fuera_del_catalogo(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $ajeno = Product::create(['code' => 'X-1', 'name' => 'Ajeno', 'unit_id' => $this->unidad->id, 'product_type' => 'consumable']);

        $this->actingAs($ing)->postJson(self::URL, $this->payload($this->almacenA->id, $ajeno->id), $this->headersEmpresa())
            ->assertStatus(422)->assertJsonValidationErrors(['detalles.0.product_id']);
    }

    public function test_lista_solo_almacenes_visibles_y_bandeja_de_compras(): void
    {
        $ingA = $this->crearUsuarioDeCampo([$this->almacenA]);
        $reqA = $this->crearRequisicion($ingA, $this->almacenA, 'enviada');
        $this->crearRequisicion($ingA, $this->almacenB, 'enviada');

        $ids = collect($this->actingAs($ingA)->getJson(self::URL, $this->headersEmpresa())->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$reqA->id], $ids);

        $this->actingAs($ingA)->getJson(self::URL . '?bandeja=por_cotizar', $this->headersEmpresa())->assertForbidden();

        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($compras);
        $this->otorgarVerTodos($compras);
        $this->assertCount(2, $this->actingAs($compras)->getJson(self::URL . '?bandeja=por_cotizar', $this->headersEmpresa())->json('data'));
    }

    public function test_enviar_va_directo_a_compras_y_avisa(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($compras);
        $req = $this->crearRequisicion($ing);

        $this->actingAs($ing)->postJson(self::URL . "/{$req->id}/enviar", [], $this->headersEmpresa())->assertOk();

        $this->assertSame('enviada', $req->fresh()->status);
        $this->assertNotNull($req->fresh()->enviada_at);
        $this->assertSame(1, SystemNotification::where('user_id', $compras->id)->count());
        $this->actingAs($ing)->postJson(self::URL . "/{$req->id}/aprobar", [], $this->headersEmpresa())->assertStatus(410);
    }

    public function test_solo_compras_rechaza_y_al_editar_vuelve_a_borrador(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $req = $this->crearRequisicion($ing, $this->almacenA, 'enviada');

        $this->actingAs($ing)->postJson(self::COMPRAS_URL . "/{$req->id}/rechazar", ['notas_rechazo' => 'x'], $this->headersEmpresa())->assertForbidden();

        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarCotizar($compras);
        $this->otorgarVerTodos($compras);
        $this->actingAs($compras)->postJson(self::COMPRAS_URL . "/{$req->id}/rechazar", ['notas_rechazo' => 'Falta dosis'], $this->headersEmpresa())->assertOk();
        $this->assertSame('rechazada', $req->fresh()->status);

        $this->actingAs($ing)->putJson(self::URL . "/{$req->id}", $this->payload($this->almacenA->id), $this->headersEmpresa())->assertOk();
        $this->assertSame('borrador', $req->fresh()->status);
    }

    public function test_contexto_stock_proveedores_y_seguimiento(): void
    {
        $ing = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->darStock($this->almacenA, $this->insumo, 7, 'L-1', '2027-01-31');
        $req = $this->crearRequisicion($ing, $this->almacenA, 'enviada');

        $ctx = $this->actingAs($ing)->getJson(self::URL . '/contexto', $this->headersEmpresa())->assertOk();
        $this->assertSame([$this->almacenA->id], collect($ctx->json('data.almacenes'))->pluck('id')->all());
        $this->assertFalse($ctx->json('data.puede_cotizar'));

        $stock = $this->actingAs($ing)->getJson(self::URL . "/stock?almacen_id={$this->almacenA->id}&product_ids[]={$this->insumo->id}", $this->headersEmpresa());
        $this->assertEquals(7, $stock->assertOk()->json("data.{$this->insumo->id}"));
        $this->actingAs($ing)->getJson(self::URL . "/stock?almacen_id={$this->almacenB->id}&product_ids[]={$this->insumo->id}", $this->headersEmpresa())->assertForbidden();

        $this->actingAs($ing)->getJson(self::COMPRAS_URL . '/proveedores', $this->headersEmpresa())->assertOk()->assertJsonFragment(['business_name' => 'Agroquímicos del Norte']);
        $this->actingAs($ing)->getJson(self::URL . '/productos?search=cloro', $this->headersEmpresa())->assertOk()->assertJsonFragment(['id' => $this->insumo->id]);

        $tipos = collect($this->actingAs($ing)->getJson(self::URL . "/{$req->id}/seguimiento", $this->headersEmpresa())->assertOk()->json('data.eventos'))->pluck('tipo')->all();
        $this->assertSame(['creada', 'enviada'], $tipos);
    }
}
