<?php

namespace Tests\Unit\Inventory;

use App\Models\CapaCosto;
use App\Models\ConsumoCapa;
use App\Models\InventoryStock;
use App\Services\Inventory\CapasCosto;
use App\Support\Inventario\Cubeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class CapasCostoTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures;

    private CapasCosto $motor;
    private Cubeta $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->motor = app(CapasCosto::class);
        $this->c = new Cubeta($this->insumo->id, $this->almacenA->id);
    }

    private function entra(float $cant, float $costo, string $fecha): void
    {
        $this->motor->entrar($this->c, $cant, $costo, \Carbon\Carbon::parse($fecha), CapaCosto::ORIGEN_COMPRA);
        InventoryStock::ajustarCantidad($this->c, $cant);
    }

    private function sale(float $cant, ?string $ref = 'prueba'): \App\Support\Inventario\ResultadoMovimiento
    {
        $r = $this->motor->salir($this->c, $cant, null, null, $ref);
        InventoryStock::ajustarCantidad($this->c, -$r->cantidad);

        return $r;
    }

    private function fila(): InventoryStock
    {
        return InventoryStock::where('product_id', $this->insumo->id)->where('entity_id', $this->almacenA->id)->firstOrFail();
    }

    public function test_salida_cruza_capas_en_orden_peps(): void
    {
        $this->entra(8, 100, '2026-10-01');
        $this->entra(50, 120, '2026-10-05');

        $r = $this->sale(10);

        $this->assertEqualsWithDelta(1040.0, $r->importe, 0.0001);
        $fila = $this->fila();
        $this->assertEqualsWithDelta(48, (float) $fila->quantity, 0.0001);
        $this->assertEqualsWithDelta(5760, (float) $fila->total_cost, 0.0001);
        $this->assertEqualsWithDelta(120, (float) $fila->unit_cost, 0.0001);
        $this->assertSame(2, ConsumoCapa::where('referencia', 'prueba')->count());
    }

    public function test_fila_muestra_valor_de_capas_entre_cantidad(): void
    {
        $this->entra(8, 100, '2026-10-01');
        $this->entra(50, 120, '2026-10-05');

        $this->assertEqualsWithDelta(6800, (float) $this->fila()->total_cost, 0.0001);
        $this->assertEqualsWithDelta(117.2414, (float) $this->fila()->unit_cost, 0.0001);
    }

    public function test_salida_sin_existencia_suficiente_lanza_422(): void
    {
        $this->entra(5, 100, '2026-10-01');

        try {
            $this->sale(6);
            $this->fail('Debió lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuficiente de Clorotalonil 720 en Almacén Campo A: hay 5, se piden 6.', $e->errors()['stock'][0]);
        }
        $this->assertEqualsWithDelta(5, (float) CapaCosto::sum('cantidad_restante'), 0.0001);
    }

    public function test_faltante_con_negativo_y_entrada_que_lo_cubre(): void
    {
        $this->entra(5, 100, '2026-10-01');
        $r = $this->motor->salir($this->c, 8, null, null, 'empaque', true);
        InventoryStock::ajustarCantidad($this->c, -8, null, null, true);

        $this->assertEqualsWithDelta(800, $r->importe, 0.0001); // 5×100 + 3×100 (último costo)
        $this->assertEqualsWithDelta(-3, (float) CapaCosto::where('origen', CapaCosto::ORIGEN_FALTANTE)->value('cantidad_restante'), 0.0001);

        $this->entra(10, 120, '2026-10-06');

        $this->assertEqualsWithDelta(0, (float) CapaCosto::where('origen', CapaCosto::ORIGEN_FALTANTE)->value('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(7, (float) CapaCosto::where('origen', CapaCosto::ORIGEN_COMPRA)->where('unit_cost', 120)->value('cantidad_restante'), 0.0001);
        $this->assertEqualsWithDelta(7, (float) $this->fila()->quantity, 0.0001);
    }

    public function test_entrada_con_costo_cero_usa_el_ultimo_costo(): void
    {
        $this->entra(5, 100, '2026-10-01');
        $this->entra(5, 0, '2026-10-02');

        $this->assertEqualsWithDelta(100, (float) CapaCosto::latest('id')->value('unit_cost'), 0.0001);
    }

    public function test_conciliar_crea_capa_inicial_para_fila_sin_capas(): void
    {
        $this->darStock($this->almacenA, $this->insumo, 12); // fila sin capas, costo 10

        $r = $this->sale(2);

        $this->assertEqualsWithDelta(20, $r->importe, 0.0001);
        $this->assertSame(1, CapaCosto::where('origen', CapaCosto::ORIGEN_INICIAL)->count());
        $this->assertEqualsWithDelta(10, (float) $this->fila()->quantity, 0.0001);
    }

    public function test_conciliar_recorta_capas_si_la_fila_tiene_menos(): void
    {
        $this->entra(10, 100, '2026-10-01');
        $this->fila()->update(['quantity' => 4]); // escrita por fuera del motor

        $this->motor->conciliar($this->c);

        $this->assertEqualsWithDelta(4, (float) CapaCosto::sum('cantidad_restante'), 0.0001);
    }

    public function test_salida_respeta_bloqueo_de_activos_fijos(): void
    {
        // Reusa la regla de UnidadesVigentes: si hay unidades vigentes, no deja salir por debajo.
        $this->entra(2, 100, '2026-10-01');
        $this->mock(\App\Services\ActivosFijos\UnidadesVigentes::class, function ($m) {
            $m->shouldReceive('exigirRespaldo')->once()->andThrow(ValidationException::withMessages(['stock' => 'respaldo']));
        });

        $this->expectException(ValidationException::class);
        app(CapasCosto::class)->salir($this->c, 1);
    }

    public function test_fecha_mas_vieja(): void
    {
        $this->entra(1, 100, '2026-10-03');
        $this->entra(1, 100, '2026-10-01');

        $this->assertSame('2026-10-01', $this->motor->fechaMasVieja($this->c)->toDateString());
    }
}
