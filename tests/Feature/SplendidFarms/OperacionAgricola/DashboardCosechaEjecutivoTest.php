<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\CierreCosecha;
use App\Models\EmbarqueEmpaque;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\SalidaCampoCosecha;
use App\Models\VentaCosecha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * Dashboard de cosecha, pestaña Ejecutivo: por etapa, los cortes (cierres y
 * salidas por día), la venta nacional y el empaque con su destino.
 */
class DashboardCosechaEjecutivoTest extends TestCase
{
    use CreatesReporteEmpaqueFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/cosecha/dashboard/ejecutivo';

    private int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->temporada->lotes()->attach($this->lote->id, [
            'cultivo_id' => $this->cultivo->id,
            'is_active' => true,
        ]);
    }

    private function crearEtapa(array $extra = [], ?Lote $lote = null): Etapa
    {
        $this->contador++;

        return Etapa::create(array_merge([
            'lote_id' => ($lote ?? $this->lote)->id,
            'nombre' => "Etapa {$this->contador}",
            'codigo' => "E{$this->contador}",
            'superficie' => 5,
            'orden' => $this->contador,
            'is_active' => true,
        ], $extra));
    }

    private function crearCierre(Etapa $etapa, string $fecha, float $ha): CierreCosecha
    {
        return CierreCosecha::create([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa->id,
            'lote_id' => $etapa->lote_id,
            'fecha_inicio' => $fecha,
            'superficie_cosechada' => $ha,
        ]);
    }

    private function crearSalida(?Etapa $etapa, string $fecha, float $kg, array $extra = []): SalidaCampoCosecha
    {
        return SalidaCampoCosecha::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa?->id,
            'fecha' => $fecha,
            'cantidad' => 10,
            'peso_neto_kg' => $kg,
        ], $extra));
    }

    private function crearVenta(CierreCosecha $cierre, float $cantidad, string $unidad, string $status = 'pagada'): VentaCosecha
    {
        return VentaCosecha::create([
            'temporada_id' => $this->temporada->id,
            'cierre_cosecha_id' => $cierre->id,
            'fecha_venta' => '2026-03-15',
            'cliente' => 'Mercado local',
            'producto' => 'Mango',
            'cantidad' => $cantidad,
            'unidad_medida' => $unidad,
            'precio_unitario' => 10,
            'total' => $cantidad * 10,
            'status' => $status,
        ]);
    }

    /** Producción de $cajas / $kg ligada a un proceso de la etapa (o sin etapa si es null). */
    private function crearProduccion(?Etapa $etapa, string $sufijo, int $cajas, float $kg): array
    {
        $fila = $this->crearRecepcionConProduccion($this->productorPrincipal, $sufijo);
        $fila['proceso']->update(['etapa_id' => $etapa?->id]);
        $fila['produccion']->update(['total_cajas' => $cajas, 'peso_neto_kg' => $kg]);

        return $fila;
    }

    private function crearEmbarque(string $tipo, string $folio, $produccion, int $cajas): EmbarqueEmpaque
    {
        $embarque = EmbarqueEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'folio_embarque' => $folio,
            'tipo_venta' => $tipo,
            'fecha_embarque' => '2026-02-10',
        ]);
        EmbarqueEmpaqueDetalle::create([
            'embarque_id' => $embarque->id,
            'produccion_id' => $produccion->id,
            'cajas' => $cajas,
            'peso_kg' => $cajas * 18,
        ]);

        return $embarque;
    }

    private function consultar(): TestResponse
    {
        return $this->getJson(self::URL.'?temporada_id='.$this->temporada->id);
    }

    public function test_suma_cortes_por_dia_sin_topar(): void
    {
        $etapa = $this->crearEtapa(['superficie' => 5]);
        $this->crearCierre($etapa, '2026-03-10', 3);
        $this->crearCierre($etapa, '2026-03-10', 4);
        $this->crearSalida($etapa, '2026-03-10', 12000);

        $r = $this->consultar()->assertOk()->assertJsonPath('success', true);

        $r->assertJsonCount(1, 'data.etapas');
        $fila = $r->json('data.etapas.0');
        $this->assertSame($etapa->id, $fila['id']);
        $this->assertSame('5.0000', $fila['superficie']);
        $this->assertSame([[
            'fecha' => '2026-03-10',
            'ha' => '7.0000',
            'kg' => '12000.00',
            'venta_nacional' => false,
        ]], $fila['cortes']);
        $r->assertJsonPath('data.temporada.id', $this->temporada->id)
            ->assertJsonPath('data.temporada.nombre', 'Mango 2026');
    }

    public function test_respuesta_trae_lote_productor_y_catalogos_de_la_etapa(): void
    {
        $etapa = $this->crearEtapa([
            'variedad_id' => $this->variedad->id,
            'fecha_siembra_estimada' => '2026-01-05',
            'fecha_siembra_real' => '2026-01-12',
            'fecha_cosecha_estimada' => '2026-05-01',
        ]);

        $fila = $this->consultar()->assertOk()->json('data.etapas.0');

        $this->assertSame($etapa->id, $fila['id']);
        $this->assertSame(['id' => $this->lote->id, 'nombre' => 'Lote 1'], $fila['lote']);
        $this->assertSame(['id' => $this->productorPrincipal->id, 'nombre' => 'Juan Pérez'], $fila['productor']);
        $this->assertSame(['id' => $this->zonaCultivo->id, 'nombre' => 'Zona Norte'], $fila['zona']);
        $this->assertSame(['id' => $this->variedad->id, 'nombre' => 'Ataulfo'], $fila['variedad']);
        $this->assertNull($fila['tipo_variedad']);
        $this->assertSame('2026-01-05', $fila['fecha_siembra_estimada']);
        $this->assertSame('2026-01-12', $fila['fecha_siembra_real']);
        $this->assertSame('2026-05-01', $fila['fecha_cosecha_estimada']);
        $this->assertNull($fila['fecha_cosecha_proyectada']);
        $this->assertSame([], $fila['cortes']);
        $this->assertSame(['ha' => '0.0000', 'kg' => '0.00'], $fila['venta_nacional']);
        $this->assertSame(
            ['cajas' => 0, 'kg' => '0.00', 'cajas_exportacion' => 0, 'cajas_nacional' => 0],
            $fila['empaque']
        );
    }

    public function test_venta_nacional_por_cierre(): void
    {
        $etapa = $this->crearEtapa(['superficie' => 10]);
        $conVenta = $this->crearCierre($etapa, '2026-03-10', 2);
        $this->crearVenta($conVenta, 3.5, 'ton');
        $this->crearVenta($conVenta, 40, 'cajas'); // unidad desconocida: aviso, sin kg

        $cancelada = $this->crearCierre($etapa, '2026-03-12', 6);
        $this->crearVenta($cancelada, 100, 'kg', 'cancelada');

        $r = $this->consultar()->assertOk();
        $fila = $r->json('data.etapas.0');

        $this->assertSame(['ha' => '2.0000', 'kg' => '3500.00'], $fila['venta_nacional']);
        $this->assertSame([
            ['fecha' => '2026-03-10', 'ha' => '2.0000', 'kg' => '3500.00', 'venta_nacional' => true],
            ['fecha' => '2026-03-12', 'ha' => '6.0000', 'kg' => '0.00', 'venta_nacional' => false],
        ], $fila['cortes']);

        $avisos = $r->json('data.avisos');
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('cajas', $avisos[0]);
    }

    public function test_empaque_por_proceso_y_destino(): void
    {
        $etapa = $this->crearEtapa();
        $fila = $this->crearProduccion($etapa, 'A', 100, 1800);
        $this->crearEmbarque('exportacion', 'EMB-1', $fila['produccion'], 60);
        $this->crearEmbarque('nacional', 'EMB-2', $fila['produccion'], 25);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.etapas.0.empaque', [
                'cajas' => 100,
                'kg' => '1800.00',
                'cajas_exportacion' => 60,
                'cajas_nacional' => 25,
            ]);
    }

    public function test_excluye_borrados(): void
    {
        $etapa = $this->crearEtapa();

        $this->crearSalida($etapa, '2026-03-10', 500, ['eliminado' => true]);
        $this->crearCierre($etapa, '2026-03-11', 2)->delete();

        $borrada = $this->crearProduccion($etapa, 'A', 70, 700);
        $borrada['produccion']->delete();

        $viva = $this->crearProduccion($etapa, 'B', 30, 300);
        $embarqueBorrado = $this->crearEmbarque('exportacion', 'EMB-1', $viva['produccion'], 20);
        $embarqueBorrado->delete();
        $this->crearEmbarque('nacional', 'EMB-2', $viva['produccion'], 10);

        $fila = $this->consultar()->assertOk()->json('data.etapas.0');

        $this->assertSame([], $fila['cortes']);
        $this->assertSame(
            ['cajas' => 30, 'kg' => '300.00', 'cajas_exportacion' => 0, 'cajas_nacional' => 10],
            $fila['empaque']
        );
    }

    public function test_inactivas_y_lotes_fuera(): void
    {
        $sinCosecha = $this->crearEtapa(['is_active' => false]);
        $conSalida = $this->crearEtapa(['is_active' => false]);
        $this->crearSalida($conSalida, '2026-03-10', 800);

        $loteFuera = Lote::create([
            'productor_id' => $this->productorPrincipal->id,
            'zona_cultivo_id' => $this->zonaCultivo->id,
            'nombre' => 'Lote fuera',
            'is_active' => true,
        ]);
        $this->temporada->lotes()->attach($loteFuera->id, [
            'cultivo_id' => $this->cultivo->id,
            'is_active' => false,
        ]);
        $deLoteFuera = $this->crearEtapa([], $loteFuera);
        $this->crearSalida($deLoteFuera, '2026-03-10', 900);

        $ids = collect($this->consultar()->assertOk()->json('data.etapas'))->pluck('id')->all();

        $this->assertSame([$conSalida->id], $ids);
        $this->assertNotContains($sinCosecha->id, $ids);
        $this->assertNotContains($deLoteFuera->id, $ids);
    }

    public function test_sin_etapa(): void
    {
        $this->crearEtapa();
        $this->crearSalida(null, '2026-03-10', 900);
        $this->crearProduccion(null, 'A', 10, 100);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.sin_etapa', ['kg' => '900.00', 'cajas' => 10]);
    }

    public function test_valida_temporada(): void
    {
        $this->getJson(self::URL)
            ->assertStatus(422)
            ->assertJsonValidationErrors('temporada_id');

        $this->getJson(self::URL.'?temporada_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('temporada_id');
    }
}
