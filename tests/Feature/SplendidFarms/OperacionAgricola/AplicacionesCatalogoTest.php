<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\AplicacionDetalle;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\Inventory\CatalogoAgricolaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AplicacionesCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/agricola';

    private Enterprise $empresa;
    private Temporada $temporada;
    private Productor $productor;
    private Product $insumo;
    private array $h = ['X-Enterprise-Slug' => 'splendidfarms'];

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'is_active' => true, 'description' => 'x']);
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $this->empresa->id, 'is_active' => true]);
        UnitOfMeasure::create(['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'L']);
        UnitOfMeasure::create(['code' => 'KG', 'name' => 'Kilogramo', 'abbreviation' => 'kg']);

        $cultivo = Cultivo::create(['nombre' => 'Chile']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $cultivo->id, 'nombre' => 'Chile 2026', 'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id . '-001', 'año_inicio' => 2026, 'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'user_id' => $user->id,
        ]);
        $this->productor = Productor::create(['nombre' => 'Juan', 'is_active' => true]);

        $this->insumo = app(CatalogoAgricolaService::class)->crearProducto($this->empresa, [
            'nombre' => 'Clorotalonil 720', 'ingrediente_activo' => 'Clorotalonil', 'marca' => 'Syngenta', 'tipo' => 'agroquimico',
        ]);
    }

    public function test_index_devuelve_articulos_agricolas_con_la_forma_de_siempre(): void
    {
        Product::create(['code' => 'PROD-09999', 'name' => 'Caja de cartón'])->enterprises()->attach($this->empresa->id);

        $response = $this->getJson(self::BASE . '/productos-aplicacion', $this->h);

        $response->assertOk();
        $this->assertSame([[
            'id' => $this->insumo->id,
            'nombre' => 'Clorotalonil 720',
            'ingrediente_activo' => 'Clorotalonil',
            'marca' => 'Syngenta',
            'tipo' => 'agroquimico',
            'activo' => true,
            'unidad' => 'L',
            'requiere_revision' => true,
        ]], $response->json('data'));

        $this->getJson(self::BASE . '/productos-aplicacion?tipo=fertilizante', $this->h)->assertJsonCount(0, 'data');
    }

    public function test_index_sin_header_da_422(): void
    {
        $this->getJson(self::BASE . '/productos-aplicacion')->assertStatus(422);
    }

    public function test_index_sin_membresia_en_la_empresa_da_403(): void
    {
        $ajeno = User::factory()->create();
        Sanctum::actingAs($ajeno);

        $this->getJson(self::BASE . '/productos-aplicacion', $this->h)->assertStatus(403);
    }

    public function test_store_crea_el_articulo_en_el_catalogo(): void
    {
        $response = $this->postJson(self::BASE . '/productos-aplicacion', [
            'nombre' => 'Urea 46%', 'tipo' => 'fertilizante', 'activo' => true,
        ], $this->h);

        $response->assertCreated()->assertJsonPath('data.tipo', 'fertilizante');
        $producto = Product::find($response->json('data.id'));
        $this->assertTrue($producto->requiere_revision);
        $this->assertTrue($producto->enterprises()->where('enterprises.id', $this->empresa->id)->exists());
    }

    public function test_update_edita_el_articulo(): void
    {
        $response = $this->putJson(self::BASE . '/productos-aplicacion/' . $this->insumo->id, [
            'nombre' => 'Clorotalonil 720 SC',
            'ingrediente_activo' => 'Clorotalonil 72%',
            'marca' => 'Bayer',
            'activo' => false,
        ], $this->h);

        $response->assertOk();
        $this->assertSame([
            'id' => $this->insumo->id,
            'nombre' => 'Clorotalonil 720 SC',
            'ingrediente_activo' => 'Clorotalonil 72%',
            'marca' => 'Bayer',
            'tipo' => 'agroquimico',
            'activo' => false,
            'unidad' => 'L',
            'requiere_revision' => true,
        ], $response->json('data'));

        $producto = $this->insumo->fresh(['brand']);
        $this->assertSame('Clorotalonil 720 SC', $producto->name);
        $this->assertSame('Clorotalonil 72%', $producto->ingrediente_activo);
        $this->assertSame('Bayer', $producto->brand->name);
        $this->assertFalse($producto->is_active);
        $this->assertTrue($producto->brand->enterprises()->where('enterprises.id', $this->empresa->id)->exists());
    }

    public function test_update_de_articulo_de_otra_empresa_da_404(): void
    {
        $ajeno = Product::create(['code' => 'PROD-07777', 'name' => 'Ajeno']);

        $this->putJson(self::BASE . '/productos-aplicacion/' . $ajeno->id, [
            'nombre' => 'Intento',
        ], $this->h)->assertStatus(404);
    }

    public function test_aplicacion_guarda_product_id(): void
    {
        $response = $this->postJson(self::BASE . '/aplicaciones', $this->payload($this->insumo->id), $this->h);

        $response->assertCreated();
        $this->assertSame($this->insumo->id, AplicacionDetalle::first()->product_id);
        $this->assertSame('Clorotalonil 720', $response->json('data.detalles.0.product.name'));
    }

    public function test_aplicacion_con_articulo_de_otra_empresa_da_422(): void
    {
        $ajeno = Product::create(['code' => 'PROD-08888', 'name' => 'Ajeno']);

        $this->postJson(self::BASE . '/aplicaciones', $this->payload($ajeno->id), $this->h)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productos.0.product_id']);
    }

    private function payload(int $productId): array
    {
        return [
            'temporada_id' => $this->temporada->id,
            'fecha' => '2026-09-17',
            'tipo_aplicacion' => 'agroquimico',
            'productor_id' => $this->productor->id,
            'problematica' => 'Tizón tardío',
            'productos' => [['product_id' => $productId, 'dosis' => 2, 'unidad_medida' => 'L/ha']],
        ];
    }
}
