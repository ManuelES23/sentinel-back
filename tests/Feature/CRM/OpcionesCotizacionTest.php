<?php

namespace Tests\Feature\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmPresentacion;
use App\Models\CRM\CrmProducto;
use App\Models\Cultivo;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** GET crm/productos/opciones-cotizacion: lo que el formulario de cotización por caja ofrece. */
class OpcionesCotizacionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const RUTA = '/api/crm/productos/opciones-cotizacion';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function variedad(string $cultivo, string $nombre): Variedad
    {
        $c = Cultivo::firstOrCreate(['nombre' => $cultivo]);

        return Variedad::create(['cultivo_id' => $c->id, 'nombre' => $nombre, 'user_id' => $this->actingUser->id]);
    }

    private function producto(string $nombre, ?string $commodity, array $extra = []): CrmProducto
    {
        return CrmProducto::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'nombre' => $nombre, 'commodity' => $commodity,
            'precio' => 0, 'unidad_medida' => 'caja', 'activo' => true,
        ], $extra));
    }

    private function opciones(): array
    {
        return $this->withHeaders($this->crmHeaders())->getJson(self::RUTA)->assertOk()->json('data.productos');
    }

    public function test_junta_calibres_agricolas_activos_y_propios_sin_repetir(): void
    {
        $variedad = $this->variedad('Mango', 'Kent Mango');
        $base = ['cultivo_id' => $variedad->cultivo_id, 'variedad_id' => $variedad->id];
        Calibre::create($base + ['nombre' => 'Calibre 8', 'valor' => 8, 'is_active' => true, 'order' => 1]);
        Calibre::create($base + ['nombre' => 'Calibre 9', 'valor' => 9, 'is_active' => false, 'order' => 2]);
        Calibre::create($base + ['nombre' => 'Calibre 10', 'valor' => 10, 'is_active' => true, 'order' => 3])->delete();

        $producto = $this->producto('Kent Mango', 'Mango', ['variedad_id' => $variedad->id]);
        $producto->calibresPropios()->create(['nombre' => 'calibre 8', 'orden' => 1]);
        $producto->calibresPropios()->create(['nombre' => 'Calibre 40', 'orden' => 2]);
        $producto->calibresPropios()->create(['nombre' => 'Calibre 41', 'orden' => 3, 'activo' => false]);

        $productos = $this->opciones();

        $this->assertSame(['Calibre 8', 'Calibre 40'], $productos[0]['variedades'][0]['calibres']);
    }

    public function test_solo_ofrece_variedades_activas_de_la_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->producto('Kent Mango', 'Mango', ['precio' => 12.5]);
        $this->producto('Tommy Mango', 'Mango', ['activo' => false]);
        CrmProducto::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena', 'commodity' => 'Mango', 'precio' => 0]);

        $productos = $this->opciones();

        $this->assertCount(1, $productos);
        $this->assertCount(1, $productos[0]['variedades']);
        $this->assertSame('Kent Mango', $productos[0]['variedades'][0]['nombre']);
        $this->assertSame(12.5, $productos[0]['variedades'][0]['precio']);
        $this->assertSame([], $productos[0]['variedades'][0]['calibres']);
    }

    public function test_un_producto_sin_commodity_va_en_otros_y_otros_va_al_final(): void
    {
        $this->producto('Zeta', null);
        $this->producto('Vacío', '');
        $this->producto('Uva A', 'Uva');
        $this->producto('Mango B', 'Mango');
        $this->producto('Mango A', 'Mango');

        $productos = $this->opciones();

        $this->assertSame(['Mango', 'Uva', 'Otros'], array_column($productos, 'commodity'));
        $this->assertSame(['Mango A', 'Mango B'], array_column($productos[0]['variedades'], 'nombre'));
        $this->assertSame(['Vacío', 'Zeta'], array_column($productos[2]['variedades'], 'nombre'));
    }

    public function test_presentaciones_activas_por_commodity_en_orden(): void
    {
        $this->producto('Kent Mango', 'Mango');
        $this->producto('Uva A', 'Uva');
        $id = $this->enterprise->id;
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Mango', 'nombre' => 'Zeta', 'orden' => 1]);
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Mango', 'nombre' => 'Beta', 'orden' => 2]);
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Mango', 'nombre' => 'Alfa', 'orden' => 2]);
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Mango', 'nombre' => 'Inactiva', 'orden' => 0, 'activo' => false]);
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Uva', 'nombre' => 'Clamshell', 'orden' => 1]);
        CrmPresentacion::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'commodity' => 'Mango', 'nombre' => 'Ajena', 'orden' => 0]);

        $productos = $this->opciones();

        $this->assertSame(['Zeta', 'Alfa', 'Beta'], $productos[0]['presentaciones']);
        $this->assertSame(['Clamshell'], $productos[1]['presentaciones']);
    }

    public function test_sin_permiso_de_escritura_tambien_puede_leer_las_opciones(): void
    {
        $this->producto('Kent Mango', 'Mango');

        // Sin ningún permiso otorgado: la lectura queda abierta dentro de la empresa.
        $this->withHeaders($this->crmHeaders())->getJson(self::RUTA)
            ->assertOk()
            ->assertJsonPath('data.productos.0.commodity', 'Mango');
    }
}
