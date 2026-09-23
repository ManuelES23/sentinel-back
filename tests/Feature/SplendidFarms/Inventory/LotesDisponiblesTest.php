<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class LotesDisponiblesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private const URL = '/api/splendidfarms/inventario/operaciones/stock/lotes';

    public function test_devuelve_lotes_con_existencia_en_orden_fefo(): void
    {
        $this->setUpAlmacenFixtures();
        $this->darStock($this->almacenA, $this->insumo, 3, 'SIN-CAD');
        $this->darStock($this->almacenA, $this->insumo, 2, 'TARDE', now()->addYear()->toDateString());
        $this->darStock($this->almacenA, $this->insumo, 1, 'PRONTO', now()->addDays(5)->toDateString());
        $this->darStock($this->almacenA, $this->insumo, 4, 'VENCIDO', now()->subDays(2)->toDateString());
        $this->darStock($this->almacenA, $this->insumo, 0, 'AGOTADO', now()->addDay()->toDateString());
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $response = $this->getJson(self::URL . "?product_id={$this->insumo->id}&entity_id={$this->almacenA->id}", $this->headersEmpresa());

        $response->assertOk();
        $lotes = collect($response->json('data'));
        $this->assertSame(['VENCIDO', 'PRONTO', 'TARDE', 'SIN-CAD'], $lotes->pluck('lot_number')->all());
        $this->assertTrue($lotes[0]['vencido']);
        $this->assertTrue($lotes[1]['por_caducar']);
        $this->assertFalse($lotes[2]['por_caducar']);
    }

    public function test_almacen_no_visible_da_403(): void
    {
        $this->setUpAlmacenFixtures();
        Sanctum::actingAs($this->crearUsuarioDeCampo([$this->almacenA]));

        $this->getJson(self::URL . "?product_id={$this->insumo->id}&entity_id={$this->almacenB->id}", $this->headersEmpresa())
            ->assertForbidden();
    }
}
