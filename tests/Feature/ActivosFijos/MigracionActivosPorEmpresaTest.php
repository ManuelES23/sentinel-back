<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\EntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigracionActivosPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION = 'database/migrations/2026_10_05_100000_activos_fijos_por_empresa.php';

    public function test_asigna_la_empresa_desde_la_sucursal_y_siembra_prefijos_y_consecutivo_de_tipos(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRACION]);
        $this->assertFalse(Schema::hasColumn('fixed_assets', 'enterprise_id'));

        $sf = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Empresa de prueba', 'is_active' => true]);
        $sp = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Empresa de prueba', 'is_active' => true]);
        $ge = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'Empresa de prueba', 'is_active' => true]);
        $sucursal = Branch::create(['enterprise_id' => $sp->id, 'code' => 'SP-1', 'name' => 'Matriz', 'slug' => 'matriz', 'is_active' => true]);
        $tipoEntidad = EntityType::create(['code' => 'OFICINA', 'name' => 'Oficina', 'slug' => 'oficina', 'is_active' => true]);
        $entidad = Entity::create(['branch_id' => $sucursal->id, 'entity_type_id' => $tipoEntidad->id, 'code' => 'E1', 'name' => 'Oficina', 'slug' => 'oficina', 'is_active' => true]);
        $tipo = AssetCategory::create(['code' => 'TAC-007', 'name' => 'Cómputo', 'is_active' => true]);

        DB::table('fixed_assets')->insert([
            'code' => 'AF-000001', 'name' => 'Laptop', 'slug' => 'laptop-x', 'category_id' => $tipo->id,
            'branch_id' => $sucursal->id, 'entity_id' => $entidad->id, 'status' => 'en_uso', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRACION]);

        $this->assertSame($sp->id, (int) DB::table('fixed_assets')->where('code', 'AF-000001')->value('enterprise_id'));
        $this->assertSame('SF', $sf->fresh()->asset_code_prefix);
        $this->assertSame('SP', $sp->fresh()->asset_code_prefix);
        $this->assertSame('GE', $ge->fresh()->asset_code_prefix);
        $this->assertSame(7, (int) DB::table('asset_category_sequences')->where('id', 1)->value('last_number'));
        $this->assertTrue(Schema::hasTable('fixed_asset_sequences'));
    }
}
