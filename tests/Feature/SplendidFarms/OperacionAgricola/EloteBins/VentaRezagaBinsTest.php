<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\ProcesoEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaque;
use App\Models\SalidaRezagaEmpaqueDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Venta de rezaga en bins: el kg de cada renglón sale de la propia rezaga,
 * una venta lleva una sola clase, nada se escribe si algo no cuadra, borrar
 * devuelve exacto y una temporada sin el interruptor sigue igual que hoy.
 */
class VentaRezagaBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/salida-rezaga';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        $this->concederPermisosSalidaRezaga();
        Sanctum::actingAs($this->actingUser);
    }

    private function cuerpoVenta(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'tipo_salida' => 'venta',
            'fecha_venta' => '2026-02-10',
            'comprador' => 'Forrajera del Valle',
            'precio_bin' => 100,
            'autorizado_por' => 'Gerente',
            'solicitado_por' => 'Comprador',
            'chofer' => 'Juan Pérez',
            'placa' => 'ABC-123',
        ], $extra);
    }

    /** @return array{0: RezagaEmpaque, 1: RezagaEmpaque} hoja de 5 bins/750 kg y de 2 bins/300 kg */
    private function dosRezagasHoja(): array
    {
        $proceso = $this->crearProcesoBins(10);

        return [
            $this->crearRezagaBins($proceso, ['cantidad_bins' => 5, 'cantidad_kg' => 750]),
            $this->crearRezagaBins($proceso, ['cantidad_bins' => 2, 'cantidad_kg' => 300]),
        ];
    }

    private function ventaDeCincoBins(RezagaEmpaque $a, RezagaEmpaque $b): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [
                ['rezaga_id' => $a->id, 'bins' => 3],
                ['rezaga_id' => $b->id, 'bins' => 2],
            ],
        ]));
    }

    private function estadoRezaga(RezagaEmpaque $r): array
    {
        $r = $r->fresh();

        return [(int) $r->cantidad_bins, round((float) $r->cantidad_kg, 2), $r->status];
    }

    public function test_venta_en_bins(): void
    {
        [$a, $b] = $this->dosRezagasHoja();

        $resp = $this->ventaDeCincoBins($a, $b)->assertCreated();

        $data = $resp->json('data');
        $this->assertSame(5, (int) $data['total_bins']);
        $this->assertEquals(100, $data['precio_bin']);
        $this->assertEquals(500, $data['monto_total']);
        $this->assertEquals(750, $data['total_peso_kg']);
        $this->assertEquals(0, $data['precio_kg']);
        $this->assertCount(2, $data['detalles']);

        $porRezaga = collect($data['detalles'])->keyBy('rezaga_id');
        $this->assertSame(3, (int) $porRezaga[$a->id]['bins']);
        $this->assertEquals(100, $porRezaga[$a->id]['precio_bin']);
        $this->assertEquals(450, $porRezaga[$a->id]['peso_kg']);
        $this->assertEquals(300, $porRezaga[$a->id]['monto']);
        $this->assertSame(2, (int) $porRezaga[$b->id]['bins']);
        $this->assertEquals(300, $porRezaga[$b->id]['peso_kg']);
        $this->assertEquals(200, $porRezaga[$b->id]['monto']);

        $this->assertSame([2, 300.0, 'pendiente'], $this->estadoRezaga($a));
        $this->assertSame([0, 0.0, 'vendida'], $this->estadoRezaga($b));
    }

    public function test_peso_por_bin_sale_de_la_rezaga(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 4, 'cantidad_kg' => 600]);

        // El catálogo cambia después de capturar: no debe afectar la venta.
        $this->binHoja->update(['peso_estimado_kg' => 999]);

        $resp = $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 1]],
        ]))->assertCreated();

        $this->assertEquals(150, $resp->json('data.detalles.0.peso_kg'));
        $this->assertEquals(150, $resp->json('data.total_peso_kg'));
        $this->assertSame([3, 450.0, 'pendiente'], $this->estadoRezaga($rezaga));
    }

    public function test_kg_con_centavos_cuadran_al_vender_y_al_borrar(): void
    {
        // 100 kg / 3 bins no es exacto: vendido + restante debe sumar 100.00 y borrar devuelve 100.00.
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 3, 'cantidad_kg' => 100]);

        $resp = $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 1]],
        ]))->assertCreated();

        $this->assertEquals(33.33, $resp->json('data.detalles.0.peso_kg'));
        $this->assertSame([2, 66.67, 'pendiente'], $this->estadoRezaga($rezaga));

        $this->deleteJson(self::URL.'/'.$resp->json('data.id'))->assertOk();
        $this->assertSame([3, 100.0, 'pendiente'], $this->estadoRezaga($rezaga));
    }

    public function test_venta_excede_bins(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 5, 'cantidad_kg' => 750]);

        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 6]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['detalles']);

        $this->assertSame([5, 750.0, 'pendiente'], $this->estadoRezaga($rezaga));
        $this->assertSame(0, SalidaRezagaEmpaque::count());
        $this->assertSame(0, SalidaRezagaEmpaqueDetalle::count());
    }

    public function test_venta_excede_bins_en_el_segundo_renglon_no_escribe_nada(): void
    {
        [$a, $b] = $this->dosRezagasHoja();

        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [
                ['rezaga_id' => $a->id, 'bins' => 3],
                ['rezaga_id' => $b->id, 'bins' => 3],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['detalles']);

        $this->assertSame([5, 750.0, 'pendiente'], $this->estadoRezaga($a));
        $this->assertSame([2, 300.0, 'pendiente'], $this->estadoRezaga($b));
        $this->assertSame(0, SalidaRezagaEmpaque::count());
        $this->assertSame(0, SalidaRezagaEmpaqueDetalle::count());
    }

    public function test_venta_mezcla_clases(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $hoja = $this->crearRezagaBins($proceso, ['cantidad_bins' => 3, 'cantidad_kg' => 450]);
        $fresco = $this->crearRezagaBins($proceso, [
            'subtipo_rezaga' => 'producto',
            'tipo_carga_id' => $this->binFresco->id,
            'cantidad_bins' => 2,
            'cantidad_kg' => 600,
        ]);

        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [
                ['rezaga_id' => $hoja->id, 'bins' => 1],
                ['rezaga_id' => $fresco->id, 'bins' => 1],
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.detalles.0', 'Una venta lleva un solo tipo de rezaga.');

        $this->assertSame([3, 450.0, 'pendiente'], $this->estadoRezaga($hoja));
        $this->assertSame([2, 600.0, 'pendiente'], $this->estadoRezaga($fresco));
        $this->assertSame(0, SalidaRezagaEmpaque::count());
        $this->assertSame(0, SalidaRezagaEmpaqueDetalle::count());
    }

    public function test_rezaga_sin_clase_o_bin_de_campo_no_se_vende(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $campo = $this->crearRezagaBins($proceso, ['tipo_carga_id' => $this->binCampo->id]);
        $sinTipo = $this->crearRezagaBins($proceso, ['tipo_carga_id' => null]);

        foreach ([$campo, $sinTipo] as $rezaga) {
            $this->postJson(self::URL, $this->cuerpoVenta([
                'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 1]],
            ]))->assertStatus(422)->assertJsonValidationErrors(['detalles']);

            $this->assertSame([3, 450.0, 'pendiente'], $this->estadoRezaga($rezaga));
        }

        $this->assertSame(0, SalidaRezagaEmpaque::count());
    }

    public function test_no_se_vende_rezaga_repetida_ni_en_kg_ni_de_otra_temporada(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $rezaga = $this->crearRezagaBins($proceso);
        $enKg = $this->crearRezagaBins($proceso, ['cantidad_bins' => null, 'cantidad_kg' => 80]);

        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [
                ['rezaga_id' => $rezaga->id, 'bins' => 1],
                ['rezaga_id' => $rezaga->id, 'bins' => 1],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['detalles']);

        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $enKg->id, 'bins' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['detalles']);

        $this->assertSame([3, 450.0, 'pendiente'], $this->estadoRezaga($rezaga));
        $this->assertSame(0, SalidaRezagaEmpaque::count());
    }

    public function test_venta_en_bins_exige_detalles_con_bins(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10));

        $this->postJson(self::URL, $this->cuerpoVenta())->assertStatus(422)->assertJsonValidationErrors(['detalles']);
        $this->postJson(self::URL, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 0]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['detalles.0.bins']);
        $this->postJson(self::URL, $this->cuerpoVenta([
            'precio_bin' => null,
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['precio_bin']);
    }

    public function test_desecho_en_bins_marca_destruida_y_no_cobra(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 2, 'cantidad_kg' => 300]);

        $resp = $this->postJson(self::URL, $this->cuerpoVenta([
            'tipo_salida' => 'desecho',
            'comprador' => null,
            'precio_bin' => 100,
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 2]],
        ]))->assertCreated();

        $this->assertSame(2, (int) $resp->json('data.total_bins'));
        $this->assertEquals(0, $resp->json('data.monto_total'));
        $this->assertEquals(0, $resp->json('data.precio_bin'));
        $this->assertSame([0, 0.0, 'destruida'], $this->estadoRezaga($rezaga));
    }

    public function test_borrar_venta_en_bins_devuelve(): void
    {
        [$a, $b] = $this->dosRezagasHoja();
        $id = $this->ventaDeCincoBins($a, $b)->assertCreated()->json('data.id');

        $this->deleteJson(self::URL.'/'.$id)->assertOk();

        $this->assertSame([5, 750.0, 'pendiente'], $this->estadoRezaga($a));
        $this->assertSame([2, 300.0, 'pendiente'], $this->estadoRezaga($b));
        $this->assertSame(0, SalidaRezagaEmpaque::count());
    }

    public function test_update_precio_bin_recalcula(): void
    {
        [$a, $b] = $this->dosRezagasHoja();
        $id = $this->ventaDeCincoBins($a, $b)->assertCreated()->json('data.id');

        $resp = $this->putJson(self::URL.'/'.$id, ['precio_bin' => 120])->assertOk();

        $this->assertEquals(600, $resp->json('data.monto_total'));
        $this->assertEquals(120, $resp->json('data.precio_bin'));
        $this->assertEquals(0, $resp->json('data.precio_kg'));
        foreach ($resp->json('data.detalles') as $det) {
            $this->assertEquals($det['bins'] * 120, $det['monto']);
            $this->assertEquals(120, $det['precio_bin']);
        }

        // Los kg y los bins vendidos no se tocan.
        $this->assertEquals(750, $resp->json('data.total_peso_kg'));
        $this->assertSame(5, (int) $resp->json('data.total_bins'));
        $this->assertSame([2, 300.0, 'pendiente'], $this->estadoRezaga($a));
    }

    public function test_update_en_bins_ignora_precio_kg(): void
    {
        [$a, $b] = $this->dosRezagasHoja();
        $id = $this->ventaDeCincoBins($a, $b)->assertCreated()->json('data.id');

        $resp = $this->putJson(self::URL.'/'.$id, ['precio_kg' => 50, 'chofer' => 'Otro'])->assertOk();

        $this->assertEquals(0, $resp->json('data.precio_kg'));
        $this->assertEquals(500, $resp->json('data.monto_total'));
        $this->assertSame('Otro', $resp->json('data.chofer'));
    }

    public function test_venta_sin_interruptor_igual_que_hoy(): void
    {
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MANGO-VTA');
        $mango = fn (string $folio, float $kg) => RezagaEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $base['proceso']->id,
            'folio_rezaga' => $folio,
            'tipo_rezaga' => 'produccion',
            'fecha' => '2026-02-02',
            'cantidad_kg' => $kg,
            'modo_registro' => 'general',
            'status' => 'pendiente',
        ]);
        $a = $mango('REZ-MANGO-1', 100);
        $b = $mango('REZ-MANGO-2', 50);

        $mangoBody = fn (array $extra) => array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'tipo_salida' => 'venta',
            'fecha_venta' => '2026-02-10',
            'comprador' => 'Comprador',
            'precio_kg' => 10,
            'autorizado_por' => 'Gerente',
            'solicitado_por' => 'Comprador',
            'chofer' => 'Juan',
            'placa' => 'XYZ-1',
        ], $extra);

        // Con detalles en kg.
        $resp = $this->postJson(self::URL, $mangoBody([
            'total_peso_kg' => 40,
            'detalles' => [['rezaga_id' => $a->id, 'peso_kg' => 40]],
        ]))->assertCreated();

        $data = $resp->json('data');
        $this->assertEquals(40, $data['total_peso_kg']);
        $this->assertEquals(10, $data['precio_kg']);
        $this->assertEquals(400, $data['monto_total']);
        $this->assertArrayNotHasKey('total_bins', $data);
        $this->assertArrayNotHasKey('precio_bin', $data);
        $this->assertArrayNotHasKey('bins', $data['detalles'][0]);
        $this->assertArrayNotHasKey('precio_bin', $data['detalles'][0]);
        $this->assertEquals(60, (float) $a->fresh()->cantidad_kg);
        $this->assertNull(SalidaRezagaEmpaque::first()->total_bins);

        // Sin detalles: descuento automático PEPS (primero a, luego b).
        $resp = $this->postJson(self::URL, $mangoBody(['total_peso_kg' => 80]))->assertCreated();

        $data = $resp->json('data');
        $this->assertEquals(800, $data['monto_total']);
        $this->assertArrayNotHasKey('total_bins', $data);
        $this->assertCount(2, $data['detalles']);
        $this->assertEquals([60, 20], collect($data['detalles'])->pluck('peso_kg')->map(fn ($v) => (float) $v)->all());
        $this->assertSame('vendida', $a->fresh()->status);
        $this->assertEquals(0, (float) $a->fresh()->cantidad_kg);
        $this->assertEquals(30, (float) $b->fresh()->cantidad_kg);
        $this->assertSame('pendiente', $b->fresh()->status);

        // Update y borrado en kg como hoy.
        $this->putJson(self::URL.'/'.$data['id'], ['precio_bin' => 5])->assertOk()
            ->assertJsonMissingPath('data.total_bins');
        $this->deleteJson(self::URL.'/'.$data['id'])->assertOk();
        $this->assertEquals(60, (float) $a->fresh()->cantidad_kg);
        $this->assertSame('pendiente', $a->fresh()->status);
        $this->assertEquals(50, (float) $b->fresh()->cantidad_kg);
    }
}
