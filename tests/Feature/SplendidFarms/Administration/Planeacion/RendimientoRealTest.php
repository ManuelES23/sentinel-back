<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\CierreCosecha;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\SalidaCampoCosecha;
use App\Models\TipoCarga;
use App\Models\Variedad;
use App\Models\VentaCosecha;
use App\Services\Planeacion\RendimientoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * Rendimiento real por temporada y variedad para Planeación: hectáreas
 * cosechadas (mismo tope que el Dashboard de cosecha), cajas empacadas y bins
 * salidos de campo, contando solo las etapas que ya tienen cosecha cerrada.
 */
class RendimientoRealTest extends TestCase
{
    use CreatesReporteEmpaqueFixtures;
    use RefreshDatabase;

    private int $contador = 0;

    private Variedad $otraVariedad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->temporada->lotes()->attach($this->lote->id, [
            'cultivo_id' => $this->cultivo->id,
            'is_active' => true,
        ]);
        $this->otraVariedad = Variedad::create(['cultivo_id' => $this->cultivo->id, 'nombre' => 'Kent', 'user_id' => $this->actingUser->id]);
    }

    private function etapa(Variedad $variedad, float $superficie, ?Lote $lote = null): Etapa
    {
        $this->contador++;

        return Etapa::create([
            'lote_id' => ($lote ?? $this->lote)->id,
            'nombre' => "Etapa {$this->contador}",
            'codigo' => "E{$this->contador}",
            'superficie' => $superficie,
            'variedad_id' => $variedad->id,
            'orden' => $this->contador,
            'is_active' => true,
        ]);
    }

    private function cierre(Etapa $etapa, string $fecha, float $ha): CierreCosecha
    {
        return CierreCosecha::create([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa->id,
            'lote_id' => $etapa->lote_id,
            'fecha_inicio' => $fecha,
            'superficie_cosechada' => $ha,
        ]);
    }

    private function ventaNacional(CierreCosecha $cierre, string $status = 'pagada'): void
    {
        VentaCosecha::create([
            'temporada_id' => $this->temporada->id,
            'cierre_cosecha_id' => $cierre->id,
            'fecha_venta' => '2026-03-15',
            'cliente' => 'Mercado local',
            'producto' => 'Mango',
            'cantidad' => 100,
            'unidad_medida' => 'kg',
            'precio_unitario' => 10,
            'total' => 1000,
            'status' => $status,
        ]);
    }

    private function tipo(string $nombre, ?string $claseRezaga = null): TipoCarga
    {
        return TipoCarga::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => $nombre,
            'peso_estimado_kg' => 300,
            'clase_rezaga' => $claseRezaga,
            'is_active' => true,
        ]);
    }

    private function salida(Etapa $etapa, TipoCarga $tipo, int $cantidad, array $extra = []): void
    {
        SalidaCampoCosecha::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa->id,
            'tipo_carga_id' => $tipo->id,
            'fecha' => '2026-02-02',
            'cantidad' => $cantidad,
            'peso_neto_kg' => $cantidad * 300,
        ], $extra));
    }

    private function empacar(Etapa $etapa, string $sufijo, int $cajas): void
    {
        $fila = $this->crearRecepcionConProduccion($this->productorPrincipal, $sufijo);
        $fila['proceso']->update(['etapa_id' => $etapa->id]);
        $fila['produccion']->update(['total_cajas' => $cajas, 'peso_neto_kg' => $cajas * 18]);
    }

    /** @return array<int, array> filas de la temporada, por variedad */
    private function real(): array
    {
        return collect((new RendimientoReal)->de([$this->temporada->id]))->keyBy('variedad_id')->all();
    }

    /** @return array{0: Etapa, 1: Etapa, 2: Etapa} dos etapas con cierre y una de la otra variedad sin cierre vivo */
    private function escenario(): array
    {
        $a = $this->etapa($this->variedad, 5);
        $b = $this->etapa($this->variedad, 2);
        $c = $this->etapa($this->otraVariedad, 4);

        $this->cierre($a, '2026-02-01', 2);
        $this->cierre($a, '2026-02-02', 1);
        $this->ventaNacional($this->cierre($a, '2026-02-03', 1)); // venta nacional: no cuenta como cosechada
        $this->ventaNacional($this->cierre($b, '2026-02-04', 5), 'cancelada'); // sin venta viva: cosecha, topada a 2 ha
        $this->cierre($c, '2026-02-05', 3)->delete();

        $bin = $this->tipo('Bin');
        $this->salida($a, $bin, 40);
        $this->salida($b, $this->tipo(' bins '), 10);
        $this->salida($a, $this->tipo('Caja de Campo'), 500);
        $this->salida($a, $this->tipo('Bin de hoja', 'hoja'), 7);
        $this->salida($a, $bin, 99, ['eliminado' => true]);

        $this->empacar($a, 'A', 300);
        $this->empacar($b, 'B', 100);
        $this->empacar($c, 'C', 70);

        return [$a, $b, $c];
    }

    public function test_suma_hectareas_cajas_y_bins_por_variedad(): void
    {
        $this->escenario();

        $real = $this->real();

        $this->assertSame(
            ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->variedad->id, 'ha' => 5.0, 'cajas' => 400, 'bins' => 50, 'sin_cierre' => false],
            $real[$this->variedad->id],
        );
        // Empacada sin cierre vivo: no cuenta, solo se avisa que hay datos esperando cierre.
        $this->assertSame(
            ['temporada_id' => $this->temporada->id, 'variedad_id' => $this->otraVariedad->id, 'ha' => 0.0, 'cajas' => 0, 'bins' => 0, 'sin_cierre' => true],
            $real[$this->otraVariedad->id],
        );
    }

    public function test_una_etapa_sin_cierre_no_infla_el_real_de_su_variedad(): void
    {
        $this->escenario();
        $abierta = $this->etapa($this->variedad, 3);
        $this->empacar($abierta, 'D', 900);
        $this->salida($abierta, $this->tipo('Bin grande'), 120);

        $fila = $this->real()[$this->variedad->id];

        $this->assertSame([5.0, 400, 50, true], [$fila['ha'], $fila['cajas'], $fila['bins'], $fila['sin_cierre']]);

        // Al cerrar su cosecha entra completa.
        $this->cierre($abierta, '2026-02-06', 3);
        $fila = $this->real()[$this->variedad->id];

        $this->assertSame([8.0, 1300, 170, false], [$fila['ha'], $fila['cajas'], $fila['bins'], $fila['sin_cierre']]);
    }

    public function test_las_cajas_son_las_del_dashboard_en_las_etapas_con_cierre(): void
    {
        [$a, $b] = $this->escenario();
        $this->empacar($this->etapa($this->variedad, 3), 'D', 900); // sin cierre: el Dashboard la suma, el real no

        $etapas = $this->getJson('/api/splendidfarms/operacion-agricola/cosecha/dashboard/ejecutivo?temporada_id='.$this->temporada->id)
            ->assertOk()->json('data.etapas');
        $cerradas = collect($etapas)->whereIn('id', [$a->id, $b->id]);

        $this->assertCount(2, $cerradas);
        $this->assertSame($cerradas->sum('empaque.cajas'), $this->real()[$this->variedad->id]['cajas']);
    }

    public function test_temporada_en_bins_cuenta_todas_las_salidas_y_no_reporta_cajas(): void
    {
        $this->escenario();
        $this->temporada->forceFill(['opera_en_bins' => true])->save();

        $fila = $this->real()[$this->variedad->id];

        $this->assertNull($fila['cajas']);
        $this->assertSame(557, $fila['bins']); // 40 + 10 + 500 + 7: en bins toda salida son bins

        // En bins las cajas no avisan: la etapa sin cierre solo tiene cajas empacadas.
        $this->assertArrayNotHasKey($this->otraVariedad->id, $this->real());
    }

    public function test_lote_inactivo_en_la_temporada_no_cuenta_y_sin_datos_no_hay_filas(): void
    {
        $this->etapa($this->variedad, 5);
        $this->assertSame([], $this->real());

        $this->escenario();
        $this->temporada->lotes()->updateExistingPivot($this->lote->id, ['is_active' => false]);

        $this->assertSame([], $this->real());
        $this->assertSame([], (new RendimientoReal)->de([]));
    }
}
