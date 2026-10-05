<?php

namespace Tests\Feature\ActivosFijos;

use App\Events\AssetCategoryUpdated;
use App\Events\FixedAssetUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class TiempoRealActivosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_alta_de_activo_emite_en_el_canal_de_su_empresa_y_en_el_corporativo(): void
    {
        Event::fake([FixedAssetUpdated::class]);

        $this->postJson('/api/splendidfarms/administration/activos-fijos/activos', $this->validFixedAssetPayload())->assertCreated();

        Event::assertDispatched(FixedAssetUpdated::class, function (FixedAssetUpdated $e) {
            $canales = collect($e->broadcastOn())->map->name->all();

            return $e->action === 'created'
                && $e->broadcastAs() === 'fixed-asset.updated'
                && $canales === [
                    'private-module.splendidfarms.administration.activos-fijos',
                    'private-module.grupoesplendido.administration.activos-fijos',
                ];
        });
    }

    public function test_cambio_de_tipo_emite_en_todas_las_empresas_con_modulo(): void
    {
        Event::fake([AssetCategoryUpdated::class]);

        $this->postJson('/api/grupoesplendido/administration/activos-fijos/tipos-activo', ['name' => 'Maquinaria'])->assertCreated();

        Event::assertDispatched(AssetCategoryUpdated::class, function (AssetCategoryUpdated $e) {
            $canales = collect($e->broadcastOn())->map->name->sort()->values()->all();

            return $e->broadcastAs() === 'asset-category.updated' && $canales === [
                'private-module.grupoesplendido.administration.activos-fijos',
                'private-module.splendidfarms.administration.activos-fijos',
            ];
        });
    }
}
