<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orden del menú del CRM según la jornada del vendedor (fase 1 de la
 * reorganización). Cubre el seeder, que siembra empresas nuevas, y la
 * migración, que corrige las empresas que ya existen.
 */
class CrmOrdenModulosTest extends TestCase
{
    use RefreshDatabase;

    private const ORDEN_ESPERADO = [
        'agenda', 'oportunidades', 'clientes', 'prospectos', 'cotizaciones',
        'actividades', 'empresas-externas', 'dashboard', 'presupuestos',
        'catalogos', 'integraciones',
    ];

    private const MIGRACION = 'database/migrations/2026_09_12_100000_reorder_crm_modules_by_seller_workflow.php';

    private function crearEmpresaConCrm(): Application
    {
        $enterprise = Enterprise::create([
            'name' => 'Empresa Orden CRM',
            'slug' => 'empresa-orden-crm-'.uniqid(),
            'description' => 'Prueba de orden de módulos',
            'is_active' => true,
        ]);

        (new CrmPermisosSeeder())->run();

        return Application::where('enterprise_id', $enterprise->id)->where('slug', 'crm')->firstOrFail();
    }

    private function slugsEnOrden(Application $app): array
    {
        return Module::where('application_id', $app->id)->orderBy('order')->pluck('slug')->all();
    }

    public function test_el_seeder_siembra_el_menu_en_el_orden_del_vendedor(): void
    {
        $app = $this->crearEmpresaConCrm();

        $this->assertSame(self::ORDEN_ESPERADO, $this->slugsEnOrden($app));
    }

    public function test_la_migracion_reordena_empresas_existentes_y_se_puede_revertir(): void
    {
        $app = $this->crearEmpresaConCrm();

        // Simula una empresa sembrada antes de este cambio.
        $ordenAnterior = [
            'catalogos', 'prospectos', 'clientes', 'empresas-externas', 'actividades',
            'oportunidades', 'cotizaciones', 'presupuestos', 'agenda', 'dashboard', 'integraciones',
        ];
        foreach ($ordenAnterior as $i => $slug) {
            Module::where('application_id', $app->id)->where('slug', $slug)->update(['order' => $i + 1]);
        }

        $migracion = require base_path(self::MIGRACION);

        $migracion->up();
        $this->assertSame(self::ORDEN_ESPERADO, $this->slugsEnOrden($app));

        $migracion->down();
        $this->assertSame($ordenAnterior, $this->slugsEnOrden($app));
    }

    public function test_la_migracion_no_toca_modulos_de_otras_aplicaciones(): void
    {
        $app = $this->crearEmpresaConCrm();
        $otraApp = Application::create([
            'enterprise_id' => $app->enterprise_id,
            'slug' => 'inventario',
            'name' => 'Inventario',
            'description' => 'Otra aplicación',
            'path' => '/inventario',
            'is_active' => true,
        ]);
        $moduloAjeno = Module::create([
            'application_id' => $otraApp->id, 'slug' => 'agenda', 'name' => 'Agenda ajena', 'order' => 7, 'is_active' => true,
        ]);

        (require base_path(self::MIGRACION))->up();

        $this->assertSame(7, (int) $moduloAjeno->fresh()->order);
    }
}
