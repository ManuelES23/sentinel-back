<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Empaque;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteProductoresFixtures;
use Tests\TestCase;

class ReporteProductoresFiltersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesReporteProductoresFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReporteProductoresFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_recepciones_filtra_por_productor_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');
        $this->crearMovimientosCompletos($this->productorSecundario, 'B');

        $response = $this->getJson('/api/splendidfarms/operacion-agricola/empaque/recepciones?productor_id='.$this->productorPrincipal->id);

        $response->assertOk();
        $folios = collect($response->json('data'))->pluck('folio_recepcion');
        $this->assertTrue($folios->contains('REC-A'));
        $this->assertFalse($folios->contains('REC-B'));
    }

    public function test_produccion_filtra_por_productor_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');
        $this->crearMovimientosCompletos($this->productorSecundario, 'B');

        $response = $this->getJson('/api/splendidfarms/operacion-agricola/empaque/produccion?productor_id='.$this->productorPrincipal->id);

        $response->assertOk();
        $folios = collect($response->json('data'))->pluck('folio_produccion');
        $this->assertTrue($folios->contains('PDN-A'));
        $this->assertFalse($folios->contains('PDN-B'));
    }

    public function test_embarques_filtra_por_productor_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');
        $this->crearMovimientosCompletos($this->productorSecundario, 'B');

        $response = $this->getJson('/api/splendidfarms/operacion-agricola/empaque/embarques?productor_id='.$this->productorPrincipal->id);

        $response->assertOk();
        $folios = collect($response->json('data'))->pluck('folio_embarque');
        $this->assertTrue($folios->contains('EMB-A'));
        $this->assertFalse($folios->contains('EMB-B'));
    }

    public function test_rezaga_filtra_por_productor_id(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');
        $this->crearMovimientosCompletos($this->productorSecundario, 'B');

        $response = $this->getJson('/api/splendidfarms/operacion-agricola/empaque/rezaga?productor_id='.$this->productorPrincipal->id);

        $response->assertOk();
        $folios = collect($response->json('data'))->pluck('folio_rezaga');
        $this->assertTrue($folios->contains('REZ-A'));
        $this->assertFalse($folios->contains('REZ-B'));
    }

    public function test_recepciones_sin_productor_id_devuelve_todas(): void
    {
        $this->crearMovimientosCompletos($this->productorPrincipal, 'A');
        $this->crearMovimientosCompletos($this->productorSecundario, 'B');

        $response = $this->getJson('/api/splendidfarms/operacion-agricola/empaque/recepciones');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
