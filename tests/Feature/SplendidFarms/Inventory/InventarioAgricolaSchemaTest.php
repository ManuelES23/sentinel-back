<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventarioAgricolaSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_existen_tablas_y_columnas_nuevas(): void
    {
        $this->assertTrue(Schema::hasColumns('entity_zona_cultivo', ['entity_id', 'zona_cultivo_id']));
        $this->assertTrue(Schema::hasColumns('user_entity_access', ['user_id', 'entity_id', 'enterprise_id', 'granted_by']));
        $this->assertTrue(Schema::hasColumns('products', ['ingrediente_activo', 'requiere_revision', 'dias_alerta_caducidad']));
        $this->assertTrue(Schema::hasColumn('productos_aplicacion', 'product_id'));
        $this->assertTrue(Schema::hasColumn('aplicaciones_detalle', 'product_id'));
    }
}
