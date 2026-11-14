<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class VentasPorLineaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const RUTA = '/api/crm/dashboard/ventas-por-linea';

    private int $folio = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-10-15 12:00:00');
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['catalogo_por_lineas' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function otorgarPermisosDashboard(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 10, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'ejecutivo'] as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => 1, 'is_active' => true],
            );

            if (in_array($slug, $slugs, true)) {
                UserSubmodulePermission::create([
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $submodulo->id,
                    'permission_type_id' => $tipo->id,
                    'is_granted' => true,
                ]);
            }
        }
    }

    private function producto(?CrmLinea $linea = null, $sub = null): CrmProducto
    {
        return CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'P'.uniqid(), 'precio' => 0,
            'unidad_medida' => 'litro', 'activo' => true,
            'linea_id' => $linea?->id, 'sublinea_id' => $sub?->id,
        ]);
    }

    private function linea(string $nombre, array $subs = []): array
    {
        $linea = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => $nombre]);
        $modelos = array_map(fn ($s) => $linea->sublineas()->create(['nombre' => $s]), $subs);

        return [$linea, ...$modelos];
    }

    private function ganada(array $extra = []): CrmOportunidad
    {
        return CrmOportunidad::create($extra + [
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada', 'monto_esperado' => 500, 'etapa' => 'cerrado_ganado',
            'fecha_cierre_real' => '2026-10-10 10:00:00',
        ]);
    }

    private function cotizacion(CrmOportunidad $o, array $renglones, string $estado = 'aprobado', string $fecha = '2026-10-01'): CrmCotizacion
    {
        $c = CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $o->id,
            'folio' => 'F'.(++$this->folio), 'estado' => $estado, 'fecha_emision' => $fecha,
        ]);
        foreach ($renglones as [$producto, $cantidad, $precio]) {
            CrmOportunidadProducto::create([
                'oportunidad_id' => $o->id, 'cotizacion_id' => $c->id, 'producto_id' => $producto->id,
                'cantidad' => $cantidad, 'precio_unitario' => $precio,
            ]);
        }

        return $c;
    }

    private function consultar(string $query = '')
    {
        return $this->withHeaders($this->crmHeaders())->getJson(self::RUTA.$query);
    }

    public function test_suma_por_linea_y_sublinea_con_dos_ganadas(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$fung, $sf1, $sf2] = $this->linea('FUNGICIDAS', ['SISTEMICO', 'CONTACTO']);
        [$ins, $si] = $this->linea('INSECTICIDAS', ['GENERAL']);
        $pA = $this->producto($fung, $sf1);
        $pB = $this->producto($fung, $sf2);
        $pC = $this->producto($ins, $si);

        $this->cotizacion($this->ganada(), [[$pA, 2, 100], [$pC, 1, 50]]);
        $this->cotizacion($this->ganada(), [[$pA, 1, 100], [$pB, 3, 10]]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.aplica', true)
            ->assertJsonPath('data.total', 380)
            ->assertJsonPath('data.lineas.0.nombre', 'FUNGICIDAS')
            ->assertJsonPath('data.lineas.0.id', $fung->id)
            ->assertJsonPath('data.lineas.0.importe', 330)
            ->assertJsonPath('data.lineas.0.sublineas.0.nombre', 'SISTEMICO')
            ->assertJsonPath('data.lineas.0.sublineas.0.importe', 300)
            ->assertJsonPath('data.lineas.0.sublineas.1.nombre', 'CONTACTO')
            ->assertJsonPath('data.lineas.0.sublineas.1.importe', 30)
            ->assertJsonPath('data.lineas.1.nombre', 'INSECTICIDAS')
            ->assertJsonPath('data.lineas.1.importe', 50)
            ->assertJsonPath('data.ganadasSinCotizacion.cantidad', 0)
            ->assertJsonPath('data.ganadasSinCotizacion.monto', 0);
    }

    public function test_usa_la_cotizacion_aprobada_mas_reciente(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);
        $o = $this->ganada();
        $this->cotizacion($o, [[$p, 1, 100]], 'aprobado', '2026-09-01');
        $this->cotizacion($o, [[$p, 1, 700]], 'aprobado', '2026-10-02');
        $this->cotizacion($o, [[$p, 1, 5000]], 'borrador', '2026-10-09');

        $this->consultar()->assertOk()->assertJsonPath('data.total', 700);
    }

    public function test_misma_fecha_gana_el_id_mayor(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);
        $o = $this->ganada();
        $this->cotizacion($o, [[$p, 1, 100]]);
        $this->cotizacion($o, [[$p, 1, 200]]);

        $this->consultar()->assertOk()->assertJsonPath('data.total', 200);
    }

    public function test_ignora_borradores_rechazadas_perdidas_y_fuera_de_periodo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);

        $this->cotizacion($this->ganada(), [[$p, 1, 10]], 'borrador');
        $this->cotizacion($this->ganada(), [[$p, 1, 20]], 'rechazado');
        $this->cotizacion($this->ganada(['etapa' => 'cerrado_perdido']), [[$p, 1, 40]]);
        $this->cotizacion($this->ganada(['etapa' => 'negociacion', 'fecha_cierre_real' => null]), [[$p, 1, 80]]);
        $this->cotizacion($this->ganada(['fecha_cierre_real' => '2026-09-10 10:00:00']), [[$p, 1, 160]]);

        $this->consultar()->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.lineas');
    }

    public function test_ignora_oportunidades_y_cotizaciones_eliminadas(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);

        $borrada = $this->ganada();
        $this->cotizacion($borrada, [[$p, 1, 1000]]);
        $borrada->delete();

        $o = $this->ganada();
        $this->cotizacion($o, [[$p, 1, 30]], 'aprobado', '2026-10-01');
        $eliminada = $this->cotizacion($o, [[$p, 1, 9000]], 'aprobado', '2026-10-05');
        $eliminada->delete();

        $this->consultar()->assertOk()->assertJsonPath('data.total', 30);
    }

    public function test_producto_sin_linea_va_a_sin_linea_y_sin_sublinea(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l] = $this->linea('L');
        $this->cotizacion($this->ganada(), [[$this->producto(), 1, 100], [$this->producto($l), 2, 10]]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.lineas.0.id', null)
            ->assertJsonPath('data.lineas.0.nombre', 'Sin línea')
            ->assertJsonPath('data.lineas.0.importe', 100)
            ->assertJsonPath('data.lineas.0.sublineas.0.id', null)
            ->assertJsonPath('data.lineas.0.sublineas.0.nombre', 'Sin sublínea')
            ->assertJsonPath('data.lineas.1.nombre', 'L')
            ->assertJsonPath('data.lineas.1.sublineas.0.nombre', 'Sin sublínea')
            ->assertJsonPath('data.lineas.1.sublineas.0.importe', 20);
    }

    public function test_ganada_sin_cotizacion_aprobada_suma_en_ganadas_sin_cotizacion(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);

        $this->ganada(['monto_esperado' => 1200]);
        $conBorrador = $this->ganada(['monto_esperado' => 300]);
        $this->cotizacion($conBorrador, [[$p, 1, 99]], 'borrador');
        $this->cotizacion($this->ganada(['monto_esperado' => 7777]), [[$p, 1, 50]]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.ganadasSinCotizacion.cantidad', 2)
            ->assertJsonPath('data.ganadasSinCotizacion.monto', 1500);
    }

    public function test_reclasificar_un_producto_cambia_el_reporte(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$a, $sa] = $this->linea('A', ['SA']);
        [$b, $sb] = $this->linea('B', ['SB']);
        $p = $this->producto($a, $sa);
        $this->cotizacion($this->ganada(), [[$p, 1, 100]]);

        $this->consultar()->assertJsonPath('data.lineas.0.nombre', 'A');

        $p->update(['linea_id' => $b->id, 'sublinea_id' => $sb->id]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.lineas.0.nombre', 'B')
            ->assertJsonPath('data.lineas.0.sublineas.0.nombre', 'SB')
            ->assertJsonCount(1, 'data.lineas');
    }

    public function test_sin_ejecutivo_solo_ve_lo_suyo(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $propio = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id, 'nombre' => 'Yo']);
        [$l, $s] = $this->linea('L', ['S']);
        $p = $this->producto($l, $s);

        $this->cotizacion($this->ganada(['vendedor_id' => $propio->id]), [[$p, 1, 100]]);
        $this->cotizacion($this->ganada(), [[$p, 1, 900]]);

        $this->consultar()->assertOk()->assertJsonPath('data.total', 100);
    }

    public function test_sin_ejecutivo_y_sin_vendedor_propio_devuelve_ceros(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        [$l, $s] = $this->linea('L', ['S']);
        $this->cotizacion($this->ganada(), [[$this->producto($l, $s), 1, 100]]);
        $this->ganada(['monto_esperado' => 50]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.aplica', true)
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.lineas')
            ->assertJsonPath('data.ganadasSinCotizacion.cantidad', 0)
            ->assertJsonPath('data.ganadasSinCotizacion.monto', 0);
    }

    public function test_empresa_sin_bandera_no_aplica(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['catalogo_por_lineas' => false]);

        $this->consultar()->assertOk()->assertJsonPath('data', ['aplica' => false]);
    }

    public function test_exige_permiso_ver(): void
    {
        $this->otorgarPermisosDashboard([]);

        $this->consultar()->assertForbidden();
    }
}
