<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\RezagaEmpaque;
use App\Models\TipoCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Rezaga capturada en bins: alta, edición y rechazo del ajuste de peso, y
 * que una temporada sin el interruptor siga respondiendo igual que hoy.
 */
class RezagaEnBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/empaque';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function cuerpoBins(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->crearProcesoBins()->id,
            'fecha' => '2026-02-02',
            'tipo_carga_id' => $this->binHoja->id,
            'cantidad_bins' => 3,
        ], $extra);
    }

    public function test_store_en_bins_calcula_kg_y_tipo(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins())
            ->assertCreated()
            ->assertJsonPath('data.cantidad_bins', 3)
            ->assertJsonPath('data.tipo_rezaga', 'produccion')
            ->assertJsonPath('data.subtipo_rezaga', 'hoja')
            ->assertJsonPath('data.modo_registro', 'general')
            ->assertJsonPath('data.status', 'pendiente');

        $rezaga = RezagaEmpaque::firstOrFail();
        $this->assertSame(450.0, (float) $rezaga->cantidad_kg);
        $this->assertSame(3, (int) $rezaga->cantidad_bins);
        $this->assertNull($rezaga->total_cajas);
        $this->assertSame($this->actingUser->id, (int) $rezaga->created_by);
        $this->assertStringStartsWith('REZ-', $rezaga->folio_rezaga);
    }

    public function test_store_en_bins_mapea_cuarto_frio_y_fresco(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins([
            'tipo_carga_id' => $this->binCuartoFrio->id,
            'cantidad_bins' => 2,
        ]))->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', 'cuarto_frio')
            ->assertJsonPath('data.subtipo_rezaga', 'producto');

        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins([
            'tipo_carga_id' => $this->binFresco->id,
        ]))->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', 'produccion')
            ->assertJsonPath('data.subtipo_rezaga', 'producto');

        $this->assertSame(500.0, (float) RezagaEmpaque::where('tipo_rezaga', 'cuarto_frio')->value('cantidad_kg'));
    }

    public function test_store_en_bins_ignora_kg_y_tipo_que_manda_el_cliente(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins([
            'cantidad_kg' => 9999,
            'tipo_rezaga' => 'lavado',
            'subtipo_rezaga' => 'producto',
        ]))->assertCreated()
            ->assertJsonPath('data.tipo_rezaga', 'produccion')
            ->assertJsonPath('data.subtipo_rezaga', 'hoja');

        $this->assertSame(450.0, (float) RezagaEmpaque::firstOrFail()->cantidad_kg);
    }

    public function test_store_en_bins_rechaza_bin_de_campo(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins(['tipo_carga_id' => $this->binCampo->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id');

        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_store_en_bins_rechaza_otro_cultivo(): void
    {
        $binMango = TipoCarga::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Bin de hoja Mango',
            'peso_estimado_kg' => 100,
            'clase_rezaga' => 'hoja',
            'is_active' => true,
        ]);

        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins(['tipo_carga_id' => $binMango->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id');
    }

    public function test_store_en_bins_rechaza_tipo_inactivo(): void
    {
        $this->binHoja->update(['is_active' => false]);

        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id');
    }

    public function test_store_en_bins_exige_bins_enteros_positivos_y_tipo(): void
    {
        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins(['cantidad_bins' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('cantidad_bins');

        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins(['cantidad_bins' => 1.5]))
            ->assertStatus(422)->assertJsonValidationErrors('cantidad_bins');

        $cuerpo = $this->cuerpoBins();
        unset($cuerpo['cantidad_bins']);
        $this->postJson(self::BASE.'/rezaga', $cuerpo)
            ->assertStatus(422)->assertJsonValidationErrors('cantidad_bins');

        $cuerpo = $this->cuerpoBins();
        unset($cuerpo['tipo_carga_id']);
        $this->postJson(self::BASE.'/rezaga', $cuerpo)
            ->assertStatus(422)->assertJsonValidationErrors('tipo_carga_id');
    }

    public function test_store_en_bins_rechaza_proceso_de_otra_temporada(): void
    {
        $procesoMango = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];

        $this->postJson(self::BASE.'/rezaga', $this->cuerpoBins(['proceso_id' => $procesoMango->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proceso_id');
    }

    public function test_store_sin_interruptor_igual_que_hoy(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];
        $cuerpo = [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'tipo_rezaga' => 'produccion',
            'subtipo_rezaga' => 'hoja',
            'fecha' => '2026-02-02',
            'cantidad_kg' => 120.5,
        ];

        $respuesta = $this->postJson(self::BASE.'/rezaga', $cuerpo)
            ->assertCreated()
            ->assertJsonPath('message', 'Rezaga registrada exitosamente')
            ->assertJsonPath('data.tipo_rezaga', 'produccion')
            ->assertJsonPath('data.subtipo_rezaga', 'hoja')
            ->assertJsonPath('data.cantidad_kg', '120.50');

        $this->assertNull(RezagaEmpaque::findOrFail($respuesta->json('data.id'))->cantidad_bins);
        $this->assertArrayNotHasKey('cantidad_bins', $respuesta->json('data'));

        unset($cuerpo['cantidad_kg']);
        $this->postJson(self::BASE.'/rezaga', $cuerpo)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'La cantidad en kg es obligatoria.');
    }

    public function test_store_sin_interruptor_ignora_cantidad_bins(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];

        $this->postJson(self::BASE.'/rezaga', [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'tipo_rezaga' => 'produccion',
            'subtipo_rezaga' => 'hoja',
            'fecha' => '2026-02-02',
            'cantidad_kg' => 10,
            'cantidad_bins' => 4,
        ])->assertCreated();

        $this->assertNull(RezagaEmpaque::firstOrFail()->cantidad_bins);
    }

    public function test_update_en_bins_recalcula_kg(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['cantidad_bins' => 5])
            ->assertOk()
            ->assertJsonPath('data.cantidad_bins', 5);

        $rezaga->refresh();
        $this->assertSame(750.0, (float) $rezaga->cantidad_kg);
        $this->assertSame('hoja', $rezaga->subtipo_rezaga);
    }

    public function test_update_en_bins_cambia_tipo_conservando_bins(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['tipo_carga_id' => $this->binCuartoFrio->id])
            ->assertOk();

        $rezaga->refresh();
        $this->assertSame(3, (int) $rezaga->cantidad_bins);
        $this->assertSame(750.0, (float) $rezaga->cantidad_kg);
        $this->assertSame('cuarto_frio', $rezaga->tipo_rezaga);
        $this->assertSame('producto', $rezaga->subtipo_rezaga);
    }

    public function test_update_en_bins_rechaza_bin_de_campo(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['tipo_carga_id' => $this->binCampo->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id');

        $this->assertSame(450.0, (float) $rezaga->fresh()->cantidad_kg);
    }

    public function test_update_en_bins_sin_tocar_bins_conserva_kg(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['motivo' => 'Se secó'])
            ->assertOk();

        $rezaga->refresh();
        $this->assertSame('Se secó', $rezaga->motivo);
        $this->assertSame(450.0, (float) $rezaga->cantidad_kg);
        $this->assertSame(3, (int) $rezaga->cantidad_bins);
    }

    public function test_update_en_bins_reenviar_los_mismos_valores_no_mueve_los_kg(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());
        $this->crearVentaDeRezaga($rezaga, 150);
        $this->binHoja->update(['peso_estimado_kg' => 200]);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, [
            'cantidad_bins' => 3,
            'tipo_carga_id' => $this->binHoja->id,
            'motivo' => 'Sin cambios en bins',
        ])->assertOk();

        $rezaga->refresh();
        $this->assertSame(450.0, (float) $rezaga->cantidad_kg);
        $this->assertSame('Sin cambios en bins', $rezaga->motivo);
    }

    public function test_update_en_bins_rechaza_proceso_de_otra_temporada(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());
        $procesoMango = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['proceso_id' => $procesoMango->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proceso_id');
    }
    public function test_update_en_bins_con_ventas_rechaza(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());
        $this->crearVentaDeRezaga($rezaga, 150);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['cantidad_bins' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cantidad_bins')
            ->assertJsonPath('errors.cantidad_bins.0', 'Esta rezaga ya tiene ventas; no se pueden cambiar sus bins.');

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['tipo_carga_id' => $this->binFresco->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cantidad_bins');

        $rezaga->refresh();
        $this->assertSame(3, (int) $rezaga->cantidad_bins);
        $this->assertSame(450.0, (float) $rezaga->cantidad_kg);
    }

    public function test_update_en_bins_con_ventas_permite_motivo(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());
        $this->crearVentaDeRezaga($rezaga, 150);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['motivo' => 'Nota'])->assertOk();
    }

    public function test_update_en_bins_de_rezaga_legada_en_kg_usa_camino_de_hoy(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(), [
            'cantidad_bins' => null,
            'tipo_carga_id' => null,
            'cantidad_kg' => 80,
        ]);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['cantidad_kg' => 95.5])
            ->assertOk()
            ->assertJsonPath('message', 'Rezaga actualizada');

        $this->assertSame(95.5, (float) $rezaga->fresh()->cantidad_kg);
    }

    public function test_update_en_bins_no_admite_quitar_el_proceso(): void
    {
        $proceso = $this->crearProcesoBins();
        $rezaga = $this->crearRezagaBins($proceso);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['proceso_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proceso_id');

        $this->assertSame($proceso->id, (int) $rezaga->fresh()->proceso_id);

        // Omitir la clave conserva el proceso.
        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['motivo' => 'Nota'])->assertOk();
        $this->assertSame($proceso->id, (int) $rezaga->fresh()->proceso_id);
    }

    public function test_update_sin_interruptor_acepta_proceso_nulo_como_hoy(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG-NULO')['proceso'];
        $rezaga = $this->crearRezagaBins($proceso, [
            'cantidad_bins' => null,
            'tipo_carga_id' => null,
            'cantidad_kg' => 40,
        ]);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['proceso_id' => null])->assertOk();

        $this->assertNull($rezaga->fresh()->proceso_id);
    }

    public function test_update_sin_interruptor_igual_que_hoy(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];
        $rezaga = $this->crearRezagaBins($proceso, [
            'cantidad_bins' => null,
            'tipo_carga_id' => null,
            'cantidad_kg' => 40,
        ]);

        $this->putJson(self::BASE.'/rezaga/'.$rezaga->id, ['cantidad_kg' => 55])
            ->assertOk();

        $this->assertSame(55.0, (float) $rezaga->fresh()->cantidad_kg);
    }

    public function test_ajuste_peso_rechaza_rezaga_en_bins(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins());

        $this->postJson(self::BASE.'/ajuste-peso-rezaga', [
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'rezaga_empaque_id' => $rezaga->id,
            'fecha_ajuste' => '2026-02-03',
            'kg_despues' => 400,
            'motivo' => 'deshidratacion',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'La rezaga en bins no lleva ajuste de peso.');

        $this->assertSame(450.0, (float) $rezaga->fresh()->cantidad_kg);
    }

    public function test_ajuste_peso_rezaga_en_kg_sigue_igual(): void
    {
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MG')['proceso'];
        $rezaga = $this->crearRezagaBins($proceso, [
            'cantidad_bins' => null,
            'tipo_carga_id' => null,
            'cantidad_kg' => 100,
        ]);

        $this->postJson(self::BASE.'/ajuste-peso-rezaga', [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'rezaga_empaque_id' => $rezaga->id,
            'fecha_ajuste' => '2026-02-03',
            'kg_despues' => 90,
            'motivo' => 'deshidratacion',
        ])->assertStatus(201);

        $this->assertSame(90.0, (float) $rezaga->fresh()->cantidad_kg);
    }
}
