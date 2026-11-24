<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\Etapa;
use App\Models\ProcesoEmpaque;
use App\Models\SalidaCampoCosecha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Balance de masas, KPIs de empaque, dashboard ejecutivo de cosecha y mensaje de
 * reabrir en una temporada que opera en bins: traen bins y el peso estimado; en
 * una temporada sin el interruptor (Mango) el JSON es el de siempre, sin claves nuevas.
 */
class BalanceYDashboardsBinsTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const EMPAQUE = '/api/splendidfarms/operacion-agricola/empaque';

    private const COSECHA = '/api/splendidfarms/operacion-agricola/cosecha';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->registrarConcatEnSqlite();
    }

    /**
     * Solo para el esquema de pruebas: el top de productores de los KPIs usa CONCAT
     * (MySQL) y SQLite no lo trae. No cambia ningún código de producción.
     */
    private function registrarConcatEnSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$partes) => implode('', $partes));
    }

    /** Producción de 3000 kg estimados sin báscula de un proceso de 10 bins, con una rezaga de 2 bins / 300 kg. */
    private function folioEnBins(): ProcesoEmpaque
    {
        $produccion = $this->crearProduccionBins(['peso_neto_kg' => 3000, 'peso_bascula_kg' => null]);
        $proceso = $produccion->proceso;
        $this->crearRezagaBins($proceso, ['cantidad_bins' => 2, 'cantidad_kg' => 300]);

        return $proceso;
    }

    /** Lo mismo en Mango: recepción de 100 cajas con la producción de 3000 kg sin báscula. */
    private function folioMango(): void
    {
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'BAL-MANGO');
        $base['produccion']->update(['peso_neto_kg' => 3000, 'peso_bascula_kg' => null]);
        $this->crearRezagaBins($base['proceso'], [
            'temporada_id' => $this->temporada->id,
            'tipo_carga_id' => $this->tipoCarga->id,
            'cantidad_bins' => null,
            'cantidad_kg' => 300,
            'subtipo_rezaga' => null,
        ]);
    }

    private function balance(int $temporadaId)
    {
        return $this->getJson(self::EMPAQUE.'/balance-masas?temporada_id='.$temporadaId);
    }

    // ── Balance de masas ──────────────────────────────────────────────

    public function test_balance_en_bins_usa_estimado(): void
    {
        $this->folioEnBins();

        $r = $this->balance($this->temporadaBins->id)->assertOk();

        $r->assertJsonPath('data.opera_en_bins', true);
        $r->assertJsonCount(1, 'data.rows');
        $r->assertJsonPath('data.rows.0.peso_producido_bascula_kg', 3000);
        $r->assertJsonPath('data.rows.0.bins_entrada', 10);
        $r->assertJsonPath('data.rows.0.bins_rezaga', 2);
        $r->assertJsonPath('data.rows.0.cantidad_entrada', 10);
        // Con el estimado la salida de control y el aprovechamiento salen con sentido.
        $r->assertJsonPath('data.rows.0.peso_salida_control_kg', 3300);
        $r->assertJsonPath('data.rows.0.diferencia_kg', 700);
        $r->assertJsonPath('data.rows.0.aprovechamiento_pct', 82.5);

        $r->assertJsonPath('data.summary.peso_producido_bascula_kg', 3000);
        $r->assertJsonPath('data.summary.peso_salida_control_kg', 3300);
        $r->assertJsonPath('data.summary.bins_entrada', 10);
        $r->assertJsonPath('data.summary.bins_rezaga', 2);
    }

    public function test_balance_en_bins_con_rezaga_vendida_cuenta_los_bins_historicos(): void
    {
        $proceso = $this->crearProcesoBins(10);
        // 3 bins de hoja capturados: se vendió 1 y quedan 2 (la venta no toca nada más).
        $rezaga = $this->crearRezagaBins($proceso, ['cantidad_bins' => 2, 'cantidad_kg' => 300]);
        $this->crearVentaDeRezaga($rezaga, 150, [], 1);
        // Otra rezaga vendida por completo: 0 bins restantes, 4 vendidos.
        $vendida = $this->crearRezagaBins($proceso, [
            'cantidad_bins' => 0, 'cantidad_kg' => 0, 'status' => 'vendida',
        ]);
        $this->crearVentaDeRezaga($vendida, 600, [], 4);

        $r = $this->balance($this->temporadaBins->id)->assertOk();

        $r->assertJsonPath('data.rows.0.bins_rezaga', 7);
        $r->assertJsonPath('data.rows.0.peso_rezaga_total_kg', 1050);
        $r->assertJsonPath('data.summary.bins_rezaga', 7);
    }

    public function test_balance_sin_interruptor_igual(): void
    {
        $this->folioMango();

        $r = $this->balance($this->temporada->id)->assertOk();

        $r->assertJsonCount(1, 'data.rows');
        $r->assertJsonPath('data.rows.0.peso_producido_bascula_kg', 0);
        $r->assertJsonPath('data.rows.0.peso_produccion_kg', 3000);
        $r->assertJsonMissingPath('data.opera_en_bins');
        $r->assertJsonMissingPath('data.rows.0.bins_entrada');
        $r->assertJsonMissingPath('data.rows.0.bins_rezaga');
        $r->assertJsonMissingPath('data.summary.bins_entrada');
        $r->assertJsonMissingPath('data.summary.bins_rezaga');
    }

    // ── KPIs del dashboard de empaque ─────────────────────────────────

    public function test_kpis_empaque_en_bins_trae_bins(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $this->crearProcesoBins(5);

        $this->crearRezagaBins($proceso, ['cantidad_bins' => 3, 'cantidad_kg' => 450]);
        // Pendiente con una venta parcial: quedan 2 de 3.
        $parcial = $this->crearRezagaBins($proceso, ['cantidad_bins' => 2, 'cantidad_kg' => 300]);
        $this->crearVentaDeRezaga($parcial, 150, [], 1);
        // Vendida por completo: 2 bins que ya no están disponibles.
        $vendida = $this->crearRezagaBins($proceso, ['cantidad_bins' => 0, 'cantidad_kg' => 0, 'status' => 'vendida']);
        $this->crearVentaDeRezaga($vendida, 300, [], 2);

        $r = $this->getJson(self::EMPAQUE.'/dashboard/kpis?temporada_id='.$this->temporadaBins->id
            .'&from=2026-01-01&to=2026-12-31')->assertOk();

        $r->assertJsonPath('data.opera_en_bins', true);
        $r->assertJsonPath('data.acumulado.recepciones.bins', 15);
        // Disponible: 3 + 2. Generada: lo disponible + lo vendido (1 + 2).
        $r->assertJsonPath('data.acumulado.rezaga.disponible_bins', 5);
        $r->assertJsonPath('data.acumulado.rezaga.generada_bins', 8);
    }

    public function test_kpis_empaque_sin_interruptor_igual(): void
    {
        $this->folioMango();

        $r = $this->getJson(self::EMPAQUE.'/dashboard/kpis?temporada_id='.$this->temporada->id
            .'&from=2026-01-01&to=2026-12-31')->assertOk();

        $r->assertJsonPath('data.acumulado.recepciones.count', 1);
        $r->assertJsonMissingPath('data.opera_en_bins');
        $r->assertJsonMissingPath('data.hoy.recepciones.bins');
        $r->assertJsonMissingPath('data.acumulado.recepciones.bins');
        $r->assertJsonMissingPath('data.acumulado.rezaga.generada_bins');
        $r->assertJsonMissingPath('data.acumulado.rezaga.disponible_bins');
    }

    // ── Dashboard ejecutivo de cosecha ────────────────────────────────

    private function etapaDe(string $temporada): Etapa
    {
        $temp = $temporada === 'bins' ? $this->temporadaBins : $this->temporada;
        $temp->lotes()->attach($this->lote->id, [
            'cultivo_id' => $temp->cultivo_id,
            'is_active' => true,
        ]);

        return Etapa::create([
            'lote_id' => $this->lote->id,
            'nombre' => 'Etapa 1',
            'codigo' => 'E1',
            'superficie' => 5,
            'orden' => 1,
            'is_active' => true,
        ]);
    }

    private function salida(int $temporadaId, ?Etapa $etapa, string $fecha, int $cantidad, float $kg): void
    {
        SalidaCampoCosecha::create([
            'temporada_id' => $temporadaId,
            'etapa_id' => $etapa?->id,
            'fecha' => $fecha,
            'cantidad' => $cantidad,
            'peso_neto_kg' => $kg,
        ]);
    }

    public function test_ejecutivo_en_bins_trae_bins(): void
    {
        $etapa = $this->etapaDe('bins');
        $this->salida($this->temporadaBins->id, $etapa, '2026-03-10', 4, 1600);
        $this->salida($this->temporadaBins->id, $etapa, '2026-03-10', 6, 2400);
        $this->salida($this->temporadaBins->id, $etapa, '2026-03-11', 5, 2000);

        $r = $this->getJson(self::COSECHA.'/dashboard/ejecutivo?temporada_id='.$this->temporadaBins->id)
            ->assertOk();

        $r->assertJsonPath('data.opera_en_bins', true);
        $r->assertJsonCount(2, 'data.etapas.0.cortes');
        $r->assertJsonPath('data.etapas.0.cortes.0.fecha', '2026-03-10');
        $r->assertJsonPath('data.etapas.0.cortes.0.bins', 10);
        $r->assertJsonPath('data.etapas.0.cortes.0.kg', '4000.00');
        $r->assertJsonPath('data.etapas.0.cortes.1.bins', 5);
    }

    public function test_ejecutivo_en_bins_sin_etapa_trae_bins(): void
    {
        $this->etapaDe('bins');
        $this->salida($this->temporadaBins->id, null, '2026-03-10', 3, 1200);
        $this->salida($this->temporadaBins->id, null, '2026-03-12', 2, 800);

        $r = $this->getJson(self::COSECHA.'/dashboard/ejecutivo?temporada_id='.$this->temporadaBins->id)
            ->assertOk();

        $r->assertJsonPath('data.opera_en_bins', true);
        $r->assertJsonPath('data.sin_etapa.bins', 5);
        $r->assertJsonPath('data.sin_etapa.kg', '2000.00');
    }

    public function test_ejecutivo_sin_interruptor_igual(): void
    {
        $etapa = $this->etapaDe('mango');
        $this->salida($this->temporada->id, $etapa, '2026-03-10', 4, 1600);
        $this->salida($this->temporada->id, null, '2026-03-10', 3, 1200);

        $r = $this->getJson(self::COSECHA.'/dashboard/ejecutivo?temporada_id='.$this->temporada->id)
            ->assertOk();

        $r->assertJsonPath('data.etapas.0.cortes.0.kg', '1600.00');
        $r->assertJsonPath('data.sin_etapa.kg', '1200.00');
        $r->assertJsonMissingPath('data.opera_en_bins');
        $r->assertJsonMissingPath('data.etapas.0.cortes.0.bins');
        $r->assertJsonMissingPath('data.sin_etapa.bins');
    }

    // ── Reabrir un folio ──────────────────────────────────────────────

    public function test_reabrir_en_bins_dice_bins(): void
    {
        $proceso = $this->crearProcesoBins(10);
        $proceso->update(['status' => 'procesado', 'cantidad_disponible' => 0]);

        $this->postJson(self::EMPAQUE."/proceso/{$proceso->id}/reabrir")
            ->assertOk()
            ->assertJsonPath('message', "Folio {$proceso->folio_proceso} reabierto con disponibilidad restaurada (10 bins)");
    }

    public function test_reabrir_sin_interruptor_dice_cajas(): void
    {
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, 'REABRIR-MANGO');
        $base['produccion']->forceDelete();
        $proceso = $base['proceso'];
        $proceso->update([
            'status' => 'procesado',
            'cantidad_entrada' => 10,
            'cantidad_disponible' => 0,
        ]);

        $this->postJson(self::EMPAQUE."/proceso/{$proceso->id}/reabrir")
            ->assertOk()
            ->assertJsonPath('message', "Folio {$proceso->folio_proceso} reabierto con disponibilidad restaurada (10 cajas)");
    }
}
