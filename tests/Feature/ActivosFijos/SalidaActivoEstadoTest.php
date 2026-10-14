<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class SalidaActivoEstadoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    private const URL_ACTIVOS = '/api/splendidfarms/administration/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        Sanctum::actingAs($this->actingUser);
    }

    /** Activo en baja con salida vigente el 20 de mayo de 2026. */
    private function activoConSalida(): FixedAsset
    {
        $activo = $this->crearActivoDepreciable();
        $activo->update(['status' => 'baja']);
        $this->crearSalidaDirecta($activo);

        return $activo;
    }

    public function test_no_se_puede_poner_baja_por_la_edicion_normal(): void
    {
        $activo = $this->crearActivoDepreciable();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->validFixedAssetPayload(['status' => 'baja']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_con_salida_vigente_el_estado_no_se_edita_pero_el_resto_si(): void
    {
        $activo = $this->activoConSalida();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->validFixedAssetPayload(['name' => 'Renombrado', 'status' => 'disponible']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->assertSame('baja', $activo->fresh()->status);

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->validFixedAssetPayload(['name' => 'Renombrado', 'status' => 'baja']))
            ->assertOk();
        $this->assertSame('Renombrado', $activo->fresh()->name);
    }

    public function test_un_baja_antiguo_sin_salida_si_puede_cambiar_de_estado(): void
    {
        $activo = $this->crearActivoDepreciable(['status' => 'baja']);

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->validFixedAssetPayload(['status' => 'disponible']))
            ->assertOk();

        $this->assertSame('disponible', $activo->fresh()->status);
    }

    public function test_no_se_borra_un_activo_con_salida_vigente_ni_con_historial(): void
    {
        $conSalida = $this->activoConSalida();
        $soloHistorial = $this->crearActivoDepreciable();
        $this->crearSalidaDirecta($soloHistorial, ['reversed_at' => now(), 'reversal_reason' => 'Error']);

        $this->deleteJson(self::URL_ACTIVOS."/{$conSalida->id}")->assertStatus(422)->assertJsonValidationErrors('asset');
        $this->deleteJson(self::URL_ACTIVOS."/{$soloHistorial->id}")->assertStatus(422)->assertJsonValidationErrors('asset');

        $this->assertNull($conSalida->fresh()->deleted_at);
        $this->assertNull($soloHistorial->fresh()->deleted_at);
    }

    public function test_no_se_borra_el_activo_recibido_en_un_traspaso_vigente(): void
    {
        $origen = $this->activoConSalida();
        $recibido = $this->crearActivoDepreciable();
        $origen->salidaVigente->update(['type' => 'traspaso', 'destination_asset_id' => $recibido->id]);

        $this->deleteJson(self::URL_ACTIVOS."/{$recibido->id}")->assertStatus(422)->assertJsonValidationErrors('asset');

        $this->assertNull($recibido->fresh()->deleted_at);
    }

    public function test_un_activo_sin_salidas_se_sigue_borrando(): void
    {
        $activo = $this->crearActivoDepreciable();

        $this->deleteJson(self::URL_ACTIVOS."/{$activo->id}")->assertOk();

        $this->assertSoftDeleted('fixed_assets', ['id' => $activo->id]);
    }

    public function test_el_json_trae_la_salida_vigente_y_la_depreciacion_congelada(): void
    {
        $activo = $this->activoConSalida();

        $this->getJson(self::URL_ACTIVOS."/{$activo->id}")
            ->assertOk()
            ->assertJsonPath('data.salida_vigente.type', 'desecho')
            ->assertJsonPath('data.salida_vigente.book_value', '11100.00')
            ->assertJsonPath('data.depreciacion.estado', 'baja')
            ->assertJsonPath('data.depreciacion.valor_en_libros', '11100.00')
            ->assertJsonPath('data.depreciacion.acumulado', '900.00');

        $this->getJson(self::URL_ACTIVOS)
            ->assertOk()
            ->assertJsonPath('data.data.0.salida_vigente.type', 'desecho');
    }

    public function test_el_listado_trae_solo_las_columnas_de_la_salida_vigente_sin_n_mas_1(): void
    {
        $activo = $this->crearActivoDepreciable();
        $activo->update(['status' => 'baja']);
        $this->crearSalidaDirecta($activo, [
            'type' => 'venta',
            'buyer_name' => 'Comprador Ejemplo',
            'reason' => 'Venta de ejemplo',
            'signed_document_path' => 'fixed-asset-disposals/1/carta.pdf',
        ]);

        $respuesta = $this->getJson(self::URL_ACTIVOS)->assertOk();
        $respuesta->assertJsonPath('data.data.0.salida_vigente.tiene_carta_firmada', true)
            ->assertJsonPath('data.data.0.salida_vigente.vigente', true)
            ->assertJsonMissingPath('data.data.0.salida_vigente.signed_document_path')
            ->assertJsonMissingPath('data.data.0.salida_vigente.buyer_name')
            ->assertJsonMissingPath('data.data.0.salida_vigente.asset_snapshot')
            ->assertJsonMissingPath('data.data.0.salida_vigente.reason');

        $consultasDeSalidas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL_ACTIVOS)->assertOk();
            $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'fixed_asset_disposals'))->count();
            DB::disableQueryLog();

            return $consultas;
        };

        $conUno = $consultasDeSalidas();

        foreach (range(1, 3) as $_) {
            $otro = $this->crearActivoDepreciable();
            $otro->update(['status' => 'baja']);
            $this->crearSalidaDirecta($otro);
        }

        $this->assertSame(1, $conUno);
        $this->assertSame($conUno, $consultasDeSalidas());
    }

    /** Payload completo de edición: los mismos valores que tiene hoy el activo, más los cambios pedidos. */
    private function edicion(FixedAsset $activo, array $cambios = []): array
    {
        return $this->validFixedAssetPayload(array_merge([
            'status' => $activo->status,
            'purchase_date' => '2026-01-15',
            'purchase_value' => 12000,
            'depreciation_rate' => 20,
            'salvage_percent' => 10,
        ], $cambios));
    }

    public function test_con_salida_vigente_no_se_puede_cambiar_el_valor_de_compra(): void
    {
        $activo = $this->activoConSalida();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, ['purchase_value' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_value')
            ->assertJsonPath('errors.purchase_value.0', 'No se puede cambiar el valor de compra mientras el activo tiene una salida registrada; deshaz la salida primero.');

        $this->assertSame('12000.00', $activo->fresh()->purchase_value);
    }

    public function test_con_salida_vigente_no_se_pueden_cambiar_las_fechas(): void
    {
        $activo = $this->activoConSalida();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, ['purchase_date' => '2026-05-30']))
            ->assertStatus(422)->assertJsonValidationErrors('purchase_date');
        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, ['depreciation_start' => '2026-03-01']))
            ->assertStatus(422)->assertJsonValidationErrors('depreciation_start');

        $this->assertSame('2026-01-15', $activo->fresh()->purchase_date->toDateString());
        $this->assertNull($activo->fresh()->depreciation_start);
    }

    public function test_con_salida_vigente_no_se_pueden_cambiar_la_tasa_el_residual_ni_la_vida_util(): void
    {
        $activo = $this->activoConSalida();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, [
            'depreciation_rate' => 33, 'salvage_percent' => 0, 'useful_life_years' => 5,
        ]))->assertStatus(422)->assertJsonValidationErrors(['depreciation_rate', 'salvage_percent', 'useful_life_years']);

        $fresco = $activo->fresh();
        $this->assertSame('20.00', $fresco->depreciation_rate);
        $this->assertSame('10.00', $fresco->salvage_percent);
        $this->assertNull($fresco->useful_life_years);
    }

    public function test_con_salida_vigente_no_se_pueden_cambiar_el_tipo_ni_el_subtipo(): void
    {
        $activo = $this->activoConSalida();
        $otroTipo = AssetCategory::create(['code' => 'TAC-003', 'name' => 'Mobiliario', 'icon' => 'Laptop', 'is_active' => true]);
        $otroSubtipo = AssetCategory::create(['code' => 'TAC-004', 'name' => 'Tablets', 'parent_id' => $this->assetCategory->id, 'icon' => 'Laptop', 'is_active' => true]);

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, ['subcategory_id' => $otroSubtipo->id]))
            ->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, ['category_id' => $otroTipo->id, 'subcategory_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->assertSame($this->assetCategory->id, $activo->fresh()->category_id);
        $this->assertSame($this->assetSubcategory->id, $activo->fresh()->subcategory_id);
    }

    public function test_con_salida_vigente_enviar_los_mismos_valores_no_es_un_cambio(): void
    {
        $activo = $this->activoConSalida();
        $activo->update(['depreciation_start' => '2026-02-01', 'useful_life_years' => 5]);

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, [
            'name' => 'Solo cambia el nombre',
            'observations' => 'Nota nueva',
            'purchase_value' => '12000.00',
            'depreciation_start' => '2026-02-20', // mismo mes: se guarda siempre el día 1
            'useful_life_years' => 5,
            'depreciation_rate' => '20.00',
            'salvage_percent' => 10.0,
            'subcategory_id' => $this->assetSubcategory->id,
        ]))->assertOk();

        $fresco = $activo->fresh();
        $this->assertSame('Solo cambia el nombre', $fresco->name);
        $this->assertSame('Nota nueva', $fresco->observations);
        $this->assertSame('baja', $fresco->status);
    }

    public function test_con_salida_vigente_los_datos_descriptivos_siguen_editables(): void
    {
        $activo = $this->activoConSalida();

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, [
            'name' => 'Laptop reasignada en papel', 'serial_number' => 'SN-NUEVA', 'model' => 'Otro modelo',
            'invoice_number' => 'F-123', 'description' => 'Descripción', 'observations' => 'Observación',
        ]))->assertOk();

        $this->assertSame('SN-NUEVA', $activo->fresh()->serial_number);
    }

    public function test_sin_salida_vigente_la_edicion_normal_no_cambia(): void
    {
        $activo = $this->crearActivoDepreciable();
        // Una salida revertida tampoco bloquea nada.
        $this->crearSalidaDirecta($activo, ['reversed_at' => now(), 'reversal_reason' => 'Error']);

        $this->putJson(self::URL_ACTIVOS."/{$activo->id}", $this->edicion($activo, [
            'purchase_value' => 5000, 'purchase_date' => '2026-02-01', 'depreciation_rate' => 25,
            'salvage_percent' => 5, 'status' => 'en_mantenimiento',
        ]))->assertOk();

        $fresco = $activo->fresh();
        $this->assertSame('5000.00', $fresco->purchase_value);
        $this->assertSame('2026-02-01', $fresco->purchase_date->toDateString());
        $this->assertSame('25.00', $fresco->depreciation_rate);
        $this->assertSame('5.00', $fresco->salvage_percent);
        $this->assertSame('en_mantenimiento', $fresco->status);
    }

    public function test_el_alta_rechaza_el_estado_baja(): void
    {
        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['status' => 'baja']))
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(0, FixedAsset::count());

        $this->postJson(self::URL_ACTIVOS, $this->validFixedAssetPayload(['status' => 'disponible']))->assertCreated();
    }
}
