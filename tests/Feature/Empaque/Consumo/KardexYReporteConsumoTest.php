<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\ProduccionEmpaque;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * El kardex y el reporte «Gasto de producción» leen el registro de consumos: las filas de producción
 * (sin movimiento) salen con su fecha, documento y descripción, y el reporte suma solo lo vigente.
 * Los pallets congelados (anteriores al registro) conservan el cálculo teórico.
 */
class KardexYReporteConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const PRODUCCION = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const REPORTES = '/api/splendidfarms/inventario/reportes';
    private const COSTO = 2.0;

    private int $procesoId;
    private int $consecutivoApi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        // Admin por rol: entra a la empresa y ve todos los almacenes en los reportes de inventario.
        $this->actingUser->update(['role' => 'admin']);
        Sanctum::actingAs($this->actingUser);
        $this->procesoId = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso']->id;
    }

    /** Cada petición HTTP empieza con el servicio Scoped vacío, como en producción. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    // ---- helpers ----

    private function surtirTodo(): void
    {
        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna] as $insumo) {
            $this->darExistencia($insumo, 1000.0, self::COSTO);
        }
    }

    private function producir(array $datos = []): ProduccionEmpaque
    {
        $n = ++$this->consecutivoApi;
        $response = $this->postJson(self::PRODUCCION, array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => sprintf('API-%04d', $n),
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => 240,
            'peso_neto_kg' => 1000,
            'clasificacion' => 'convencional',
            'is_cola' => false,
        ], $datos));
        $response->assertCreated();

        return ProduccionEmpaque::findOrFail($response->json('data.id'));
    }

    private function gasto(array $filtros = []): TestResponse
    {
        return $this->reporte('/gasto-produccion?'.http_build_query($filtros));
    }

    private function reporte(string $ruta): TestResponse
    {
        return $this->getJson(self::REPORTES.$ruta, ['X-Enterprise-Slug' => 'splendidfarms']);
    }

    /** @return array{consumed_quantity: float, consumed_cost: float}|null */
    private function filaGasto(TestResponse $response, Product $insumo): ?array
    {
        $filas = collect($response->json('data.rows'))->where('product_id', $insumo->id);

        return $filas->isEmpty() ? null : [
            'consumed_quantity' => round((float) $filas->sum('consumed_quantity'), 4),
            'consumed_cost' => round((float) $filas->sum('consumed_cost'), 4),
        ];
    }

    private function assertCapasConsistentes(): void
    {
        $this->assertSame(0, Artisan::call('inventario:verificar-capas'), Artisan::output());
    }

    // ---- kardex ----

    public function test_kardex_muestra_consumo_de_produccion_con_fechas(): void
    {
        $this->surtirTodo();
        $pallet = $this->producir(['fecha_produccion' => '2026-02-03']);

        $url = "/kardex/{$this->cajaB15->id}";
        $respuesta = $this->reporte($url)->assertOk();
        $entradas = collect($respuesta->json('data.entries'));
        $this->assertCount(1, $entradas);

        $fila = $entradas->first();
        $this->assertArrayHasKey('movement', $fila);
        $this->assertNull($fila['movement']);
        $this->assertSame($pallet->numero_pallet, $fila['document_number']);
        $this->assertStringStartsWith("Producción empaque · pallet {$pallet->numero_pallet}", $fila['description']);
        $this->assertSame('decrease', $fila['transaction_type']);
        $this->assertEqualsWithDelta(240, (float) $fila['quantity'], 0.0001);
        $this->assertEqualsWithDelta(240 * self::COSTO, (float) $fila['total_cost'], 0.0001);
        $this->assertStringStartsWith('2026-02-03', (string) $fila['movement_date']);
        $this->assertEqualsWithDelta(-240, (float) $respuesta->json('data.final_balance'), 0.0001);

        // La fila entra en el rango por su propia fecha...
        $dentro = $this->reporte($url.'?date_from=2026-02-03&date_to=2026-02-03')->assertOk();
        $this->assertCount(1, $dentro->json('data.entries'));
        $this->assertEqualsWithDelta(0, (float) $dentro->json('data.initial_balance'), 0.0001);

        // ...y si el rango empieza después, pasa al saldo inicial.
        $despues = $this->reporte($url.'?date_from=2026-02-04')->assertOk();
        $this->assertCount(0, $despues->json('data.entries'));
        $this->assertEqualsWithDelta(-240, (float) $despues->json('data.initial_balance'), 0.0001);

        $antes = $this->reporte($url.'?date_to=2026-02-02')->assertOk();
        $this->assertCount(0, $antes->json('data.entries'));

        // El reporte de movimientos también la incluye y la filtra por fecha.
        $movimientos = $this->reporte("/movimientos?product_id={$this->cajaB15->id}&date_from=2026-02-03&date_to=2026-02-03")->assertOk();
        $this->assertCount(1, $movimientos->json('data.data'));
        $this->assertNull($movimientos->json('data.data.0.movement'));
        $this->assertSame($pallet->numero_pallet, $movimientos->json('data.data.0.document_number'));
        $fuera = $this->reporte("/movimientos?product_id={$this->cajaB15->id}&date_from=2026-02-04")->assertOk();
        $this->assertCount(0, $fuera->json('data.data'));

        $this->assertCapasConsistentes();
    }

    // ---- reporte de consumo ----

    public function test_reporte_consumo_usa_lo_vigente(): void
    {
        $this->surtirTodo();
        $pallet = $this->producir(['total_cajas' => 240]);

        $completo = $this->gasto()->assertOk();
        $this->assertEqualsWithDelta(240, $this->filaGasto($completo, $this->cajaB15)['consumed_quantity'], 0.0001);
        $this->assertEqualsWithDelta(240 * self::COSTO, $this->filaGasto($completo, $this->cajaB15)['consumed_cost'], 0.0001);

        // Se devuelven 40 al corregir las cajas: el reporte baja a lo vigente, con costo proporcional.
        $this->putJson(self::PRODUCCION."/{$pallet->id}", ['total_cajas' => 200])->assertOk();

        $vigente = $this->gasto()->assertOk();
        $caja = $this->filaGasto($vigente, $this->cajaB15);
        $this->assertEqualsWithDelta(200, $caja['consumed_quantity'], 0.0001);
        $this->assertEqualsWithDelta(200 * self::COSTO, $caja['consumed_cost'], 0.0001);
        $this->assertEqualsWithDelta(40, $this->filaGasto($vigente, $this->fleje)['consumed_quantity'], 0.0001);

        // Filtros: por producto y por fecha (la de la producción).
        $this->assertCount(1, $this->gasto(['product_id' => $this->cajaB15->id])->json('data.rows'));
        $this->assertSame([], $this->gasto(['date_from' => '2026-02-04'])->json('data.rows'));
        $this->assertSame([], $this->gasto(['date_to' => '2026-02-02'])->json('data.rows'));
        $this->assertNotNull($this->filaGasto($this->gasto(['date_from' => '2026-02-03', 'date_to' => '2026-02-03']), $this->cajaB15));

        // Un pallet borrado devuelve todo: ya no cuenta.
        $this->deleteJson(self::PRODUCCION."/{$pallet->id}")->assertOk();
        $this->assertSame([], $this->gasto()->json('data.rows'));

        $this->assertCapasConsistentes();
    }

    public function test_reporte_consumo_pallet_congelado_sigue_teorico(): void
    {
        $this->surtirTodo();
        // Anterior al registro: sin renglones, con receta y cajas. Su consumo se recalcula con la receta (costo de receta = 1).
        $congelado = $this->crearPallet(['total_cajas' => 240, 'fecha_produccion' => '2026-02-03']);
        $this->assertTrue($congelado->congelado());
        $this->crearEntrada($congelado, 1, 120);

        $soloCongelado = $this->gasto()->assertOk();
        $caja = $this->filaGasto($soloCongelado, $this->cajaB15);
        $this->assertEqualsWithDelta(120, $caja['consumed_quantity'], 0.0001);
        $this->assertEqualsWithDelta(120 * 1.0, $caja['consumed_cost'], 0.0001);

        // Un pallet nuevo (con registro) suma lo registrado a costo PEPS, sin que su receta se cuente dos veces.
        $this->producir(['total_cajas' => 240]);

        $ambos = $this->gasto()->assertOk();
        $caja = $this->filaGasto($ambos, $this->cajaB15);
        $this->assertEqualsWithDelta(120 + 240, $caja['consumed_quantity'], 0.0001);
        $this->assertEqualsWithDelta(120 * 1.0 + 240 * self::COSTO, $caja['consumed_cost'], 0.0001);
        $etiqueta = $this->filaGasto($ambos, $this->etiqueta);
        $this->assertEqualsWithDelta(121 + 242, $etiqueta['consumed_quantity'], 0.0001);

        // El stock usa la misma fuente.
        $stock = collect($this->reporte('/stock')->assertOk()->json('data.stock'))
            ->firstWhere('product_id', $this->cajaB15->id);
        $this->assertEqualsWithDelta(120 + 240, (float) $stock['production_consumed_quantity'], 0.0001);

        // Existencias ya no muestra el gasto: con sin_consumo=1 no se calcula y vale 0.
        $sinGasto = collect($this->reporte('/stock?sin_consumo=1')->assertOk()->json('data.stock'))
            ->firstWhere('product_id', $this->cajaB15->id);
        $this->assertNotNull($sinGasto);
        $this->assertEqualsWithDelta(0.0, (float) $sinGasto['production_consumed_quantity'], 0.0001);

        $this->assertCapasConsistentes();
    }
}
