<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmProducto;
use App\Models\Enterprise;
use App\Support\CRM\EmpresasCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega commodity y la unidad «caja» a crm_productos y siembra
 * el catálogo de Splendid by Porvenir (spec 2026-10-23 §4.3).
 */
class ProductosSbpMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_23_100100_add_commodity_y_caja_to_crm_productos.php';

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

    public function test_siembra_47_productos_en_sbp(): void
    {
        $sbp = $this->crearSbp();

        $this->subir();

        $productos = CrmProducto::where('empresa_id', $sbp->id)->get();
        $this->assertCount(47, $productos);

        $porCommodity = $productos->groupBy('commodity')->map->count();
        $this->assertSame(8, $porCommodity['Corn']);
        $this->assertSame(10, $porCommodity['Mango']);
        $this->assertSame(7, $porCommodity['Pepper']);
        $this->assertSame(4, $porCommodity['Squash']);
        $this->assertSame(2, $porCommodity['Lettuce']);
        $this->assertSame(1, $porCommodity['Melon']);

        $nombres = $productos->pluck('nombre');
        $this->assertTrue($nombres->contains('Tomato'));
        $this->assertTrue($nombres->contains('Spaghetti Squash'));
        $this->assertFalse($nombres->contains('Tomatoe'));
        $this->assertFalse($nombres->contains('Spaguetti Squash'));
        $this->assertSame(1, $nombres->filter(fn ($n) => $n === 'Cucumber')->count());

        // Los sueltos llevan su propio nombre como commodity.
        $this->assertSame('Tomato', $productos->firstWhere('nombre', 'Tomato')->commodity);

        $this->assertSame(['caja'], $productos->pluck('unidad_medida')->unique()->values()->all());
        $this->assertTrue($productos->every(fn ($p) => $p->activo && (float) $p->precio === 0.0));
    }

    public function test_siembra_es_idempotente_y_no_toca_otras_empresas(): void
    {
        $sbp = $this->crearSbp();

        $this->subir();
        $this->subir();

        $this->assertSame(47, CrmProducto::where('empresa_id', $sbp->id)->count());
        $this->assertSame(0, CrmProducto::where('empresa_id', $this->enterprise->id)->count());
    }

    public function test_no_hace_nada_si_sbp_no_existe(): void
    {
        $this->subir();

        $this->assertSame(0, CrmProducto::count());
        $this->assertTrue(Schema::hasColumn('crm_productos', 'commodity'));
    }

    public function test_conserva_los_productos_existentes_y_no_duplica_por_nombre(): void
    {
        $sbp = $this->crearSbp();
        $existente = CrmProducto::create([
            'empresa_id' => $sbp->id, 'nombre' => 'Kent Mango', 'precio' => 12.5, 'unidad_medida' => 'kg',
        ]);
        $otro = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Aguacate', 'precio' => 99, 'unidad_medida' => 'litro',
        ]);

        $this->subir();

        $this->assertSame(47, CrmProducto::where('empresa_id', $sbp->id)->count());
        $this->assertSame('kg', $existente->fresh()->unidad_medida);
        $this->assertSame('litro', $otro->fresh()->unidad_medida);
        $this->assertSame('99.00', $otro->fresh()->precio);
    }

    public function test_up_es_re_ejecutable_y_conserva_las_unidades_existentes(): void
    {
        // Productos con las unidades originales sobreviven al recreo del enum en SQLite.
        $producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Servicio', 'precio' => 10, 'unidad_medida' => 'hora',
        ]);

        $this->subir();
        $this->subir();

        $this->assertTrue(Schema::hasColumn('crm_productos', 'commodity'));
        $this->assertSame('hora', DB::table('crm_productos')->where('id', $producto->id)->value('unidad_medida'));

        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Caja', 'precio' => 0, 'unidad_medida' => 'caja',
        ]);
        $this->assertSame(2, DB::table('crm_productos')->count());
    }
}
