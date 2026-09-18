<?php

namespace Tests\Feature\Aplicaciones;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\Concerns\CreatesAplicacionesFixtures;
use Tests\TestCase;

class EsquemaAplicacionesTest extends TestCase
{
    use RefreshDatabase, CreatesAlmacenFixtures, CreatesAplicacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAplicacionesFixtures();
    }

    public function test_las_tablas_tienen_las_columnas_de_consumo(): void
    {
        $this->assertTrue(Schema::hasColumns('aplicaciones', ['enterprise_id', 'almacen_id', 'inventory_movement_id']));
        $this->assertTrue(Schema::hasColumns('aplicaciones_detalle', ['unidad_dosis_id', 'conversion_factor', 'base_quantity']));
    }

    public function test_la_aplicacion_guarda_almacen_empresa_y_unidad_de_dosis(): void
    {
        $aplicacion = $this->crearAplicacion();
        $detalle = $aplicacion->detalles->first();

        $this->assertSame($this->almacenA->id, $aplicacion->almacen->id);
        $this->assertSame($this->empresa->id, $aplicacion->empresa->id);
        $this->assertNull($aplicacion->inventory_movement_id);
        $this->assertSame($this->unidad->id, $detalle->unidadDosis->id);
        $this->assertSame('L/ha', $detalle->unidad_medida);
    }

    public function test_una_aplicacion_historica_sin_almacen_sigue_guardando(): void
    {
        $aplicacion = $this->crearAplicacion(['almacen_id' => null, 'enterprise_id' => null]);

        $this->assertNull($aplicacion->fresh()->almacen_id);
        $this->assertNull($aplicacion->fresh()->enterprise_id);
    }
}
