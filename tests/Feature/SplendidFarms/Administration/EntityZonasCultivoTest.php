<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\ZonaCultivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class EntityZonasCultivoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    public function test_update_sincroniza_zonas_y_la_respuesta_las_incluye(): void
    {
        $this->setUpAlmacenFixtures();
        $norte = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);
        $sur = ZonaCultivo::create(['nombre' => 'Zona Sur', 'is_active' => true]);
        Sanctum::actingAs($this->crearAdmin());
        $url = "/api/splendidfarms/administration/organizacion/entidades/{$this->almacenA->id}";

        $response = $this->putJson($url, ['zona_cultivo_ids' => [$norte->id, $sur->id]], $this->headersEmpresa());

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['Zona Norte', 'Zona Sur'],
            collect($response->json('data.zonas_cultivo'))->pluck('nombre')->all(),
        );

        $this->putJson($url, ['zona_cultivo_ids' => []], $this->headersEmpresa())->assertOk();
        $this->assertDatabaseCount('entity_zona_cultivo', 0);

        $this->putJson($url, ['name' => 'Almacén A renombrado'], $this->headersEmpresa())->assertOk();
        $this->assertDatabaseCount('entity_zona_cultivo', 0);
    }
}
