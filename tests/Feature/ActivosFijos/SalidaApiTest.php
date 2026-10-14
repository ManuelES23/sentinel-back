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

    /** Traspaso hecho por el servicio hacia SBP (la API solo traspasa desde GE; aquí interesa deshacerlo). */
    private function traspasoASbp(FixedAsset $origen, Enterprise $sbp): FixedAssetDisposal
    {
        [$sucursal, $entidad] = $this->crearUbicacion($sbp, 'SBP');

        return app(RegistradorSalidaActivo::class)->registrar($origen, [
            'type' => 'traspaso', 'reason' => 'Pasa a SBP',
            'authorized_by_name' => 'Dirección General', 'disposal_date' => '2026-05-20',
            'destination_enterprise_id' => $sbp->id,
            'destination_branch_id' => $sucursal->id, 'destination_entity_id' => $entidad->id,
        ], User::factory()->create(['name' => 'Ana']), [$sbp->id]);
    }

    private function activoDe(Enterprise $empresa, string $codigo): FixedAsset
    {
        return FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $empresa->id, 'code' => $codigo]));
    }

    public function test_deshacer_un_traspaso_exige_poder_retirar_el_activo_recibido(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);
        $recibido = FixedAsset::findOrFail($salida->destination_asset_id);

        // Usuario de SF con salidas.delete solo en SF: no ve ni tiene permisos en SBP.
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'delete']));
        $respuesta = $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'Error'])
            ->assertStatus(422)->assertJsonValidationErrors('salida');

        $this->assertStringNotContainsString($recibido->code, $respuesta->getContent());
        $this->assertNull($salida->fresh()->reversed_at);
        $this->assertNull($recibido->fresh()->deleted_at);
    }

    public function test_grupo_esplendido_con_permiso_en_la_receptora_deshace_el_traspaso(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);

        $usuario = $this->usuarioSalidas($this->corporativo, ['view', 'delete']);
        $this->otorgarActivos($usuario, $sbp, 'activos', ['delete']);
        Sanctum::actingAs($usuario);

        $this->deleteJson(self::URL_SALIDAS_GE."/{$salida->id}", ['reason' => 'Error'])->assertOk();

        $this->assertNotNull($salida->fresh()->reversed_at);
        $this->assertSoftDeleted('fixed_assets', ['id' => $salida->destination_asset_id]);
    }

    public function test_deshacer_un_traspaso_con_destino_visible_pero_sin_activos_delete_se_rechaza(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);

        // GE ve SBP, pero el usuario no tiene activos.delete ahí.
        Sanctum::actingAs($this->usuarioSalidas($this->corporativo, ['view', 'delete']));
        $this->deleteJson(self::URL_SALIDAS_GE."/{$salida->id}", ['reason' => 'Error'])
            ->assertStatus(422)->assertJsonValidationErrors('salida');

        $this->assertNull($salida->fresh()->reversed_at);
        $this->assertNull(FixedAsset::findOrFail($salida->destination_asset_id)->deleted_at);
    }

    public function test_el_precio_absurdo_se_rechaza_en_la_previa(): void
    {
        $activo = $this->crearActivoDepreciable();
        Sanctum::actingAs($this->usuarioSalidas());

        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=venta&sale_price=99999999999999.99")
            ->assertStatus(422)->assertJsonValidationErrors('sale_price');
    }

    public function test_una_salida_de_otra_empresa_responde_404_antes_que_403(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $ajena = $this->crearSalidaDirecta($this->activoDe($sbp, 'SBP-AF-000001'));

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create'])); // sin delete
        $this->deleteJson(self::URL_SALIDAS."/{$ajena->id}", ['reason' => 'X'])->assertNotFound();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['create', 'delete'])); // sin view
        $this->getJson(self::URL_SALIDAS."/{$ajena->id}")->assertNotFound();
    }

    public function test_el_listado_de_una_empresa_excluye_las_ajenas_aunque_se_pida_su_id(): void
    {
        $propia = $this->crearSalidaDirecta($this->crearActivoDepreciable());
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $this->crearSalidaDirecta($this->activoDe($sbp, 'SBP-AF-000001'));
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->getJson(self::URL_SALIDAS)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('totals.count', 1);
        $this->getJson(self::URL_SALIDAS.'?enterprise_id='.$sbp->id)
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $propia->id)
            ->assertJsonPath('totals.count', 1);
    }

    public function test_la_previa_de_un_activo_de_otra_empresa_responde_404(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $ajeno = $this->activoDe($sbp, 'SBP-AF-000001');
        Sanctum::actingAs($this->usuarioSalidas());

        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$ajeno->id}&date=2026-05-20&type=desecho")->assertNotFound();
    }

    public function test_el_detalle_exige_view_y_la_previa_exige_create(): void
    {
        $salida = $this->crearSalidaDirecta($this->crearActivoDepreciable());
        $activo = $this->crearActivoDepreciable();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['create']));
        $this->getJson(self::URL_SALIDAS."/{$salida->id}")->assertForbidden();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['delete']));
        $this->getJson(self::URL_SALIDAS."/previa?fixed_asset_id={$activo->id}&date=2026-05-20&type=desecho")->assertForbidden();
    }

    public function test_sin_autenticar_responde_401(): void
    {
        $this->getJson(self::URL_SALIDAS)->assertUnauthorized();
        $this->getJson(self::URL_SALIDAS.'/previa')->assertUnauthorized();
        $this->postJson(self::URL_SALIDAS, [])->assertUnauthorized();
        $this->getJson(self::URL_SALIDAS.'/1')->assertUnauthorized();
        $this->deleteJson(self::URL_SALIDAS.'/1', ['reason' => 'X'])->assertUnauthorized();
    }

    public function test_el_domicilio_del_comprador_admite_500_caracteres_y_no_mas(): void
    {
        Sanctum::actingAs($this->usuarioSalidas());
        $activo = $this->crearActivoDepreciable();
        $domicilio = substr(str_repeat('Calle 5, Colonia Centro, ', 30), 0, 499).'Z'; // 500 caracteres, más de 255
        $this->assertSame(500, strlen($domicilio));

        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo, ['buyer_address' => $domicilio.'X']))
            ->assertStatus(422)->assertJsonValidationErrors('buyer_address');
        $this->assertSame(0, FixedAssetDisposal::count());

        $this->postJson(self::URL_SALIDAS, $this->payloadVenta($activo, ['buyer_address' => $domicilio]))
            ->assertCreated()
            ->assertJsonPath('data.buyer_address', $domicilio);

        $this->assertSame($domicilio, FixedAssetDisposal::first()->buyer_address);
    }

    public function test_deshacer_valida_en_orden_alcance_permiso_motivo_y_retiro_del_destino(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);
        $ajena = $this->crearSalidaDirecta($this->activoDe($sbp, 'SBP-AF-000050'));

        // 404 (otra empresa) antes que 403 (sin permiso) antes que 422 (sin motivo).
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));
        $this->deleteJson(self::URL_SALIDAS."/{$ajena->id}", [])->assertNotFound();
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", [])->assertForbidden();

        // Con permiso: el motivo faltante (422 `reason`) se reporta antes que el retiro imposible (422 `salida`).
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'delete']));
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason')->assertJsonMissingValidationErrors('salida');
        $this->deleteJson(self::URL_SALIDAS."/{$salida->id}", ['reason' => 'Error'])
            ->assertStatus(422)->assertJsonValidationErrors('salida');

        $this->assertNull($salida->fresh()->reversed_at);
    }

    public function test_un_traspaso_ya_deshecho_cuyo_destino_sigue_vivo_responde_422_sin_tocar_el_destino(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);
        $salida->update(['reversed_at' => now(), 'reversal_reason' => 'Ya se deshizo']);

        $usuario = $this->usuarioSalidas($this->corporativo, ['view', 'delete']);
        $this->otorgarActivos($usuario, $sbp, 'activos', ['delete']);
        Sanctum::actingAs($usuario);

        $respuesta = $this->deleteJson(self::URL_SALIDAS_GE."/{$salida->id}", ['reason' => 'Otra vez'])
            ->assertStatus(422)->assertJsonValidationErrors('salida');

        $this->assertStringContainsString('ya fue deshecha', $respuesta->json('errors.salida.0'));
        $this->assertNull(FixedAsset::findOrFail($salida->destination_asset_id)->deleted_at);
        $this->assertSame('Ya se deshizo', $salida->fresh()->reversal_reason);
    }

    public function test_el_destino_de_la_salida_sale_como_entero_en_el_json(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $salida = $this->traspasoASbp($this->crearActivoDepreciable(), $sbp);
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->assertSame($sbp->id, $salida->fresh()->destination_enterprise_id);
        $this->getJson(self::URL_SALIDAS."/{$salida->id}")
            ->assertOk()
            ->assertJsonPath('data.destination_enterprise_id', $sbp->id);
    }
}
