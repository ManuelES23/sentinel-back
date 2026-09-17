<?php

namespace Tests\Feature\Console;

use App\Models\Aplicacion;
use App\Models\AplicacionDetalle;
use App\Models\Brand;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\ProductoAplicacion;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrarProductosAplicacionTest extends TestCase
{
    use RefreshDatabase;

    private Enterprise $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'x']);
        UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'L']);
        UnitOfMeasure::create(['code' => 'KG', 'name' => 'Kilogramo', 'abbreviation' => 'kg']);

        ProductoAplicacion::create(['nombre' => 'Clorotalonil 720', 'ingrediente_activo' => 'Clorotalonil', 'marca' => 'Syngenta', 'tipo' => 'agroquimico', 'activo' => true]);
        ProductoAplicacion::create(['nombre' => 'Urea 46%', 'tipo' => 'fertilizante', 'activo' => true]);
    }

    private function correr(array $opciones = [])
    {
        return $this->artisan('agro:migrar-productos-aplicacion', array_merge(['--empresa' => 'splendidfarms'], $opciones));
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->correr(['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductoAplicacion::whereNotNull('product_id')->count());
    }

    public function test_crea_articulos_con_categoria_unidad_marca_y_caducidad(): void
    {
        $this->correr()->assertSuccessful();

        $cloro = Product::where('name', 'Clorotalonil 720')->with(['category', 'unit', 'brand'])->first();
        $this->assertSame('Agroquímicos', $cloro->category->name);
        $this->assertSame('LT', $cloro->unit->code);
        $this->assertSame('Syngenta', $cloro->brand->name);
        $this->assertSame('Clorotalonil', $cloro->ingrediente_activo);
        $this->assertTrue($cloro->track_lots);
        $this->assertTrue($cloro->track_expiry);
        $this->assertTrue($cloro->requiere_revision);
        $this->assertSame('consumable', $cloro->product_type);
        $this->assertTrue($cloro->enterprises()->where('enterprises.id', $this->empresa->id)->exists());

        $urea = Product::where('name', 'Urea 46%')->with('unit', 'category')->first();
        $this->assertSame('KG', $urea->unit->code);
        $this->assertSame('Fertilizantes', $urea->category->name);
        $this->assertFalse($urea->track_expiry);

        $this->assertSame(0, ProductoAplicacion::whereNull('product_id')->count());
    }

    public function test_correr_dos_veces_no_duplica(): void
    {
        $this->correr()->assertSuccessful();
        $this->correr()->assertSuccessful();

        $this->assertSame(2, Product::count());
        $this->assertSame(1, Brand::count());
    }

    public function test_enlaza_nombre_existente_y_reporta_ambiguos(): void
    {
        $existente = Product::create(['code' => 'PROD-00001', 'name' => 'CLOROTALONIL  720']);
        $existente->enterprises()->attach($this->empresa->id);
        foreach (['PROD-00002', 'PROD-00003'] as $code) {
            Product::create(['code' => $code, 'name' => 'Urea 46%'])->enterprises()->attach($this->empresa->id);
        }

        $this->correr()->expectsOutputToContain('Urea 46%')->assertSuccessful();

        $this->assertSame($existente->id, ProductoAplicacion::where('nombre', 'Clorotalonil 720')->value('product_id'));
        $this->assertNull(ProductoAplicacion::where('nombre', 'Urea 46%')->value('product_id'));
        $this->assertSame(3, Product::count());
    }

    public function test_rellena_product_id_en_detalles_de_aplicaciones(): void
    {
        $user = User::factory()->create();
        $cultivo = Cultivo::create(['nombre' => 'Chile']);
        $temporada = Temporada::create([
            'cultivo_id' => $cultivo->id, 'nombre' => 'Chile 2026', 'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id . '-001', 'año_inicio' => 2026, 'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'user_id' => $user->id,
        ]);
        $productor = Productor::create(['nombre' => 'Juan', 'is_active' => true]);
        $aplicacion = Aplicacion::create([
            'temporada_id' => $temporada->id, 'folio' => 'APL-2026-0001', 'fecha' => '2026-09-01',
            'tipo_aplicacion' => 'agroquimico', 'productor_id' => $productor->id, 'problematica' => 'Tizón',
            'created_by' => $user->id,
        ]);
        $pa = ProductoAplicacion::where('nombre', 'Clorotalonil 720')->first();
        $detalle = AplicacionDetalle::create(['aplicacion_id' => $aplicacion->id, 'producto_id' => $pa->id, 'dosis' => 2, 'unidad_medida' => 'L/ha']);

        $this->correr()->assertSuccessful();

        $this->assertSame($pa->fresh()->product_id, $detalle->fresh()->product_id);
    }

    public function test_falla_si_la_empresa_no_existe(): void
    {
        $this->artisan('agro:migrar-productos-aplicacion', ['--empresa' => 'no-existe'])->assertFailed();
    }
}
