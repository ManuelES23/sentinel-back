<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\Enterprise;
use App\Support\CRM\NombreCatalogo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Esquema del catálogo por líneas (marca, línea, sublínea, clave) y su bandera. */
class CatalogoPorLineasEsquemaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_11_10_100300_encender_catalogo_por_lineas_canagro.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    public function test_nombre_catalogo_normaliza(): void
    {
        $this->assertSame('melon dulce', NombreCatalogo::clave('  Melón  Dulce '));
        $this->assertSame('JHON DEERE', NombreCatalogo::limpio('  JHON   DEERE '));
    }

    public function test_marca_y_linea_unicas_por_empresa_sin_distinguir_acentos(): void
    {
        $otra = $this->crearOtraEmpresa();
        CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Bayer']);
        CrmMarca::create(['empresa_id' => $otra->id, 'nombre' => 'BAYER ']);
        $this->assertDatabaseCount('crm_marcas', 2);

        try {
            CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'BAYER ']);
            $this->fail('Debió lanzar QueryException (marca).');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Fungicida']);
        $this->expectException(QueryException::class);
        CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => ' FUNGÍCIDA']);
    }

    public function test_sublinea_mismo_nombre_en_dos_lineas(): void
    {
        $a = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Linea A']);
        $b = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Linea B']);
        $a->sublineas()->create(['nombre' => 'BACTERICIDA']);
        $b->sublineas()->create(['nombre' => 'BACTERICIDA']);
        $this->assertDatabaseCount('crm_sublineas', 2);
        $this->assertSame($a->id, $a->sublineas()->first()->linea->id);
    }

    public function test_clave_de_producto_unica_por_empresa(): void
    {
        $e = $this->enterprise->id;
        CrmProducto::create(['empresa_id' => $e, 'nombre' => 'P1', 'precio' => 0, 'clave' => 'ABC-1']);
        CrmProducto::create(['empresa_id' => $e, 'nombre' => 'P2', 'precio' => 0]);
        CrmProducto::create(['empresa_id' => $e, 'nombre' => 'P3', 'precio' => 0]);
        $this->assertDatabaseCount('crm_productos', 3);

        $this->expectException(QueryException::class);
        CrmProducto::create(['empresa_id' => $e, 'nombre' => 'P4', 'precio' => 0, 'clave' => ' abc-1']);
    }

    public function test_configuracion_expone_catalogo_por_lineas(): void
    {
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        $config = CrmConfiguracionComercial::paraEmpresa($this->enterprise->id);

        $config->update(['catalogo_por_lineas' => true]);
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/configuracion-comercial')
            ->assertOk()->assertJsonPath('data.catalogo_por_lineas', true);

        $config->update(['cotizacion_por_caja' => true]);
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/configuracion-comercial')
            ->assertOk()->assertJsonPath('data.catalogo_por_lineas', false);
    }

    public function test_migracion_enciende_canagro(): void
    {
        $canagro = Enterprise::create(['name' => 'CanAgro', 'slug' => 'canagro', 'description' => 'x', 'is_active' => true]);

        $migracion = require database_path(self::MIGRACION);
        $migracion->up();
        $migracion->up();

        $this->assertTrue(CrmConfiguracionComercial::catalogoPorLineas($canagro->id));
        $this->assertFalse(CrmConfiguracionComercial::catalogoPorLineas($this->enterprise->id));

        $migracion->down();
        $this->assertFalse(CrmConfiguracionComercial::catalogoPorLineas($canagro->id));
    }

    public function test_migracion_sin_canagro_no_hace_nada(): void
    {
        $migracion = require database_path(self::MIGRACION);
        $migracion->up();
        $this->assertDatabaseCount('crm_configuraciones_comerciales', 0);
    }
}
