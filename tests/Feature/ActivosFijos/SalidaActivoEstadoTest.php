<?php

namespace Tests\Feature\ActivosFijos;

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
}
