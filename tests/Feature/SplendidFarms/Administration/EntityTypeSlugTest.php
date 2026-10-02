<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\Enterprise;
use App\Models\EntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Igual que en áreas: el slug sale del nombre y `entity_types.slug` es único aun
 * entre los borrados (borrado lógico), así que repetir un nombre no debe reventar
 * con "Duplicate entry" (1062).
 */
class EntityTypeSlugTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/splendidfarms/administration/organizacion/tipos-entidades';

    private array $headers = ['X-Enterprise-Slug' => 'splendidfarms'];

    protected function setUp(): void
    {
        parent::setUp();
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Agrícola', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_recrear_tipo_con_el_mismo_nombre_tras_borrarlo(): void
    {
        $primero = $this->postJson($this->url, ['code' => 'BOD', 'name' => 'Bodega'], $this->headers)->assertCreated()->json('data');
        EntityType::findOrFail($primero['id'])->delete();

        $segundo = $this->postJson($this->url, ['code' => 'BOD2', 'name' => 'Bodega'], $this->headers)->assertCreated()->json('data');

        $this->assertSame('bodega', $primero['slug']);
        $this->assertSame('bodega-2', $segundo['slug']);
    }

    public function test_quitar_el_slug_sin_renombrar_conserva_uno_valido(): void
    {
        $tipo = $this->postJson($this->url, ['code' => 'BOD', 'name' => 'Bodega'], $this->headers)->assertCreated()->json('data');

        $actualizado = $this->putJson("{$this->url}/{$tipo['id']}", ['slug' => null], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame('bodega', $actualizado['slug']);
    }
}
