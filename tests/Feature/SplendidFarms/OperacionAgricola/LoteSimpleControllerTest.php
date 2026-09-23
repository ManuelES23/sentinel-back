<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\Cultivo;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ZonaCultivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoteSimpleControllerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/agricola/lotes';

    private Temporada $temporada;
    private Productor $productor;
    private ZonaCultivo $zona;

    /** Polígono pequeño (~1 ha) en Los Mochis */
    private array $poligono = [
        [25.7922, -108.9939],
        [25.7922, -108.9929],
        [25.7931, -108.9929],
        [25.7931, -108.9939],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $cultivo = Cultivo::create(['nombre' => 'Mango']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $user->id,
        ]);
        $this->productor = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);
        $this->zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);
    }

    private function crearLoteConMapa(): Lote
    {
        $response = $this->postJson(self::URL, [
            'nombre' => 'Keitt A',
            'zona_cultivo_id' => (string) $this->zona->id,
            'productor_id' => (string) $this->productor->id,
            'superficie' => '',
            'descripcion' => '',
            'coordenadas' => $this->poligono,
            'centro_lat' => 25.79265,
            'centro_lng' => -108.9934,
            'superficie_calculada' => 1.01,
            'temporada_id' => $this->temporada->id,
        ])->assertCreated();

        return Lote::findOrFail($response->json('data.id'));
    }

    public function test_crear_con_mapa_usa_superficie_calculada(): void
    {
        $lote = $this->crearLoteConMapa();

        $this->assertEquals(1.01, (float) $lote->superficie);
        $this->assertCount(4, $lote->coordenadas);
    }

    public function test_quitar_mapa_y_capturar_superficie_manual_guarda_bien(): void
    {
        $lote = $this->crearLoteConMapa();

        // Lo que manda el front después de "Limpiar todo" en el mapa
        // y capturar la superficie a mano.
        $response = $this->putJson(self::URL.'/'.$lote->id, [
            'nombre' => 'Keitt A',
            'zona_cultivo_id' => $this->zona->id,
            'productor_id' => $this->productor->id,
            'superficie' => '7.5',
            'descripcion' => '',
            'coordenadas' => [],
            'centro_lat' => null,
            'centro_lng' => null,
            'superficie_calculada' => null,
            'temporada_id' => $this->temporada->id,
        ])->assertOk();

        // La card del front pinta productor y zona desde la respuesta
        $response->assertJsonPath('data.productor.id', $this->productor->id);
        $response->assertJsonPath('data.zona_cultivo.id', $this->zona->id);
        $response->assertJsonPath('data.coordenadas', []);

        $lote->refresh();
        $this->assertEquals($this->productor->id, $lote->productor_id);
        $this->assertEquals(7.5, (float) $lote->superficie);
        $this->assertNull($lote->superficie_calculada);
        $this->assertNull($lote->centro_lat);
        $this->assertSame([], $lote->coordenadas);
    }

    public function test_crear_sin_mapa_con_superficie_manual(): void
    {
        $response = $this->postJson(self::URL, [
            'nombre' => 'La Cuenca',
            'zona_cultivo_id' => (string) $this->zona->id,
            'productor_id' => (string) $this->productor->id,
            'superficie' => '12.25',
            'coordenadas' => [],
            'centro_lat' => null,
            'centro_lng' => null,
            'superficie_calculada' => null,
            'temporada_id' => $this->temporada->id,
        ])->assertCreated();

        $response->assertJsonPath('data.productor.id', $this->productor->id);
        $this->assertEquals(12.25, (float) Lote::find($response->json('data.id'))->superficie);
    }

    public function test_listado_incluye_productor_despues_de_editar(): void
    {
        $lote = $this->crearLoteConMapa();

        $this->putJson(self::URL.'/'.$lote->id, [
            'productor_id' => $this->productor->id,
            'superficie' => '3',
            'coordenadas' => [],
        ])->assertOk();

        $this->getJson(self::URL.'?temporada_id='.$this->temporada->id)
            ->assertOk()
            ->assertJsonPath('data.0.productor.id', $this->productor->id);
    }
}
