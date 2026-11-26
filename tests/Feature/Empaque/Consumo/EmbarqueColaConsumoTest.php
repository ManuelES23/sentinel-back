<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\EmbarqueEmpaque;
use App\Models\Product;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesDestinoEmbarque;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * Una cola embarcada sin cerrar consume sus insumos de cierre (esquineros, fleje, grapa) sin dejar de
 * ser cola; si el embarque se regresa, la cola abierta los devuelve y el pallet cerrado conserva los suyos.
 */
class EmbarqueColaConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures, CreatesDestinoEmbarque;

    private const PRODUCCION = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const EMBARQUES = '/api/splendidfarms/operacion-agricola/empaque/embarques';
    private const INICIAL = 1000.0;

    private int $procesoId;
    private int $consecutivoApi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
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

    /** @return Product[] */
    private function insumos(): array
    {
        return [$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna];
    }

    private function surtirTodo(): void
    {
        foreach ($this->insumos() as $insumo) {
            $this->darExistencia($insumo, self::INICIAL);
        }
    }

    private function assertNeto(float $esperado, Product $insumo): void
    {
        $this->assertEqualsWithDelta($esperado, round($this->existencia($insumo) - self::INICIAL, 4), 0.0001, $insumo->name);
    }

    /** Pallet con registro de consumos, creado por la API de producción. */
    private function producir(int $cajas, bool $cola): ProduccionEmpaque
    {
        $n = ++$this->consecutivoApi;
        $response = $this->postJson(self::PRODUCCION, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => sprintf('EMB-%04d', $n),
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 4,
            'clasificacion' => 'convencional',
            'is_cola' => $cola,
        ]);
        $response->assertCreated();

        return ProduccionEmpaque::findOrFail($response->json('data.id'));
    }

    /** @param ProduccionEmpaque[] $pallets */
    private function embarcar(array $pallets): TestResponse
    {
        return $this->postJson(self::EMBARQUES, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'tipo_venta' => 'nacional',
            'fecha_embarque' => '2026-02-10',
            'destino_embarque_id' => $this->destinoEmbarqueId(),
            'pallets' => array_map(fn (ProduccionEmpaque $p, int $i) => ['id' => $p->id, 'posicion_carga' => $i + 1],
                $pallets, array_keys($pallets)),
        ]);
    }

    private function regresar(TestResponse $embarque): void
    {
        $this->deleteJson(self::EMBARQUES.'/'.$embarque->json('data.id'))->assertOk();
    }

    // ---- tests ----

    public function test_embarcar_cola_abierta_consume_cierre(): void
    {
        $this->surtirTodo();
        $cola = $this->producir(60, true);
        $this->assertNeto(0, $this->esquineros);

        $this->embarcar([$cola])->assertCreated()->assertJsonPath('faltantes', []);

        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-60, $this->cajaB15);
        $cola->refresh();
        $this->assertTrue((bool) $cola->is_cola);
        $this->assertSame('embarcado', $cola->status);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_embarcar_cola_abierta_sin_existencia_avisa_faltantes(): void
    {
        foreach ([$this->tarima, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15] as $insumo) {
            $this->darExistencia($insumo, self::INICIAL);
        }
        $cola = $this->producir(60, true);

        $response = $this->embarcar([$cola])->assertCreated()->assertJsonCount(1, 'faltantes');

        $this->assertSame($this->esquineros->id, $response->json('faltantes.0.producto_id'));
        $this->assertEqualsWithDelta(-4, (float) $response->json('faltantes.0.existencia'), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_embarcar_pallet_cerrado_no_consume_de_nuevo(): void
    {
        $this->surtirTodo();
        $cerrado = $this->producir(240, false);
        $this->assertNeto(-4, $this->esquineros);
        $registros = ProduccionEmpaqueConsumo::count();

        $this->embarcar([$cerrado])->assertCreated()->assertJsonPath('faltantes', []);

        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        $this->assertSame($registros, ProduccionEmpaqueConsumo::count());
        $this->assertFalse((bool) $cerrado->fresh()->is_cola);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_regresar_embarque_devuelve_cierre_de_la_cola(): void
    {
        $this->surtirTodo();
        $cola = $this->producir(60, true);
        $cerrado = $this->producir(240, false);

        $embarque = $this->embarcar([$cola, $cerrado])->assertCreated();
        // Cierre de la cola (4) + cierre del pallet cerrado (4).
        $this->assertNeto(-8, $this->esquineros);

        $this->regresar($embarque);

        // La cola abierta devuelve su cierre; el pallet cerrado conserva el suyo.
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        // Lo demás de la cola sigue siendo suyo (tarima y cajas).
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(-300, $this->cajaB15);
        $cola->refresh();
        $this->assertTrue((bool) $cola->is_cola);
        $this->assertSame('en_almacen', $cola->status);
        $this->assertSame('en_almacen', $cerrado->fresh()->status);
        $this->assertSame(0, EmbarqueEmpaque::count());

        // Embarcarla otra vez la cobra de nuevo, una sola vez.
        $this->embarcar([$cola])->assertCreated();
        $this->assertNeto(-8, $this->esquineros);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_cola_congelada_embarcada_no_consume(): void
    {
        $this->surtirTodo();
        // Anterior al registro de consumos: tiene receta pero ningún renglón de consumo.
        $congelada = $this->crearPallet(['total_cajas' => 60, 'is_cola' => true]);
        $this->assertTrue($congelada->congelado());

        $embarque = $this->embarcar([$congelada])->assertCreated()->assertJsonPath('faltantes', []);

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertSame(0, ProduccionEmpaqueConsumo::count());

        $this->regresar($embarque);

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertSame(0, ProduccionEmpaqueConsumo::count());
        $this->assertTrue((bool) $congelada->fresh()->is_cola);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }
}
