<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaque;
use App\Models\TipoCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * La clase de rezaga (hoja / fresco = «Producto» / cuarto frío) se elige APARTE
 * del tipo de carga: el mismo bin (p. ej. el de campo, sin clase) sirve para
 * todo. Sin `clase_rezaga` rige el camino anterior (la clase del bin manda).
 */
class ClaseRezagaIndependienteTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/empaque';
    private const VENTAS = '/api/splendidfarms/operacion-agricola/empaque/salida-rezaga';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        $this->concederPermisosSalidaRezaga();
        Sanctum::actingAs($this->actingUser);
    }

    private function cuerpo(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->crearProcesoBins()->id,
            'fecha' => '2026-02-02',
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_bins' => 2,
            'clase_rezaga' => 'hoja',
        ], $extra);
    }

    public static function clases(): array
    {
        return [
            'hoja' => ['hoja', 'produccion', 'hoja'],
            'fresco' => ['fresco', 'produccion', 'producto'],
            'cuarto_frio' => ['cuarto_frio', 'cuarto_frio', 'producto'],
        ];
    }

    /** @dataProvider clases */
    public function test_alta_con_bin_de_campo_y_clase_aparte(string $clase, string $tipo, string $subtipo): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpo(['clase_rezaga' => $clase]))
            ->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', $tipo)
            ->assertJsonPath('data.subtipo_rezaga', $subtipo)
            ->assertJsonPath('data.cantidad_bins', 2);

        $rezaga = RezagaEmpaque::firstOrFail();
        $this->assertSame($tipo, $rezaga->tipo_rezaga);
        $this->assertSame($subtipo, $rezaga->subtipo_rezaga);
        $this->assertSame(800.0, (float) $rezaga->cantidad_kg); // 2 bins × 400 kg del bin de campo
        $this->assertSame($this->binCampo->id, (int) $rezaga->tipo_carga_id);
        $this->assertSame($clase, $rezaga->claseRezagaEfectiva());
    }

    public function test_alta_con_clase_explicita_gana_sobre_la_clase_del_bin(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpo([
            'tipo_carga_id' => $this->binHoja->id,
            'clase_rezaga' => 'cuarto_frio',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', 'cuarto_frio')
            ->assertJsonPath('data.subtipo_rezaga', 'producto');

        $this->assertSame(300.0, (float) RezagaEmpaque::firstOrFail()->cantidad_kg);
    }

    public function test_alta_sin_clase_con_bin_sin_clase_da_422_en_clase_rezaga(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpo(['clase_rezaga' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clase_rezaga');

        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_alta_sin_clase_con_bin_con_clase_sigue_igual_que_antes(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpo([
            'tipo_carga_id' => $this->binFresco->id,
            'clase_rezaga' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.subtipo_rezaga', 'producto');

        $this->assertSame(600.0, (float) RezagaEmpaque::firstOrFail()->cantidad_kg);
    }

    public function test_alta_con_clase_invalida_da_422(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpo(['clase_rezaga' => 'basura']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clase_rezaga');

        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_alta_con_bin_de_otro_cultivo_o_inactivo_da_422_en_tipo_carga(): void
    {
        $otroCultivo = TipoCarga::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Bin Mango',
            'peso_estimado_kg' => 100,
            'is_active' => true,
        ]);
        $inactivo = TipoCarga::create([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => 'Bin inactivo',
            'peso_estimado_kg' => 100,
            'is_active' => false,
        ]);

        foreach ([$otroCultivo, $inactivo] as $tipo) {
            $this->postJson(self::BASE.'/rezaga', $this->cuerpo(['tipo_carga_id' => $tipo->id]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('tipo_carga_id');
        }

        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_edicion_que_solo_cambia_la_clase_recalcula_tipo_y_no_mueve_los_bins(): void
    {
        $id = $this->postJson(self::BASE.'/rezaga', $this->cuerpo())->assertCreated()->json('data.id');

        $this->putJson(self::BASE.'/rezaga/'.$id, ['clase_rezaga' => 'cuarto_frio'])
            ->assertOk()
            ->assertJsonPath('data.tipo_rezaga', 'cuarto_frio')
            ->assertJsonPath('data.subtipo_rezaga', 'producto');

        $rezaga = RezagaEmpaque::findOrFail($id);
        $this->assertSame('cuarto_frio', $rezaga->tipo_rezaga);
        $this->assertSame('producto', $rezaga->subtipo_rezaga);
        $this->assertSame(800.0, (float) $rezaga->cantidad_kg);
        $this->assertSame(2, (int) $rezaga->cantidad_bins);
    }

    public function test_reenviar_lo_mismo_no_mueve_los_kg(): void
    {
        $id = $this->postJson(self::BASE.'/rezaga', $this->cuerpo())->assertCreated()->json('data.id');

        // El peso del bin cambia después en el catálogo: los kg guardados no se mueven.
        $this->binCampo->update(['peso_estimado_kg' => 999]);

        $this->putJson(self::BASE.'/rezaga/'.$id, [
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_bins' => 2,
            'clase_rezaga' => 'hoja',
        ])->assertOk();

        $rezaga = RezagaEmpaque::findOrFail($id);
        $this->assertSame(800.0, (float) $rezaga->cantidad_kg);
        $this->assertSame('hoja', $rezaga->subtipo_rezaga);
    }

    public function test_editar_bins_sin_mandar_clase_conserva_la_clase_del_registro(): void
    {
        $id = $this->postJson(self::BASE.'/rezaga', $this->cuerpo(['clase_rezaga' => 'fresco']))->assertCreated()->json('data.id');

        $this->putJson(self::BASE.'/rezaga/'.$id, ['cantidad_bins' => 5])->assertOk();

        $rezaga = RezagaEmpaque::findOrFail($id);
        $this->assertSame(2000.0, (float) $rezaga->cantidad_kg);
        $this->assertSame('produccion', $rezaga->tipo_rezaga);
        $this->assertSame('producto', $rezaga->subtipo_rezaga);
    }

    public function test_edicion_de_clase_con_ventas_da_422(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(), [
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_bins' => 3,
            'cantidad_kg' => 1200,
        ]);
        $this->crearVentaDeRezaga($rezaga, 400, [], 1);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['clase_rezaga' => 'fresco'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clase_rezaga');

        $this->assertSame('hoja', $rezaga->fresh()->subtipo_rezaga);

        // Reenviar la misma clase no cuenta como cambio.
        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['clase_rezaga' => 'hoja', 'motivo' => 'Nota'])->assertOk();
    }

    public function test_cierre_de_folio_con_clase_por_renglon(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", [
            'cantidad_cuarto_frio' => 6,
            'cantidad_fresco' => 4,
            'rezagas_bins' => [
                ['tipo_carga_id' => $this->binCampo->id, 'bins' => 2, 'clase_rezaga' => 'hoja'],
                ['tipo_carga_id' => $this->binCampo->id, 'bins' => 1, 'clase_rezaga' => 'fresco'],
            ],
        ])->assertOk();

        $rezagas = RezagaEmpaque::where('proceso_id', $proceso->id)->orderBy('id')->get();
        $this->assertCount(2, $rezagas);
        $this->assertSame(['produccion', 'hoja', 800.0], [$rezagas[0]->tipo_rezaga, $rezagas[0]->subtipo_rezaga, (float) $rezagas[0]->cantidad_kg]);
        $this->assertSame(['produccion', 'producto', 400.0], [$rezagas[1]->tipo_rezaga, $rezagas[1]->subtipo_rezaga, (float) $rezagas[1]->cantidad_kg]);
        $this->assertSame('procesado', $proceso->fresh()->status);
    }

    public function test_cierre_con_cuarto_frio_o_sin_clase_en_bin_de_campo_da_422_y_no_escribe(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $cuerpo = fn (array $renglon) => [
            'cantidad_cuarto_frio' => 6,
            'cantidad_fresco' => 4,
            'rezagas_bins' => [$renglon],
        ];

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $cuerpo([
            'tipo_carga_id' => $this->binCampo->id, 'bins' => 1, 'clase_rezaga' => 'cuarto_frio',
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.clase_rezaga']);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $cuerpo([
            'tipo_carga_id' => $this->binCampo->id, 'bins' => 1,
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.clase_rezaga']);

        $this->assertSame(0, RezagaEmpaque::where('proceso_id', $proceso->id)->count());
        $this->assertSame('en_proceso', $proceso->fresh()->status);
    }

    public function test_cierre_destrabar_con_renglones_usa_el_mismo_validador(): void
    {
        $proceso = $this->crearProcesoBins(0);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", [
            'cantidad_cuarto_frio' => 0,
            'cantidad_fresco' => 0,
            'rezagas_bins' => [['tipo_carga_id' => $this->binCampo->id, 'bins' => 1, 'clase_rezaga' => 'fresco']],
        ])->assertOk();

        $this->assertSame(1, RezagaEmpaque::where('proceso_id', $proceso->id)->count());
        $this->assertSame('producto', RezagaEmpaque::where('proceso_id', $proceso->id)->value('subtipo_rezaga'));
    }

    public function test_venta_por_bins_de_rezaga_creada_con_bin_sin_clase(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $hoja = RezagaEmpaque::findOrFail($this->postJson(self::BASE.'/rezaga', $this->cuerpo([
            'proceso_id' => $proceso->id, 'clase_rezaga' => 'hoja', 'cantidad_bins' => 4,
        ]))->assertCreated()->json('data.id'));
        $fresco = RezagaEmpaque::findOrFail($this->postJson(self::BASE.'/rezaga', $this->cuerpo([
            'proceso_id' => $proceso->id, 'clase_rezaga' => 'fresco', 'cantidad_bins' => 2,
        ]))->assertCreated()->json('data.id'));

        $venta = fn (array $detalles) => $this->postJson(self::VENTAS, [
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
            'detalles' => $detalles,
        ]);

        // Una sola clase por venta.
        $venta([
            ['rezaga_id' => $hoja->id, 'bins' => 1],
            ['rezaga_id' => $fresco->id, 'bins' => 1],
        ])->assertStatus(422)->assertJsonPath('errors.detalles.0', 'Una venta lleva un solo tipo de rezaga.');
        $this->assertSame(0, SalidaRezagaEmpaque::count());

        $venta([['rezaga_id' => $hoja->id, 'bins' => 3]])->assertCreated();

        $hoja->refresh();
        $this->assertSame(1, (int) $hoja->cantidad_bins);
        $this->assertSame(400.0, (float) $hoja->cantidad_kg);
    }

    public function test_mango_sin_interruptor_no_cambia_y_ignora_la_clase(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];

        $respuesta = $this->postJson(self::BASE.'/rezaga', [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'tipo_rezaga' => 'produccion',
            'subtipo_rezaga' => 'hoja',
            'fecha' => '2026-02-02',
            'cantidad_kg' => 120.5,
            'clase_rezaga' => 'cuarto_frio',
        ])->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', 'produccion')
            ->assertJsonPath('data.subtipo_rezaga', 'hoja');

        $rezaga = RezagaEmpaque::findOrFail($respuesta->json('data.id'));
        $this->assertNull($rezaga->cantidad_bins);
        $this->assertSame(120.5, (float) $rezaga->cantidad_kg);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['clase_rezaga' => 'fresco', 'motivo' => 'x'])->assertOk();
        $this->assertSame('hoja', $rezaga->fresh()->subtipo_rezaga);
    }
}
