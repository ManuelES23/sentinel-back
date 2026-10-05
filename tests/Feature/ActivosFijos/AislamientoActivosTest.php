<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class AislamientoActivosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const SF = '/api/splendidfarms/administration/activos-fijos/activos';
    private const SP = '/api/splendidbyporvenir/administration/activos-fijos/activos';
    private const GE = '/api/grupoesplendido/administration/activos-fijos/activos';

    private \App\Models\Enterprise $sp;
    private FixedAsset $activoSp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->sp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        [$sucursalSp, $entidadSp] = $this->crearUbicacion($this->sp, 'SP');
        $this->activoSp = FixedAsset::create($this->validFixedAssetPayload([
            'enterprise_id' => $this->sp->id, 'code' => 'SP-AF-000001', 'name' => 'Tractor SP',
            'branch_id' => $sucursalSp->id, 'entity_id' => $entidadSp->id, 'brand_id' => null,
        ]));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'SF-AF-000001', 'name' => 'Laptop SF']));
        Sanctum::actingAs($this->actingUser);
    }

    public function test_sf_solo_lista_sus_activos(): void
    {
        $this->getJson(self::SF)->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.code', 'SF-AF-000001');
    }

    public function test_sf_no_ve_edita_ni_borra_un_activo_de_sp_por_id(): void
    {
        $this->getJson(self::SF."/{$this->activoSp->id}")->assertNotFound();
        $this->putJson(self::SF."/{$this->activoSp->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson(self::SF."/{$this->activoSp->id}")->assertNotFound();
        $this->assertNotSoftDeleted('fixed_assets', ['id' => $this->activoSp->id]);
    }

    public function test_el_alta_en_sf_ignora_el_enterprise_id_del_cliente(): void
    {
        $this->postJson(self::SF, $this->validFixedAssetPayload(['enterprise_id' => $this->sp->id]))
            ->assertCreated()
            ->assertJsonPath('data.enterprise_id', $this->enterprise->id)
            ->assertJsonPath('data.code', 'SF-AF-000002');
    }

    public function test_sin_acceso_a_sp_responde_403(): void
    {
        $this->getJson(self::SP)->assertForbidden();
    }

    public function test_ge_lista_todas_y_filtra_por_empresa(): void
    {
        $this->getJson(self::GE)->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson(self::GE.'?enterprise_id='.$this->sp->id)->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.enterprise.slug', 'splendidbyporvenir');
    }

    public function test_ge_crea_para_sf_con_prefijo_sf(): void
    {
        $this->postJson(self::GE, $this->validFixedAssetPayload(['enterprise_id' => $this->enterprise->id]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'SF-AF-000002')
            ->assertJsonPath('data.enterprise_id', $this->enterprise->id);
    }

    public function test_ge_exige_elegir_empresa(): void
    {
        $payload = $this->validFixedAssetPayload();
        unset($payload['enterprise_id']);

        $this->postJson(self::GE, $payload)->assertStatus(422)->assertJsonValidationErrors(['enterprise_id']);
    }

    public function test_la_sucursal_debe_ser_de_la_empresa_del_activo(): void
    {
        $this->postJson(self::GE, $this->validFixedAssetPayload(['enterprise_id' => $this->sp->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
    }

    public function test_la_marca_debe_estar_ligada_a_la_empresa(): void
    {
        $marcaAjena = \App\Models\Brand::create(['code' => 'MRC-009', 'name' => 'HP', 'is_active' => true]);

        $this->postJson(self::SF, $this->validFixedAssetPayload(['brand_id' => $marcaAjena->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['brand_id']);
    }

    public function test_sin_permiso_create_responde_403(): void
    {
        $lector = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($lector, $this->enterprise, 'activos', ['view']);
        Sanctum::actingAs($lector);

        $this->getJson(self::SF)->assertOk();
        $this->postJson(self::SF, $this->validFixedAssetPayload())->assertForbidden();
        $this->putJson(self::SF.'/'.FixedAsset::where('code', 'SF-AF-000001')->value('id'), ['name' => 'X'])->assertForbidden();
        $this->deleteJson(self::SF.'/'.FixedAsset::where('code', 'SF-AF-000001')->value('id'))->assertForbidden();
    }

    public function test_sin_permiso_view_no_lista(): void
    {
        $sinPermisos = User::factory()->create(['role' => 'user']);
        \App\Models\UserEnterpriseAccess::create(['user_id' => $sinPermisos->id, 'enterprise_id' => $this->enterprise->id, 'is_active' => true]);
        Sanctum::actingAs($sinPermisos);

        $this->getJson(self::SF)->assertForbidden();
    }

    public function test_next_code_es_vista_previa_por_empresa(): void
    {
        $this->getJson(self::SF.'/next-code')->assertOk()->assertJsonPath('data.code', 'SF-AF-000002');
    }
}
