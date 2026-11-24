<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\TipoCarga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Clase de rezaga de los tipos de carga: validación en alta y edición,
 * filtro `rezaga` del listado y mapeo a tipo/subtipo de rezaga.
 */
class TipoCargaClaseRezagaTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/inventario/catalogos/tipos-carga';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function nombres(string $query = ''): array
    {
        $respuesta = $this->getJson(self::URL.'?cultivo_id='.$this->cultivoElote->id.$query)
            ->assertOk();

        return collect($respuesta->json('data'))->pluck('nombre')->sort()->values()->all();
    }

    private function cuerpo(array $extra = []): array
    {
        return array_merge([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => 'Bin nuevo',
            'categoria_caja' => 'campo',
            'peso_estimado_kg' => 200,
        ], $extra);
    }

    public function test_index_por_defecto_oculta_bins_de_rezaga(): void
    {
        $this->assertSame(['Bin de campo'], $this->nombres());
    }

    public function test_index_rezaga_solo_y_todas(): void
    {
        $this->assertSame(
            ['Bin de cuarto frío', 'Bin de fresco', 'Bin de hoja'],
            $this->nombres('&rezaga=solo')
        );
        $this->assertSame(
            ['Bin de campo', 'Bin de cuarto frío', 'Bin de fresco', 'Bin de hoja'],
            $this->nombres('&rezaga=todas')
        );
        // Un valor desconocido se comporta como si no viniera.
        $this->assertSame(['Bin de campo'], $this->nombres('&rezaga=cualquiera'));
    }

    public function test_index_rezaga_compone_con_is_active_y_search(): void
    {
        $this->binHoja->update(['is_active' => false]);

        $this->assertSame(
            ['Bin de cuarto frío', 'Bin de fresco'],
            $this->nombres('&rezaga=solo')
        );
        $this->assertSame(
            ['Bin de hoja'],
            $this->nombres('&rezaga=solo&is_active=false')
        );
        $this->assertSame(
            ['Bin de fresco'],
            $this->nombres('&rezaga=solo&search=fresco')
        );
        $this->assertSame([], $this->nombres('&search=fresco'));
    }

    public function test_store_valida_clase_rezaga(): void
    {
        $this->postJson(self::URL, $this->cuerpo(['clase_rezaga' => 'otra']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clase_rezaga');

        $this->postJson(self::URL, $this->cuerpo(['clase_rezaga' => 'hoja']))
            ->assertStatus(201)
            ->assertJsonPath('data.clase_rezaga', 'hoja');

        $this->assertDatabaseHas('tipos_carga', ['nombre' => 'Bin nuevo', 'clase_rezaga' => 'hoja']);

        $this->postJson(self::URL, $this->cuerpo(['nombre' => 'Sin clase']))
            ->assertStatus(201)
            ->assertJsonPath('data.clase_rezaga', null);
    }

    public function test_update_valida_y_conserva_o_limpia_la_clase(): void
    {
        $url = self::URL.'/'.$this->binHoja->id;

        $this->putJson($url, ['clase_rezaga' => 'otra'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clase_rezaga');

        // Omitirla no toca el valor guardado.
        $this->putJson($url, ['nombre' => 'Bin de hoja 2'])->assertOk();
        $this->assertSame('hoja', $this->binHoja->fresh()->clase_rezaga);

        $this->putJson($url, ['clase_rezaga' => 'fresco'])
            ->assertOk()
            ->assertJsonPath('data.clase_rezaga', 'fresco');

        // Mandarla en null la limpia.
        $this->putJson($url, ['clase_rezaga' => null])
            ->assertOk()
            ->assertJsonPath('data.clase_rezaga', null);
        $this->assertNull($this->binHoja->fresh()->clase_rezaga);
    }

    public function test_mapeo_rezaga(): void
    {
        $this->assertSame(['hoja', 'fresco', 'cuarto_frio'], TipoCarga::CLASES_REZAGA);

        $this->assertSame(
            ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'hoja'],
            $this->binHoja->mapeoRezaga()
        );
        $this->assertSame(
            ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'producto'],
            $this->binFresco->mapeoRezaga()
        );
        $this->assertSame(
            ['tipo_rezaga' => 'cuarto_frio', 'subtipo_rezaga' => 'producto'],
            $this->binCuartoFrio->mapeoRezaga()
        );
        $this->assertNull($this->binCampo->mapeoRezaga());

        $desconocida = new TipoCarga(['clase_rezaga' => 'rara']);
        $this->assertNull($desconocida->mapeoRezaga());
    }
}
