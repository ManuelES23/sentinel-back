<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\Cultivo;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * «Gasto de producción» por temporada y cultivo: cada renglón trae la temporada del pallet y el
 * cultivo de esa temporada, se puede filtrar por cualquiera de los dos y la respuesta trae un resumen
 * de costo por temporada. Vale para lo registrado y para los pallets congelados (cálculo con la receta).
 */
class GastoProduccionPorTemporadaTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const PRODUCCION = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const GASTO = '/api/splendidfarms/inventario/reportes/gasto-produccion';

    private int $procesoId;
    private Cultivo $elote;
    private Temporada $temporadaElote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        $this->actingUser->update(['role' => 'admin']);
        Sanctum::actingAs($this->actingUser);
        $this->procesoId = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso']->id;

        $this->elote = Cultivo::create(['nombre' => 'Elote Dulce']);
        $this->temporadaElote = Temporada::create([
            'cultivo_id' => $this->elote->id,
            'nombre' => 'Elote Mochis 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $this->elote->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2027,
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2027-05-30',
            'user_id' => $this->actingUser->id,
        ]);

        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna] as $insumo) {
            $this->darExistencia($insumo, 1000.0, 2.0);
        }

        // Mango (temporada de los fixtures): un pallet capturado con el registro de consumos.
        $this->postJson(self::PRODUCCION, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => 'API-0001',
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => 240,
            'peso_neto_kg' => 1000,
            'clasificacion' => 'convencional',
            'is_cola' => false,
        ])->assertCreated();

        // Elote: dos pallets congelados (sin registro), uno sin entradas y otro con una entrada.
        $this->crearPallet(['temporada_id' => $this->temporadaElote->id]);
        $conEntrada = $this->crearPallet(['temporada_id' => $this->temporadaElote->id]);
        $this->crearEntrada($conEntrada, 1, 120);
    }

    private function gasto(array $filtros = []): TestResponse
    {
        return $this->getJson(self::GASTO.'?'.http_build_query($filtros), ['X-Enterprise-Slug' => 'splendidfarms'])
            ->assertOk();
    }

    public function test_cada_renglon_trae_temporada_y_cultivo_y_hay_resumen_por_temporada(): void
    {
        $respuesta = $this->gasto();
        $filas = collect($respuesta->json('data.rows'));

        $this->assertEqualsCanonicalizing(
            [$this->temporada->id, $this->temporadaElote->id],
            $filas->pluck('temporada_id')->unique()->values()->all(),
        );
        $filaElote = $filas->firstWhere('temporada_id', $this->temporadaElote->id);
        $this->assertSame('Elote Mochis 2026', $filaElote['temporada']);
        $this->assertSame('Elote Dulce', $filaElote['cultivo']);
        $this->assertSame($this->elote->id, $filaElote['cultivo_id']);
        $this->assertSame('Mango', $filas->firstWhere('temporada_id', $this->temporada->id)['cultivo']);

        // El mismo insumo sale en un renglón por temporada, no sumado.
        $this->assertCount(2, $filas->where('product_id', $this->etiqueta->id));

        // Resumen: la temporada más reciente primero y los costos cuadran con el total.
        $resumen = collect($respuesta->json('data.por_temporada'));
        $this->assertSame([$this->temporadaElote->id, $this->temporada->id], $resumen->pluck('temporada_id')->all());
        $this->assertSame('Elote Dulce', $resumen[0]['cultivo']);
        $this->assertEqualsWithDelta(
            (float) $respuesta->json('data.totals.total_consumed_cost'),
            (float) $resumen->sum('consumed_cost'),
            0.001,
        );
        $this->assertSame(
            $filas->where('temporada_id', $this->temporada->id)->pluck('product_id')->unique()->count(),
            $resumen[1]['total_products'],
        );

        // Opciones de los filtros.
        $temporadas = collect($respuesta->json('data.filtros.temporadas'));
        $this->assertSame('Elote Dulce', $temporadas->firstWhere('id', $this->temporadaElote->id)['cultivo']);
        $this->assertEqualsCanonicalizing(['Elote Dulce', 'Mango'], collect($respuesta->json('data.filtros.cultivos'))->pluck('nombre')->all());
    }

    public function test_filtra_por_temporada(): void
    {
        $filas = collect($this->gasto(['temporada_id' => $this->temporadaElote->id])->json('data.rows'));

        $this->assertNotEmpty($filas);
        $this->assertSame([$this->temporadaElote->id], $filas->pluck('temporada_id')->unique()->values()->all());

        $mango = collect($this->gasto(['temporada_id' => $this->temporada->id])->json('data.rows'));
        $this->assertSame([$this->temporada->id], $mango->pluck('temporada_id')->unique()->values()->all());
        // Lo registrado del pallet de mango: 242 etiquetas por caja × 240 cajas / 240.
        $this->assertEqualsWithDelta(242.0, (float) $mango->firstWhere('product_id', $this->etiqueta->id)['consumed_quantity'], 0.001);
    }

    public function test_filtra_por_cultivo_y_combina_con_temporada(): void
    {
        $mango = collect($this->gasto(['cultivo_id' => $this->cultivo->id])->json('data.rows'));
        $this->assertSame([$this->temporada->id], $mango->pluck('temporada_id')->unique()->values()->all());

        $elote = $this->gasto(['cultivo_id' => $this->elote->id]);
        $this->assertSame([$this->temporadaElote->id], collect($elote->json('data.rows'))->pluck('temporada_id')->unique()->values()->all());
        $this->assertCount(1, $elote->json('data.por_temporada'));

        // Temporada de otro cultivo: no hay nada que mostrar.
        $vacio = $this->gasto(['cultivo_id' => $this->elote->id, 'temporada_id' => $this->temporada->id]);
        $this->assertSame([], $vacio->json('data.rows'));
        $this->assertSame([], $vacio->json('data.por_temporada'));
        $this->assertNotEmpty($vacio->json('data.filtros.temporadas'));
    }
}
