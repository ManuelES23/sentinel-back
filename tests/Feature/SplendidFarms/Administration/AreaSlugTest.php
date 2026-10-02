<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Area;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El slug de un área sale de su nombre y `areas.slug` es único aun entre las
 * borradas (borrado lógico): repetir el nombre de un área borrada (o de otra
 * activa) no debe reventar con "Duplicate entry" (1062), sino tomar un slug libre.
 */
class AreaSlugTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/splendidfarms/administration/organizacion/areas';

    private array $headers = ['X-Enterprise-Slug' => 'splendidfarms'];

    protected function setUp(): void
    {
        parent::setUp();
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Agrícola', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_recrear_area_con_el_mismo_nombre_tras_borrarla(): void
    {
        $primera = $this->postJson($this->url, ['name' => 'Taller'], $this->headers)->assertCreated()->json('data');
        Area::findOrFail($primera['id'])->delete();

        $segunda = $this->postJson($this->url, ['name' => 'Taller'], $this->headers)->assertCreated()->json('data');

        $this->assertSame('taller', $primera['slug']);
        $this->assertSame('taller-2', $segunda['slug']);
    }

    public function test_dos_areas_activas_con_el_mismo_nombre(): void
    {
        $this->postJson($this->url, ['name' => 'Taller'], $this->headers)->assertCreated();

        $segunda = $this->postJson($this->url, ['name' => 'Taller'], $this->headers)->assertCreated()->json('data');

        $this->assertSame('taller-2', $segunda['slug']);
    }

    public function test_renombrar_borrando_el_slug_toma_uno_libre(): void
    {
        $borrada = $this->postJson($this->url, ['name' => 'Taller'], $this->headers)->assertCreated()->json('data');
        Area::findOrFail($borrada['id'])->delete();
        $area = $this->postJson($this->url, ['name' => 'Bodega'], $this->headers)->assertCreated()->json('data');

        $actualizada = $this->putJson("{$this->url}/{$area['id']}", ['name' => 'Taller', 'slug' => null], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame('taller-2', $actualizada['slug']);
    }

    public function test_quitar_el_slug_sin_renombrar_conserva_uno_valido(): void
    {
        $area = $this->postJson($this->url, ['name' => 'Bodega'], $this->headers)->assertCreated()->json('data');

        $actualizada = $this->putJson("{$this->url}/{$area['id']}", ['slug' => null], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame('bodega', $actualizada['slug']);
    }
}
