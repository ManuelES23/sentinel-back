<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\ActivosFijos\CreadorActivo;
use App\Services\ActivosFijos\ValidadorRelacionesActivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class AltaCompraCreadorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
    }

    public function test_crear_genera_codigo_consecutivo_y_asigna_la_empresa(): void
    {
        $creador = app(CreadorActivo::class);

        $a = DB::transaction(fn () => $creador->crear($this->enterprise, $this->validFixedAssetPayload(['name' => 'A'])));
        $b = DB::transaction(fn () => $creador->crear($this->enterprise, $this->validFixedAssetPayload(['name' => 'B'])));

        $this->assertSame('SF-AF-000001', $a->code);
        $this->assertSame('SF-AF-000002', $b->code);
        $this->assertSame($this->enterprise->id, $a->enterprise_id);
    }

    public function test_crear_respeta_el_codigo_capturado_a_mano(): void
    {
        $activo = DB::transaction(fn () => app(CreadorActivo::class)->crear(
            $this->enterprise,
            $this->validFixedAssetPayload(['code' => 'MANUAL-1']),
        ));

        $this->assertSame('MANUAL-1', $activo->code);
    }

    public function test_crear_sincroniza_las_caracteristicas_solo_si_se_piden(): void
    {
        $creador = app(CreadorActivo::class);

        $sin = DB::transaction(fn () => $creador->crear($this->enterprise, $this->validFixedAssetPayload(['name' => 'Sin'])));
        $con = DB::transaction(fn () => $creador->crear(
            $this->enterprise,
            $this->validFixedAssetPayload(['name' => 'Con']),
            [['name' => 'RAM', 'value' => '16 GB'], ['name' => '', 'value' => 'ignorada']],
        ));

        $this->assertCount(0, $sin->characteristics);
        $this->assertCount(1, $con->fresh()->characteristics);
        $this->assertSame('RAM', $con->characteristics->first()->name);
    }

    public function test_el_validador_detecta_cada_relacion_invalida(): void
    {
        $validador = app(ValidadorRelacionesActivo::class);
        $otraSucursal = Branch::create([
            'enterprise_id' => $this->corporativo->id, 'code' => 'SUC-X', 'name' => 'Otra', 'slug' => 'otra', 'is_active' => true,
        ]);

        $errores = $validador->errores($this->enterprise, [
            'branch_id' => $otraSucursal->id,
            'entity_id' => $this->entity->id,
            'area_id' => 999999,
            'brand_id' => 999999,
            'category_id' => $this->assetSubcategory->id, // un subtipo no es tipo principal
            'subcategory_id' => $this->assetCategory->id,  // y este no es hijo de aquel
        ]);

        $this->assertEqualsCanonicalizing(
            ['branch_id', 'entity_id', 'area_id', 'brand_id', 'category_id', 'subcategory_id'],
            array_keys($errores),
        );
    }

    public function test_el_validador_acepta_una_combinacion_correcta(): void
    {
        $errores = app(ValidadorRelacionesActivo::class)->errores($this->enterprise, [
            'branch_id' => $this->branch->id,
            'entity_id' => $this->entity->id,
            'brand_id' => $this->brand->id,
            'category_id' => $this->assetCategory->id,
            'subcategory_id' => $this->assetSubcategory->id,
        ]);

        $this->assertSame([], $errores);
    }
}
