<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\Enterprise;
use App\Support\CRM\EmpresasCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega moneda y productos_en_oportunidad a la configuración
 * comercial y deja a Splendid by Porvenir (por slug) en USD con productos.
 */
class ConfiguracionComercialMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_23_100000_add_moneda_y_productos_to_crm_configuraciones_comerciales.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function subir(): void
    {
        (require database_path(self::MIGRACION))->up();
    }

    private function crearSbp(): Enterprise
    {
        return Enterprise::create([
            'name' => 'Splendid by Porvenir',
            'slug' => EmpresasCrm::SLUG_SBP,
            'description' => 'SBP de prueba',
            'is_active' => true,
        ]);
    }

    public function test_migracion_fija_usd_y_productos_en_sbp_por_slug(): void
    {
        $sbp = $this->crearSbp();
        $this->assertDatabaseMissing('crm_configuraciones_comerciales', ['empresa_id' => $sbp->id]);

        $this->subir();

        $fila = DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbp->id)->first();
        $this->assertNotNull($fila);
        $this->assertSame('USD', $fila->moneda);
        $this->assertEquals(1, $fila->productos_en_oportunidad);
        $this->assertEquals(1, $fila->descuento_global_habilitado);
        $this->assertSame('USD', CrmConfiguracionComercial::monedaDe($sbp->id));
        $this->assertTrue(CrmConfiguracionComercial::productosEnOportunidad($sbp->id));
    }

    public function test_migracion_respeta_fila_existente_de_sbp(): void
    {
        $sbp = $this->crearSbp();
        DB::table('crm_configuraciones_comerciales')->insert([
            'empresa_id' => $sbp->id,
            'descuento_global_habilitado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subir();

        $fila = DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbp->id)->first();
        $this->assertEquals(0, $fila->descuento_global_habilitado);
        $this->assertSame('USD', $fila->moneda);
        $this->assertEquals(1, $fila->productos_en_oportunidad);
    }

    public function test_migracion_no_toca_otras_empresas_y_es_idempotente(): void
    {
        $sbp = $this->crearSbp();
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id);

        $this->subir();
        $this->subir();

        $otra = DB::table('crm_configuraciones_comerciales')->where('empresa_id', $this->enterprise->id)->first();
        $this->assertSame('MXN', $otra->moneda);
        $this->assertEquals(0, $otra->productos_en_oportunidad);
        $this->assertSame(1, DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbp->id)->count());
        $this->assertSame(1, DB::table('crm_configuraciones_comerciales')->where('empresa_id', $this->enterprise->id)->count());
    }

    public function test_migracion_sin_sbp_no_hace_nada(): void
    {
        $this->subir();

        $this->assertSame(0, DB::table('crm_configuraciones_comerciales')->count());
    }
}
