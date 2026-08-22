<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Cultivo;
use App\Models\Productor;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteProductoresFixtures;
use Tests\TestCase;

class ReporteProductoresControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesReporteProductoresFixtures;

    private const BASE_URL = '/api/splendidfarms/administration/reportes/productores';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteProductoresFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_lista_todos_los_productores_del_catalogo_incluyendo_sin_movimientos(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk()->assertJson(['success' => true]);
        $ids = collect($response->json('data.productores'))->pluck('productor.id');
        $this->assertTrue($ids->contains($this->productorPrincipal->id));
        $this->assertTrue($ids->contains($this->productorSecundario->id));
    }

    public function test_calcula_metricas_agregadas_correctamente(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk();
        $fila = collect($response->json('data.productores'))
            ->firstWhere('productor.id', $this->productorPrincipal->id);

        $this->assertNotNull($fila);
        $this->assertSame(1, $fila['metricas']['total_salidas_campo']);
        $this->assertEquals(1000, $fila['metricas']['total_kilos_recibidos']);
        $this->assertSame(50, $fila['metricas']['total_cajas_producidas']);
        $this->assertSame(50, $fila['metricas']['total_cajas_embarcadas']);
        $this->assertEquals(3.0, $fila['metricas']['porcentaje_rezaga']); // 30kg rezaga / 1000kg recibidos
    }

    public function test_productor_sin_movimientos_devuelve_metricas_en_cero(): void
    {
        $response = $this->getJson(self::BASE_URL);

        $response->assertOk();
        $fila = collect($response->json('data.productores'))
            ->firstWhere('productor.id', $this->productorSecundario->id);

        $this->assertNotNull($fila);
        $this->assertSame(0, $fila['metricas']['total_salidas_campo']);
        $this->assertEquals(0, $fila['metricas']['total_kilos_recibidos']);
        $this->assertSame(0, $fila['metricas']['porcentaje_rezaga']);
    }

    public function test_filtra_por_temporada_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');

        $otroCultivo = Cultivo::create(['nombre' => 'Aguacate']);
        $otraTemporada = Temporada::create([
            'cultivo_id' => $otroCultivo->id,
            'nombre' => 'Aguacate 2026',
            'locacion' => 'Michoacán',
            'folio_temporada' => $otroCultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $this->actingUser->id,
        ]);
        $otraTemporada->productores()->attach($this->productorPrincipal->id, ['is_active' => true]);

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$otraTemporada->id);

        $response->assertOk();
        $ids = collect($response->json('data.productores'))->pluck('productor.id');
        $this->assertTrue($ids->contains($this->productorPrincipal->id));
        $this->assertFalse($ids->contains($this->productorSecundario->id));

        // Está en el catálogo de esa temporada, pero sus movimientos son de $this->temporada — deben quedar en cero.
        $fila = collect($response->json('data.productores'))->firstWhere('productor.id', $this->productorPrincipal->id);
        $this->assertSame(0, $fila['metricas']['total_salidas_campo']);
    }

    public function test_filtra_por_tipo_de_productor(): void
    {
        $this->productorSecundario->update(['tipo' => Productor::TIPO_INTERNO]);

        $response = $this->getJson(self::BASE_URL.'?tipo=interno');

        $response->assertOk();
        $ids = collect($response->json('data.productores'))->pluck('productor.id');
        $this->assertTrue($ids->contains($this->productorSecundario->id));
        $this->assertFalse($ids->contains($this->productorPrincipal->id));
    }

    public function test_excluye_registros_eliminados_de_las_metricas(): void
    {
        $movimientos = $this->crearMovimientosCompletos($this->productorPrincipal, 'DEL');

        // salidas_campo_cosecha usa la columna `eliminado` (no SoftDeletes real).
        $movimientos['salida']->update(['eliminado' => true]);
        // Estas cuatro sí usan SoftDeletes real (deleted_at).
        $movimientos['recepcion']->delete();
        $movimientos['produccion']->delete();
        $movimientos['rezaga']->delete();

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk();
        $fila = collect($response->json('data.productores'))
            ->firstWhere('productor.id', $this->productorPrincipal->id);

        $this->assertNotNull($fila);
        $this->assertSame(0, $fila['metricas']['total_salidas_campo']);
        $this->assertEquals(0, $fila['metricas']['total_kilos_recibidos']);
        $this->assertSame(0, $fila['metricas']['total_cajas_producidas']);
        $this->assertSame(0, $fila['metricas']['total_cajas_embarcadas']);
        $this->assertSame(0, $fila['metricas']['porcentaje_rezaga']);
    }

    public function test_filtra_por_cultivo_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'CULT');

        $response = $this->getJson(self::BASE_URL.'?cultivo_id='.$this->cultivo->id);
        $response->assertOk();
        $ids = collect($response->json('data.productores'))->pluck('productor.id');
        $this->assertTrue($ids->contains($this->productorPrincipal->id));

        $otroCultivo = \App\Models\Cultivo::create(['nombre' => 'Cultivo Ajeno']);
        $response = $this->getJson(self::BASE_URL.'?cultivo_id='.$otroCultivo->id);
        $response->assertOk();
        $ids = collect($response->json('data.productores'))->pluck('productor.id');
        $this->assertFalse($ids->contains($this->productorPrincipal->id));
    }

    public function test_metricas_incluyen_pallets_mixtos(): void
    {
        $movimientos = $this->crearMovimientosCompletos($this->productorPrincipal, 'MIX');

        // Simula un pallet mixto: el proceso_id del pallet queda NULL
        // (como hace ProduccionEmpaqueController::mixtear()), y el
        // productor real vive en produccion_empaque_detalles.
        $movimientos['produccion']->update(['proceso_id' => null]);
        \App\Models\ProduccionEmpaqueDetalle::create([
            'produccion_id' => $movimientos['produccion']->id,
            'numero_entrada' => 1,
            'proceso_id' => $movimientos['proceso']->id,
            'fecha_produccion' => '2026-02-02',
            'total_cajas' => 50,
            'peso_neto_kg' => 500,
        ]);

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk();
        $fila = collect($response->json('data.productores'))
            ->firstWhere('productor.id', $this->productorPrincipal->id);

        $this->assertNotNull($fila);
        $this->assertSame(50, $fila['metricas']['total_cajas_producidas']);
        $this->assertSame(50, $fila['metricas']['total_cajas_embarcadas']);
    }
}
