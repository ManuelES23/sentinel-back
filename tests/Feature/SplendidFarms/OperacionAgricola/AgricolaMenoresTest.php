<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\CosteoAgricola;
use App\Models\Cultivo;
use App\Models\Temporada;
use App\Models\TipoVariedad;
use App\Models\User;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menores pendientes de la auditoría agrícola (2026-09-17): la miniatura del
 * cultivo no llegaba al front porque imagen_url se armaba a mano solo en
 * algunos controladores, y el listado de costeo no tenía paginación.
 */
class AgricolaMenoresTest extends TestCase
{
    use RefreshDatabase;

    private const OA = '/api/splendidfarms/operacion-agricola/agricola';
    private const ADMIN = '/api/splendidfarms/administration/agricola';

    private User $user;
    private Cultivo $cultivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->cultivo = Cultivo::create([
            'nombre' => 'Mango',
            'imagen' => 'cultivos/mango.jpg',
        ]);
    }

    private function variedad(): Variedad
    {
        return Variedad::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Keitt',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_imagen_url_del_cultivo_llega_en_tipos_de_variedad(): void
    {
        TipoVariedad::create([
            'variedad_id' => $this->variedad()->id,
            'nombre' => 'Primera',
            'user_id' => $this->user->id,
        ]);

        $this->getJson(self::ADMIN.'/tipos-variedad')
            ->assertOk()
            ->assertJsonPath(
                'data.0.variedad.cultivo.imagen_url',
                asset('storage/cultivos/mango.jpg')
            );
    }

    public function test_imagen_url_del_cultivo_llega_en_variedades(): void
    {
        $this->variedad();

        $this->getJson(self::ADMIN.'/variedades')
            ->assertOk()
            ->assertJsonPath('0.cultivo.imagen_url', asset('storage/cultivos/mango.jpg'));
    }

    public function test_imagen_url_del_cultivo_llega_en_cultivos(): void
    {
        $this->getJson(self::ADMIN.'/cultivos')
            ->assertOk()
            ->assertJsonPath('data.0.imagen_url', asset('storage/cultivos/mango.jpg'));
    }

    public function test_imagen_url_del_cultivo_llega_en_ciclos_agricolas(): void
    {
        // El listado selecciona columnas puntuales del cultivo: si 'imagen' no
        // viene en el select, el accesor no puede armar la URL.
        $this->postJson(self::ADMIN.'/ciclos-agricolas', [
            'cultivo_id' => $this->cultivo->id,
            'periodo' => 'primavera-verano',
            'año' => 2026,
            'fecha_inicio' => '2026-01-01',
            'estado' => 'activo',
        ])->assertCreated();

        $this->getJson(self::ADMIN.'/ciclos-agricolas')
            ->assertOk()
            ->assertJsonPath('data.0.cultivo.imagen_url', asset('storage/cultivos/mango.jpg'));
    }

    public function test_imagen_url_es_null_cuando_el_cultivo_no_tiene_imagen(): void
    {
        Cultivo::create(['nombre' => 'Sin foto']);

        $r = $this->getJson(self::ADMIN.'/cultivos')->assertOk();

        $sinFoto = collect($r->json('data'))->firstWhere('nombre', 'Sin foto');
        $this->assertNull($sinFoto['imagen_url']);
    }

    public function test_listado_de_costeo_esta_paginado(): void
    {
        $temporada = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $this->cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $this->user->id,
        ]);

        foreach ([100, 200, 300] as $i => $costo) {
            CosteoAgricola::create([
                'temporada_id' => $temporada->id,
                'tipo_fuente' => CosteoAgricola::TIPO_FUENTE_MANUAL,
                'descripcion' => 'Costo '.$i,
                'categoria' => 'otro',
                'costo_total' => $costo,
                'fecha' => '2026-02-0'.($i + 1),
                'user_id' => $this->user->id,
            ]);
        }

        $r = $this->getJson(self::OA."/costeo?temporada_id={$temporada->id}&per_page=2")
            ->assertOk();

        $this->assertCount(2, $r->json('data'));
        $this->assertSame(3, $r->json('meta.registros'));
        $this->assertSame(1, $r->json('meta.current_page'));
        $this->assertSame(2, $r->json('meta.last_page'));
        // El total sigue siendo el del filtro completo, no el de la página
        $this->assertEquals(600, $r->json('meta.total'));

        $segunda = $this->getJson(self::OA."/costeo?temporada_id={$temporada->id}&per_page=2&page=2")
            ->assertOk();

        $this->assertCount(1, $segunda->json('data'));
        $this->assertEquals(600, $segunda->json('meta.total'));
    }
}
