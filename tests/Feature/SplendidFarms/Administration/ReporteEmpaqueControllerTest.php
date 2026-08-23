<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Productor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

class ReporteEmpaqueControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesReporteEmpaqueFixtures;

    private const BASE_URL = '/api/splendidfarms/administration/reportes/empaque/recepcion';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_requiere_temporada_id(): void
    {
        $this->getJson(self::BASE_URL)->assertStatus(422);
    }

    public function test_lista_recepciones_con_metricas_correctas(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id);

        $response->assertOk()->assertJson(['success' => true]);
        $fila = collect($response->json('data.recepciones'))->first();

        $this->assertSame('REC-A', $fila['folio_recepcion']);
        $this->assertSame(100, $fila['cantidad_recibida']);
        $this->assertEquals(1000.0, $fila['kg_recibidos']);
        $this->assertSame(50, $fila['cajas_producidas']);
        $this->assertEquals(500.0, $fila['kg_producidos']);
        $this->assertSame('Juan Pérez', $fila['productor']['nombre_completo']);
        $this->assertSame('Ataulfo', $fila['variedad']['nombre']);

        $resumen = $response->json('data.resumen');
        $this->assertSame(1, $resumen['total_recepciones']);
        $this->assertEquals(1000.0, $resumen['total_kg_recibidos']);
        $this->assertSame(50, $resumen['total_cajas_producidas']);
        $this->assertEquals(50.0, $resumen['rendimiento_pct']); // 500kg producidos / 1000kg recibidos
    }

    public function test_excluye_recepciones_de_otra_temporada(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');

        $otroCultivo = \App\Models\Cultivo::create(['nombre' => 'Aguacate']);
        $otraTemporada = \App\Models\Temporada::create([
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

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$otraTemporada->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.recepciones'));
    }

    public function test_excluye_recepciones_eliminadas(): void
    {
        $datos = $this->crearRecepcionConProduccion($this->productorPrincipal, 'DEL');
        $datos['recepcion']->delete();

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.recepciones'));
    }

    public function test_filtra_por_entity_id(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');

        $otraEntity = \App\Models\Entity::create([
            'branch_id' => $this->entity->branch_id,
            'entity_type_id' => $this->entity->entity_type_id,
            'code' => 'EMP-002',
            'name' => 'Empaque Secundario',
            'slug' => 'empaque-secundario',
            'is_active' => true,
        ]);

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&entity_id='.$otraEntity->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.recepciones'));
    }

    public function test_filtra_por_productor_id(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');
        $this->crearRecepcionConProduccion($this->productorSecundario, 'B');

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&productor_id='.$this->productorSecundario->id);

        $response->assertOk();
        $folios = collect($response->json('data.recepciones'))->pluck('folio_recepcion');
        $this->assertSame(['REC-B'], $folios->all());
    }

    public function test_filtra_por_variedad_id(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A');

        $otraVariedad = \App\Models\Variedad::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Kent',
            'user_id' => $this->actingUser->id,
        ]);

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&variedad_id='.$otraVariedad->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.recepciones'));
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'A', ['fecha_recepcion' => '2026-02-01']);
        $this->crearRecepcionConProduccion($this->productorSecundario, 'B', ['fecha_recepcion' => '2026-03-15']);

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&fecha_desde=2026-03-01&fecha_hasta=2026-03-31');

        $response->assertOk();
        $folios = collect($response->json('data.recepciones'))->pluck('folio_recepcion');
        $this->assertSame(['REC-B'], $folios->all());
    }

    public function test_filtra_por_search_de_folio(): void
    {
        $this->crearRecepcionConProduccion($this->productorPrincipal, 'ESPECIAL');
        $this->crearRecepcionConProduccion($this->productorSecundario, 'B');

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id.'&search=ESPECIAL');

        $response->assertOk();
        $folios = collect($response->json('data.recepciones'))->pluck('folio_recepcion');
        $this->assertSame(['REC-ESPECIAL'], $folios->all());
    }

    public function test_cajas_producidas_se_dividen_por_recepcion_de_cada_detalle(): void
    {
        $this->crearRecepcionConProduccionMultiEntrada(
            $this->productorPrincipal,
            'MULTI',
            $this->productorSecundario,
        );

        $response = $this->getJson(self::BASE_URL.'?temporada_id='.$this->temporada->id);

        $response->assertOk();
        $porFolio = collect($response->json('data.recepciones'))->keyBy('folio_recepcion');

        // El pallet tiene 50 cajas totales repartidas en 2 filas de detalle:
        // 30 vía el proceso de REC-MULTI (la recepción original del pallet)
        // y 20 vía el proceso de REC-MULTI-B (otra recepción que aportó
        // piezas al mismo pallet). Cada folio de recepción se queda con las
        // cajas que le corresponden según SU PROPIO proceso — igual
        // criterio que ReporteProductoresController usa para productor_id,
        // aquí aplicado a recepcion_id. Ninguna recepción se queda con las
        // 50 completas.
        $this->assertSame(30, $porFolio['REC-MULTI']['cajas_producidas']);
        $this->assertSame(20, $porFolio['REC-MULTI-B']['cajas_producidas']);
    }
}
