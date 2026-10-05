<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Enterprise;
use App\Services\ActivosFijos\GeneradorCodigoActivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeneradorCodigoActivoTest extends TestCase
{
    use RefreshDatabase;

    private GeneradorCodigoActivo $generador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generador = app(GeneradorCodigoActivo::class);
    }

    private function empresa(string $slug, ?string $prefijo): Enterprise
    {
        return Enterprise::create(['name' => $slug, 'slug' => $slug, 'description' => $slug, 'is_active' => true, 'asset_code_prefix' => $prefijo]);
    }

    public function test_cada_empresa_lleva_su_propio_consecutivo(): void
    {
        $sf = $this->empresa('splendidfarms', 'SF');
        $sp = $this->empresa('splendidbyporvenir', 'SP');

        $codigos = DB::transaction(fn () => [
            $this->generador->siguiente($sf),
            $this->generador->siguiente($sf),
            $this->generador->siguiente($sp),
        ]);

        $this->assertSame(['SF-AF-000001', 'SF-AF-000002', 'SP-AF-000001'], $codigos);
    }

    public function test_la_vista_previa_no_reserva_numero(): void
    {
        $sf = $this->empresa('splendidfarms', 'SF');

        $this->assertSame('SF-AF-000001', $this->generador->vistaPrevia($sf));
        $this->assertSame('SF-AF-000001', $this->generador->vistaPrevia($sf));
        $this->assertSame('SF-AF-000001', DB::transaction(fn () => $this->generador->siguiente($sf)));
        $this->assertSame('SF-AF-000002', $this->generador->vistaPrevia($sf));
    }

    public function test_salta_un_codigo_que_ya_se_capturo_a_mano(): void
    {
        $sf = $this->empresa('splendidfarms', 'SF');
        DB::table('fixed_asset_sequences')->insert(['enterprise_id' => $sf->id, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->crearActivoConCodigo($sf, 'SF-AF-000001');

        $this->assertSame('SF-AF-000002', DB::transaction(fn () => $this->generador->siguiente($sf)));
    }

    public function test_sin_prefijo_lanza_error_de_validacion(): void
    {
        $sin = $this->empresa('canes-agro', null);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->generador->siguiente($sin));
    }

    public function test_el_consecutivo_de_tipos_salta_codigos_existentes(): void
    {
        AssetCategory::create(['code' => 'TAC-001', 'name' => 'A', 'is_active' => true]);

        $this->assertSame('TAC-002', DB::transaction(fn () => $this->generador->siguienteTipo()));
        $this->assertSame('TAC-003', DB::transaction(fn () => $this->generador->siguienteTipo()));
    }

    private function crearActivoConCodigo(Enterprise $empresa, string $codigo): void
    {
        $sucursal = \App\Models\Branch::create(['enterprise_id' => $empresa->id, 'code' => 'B'.$codigo, 'name' => 'Matriz', 'slug' => 'm'.$codigo, 'is_active' => true]);
        $tipoEntidad = \App\Models\EntityType::firstOrCreate(['code' => 'OFICINA'], ['name' => 'Oficina', 'slug' => 'oficina', 'is_active' => true]);
        $entidad = \App\Models\Entity::create(['branch_id' => $sucursal->id, 'entity_type_id' => $tipoEntidad->id, 'code' => 'E'.$codigo, 'name' => 'Oficina', 'slug' => 'e'.$codigo, 'is_active' => true]);
        $tipo = AssetCategory::create(['code' => 'T'.$codigo, 'name' => 'Tipo', 'is_active' => true]);

        \App\Models\FixedAsset::create([
            'enterprise_id' => $empresa->id, 'code' => $codigo, 'name' => 'X', 'category_id' => $tipo->id,
            'branch_id' => $sucursal->id, 'entity_id' => $entidad->id,
        ]);
    }
}
