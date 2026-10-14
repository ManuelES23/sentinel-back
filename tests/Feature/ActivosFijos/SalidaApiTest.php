<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use App\Services\ActivosFijos\CierreDepreciacion;
use App\Services\ActivosFijos\RegistradorSalidaActivo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class SalidaApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
    }

    private function payloadVenta(FixedAsset $activo, array $overrides = []): array
    {
        return array_merge([
            'fixed_asset_id' => $activo->id,
            'type' => 'venta',
            'disposal_date' => '2026-05-20',
            'authorized_by_name' => 'Dirección General',
            'sale_price' => 15000,
            'buyer_name' => 'Comercial Rivera S.A.',
            'buyer_address' => 'Calle 5 #123, Culiacán',
            'buyer_identification' => 'INE 1234',
            'payment_method' => 'Transferencia',
            'place' => 'Culiacán, Sin.',
        ], $overrides);
    }

    private function payloadDesecho(FixedAsset $activo, array $overrides = []): array
    {
        return array_merge([
            'fixed_asset_id' => $activo->id,
            'type' => 'desecho',
            'disposal_date' => '2026-05-20',
            'authorized_by_name' => 'Dirección General',
            'reason' => 'Equipo obsoleto',
        ], $overrides);
    }

    private function salidaPorServicio(FixedAsset $activo, array $datos): FixedAssetDisposal
    {
        $datos = array_merge(['authorized_by_name' => 'Dirección General', 'disposal_date' => '2026-05-20'], $datos);

        return app(RegistradorSalidaActivo::class)->registrar($activo, $datos, User::factory()->create(['name' => 'Ana']));
    }

    public function test_registra_una_venta_con_las_cifras_congeladas(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo))
            ->assertCreated()
            ->assertJsonPath('data.type', 'venta')
            ->assertJsonPath('data.book_value', '11100.00')
            ->assertJsonPath('data.accumulated_depreciation', '900.00')
            ->assertJsonPath('data.sale_price', '15000.00')
            ->assertJsonPath('data.result_amount', '3900.00')
            ->assertJsonPath('data.asset_snapshot.code', $activo->code)
            ->assertJsonMissingPath('data.signed_document_path');

        $this->assertSame('baja', $activo->fresh()->status);
    }

    public function test_valida_los_campos_segun_el_tipo(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo, ['sale_price' => null, 'buyer_name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['sale_price', 'buyer_name']);
        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo, ['sale_price' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors('sale_price');
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['reason' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['type' => 'regalo']))
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['disposal_date' => '20/05/2026']))
            ->assertStatus(422)->assertJsonValidationErrors('disposal_date');
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['authorized_by_name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('authorized_by_name');
        $this->postJson(self::URL_SALIDAS, ['type' => 'traspaso', 'fixed_asset_id' => $activo->id, 'disposal_date' => '2026-05-20', 'authorized_by_name' => 'X', 'reason' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['destination_enterprise_id', 'destination_branch_id', 'destination_entity_id']);

        $this->assertSame(0, FixedAssetDisposal::count());
    }

    public function test_un_mes_ya_cerrado_responde_422(): void
    {
        $activo = $this->crearActivoDepreciable();
        $usuario = $this->usuarioSalidas();
        app(CierreDepreciacion::class)->cerrar($this->enterprise, CarbonImmutable::createFromFormat('!Y-m', '2026-04'), $usuario);
        Sanctum::actingAs($usuario);

        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['disposal_date' => '2026-04-20']))
            ->assertStatus(422)->assertJsonValidationErrors('disposal_date');
    }

    public function test_la_segunda_salida_del_mismo_activo_responde_422(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo))->assertCreated();
        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo))
            ->assertStatus(422)->assertJsonValidationErrors('asset');

        $this->assertSame(1, FixedAssetDisposal::count());
    }

    public function test_la_vista_previa_calcula_sin_escribir(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=venta&sale_price=15000")
            ->assertOk()
            ->assertJsonPath('data.book_value', '11100.00')
            ->assertJsonPath('data.result_amount', '3900.00')
            ->assertJsonPath('data.has_active_assignment', false);

        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=venta")
            ->assertOk()
            ->assertJsonPath('data.result_amount', null);

        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-06-16&type=desecho")
            ->assertStatus(422)->assertJsonValidationErrors('disposal_date');

        $this->assertSame(0, FixedAssetDisposal::count());
        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_el_listado_filtra_y_los_totales_solo_suman_las_vigentes(): void
    {
        $venta = $this->crearSalidaDirecta($this->crearActivoDepreciable(), [
            'type' => 'venta', 'disposal_date' => '2026-05-20', 'buyer_name' => 'Comercial Rivera S.A.',
            'sale_price' => '15000.00', 'result_amount' => '3900.00',
        ]);
        $this->crearSalidaDirecta($this->crearActivoDepreciable(), ['disposal_date' => '2026-04-10', 'result_amount' => '-11100.00']);
        $this->crearSalidaDirecta($this->crearActivoDepreciable(), [
            'result_amount' => '-500.00', 'reversed_at' => now(), 'reversal_reason' => 'Error',
        ]);
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->getJson(self::URL_SALIDAS)
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.profit', '3900.00')
            ->assertJsonPath('totals.loss', '-11100.00')
            ->assertJsonPath('totals.net', '-7200.00');

        $this->getJson(self::URL_SALIDAS.'?reversed=1')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('totals.count', 2);

        $this->getJson(self::URL_SALIDAS.'?type=venta')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $venta->id);
        $this->getJson(self::URL_SALIDAS.'?from=2026-05-01&to=2026-05-31')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL_SALIDAS.'?search=Rivera')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::URL_SALIDAS.'?search=zzz')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_el_detalle_y_el_aislamiento_entre_empresas(): void
    {
        $propia = $this->crearSalidaDirecta($this->crearActivoDepreciable());
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $ajeno = FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $sbp->id, 'code' => 'SBP-AF-000001']));
        $salidaAjena = $this->crearSalidaDirecta($ajeno);
        Sanctum::actingAs($this->usuarioSalidas());

        $this->getJson(self::URL_SALIDAS."/{$propia->id}")->assertOk()->assertJsonPath('data.id', $propia->id);
        $this->getJson(self::URL_SALIDAS."/{$salidaAjena->id}")->assertNotFound();
        $this->deleteJson(self::URL_SALIDAS."/{$salidaAjena->id}", ['reason' => 'X'])->assertNotFound();
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($ajeno))->assertNotFound();
        $this->assertNull($salidaAjena->fresh()->reversed_at);
    }

    public function test_grupo_esplendido_ve_las_salidas_de_todas_las_empresas(): void
    {
        $this->crearSalidaDirecta($this->crearActivoDepreciable());
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $this->crearSalidaDirecta(FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $sbp->id, 'code' => 'SBP-AF-000001'])));
        Sanctum::actingAs($this->usuarioSalidas($this->corporativo, ['view']));

        $this->getJson(self::URL_SALIDAS_GE)->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson(self::URL_SALIDAS_GE.'?enterprise_id='.$sbp->id)->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_deshacer_restaura_el_activo_y_exige_motivo(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->salidaPorServicio($activo, ['type' => 'desecho', 'reason' => 'Obsoleto']);
        Sanctum::actingAs($this->usuarioSalidas());

        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('baja', $activo->fresh()->status);

        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'Se registró por error'])->assertOk();

        $this->assertSame('en_uso', $activo->fresh()->status);
        $this->assertNotNull($salida->fresh()->reversed_at);
    }

    public function test_traspaso_desde_grupo_esplendido_crea_el_activo_en_la_receptora(): void
    {
        $activo = $this->crearActivoDepreciable();
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        $usuario = $this->usuarioSalidas($this->corporativo, ['view', 'create']);
        $this->otorgarActivos($usuario, $this->corporativo, 'activos', ['create']);
        Sanctum::actingAs($usuario);

        $this->postJson(self::URL_SALIDAS_GE, [
            'fixed_asset_id' => $activo->id, 'type' => 'traspaso', 'disposal_date' => '2026-05-20',
            'authorized_by_name' => 'Dirección General', 'reason' => 'Pasa al corporativo',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id, 'destination_entity_id' => $entidad->id,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'traspaso')
            ->assertJsonPath('data.result_amount', '0.00')
            ->assertJsonPath('data.destination_enterprise.slug', 'grupoesplendido');

        $destinoId = FixedAssetDisposal::first()->destination_asset_id;
        $this->assertNotNull($destinoId);
        $this->assertSame($this->corporativo->id, FixedAsset::findOrFail($destinoId)->enterprise_id);
    }

    public function test_el_traspaso_exige_activos_create_en_la_empresa_destino(): void
    {
        $activo = $this->crearActivoDepreciable();
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        Sanctum::actingAs($this->usuarioSalidas($this->corporativo, ['view', 'create'])); // sin activos.create en GE

        $this->postJson(self::URL_SALIDAS_GE, [
            'fixed_asset_id' => $activo->id, 'type' => 'traspaso', 'disposal_date' => '2026-05-20',
            'authorized_by_name' => 'Dirección General', 'reason' => 'X',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id, 'destination_entity_id' => $entidad->id,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_enterprise_id');

        $this->assertSame(0, FixedAssetDisposal::count());
    }

    public function test_el_traspaso_desde_una_empresa_operativa_se_rechaza(): void
    {
        $activo = $this->crearActivoDepreciable();
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, [
            'fixed_asset_id' => $activo->id, 'type' => 'traspaso', 'disposal_date' => '2026-05-20',
            'authorized_by_name' => 'Dirección General', 'reason' => 'X',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id, 'destination_entity_id' => $entidad->id,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_enterprise_id');
    }

    public function test_mis_permisos_incluye_salidas(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $this->getJson('/api/splendidfarms/administration/activos-fijos/mis-permisos')
            ->assertOk()
            ->assertJsonPath('data.salidas.view', true)
            ->assertJsonPath('data.salidas.create', true)
            ->assertJsonPath('data.salidas.edit', false)
            ->assertJsonPath('data.salidas.delete', false);
    }

    public function test_cada_permiso_abre_solo_su_accion(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->salidaPorServicio($this->crearActivoDepreciable(), ['type' => 'desecho', 'reason' => 'X']);

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));
        $this->getJson(self::URL_SALIDAS)->assertOk();
        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=desecho")->assertForbidden();
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo))->assertForbidden();
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['create']));
        $this->getJson(self::URL_SALIDAS)->assertForbidden();
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'X'])->assertForbidden();
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo))->assertCreated();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['delete']));
        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($this->crearActivoDepreciable()))->assertForbidden();
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'Error'])->assertOk();
    }

    public function test_el_precio_de_venta_exige_centavos_exactos(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo, ['sale_price' => 10.005]))
            ->assertStatus(422)->assertJsonValidationErrors('sale_price');
        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=venta&sale_price=10.005")
            ->assertStatus(422)->assertJsonValidationErrors('sale_price');
        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=venta&sale_price=10.5")
            ->assertOk();

        $this->assertSame(0, FixedAssetDisposal::count());
    }

    public function test_una_fecha_imposible_responde_422_y_no_500(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->postJson(self::URL_SALIDAS, $this->payloadDesecho($activo, ['disposal_date' => '2026-02-30']))
            ->assertStatus(422)->assertJsonValidationErrors('disposal_date');
        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-02-30&type=desecho")
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $this->assertSame(0, FixedAssetDisposal::count());
    }

    public function test_sin_autenticar_responde_401(): void
    {
        $this->getJson(self::URL_SALIDAS)->assertUnauthorized();
        $this->getJson(self::URL_SALIDAS.'/previa')->assertUnauthorized();
        $this->postJson(self::URL_SALIDAS, [])->assertUnauthorized();
        $this->getJson(self::URL_SALIDAS.'/1')->assertUnauthorized();
        $this->deleteJson(self::URL_SALIDAS.'/1', ['reason' => 'X'])->assertUnauthorized();
    }
}
