<?php

namespace Tests\Feature\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmPresentacion;
use App\Models\CRM\CrmProducto;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Variedad;
use App\Support\CRM\CotizacionPorCajaSbp;
use App\Support\CRM\EmpresasCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Preparación de Splendid by Porvenir para cotizar por caja (spec 2026-10-31 §5). */
class CotizacionPorCajaSbpTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_31_100100_preparar_cotizacion_por_caja_sbp.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function producto(int $empresaId, string $nombre, string $commodity, bool $activo = true): CrmProducto
    {
        return CrmProducto::create([
            'empresa_id' => $empresaId, 'nombre' => $nombre, 'commodity' => $commodity,
            'precio' => 0, 'unidad_medida' => 'caja', 'activo' => $activo,
        ]);
    }

    private function variedad(string $cultivo, string $variedad): Variedad
    {
        $c = Cultivo::firstOrCreate(['nombre' => $cultivo]);

        return Variedad::create(['cultivo_id' => $c->id, 'nombre' => $variedad, 'user_id' => $this->actingUser->id]);
    }

    public function test_reduce_el_elote_a_tres_variedades_activas(): void
    {
        $id = $this->enterprise->id;
        foreach ([
            'White Corn Bulk', 'Yellow Corn Bulk', 'Bicolor Corn Bulk',
            'White Corn Tray', 'Yellow Corn Tray', 'Bicolor Corn Tray',
            'Mini Cob Yellow', 'Mini Cob Bicolor',
        ] as $nombre) {
            $this->producto($id, $nombre, 'Corn');
        }

        CotizacionPorCajaSbp::aplicar($id);

        $activos = CrmProducto::where('empresa_id', $id)->where('commodity', 'Corn')->where('activo', true)
            ->pluck('nombre')->sort()->values();
        $this->assertSame(['Bicolor Corn', 'White Corn', 'Yellow Corn'], $activos->all());
        $this->assertSame(8, CrmProducto::where('empresa_id', $id)->where('commodity', 'Corn')->count());
    }

    public function test_liga_variedades_por_nombre_y_deja_sin_liga_las_que_no_existen(): void
    {
        $id = $this->enterprise->id;
        $kent = $this->variedad('Mango', 'Kent');
        $borrada = $this->variedad('Mango', 'Ataulfo');
        $borrada->delete();
        $kentProd = $this->producto($id, 'Kent Mango', 'Mango');
        $criollo = $this->producto($id, 'Criollo Mango', 'Mango');
        $keitt = $this->producto($id, 'Keitt Mango', 'Mango');
        $ataulfo = $this->producto($id, 'Ataulfo Mango', 'Mango');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame($kent->id, $kentProd->fresh()->variedad_id);
        $this->assertNull($criollo->fresh()->variedad_id);
        $this->assertNull($keitt->fresh()->variedad_id);
        $this->assertNull($ataulfo->fresh()->variedad_id);
    }

    public function test_no_pisa_una_liga_existente(): void
    {
        $id = $this->enterprise->id;
        $this->variedad('Mango', 'Kent');
        $otra = $this->variedad('Mango', 'Haden');
        $prod = $this->producto($id, 'Kent Mango', 'Mango');
        $prod->update(['variedad_id' => $otra->id]);

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame($otra->id, $prod->fresh()->variedad_id);
    }

    public function test_liga_el_elote_ya_renombrado(): void
    {
        $id = $this->enterprise->id;
        $amarillo = $this->variedad('Elote Dulce', 'Amarillo');
        $prod = $this->producto($id, 'Yellow Corn Bulk', 'Corn');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame('Yellow Corn', $prod->fresh()->nombre);
        $this->assertSame($amarillo->id, $prod->fresh()->variedad_id);
    }

    public function test_precarga_presentaciones_y_enciende_el_interruptor(): void
    {
        $id = $this->enterprise->id;

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame(6, CrmPresentacion::where('empresa_id', $id)->where('commodity', 'Mango')->count());
        $this->assertSame(6, CrmPresentacion::where('empresa_id', $id)->where('commodity', 'Corn')->count());
        $this->assertSame(
            ['Caja cartón 8.85 LBS B15' => 1, 'Caja plástico RPC 6411' => 6],
            CrmPresentacion::where('empresa_id', $id)->where('commodity', 'Mango')
                ->whereIn('nombre', ['Caja cartón 8.85 LBS B15', 'Caja plástico RPC 6411'])
                ->pluck('orden', 'nombre')->all()
        );
        $this->assertTrue(CrmConfiguracionComercial::cotizacionPorCaja($id));
    }

    public function test_se_puede_repetir_sin_duplicar_y_sin_chocar_con_un_nombre_ya_usado(): void
    {
        $id = $this->enterprise->id;
        $this->producto($id, 'Yellow Corn', 'Corn');
        $viejo = $this->producto($id, 'Yellow Corn Bulk', 'Corn');

        CotizacionPorCajaSbp::aplicar($id);
        CotizacionPorCajaSbp::aplicar($id);

        $viejo = $viejo->fresh();
        $this->assertSame('Yellow Corn Bulk', $viejo->nombre);
        $this->assertFalse($viejo->activo);
        $this->assertSame(2, CrmProducto::where('empresa_id', $id)->count());
        $this->assertSame(12, CrmPresentacion::where('empresa_id', $id)->count());
    }

    public function test_no_toca_otra_empresa(): void
    {
        $otroId = $this->crearOtraEmpresa()->id;
        $ajeno = $this->producto($otroId, 'Yellow Corn Bulk', 'Corn');

        CotizacionPorCajaSbp::aplicar($this->enterprise->id);

        $this->assertSame('Yellow Corn Bulk', $ajeno->fresh()->nombre);
        $this->assertTrue($ajeno->fresh()->activo);
        $this->assertSame(0, CrmPresentacion::where('empresa_id', $otroId)->count());
        $this->assertFalse(CrmConfiguracionComercial::cotizacionPorCaja($otroId));
    }

    public function test_con_dos_variedades_del_mismo_nombre_liga_la_que_tiene_calibres_activos(): void
    {
        $id = $this->enterprise->id;
        $menor = $this->variedad('Mango', 'Kent');
        $mayor = $this->variedad('Mango', 'Kent');
        $mayorInactiva = $this->variedad('Mango', 'Ataulfo');
        $ataulfoConCalibres = $this->variedad('Mango', 'Ataulfo');
        foreach ([[$mayor, 'Calibre 8', true], [$menor, 'Calibre 9', false], [$ataulfoConCalibres, 'Calibre 10', true]] as [$v, $nombre, $activo]) {
            Calibre::create(['cultivo_id' => $v->cultivo_id, 'variedad_id' => $v->id, 'nombre' => $nombre, 'valor' => 1, 'is_active' => $activo, 'order' => 1]);
        }
        $kent = $this->producto($id, 'Kent Mango', 'Mango');
        $ataulfo = $this->producto($id, 'Ataulfo Mango', 'Mango');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame($mayor->id, $kent->fresh()->variedad_id);
        $this->assertSame($ataulfoConCalibres->id, $ataulfo->fresh()->variedad_id);
        $this->assertNotSame($mayorInactiva->id, $ataulfo->fresh()->variedad_id);
    }

    public function test_con_dos_variedades_del_mismo_nombre_y_sin_calibres_liga_la_de_menor_id(): void
    {
        $id = $this->enterprise->id;
        $menor = $this->variedad('Mango', 'Kent');
        $this->variedad('Mango', 'Kent');
        $kent = $this->producto($id, 'Kent Mango', 'Mango');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame($menor->id, $kent->fresh()->variedad_id);
    }

    public function test_renombra_solo_el_primero_y_desactiva_los_duplicados_del_nombre_viejo(): void
    {
        $id = $this->enterprise->id;
        $primero = $this->producto($id, 'Yellow Corn Bulk', 'Corn');
        $segundo = $this->producto($id, 'Yellow Corn Bulk', 'Corn');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertSame('Yellow Corn', $primero->fresh()->nombre);
        $this->assertTrue($primero->fresh()->activo);
        $this->assertSame('Yellow Corn Bulk', $segundo->fresh()->nombre);
        $this->assertFalse($segundo->fresh()->activo);
    }

    public function test_si_el_destino_ya_existe_desactiva_todos_los_duplicados_del_nombre_viejo(): void
    {
        $id = $this->enterprise->id;
        $this->producto($id, 'Yellow Corn', 'Corn');
        $a = $this->producto($id, 'Yellow Corn Bulk', 'Corn');
        $b = $this->producto($id, 'Yellow Corn Bulk', 'Corn');

        CotizacionPorCajaSbp::aplicar($id);

        $this->assertFalse($a->fresh()->activo);
        $this->assertFalse($b->fresh()->activo);
        $this->assertSame(1, CrmProducto::where('empresa_id', $id)->where('activo', true)->count());
    }

    public function test_la_migracion_prepara_sbp_y_no_hace_nada_si_no_existe(): void
    {
        $migracion = require database_path(self::MIGRACION);

        $migracion->up();
        $this->assertSame(0, CrmPresentacion::count());

        $sbp = Enterprise::create([
            'name' => 'Splendid by Porvenir', 'slug' => EmpresasCrm::SLUG_SBP,
            'description' => 'SBP de prueba', 'is_active' => true,
        ]);
        $migracion->up();
        $this->assertTrue(CrmConfiguracionComercial::cotizacionPorCaja($sbp->id));
        $this->assertSame(12, CrmPresentacion::where('empresa_id', $sbp->id)->count());

        $migracion->down();
        $this->assertFalse(CrmConfiguracionComercial::cotizacionPorCaja($sbp->id));
        $this->assertSame(12, CrmPresentacion::where('empresa_id', $sbp->id)->count());
    }
}
