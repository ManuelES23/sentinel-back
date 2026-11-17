<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Calibre;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use App\Models\Cultivo as CultivoSf;
use App\Models\User;
use App\Models\Variedad as VariedadSf;
use App\Support\CRM\UsoEnAbastecimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Migración `2026_11_16_120000_calibres_desde_productos_crm`: los calibres que el CRM leía de Splendid
 * Farms pasan a ser propios del producto y las variedades de Abastecimiento se ligan a los productos.
 */
class CalibresDesdeProductosCrmTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_11_16_120000_calibres_desde_productos_crm.php'))->up();
    }

    private function variedadSf(string $cultivo, string $nombre, array $calibres): VariedadSf
    {
        $c = CultivoSf::firstOrCreate(['nombre' => $cultivo]);
        $v = VariedadSf::create(['cultivo_id' => $c->id, 'nombre' => $nombre, 'user_id' => User::factory()->create()->id]);
        foreach ($calibres as $i => [$n, $activo, $borrado]) {
            $k = Calibre::create(['cultivo_id' => $c->id, 'variedad_id' => $v->id, 'nombre' => $n, 'valor' => $n, 'order' => $i + 1, 'is_active' => $activo]);
            if ($borrado) {
                $k->delete();
            }
        }

        return $v;
    }

    public function test_copia_los_calibres_de_sf_como_propios_sin_repetir_y_es_repetible(): void
    {
        $sf = $this->variedadSf('Elote Dulce', 'Amarillo', [['4 DOCENAS', true, false], ['12/4 TRAYS', false, false], ['Borrado', true, true]]);
        $corn = CrmProducto::create(['empresa_id' => $this->sbp->id, 'nombre' => 'Yellow Corn', 'commodity' => 'Corn', 'precio' => 0, 'activo' => true, 'variedad_id' => $sf->id]);
        CrmProductoCalibre::create(['producto_id' => $corn->id, 'nombre' => '4 docenas', 'orden' => 9]);

        $this->migrar();
        $this->migrar();

        $calibres = CrmProductoCalibre::where('producto_id', $corn->id)->orderBy('orden')->get();
        $this->assertSame(['12/4 TRAYS', '4 docenas'], $calibres->pluck('nombre')->all());
        $this->assertFalse($calibres->firstWhere('nombre', '12/4 TRAYS')->activo, 'Un calibre inactivo en SF se copia inactivo.');
    }

    public function test_liga_las_variedades_por_nombre_y_si_no_por_la_liga_del_crm_con_sf(): void
    {
        $sf = $this->variedadSf('Elote Dulce', 'Amarillo', [['4 DOCENAS', true, false]]);
        $corn = CrmProducto::create(['empresa_id' => $this->sbp->id, 'nombre' => 'Yellow Corn', 'commodity' => 'Corn', 'precio' => 0, 'activo' => true, 'variedad_id' => $sf->id]);
        $kent = CrmProducto::create(['empresa_id' => $this->sbp->id, 'nombre' => 'Kent Mango', 'commodity' => 'Mango', 'precio' => 0, 'activo' => true]);
        CrmProducto::create(['empresa_id' => $this->sbp->id, 'nombre' => 'Kent Organic Mango', 'commodity' => 'Mango', 'precio' => 0, 'activo' => true]);

        $this->migrar();

        $amarillo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Elote')->firstOrFail()->variedades()->where('nombre', 'Amarillo')->firstOrFail();
        $kentAbs = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail()->variedades()->where('nombre', 'Kent')->firstOrFail();
        $this->assertSame($corn->id, $amarillo->producto_id);
        $this->assertSame($kent->id, $kentAbs->producto_id);
        $this->assertSame(['4 DOCENAS'], $amarillo->calibres()->pluck('nombre')->all());
        $this->assertNull(Cultivo::where('enterprise_id', $this->otra->id)->first()->variedades()->whereNotNull('producto_id')->first(), 'Otra empresa no toma productos de SBP.');
    }

    public function test_detecta_calibres_y_productos_usados_en_abastecimiento(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $calibre = $this->crearCalibre($convenio->variedad, 'Jumbo');
        $libre = CrmProductoCalibre::create(['producto_id' => $calibre->producto_id, 'nombre' => 'Libre']);
        $suelto = CrmProducto::create(['empresa_id' => $this->sbp->id, 'nombre' => 'Suelto', 'precio' => 0, 'activo' => true]);

        $this->assertFalse(UsoEnAbastecimiento::calibre($calibre->id));
        ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $calibre->id]);

        $this->assertTrue(UsoEnAbastecimiento::calibre($calibre->id));
        $this->assertFalse(UsoEnAbastecimiento::calibre($libre->id));
        $this->assertTrue(UsoEnAbastecimiento::producto($calibre->producto_id), 'Ligado a una variedad de Abastecimiento.');
        $this->assertFalse(UsoEnAbastecimiento::producto($suelto->id));
    }
}