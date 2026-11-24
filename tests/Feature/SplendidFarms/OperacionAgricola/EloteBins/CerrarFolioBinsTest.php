<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Cerrar un folio y moverlo a proceso en una temporada que opera en bins:
 * varios renglones de rezaga en bins en una sola transacción, rechazo de la
 * rezaga en kg, y que una temporada sin el interruptor siga igual que hoy.
 */
class CerrarFolioBinsTest extends TestCase
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

    private function cuerpoCierre(array $extra = []): array
    {
        return array_merge([
            'cantidad_cuarto_frio' => 6,
            'cantidad_fresco' => 4,
        ], $extra);
    }

    /** Folio en proceso de la temporada Mango (sin interruptor), 10 unidades. */
    private function crearProcesoMango(int $unidades = 10): ProcesoEmpaque
    {
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'MANGO-CIERRE');

        $base['proceso']->update([
            'status' => 'en_proceso',
            'cantidad_entrada' => $unidades,
            'cantidad_disponible' => $unidades,
            'peso_entrada_kg' => $unidades * 10,
            'peso_disponible_kg' => $unidades * 10,
        ]);

        return $base['proceso']->fresh();
    }

    public function test_cerrar_en_bins_crea_varias_rezagas(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [
                ['tipo_carga_id' => $this->binHoja->id, 'bins' => 3],
                ['tipo_carga_id' => $this->binFresco->id, 'bins' => 2],
            ],
        ]))->assertOk()->assertJsonPath('success', true);

        $proceso->refresh();
        $this->assertSame('procesado', $proceso->status);
        $this->assertSame(6, (int) $proceso->cantidad_cuarto_frio);
        $this->assertSame(4, (int) $proceso->cantidad_fresco);

        $rezagas = RezagaEmpaque::where('proceso_id', $proceso->id)->orderBy('id')->get();
        $this->assertCount(2, $rezagas);
        $this->assertCount(2, $rezagas->pluck('folio_rezaga')->unique());

        $hoja = $rezagas[0];
        $this->assertSame(3, (int) $hoja->cantidad_bins);
        $this->assertSame(450.0, (float) $hoja->cantidad_kg);
        $this->assertSame('produccion', $hoja->tipo_rezaga);
        $this->assertSame('hoja', $hoja->subtipo_rezaga);
        $this->assertSame($this->binHoja->id, (int) $hoja->tipo_carga_id);

        $fresco = $rezagas[1];
        $this->assertSame(2, (int) $fresco->cantidad_bins);
        $this->assertSame(600.0, (float) $fresco->cantidad_kg);
        $this->assertSame('produccion', $fresco->tipo_rezaga);
        $this->assertSame('producto', $fresco->subtipo_rezaga);

        foreach ($rezagas as $rezaga) {
            $this->assertSame('pendiente', $rezaga->status);
            $this->assertSame('general', $rezaga->modo_registro);
            $this->assertSame($this->temporadaBins->id, (int) $rezaga->temporada_id);
            $this->assertSame($this->entity->id, (int) $rezaga->entity_id);
            $this->assertSame($this->actingUser->id, (int) $rezaga->created_by);
            $this->assertStringStartsWith('REZ-', $rezaga->folio_rezaga);
        }
    }

    public function test_cerrar_en_bins_sin_rezagas_funciona(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre())
            ->assertOk();

        $this->assertSame('procesado', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_rechaza_cuarto_frio_y_nada_se_escribe(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [
                ['tipo_carga_id' => $this->binHoja->id, 'bins' => 1],
                ['tipo_carga_id' => $this->binCuartoFrio->id, 'bins' => 1],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.1.tipo_carga_id']);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(10, (int) $proceso->fresh()->cantidad_disponible);
        $this->assertSame(0, RezagaEmpaque::count());

        // Mismo rechazo en el primer renglón.
        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['tipo_carga_id' => $this->binCuartoFrio->id, 'bins' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.tipo_carga_id']);
    }

    public function test_cerrar_en_bins_rechaza_bin_de_campo_y_bins_invalidos(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['tipo_carga_id' => $this->binCampo->id, 'bins' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.tipo_carga_id']);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['tipo_carga_id' => $this->binHoja->id, 'bins' => 0]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.bins']);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['bins' => 2]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.tipo_carga_id']);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_rechaza_bin_de_otro_cultivo(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['tipo_carga_id' => $this->tipoCarga->id, 'bins' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins.0.tipo_carga_id']);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_rechaza_mas_de_diez_renglones(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $renglones = array_fill(0, 11, ['tipo_carga_id' => $this->binHoja->id, 'bins' => 1]);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => $renglones,
        ]))->assertStatus(422)->assertJsonValidationErrors(['rezagas_bins']);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
    }

    public function test_cerrar_en_bins_rechaza_rezaga_kg(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezaga_kg' => 50,
            'subtipo_rezaga' => 'hoja',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['rezaga_kg'])
            ->assertJsonPath('errors.rezaga_kg.0', 'En esta temporada la rezaga se captura en bins.');

        // Sin subtipo tampoco debe fallar por otra cosa que el kg.
        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezaga_kg' => 50,
        ]))->assertStatus(422)
            ->assertJsonPath('errors.rezaga_kg.0', 'En esta temporada la rezaga se captura en bins.');

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_rechaza_rezagas_por_tarima(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'usar_rezagas_tarima' => true,
            'rezaga_tarima_produccion_kg' => 80,
        ]))->assertStatus(422)->assertJsonValidationErrors(['usar_rezagas_tarima']);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_acepta_usar_rezagas_tarima_falso(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'usar_rezagas_tarima' => false,
            'rezagas_bins' => [['tipo_carga_id' => $this->binHoja->id, 'bins' => 1]],
        ]))->assertOk();

        $this->assertSame(1, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_no_crea_rezaga_si_el_cierre_falla_por_suma(): void
    {
        $proceso = $this->crearProcesoBins(10);

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", [
            'cantidad_cuarto_frio' => 8,
            'cantidad_fresco' => 5,
            'rezagas_bins' => [['tipo_carga_id' => $this->binHoja->id, 'bins' => 1]],
        ])->assertStatus(422);

        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(0, RezagaEmpaque::count());
    }

    public function test_cerrar_en_bins_revierte_todo_si_falla_una_escritura(): void
    {
        $proceso = $this->crearProcesoBins(10);

        // Simula una falla al escribir la segunda rezaga (ya validado todo).
        $creadas = 0;
        RezagaEmpaque::creating(function () use (&$creadas) {
            if (++$creadas === 2) {
                throw new \RuntimeException('falla simulada');
            }
        });

        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [
                ['tipo_carga_id' => $this->binHoja->id, 'bins' => 3],
                ['tipo_carga_id' => $this->binFresco->id, 'bins' => 2],
            ],
        ]))->assertStatus(500);

        $this->assertSame(2, $creadas);
        $this->assertSame(0, RezagaEmpaque::count());
        $this->assertSame('en_proceso', $proceso->fresh()->status);
        $this->assertSame(10, (int) $proceso->fresh()->cantidad_disponible);
    }


    public function test_cerrar_sin_interruptor_igual_que_hoy(): void
    {
        $proceso = $this->crearProcesoMango(10);

        $respuesta = $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezaga_kg' => 25.5,
            'subtipo_rezaga' => 'hoja',
            'rezaga_observaciones' => 'Mala calidad',
        ]))->assertOk()->assertJsonPath('success', true);

        $respuesta->assertJsonPath('message', 'Folio cerrado completamente');
        $this->assertSame('procesado', $proceso->fresh()->status);

        $rezagas = RezagaEmpaque::where('proceso_id', $proceso->id)->get();
        $this->assertCount(1, $rezagas);
        $this->assertSame(25.5, (float) $rezagas[0]->cantidad_kg);
        $this->assertSame('produccion', $rezagas[0]->tipo_rezaga);
        $this->assertSame('hoja', $rezagas[0]->subtipo_rezaga);
        $this->assertNull($rezagas[0]->cantidad_bins);
        $this->assertArrayNotHasKey('cantidad_bins', $rezagas[0]->toArray());
    }

    public function test_cerrar_sin_interruptor_mantiene_validaciones_de_hoy(): void
    {
        $proceso = $this->crearProcesoMango(10);

        // subtipo sigue siendo obligatorio con rezaga_kg.
        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezaga_kg' => 25,
        ]))->assertStatus(422)->assertJsonValidationErrors(['subtipo_rezaga']);

        // rezagas_bins se ignora en una temporada sin el interruptor.
        $this->postJson(self::BASE."/proceso/{$proceso->id}/cerrar", $this->cuerpoCierre([
            'rezagas_bins' => [['tipo_carga_id' => 999999, 'bins' => 'x']],
        ]))->assertOk();

        $this->assertSame(0, RezagaEmpaque::count());
    }

    private function crearRecepcionSinProceso(bool $enBins, int $cantidad): RecepcionEmpaque
    {
        $temporada = $enBins ? $this->temporadaBins : $this->temporada;

        return RecepcionEmpaque::create([
            'temporada_id' => $temporada->id,
            'entity_id' => $this->entity->id,
            'folio_recepcion' => $enBins ? 'REC-MOVER-BINS' : 'REC-MOVER-MANGO',
            'fecha_recepcion' => '2026-02-01',
            'productor_id' => $this->productorPrincipal->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $enBins ? $this->variedadElote->id : $this->variedad->id,
            'tipo_carga_id' => $enBins ? $this->binCampo->id : $this->tipoCarga->id,
            'cantidad_recibida' => $cantidad,
            'peso_recibido_kg' => $cantidad * 10,
            'peso_bascula' => $cantidad * 10,
            'status' => 'recibida',
        ]);
    }

    public function test_mover_a_proceso_mensaje_en_bins(): void
    {
        $recepcion = $this->crearRecepcionSinProceso(true, 12);

        $this->postJson(self::BASE.'/proceso', [
            'temporada_id' => $this->temporadaBins->id,
            'recepcion_id' => $recepcion->id,
            'cantidad' => 7,
        ])->assertCreated()
            ->assertJsonPath('message', 'Folio movido a procesando (7 bins)');
    }

    public function test_mover_a_proceso_mensaje_sin_interruptor_igual_que_hoy(): void
    {
        $recepcion = $this->crearRecepcionSinProceso(false, 12);

        $this->postJson(self::BASE.'/proceso', [
            'temporada_id' => $this->temporada->id,
            'recepcion_id' => $recepcion->id,
            'cantidad' => 7,
        ])->assertCreated()
            ->assertJsonPath('message', 'Folio movido a procesando (7 uds)');
    }

    public function test_mover_a_proceso_desde_lavado_mensaje_en_bins_y_en_cajas(): void
    {
        $procesoBins = $this->crearProcesoBins(9);
        // 'listo_produccion' no existe en el enum de SQLite: camino de procesado con remanente.
        $procesoBins->update(['status' => 'procesado']);

        $this->postJson(self::BASE.'/proceso', ['proceso_id' => $procesoBins->id])
            ->assertOk()
            ->assertJsonPath('message', 'Folio movido a procesando (9 bins)');

        $procesoMango = $this->crearProcesoMango(8);
        $procesoMango->update(['status' => 'procesado']);

        $this->postJson(self::BASE.'/proceso', ['proceso_id' => $procesoMango->id])
            ->assertOk()
            ->assertJsonPath('message', 'Folio movido a procesando (8 cajas)');
    }
}
