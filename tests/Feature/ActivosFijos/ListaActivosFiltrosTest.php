<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class ListaActivosFiltrosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const URL = '/api/splendidfarms/administration/activos-fijos/activos';
    private const URL_GE = '/api/grupoesplendido/administration/activos-fijos/activos';

    private AssetCategory $otraCategoria;
    private Entity $otraEntidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->otraCategoria = AssetCategory::create([
            'code' => 'TAC-010', 'name' => 'Maquinaria y equipos', 'icon' => 'Truck', 'is_active' => true,
        ]);
        $this->otraEntidad = Entity::create([
            'branch_id' => $this->branch->id, 'entity_type_id' => $this->entity->entity_type_id,
            'code' => 'OFC-X', 'name' => 'Empaque Charay', 'slug' => 'empaque-charay', 'is_active' => true,
        ]);
    }

    /** Nombres de los activos que devuelve el índice, en el orden recibido. */
    private function nombres(string $query = '', string $url = self::URL): array
    {
        return collect($this->getJson($url.$query)->assertOk()->json('data.data'))->pluck('name')->all();
    }

    private function asignar(FixedAsset $activo, string $responsable, ?string $devuelta = null): FixedAssetAssignment
    {
        return FixedAssetAssignment::create([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id,
            'assignee_name' => $responsable,
            'assigned_at' => '2026-01-10',
            'condition_out' => 'bueno',
            'asset_snapshot' => [],
            'returned_at' => $devuelta,
        ]);
    }

    public function test_filtra_por_varios_estados(): void
    {
        $this->crearActivo(['name' => 'A', 'status' => 'en_uso']);
        $this->crearActivo(['name' => 'B', 'status' => 'disponible']);
        $this->crearActivo(['name' => 'C', 'status' => 'fuera_de_servicio']);

        $this->assertSame(['A', 'B'], $this->nombres('?status=en_uso,disponible'));
        $this->assertSame(['C'], $this->nombres('?status=fuera_de_servicio'));
    }

    public function test_filtra_por_uno_o_varios_tipos(): void
    {
        $this->crearActivo(['name' => 'A']);
        $this->crearActivo(['name' => 'B', 'category_id' => $this->otraCategoria->id]);

        $this->assertSame(['B'], $this->nombres('?category_id='.$this->otraCategoria->id));
        $this->assertSame(['A', 'B'], $this->nombres('?category_id='.$this->assetCategory->id.','.$this->otraCategoria->id));
    }

    public function test_filtra_por_una_o_varias_ubicaciones(): void
    {
        $this->crearActivo(['name' => 'A']);
        $this->crearActivo(['name' => 'B', 'entity_id' => $this->otraEntidad->id]);

        $this->assertSame(['B'], $this->nombres('?entity_id='.$this->otraEntidad->id));
        $this->assertSame(['A', 'B'], $this->nombres('?entity_id='.$this->entity->id.','.$this->otraEntidad->id));
    }

    public function test_ignora_valores_no_numericos_en_los_filtros_de_id(): void
    {
        $this->crearActivo(['name' => 'A']);
        $this->crearActivo(['name' => 'B']);

        $this->assertSame(['A', 'B'], $this->nombres('?category_id=abc&entity_id=;drop'));
    }

    public function test_filtra_con_y_sin_responsable(): void
    {
        $conResponsable = $this->crearActivo(['name' => 'A']);
        $devuelto = $this->crearActivo(['name' => 'B']);
        $this->crearActivo(['name' => 'C']);
        $this->asignar($conResponsable, 'Ana Ruiz');
        $this->asignar($devuelto, 'Luis Gómez', '2026-02-01');

        $this->assertSame(['A'], $this->nombres('?assigned=si'));
        $this->assertSame(['B', 'C'], $this->nombres('?assigned=no'));
    }

    public function test_filtra_con_y_sin_valor_de_compra(): void
    {
        $this->crearActivo(['name' => 'A', 'purchase_value' => 1500]);
        $this->crearActivo(['name' => 'B']);

        $this->assertSame(['A'], $this->nombres('?has_value=si'));
        $this->assertSame(['B'], $this->nombres('?has_value=no'));
    }

    public function test_filtra_por_rango_de_anio_de_compra(): void
    {
        $this->crearActivo(['name' => 'A', 'purchase_date' => '2019-05-01']);
        $this->crearActivo(['name' => 'B', 'purchase_date' => '2022-06-01']);
        $this->crearActivo(['name' => 'C', 'purchase_date' => '2025-01-01']);
        $this->crearActivo(['name' => 'D']);

        $this->assertSame(['A'], $this->nombres('?year=a'));
        $this->assertSame(['B'], $this->nombres('?year=b'));
        $this->assertSame(['C'], $this->nombres('?year=c'));
        $this->assertSame(['A', 'C'], $this->nombres('?year=a,c'));
        $this->assertSame(['A', 'B', 'C', 'D'], $this->nombres('?year=z'));
    }

    public function test_los_limites_de_cada_rango_de_anio_quedan_en_su_lado(): void
    {
        $this->crearActivo(['name' => 'A', 'purchase_date' => '2020-12-31']);
        $this->crearActivo(['name' => 'B', 'purchase_date' => '2021-01-01']);
        $this->crearActivo(['name' => 'C', 'purchase_date' => '2023-12-31']);
        $this->crearActivo(['name' => 'D', 'purchase_date' => '2024-01-01']);

        $this->assertSame(['A'], $this->nombres('?year=a'));
        $this->assertSame(['B', 'C'], $this->nombres('?year=b'));
        $this->assertSame(['D'], $this->nombres('?year=c'));
    }

    public function test_busca_por_marca_tipo_subtipo_y_responsable(): void
    {
        $lenovo = Brand::create(['code' => 'MRC-002', 'name' => 'Lenovo', 'is_active' => true]);
        $impresoras = AssetCategory::create([
            'code' => 'TAC-011', 'name' => 'Impresoras láser', 'parent_id' => $this->assetCategory->id, 'is_active' => true,
        ]);
        $this->crearActivo(['name' => 'X', 'brand_id' => $lenovo->id]);
        $this->crearActivo(['name' => 'Y', 'subcategory_id' => $impresoras->id]);
        $this->crearActivo(['name' => 'W', 'category_id' => $this->otraCategoria->id]);
        $z = $this->crearActivo(['name' => 'Z']);
        $this->asignar($z, 'Carmen Ruiz');

        $this->assertSame(['X'], $this->nombres('?search=lenovo'));
        $this->assertSame(['Y'], $this->nombres('?search=l%C3%A1ser'));
        $this->assertSame(['W'], $this->nombres('?search=maquinaria'));
        $this->assertSame(['Z'], $this->nombres('?search=carmen'));
    }

    public function test_los_comodines_de_like_en_la_busqueda_no_actuan_como_comodin(): void
    {
        $this->crearActivo(['name' => 'Disco 50% usado']);
        $this->crearActivo(['name' => 'Disco 500 GB']);
        $this->crearActivo(['name' => 'Disco_A']);
        $this->crearActivo(['name' => 'DiscoXA']);

        $this->assertSame(['Disco 50% usado'], $this->nombres('?search=50%25'));
        $this->assertSame(['Disco_A'], $this->nombres('?search=disco_a'));
    }

    public function test_ordena_por_valor_de_compra_y_desempata_por_nombre(): void
    {
        $this->crearActivo(['name' => 'B', 'purchase_value' => 100]);
        $this->crearActivo(['name' => 'A', 'purchase_value' => 100]);
        $this->crearActivo(['name' => 'C', 'purchase_value' => 900]);

        $this->assertSame(['C', 'A', 'B'], $this->nombres('?sort=purchase_value&dir=desc'));
        $this->assertSame(['A', 'B', 'C'], $this->nombres('?sort=purchase_value&dir=asc'));
    }

    public function test_un_orden_desconocido_cae_al_orden_por_nombre(): void
    {
        $this->crearActivo(['name' => 'B']);
        $this->crearActivo(['name' => 'A']);

        $this->assertSame(['A', 'B'], $this->nombres('?sort=password&dir=desc-x'));
    }

    public function test_los_filtros_se_combinan_y_no_cruzan_empresas(): void
    {
        $this->crearActivo(['name' => 'A', 'status' => 'en_uso']);
        $this->crearActivo(['name' => 'B', 'status' => 'en_uso', 'enterprise_id' => $this->corporativo->id]);
        $this->crearActivo(['name' => 'C', 'status' => 'disponible']);

        $this->assertSame(['A'], $this->nombres('?status=en_uso&assigned=no'));
        // Splendid Farms no ve el de GE; GE ve los dos.
        $this->assertSame(['A', 'B'], $this->nombres('?status=en_uso', self::URL_GE));
        $this->assertSame(['B'], $this->nombres('?status=en_uso&enterprise_id='.$this->corporativo->id, self::URL_GE));
    }
}
