<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Area;
use App\Models\Enterprise;
use App\Models\MovementType;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El código autogenerado debe contar los registros borrados (borrado lógico):
 * el índice único de `code` sí los incluye, así que si el último registro se
 * borró, volver a generar su código revienta con "Duplicate entry" (1062).
 */
class CodigoTrasBorradoTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Enterprise-Slug' => 'splendidfarms'];

    protected function setUp(): void
    {
        parent::setUp();
        Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Agrícola', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function crearBorrarYCrear(string $url, string $modelo, array $datos, ?array $datosSegundo = null): void
    {
        $primero = $this->postJson($url, $datos, $this->headers)->assertCreated()->json('data');
        $modelo::findOrFail($primero['id'])->delete();

        $segundo = $this->postJson($url, $datosSegundo ?? $datos, $this->headers)->assertCreated()->json('data');

        $this->assertNotSame($primero['code'], $segundo['code']);
    }

    public function test_categoria_hija_tras_borrar_la_ultima(): void
    {
        $padre = ProductCategory::create(['code' => 'CAT-001', 'name' => 'Combustibles y lubricantes']);

        $this->crearBorrarYCrear('/api/splendidfarms/inventario/catalogos/categorias', ProductCategory::class, [
            'name' => 'Combustible', 'parent_id' => $padre->id,
        ]);
    }

    public function test_unidad_de_medida_tras_borrar_la_ultima(): void
    {
        $this->crearBorrarYCrear('/api/splendidfarms/inventario/catalogos/unidades', UnitOfMeasure::class, [
            'name' => 'Galón', 'abbreviation' => 'gal', 'type' => 'volume',
        ]);
    }

    public function test_tipo_de_movimiento_tras_borrar_el_ultimo(): void
    {
        $this->crearBorrarYCrear('/api/splendidfarms/inventario/catalogos/tipos-movimiento', MovementType::class, [
            'name' => 'Donación', 'direction' => 'out', 'effect' => 'decrease',
        ]);
    }

    public function test_area_tras_borrar_la_ultima(): void
    {
        $this->crearBorrarYCrear('/api/splendidfarms/administration/organizacion/areas', Area::class, [
            'name' => 'Taller',
        ]);
    }
}
