<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class SalidaCartaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    private FixedAsset $activo;
    private FixedAssetDisposal $venta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        Storage::fake('local');

        $this->activo = $this->crearActivoDepreciable();
        $this->venta = $this->crearSalidaDirecta($this->activo, [
            'type' => 'venta',
            'sale_price' => '15000.00',
            'result_amount' => '3900.00',
            'buyer_name' => 'Comercial Rivera S.A.',
            'buyer_address' => 'Calle 5 #123, Culiacán',
            'buyer_identification' => 'INE 1234',
            'payment_method' => 'Transferencia',
            'place' => 'Culiacán, Sin.',
            'asset_snapshot' => ['code' => 'SF-AF-000007', 'name' => 'Laptop Dell', 'brand' => 'Dell', 'model' => 'Latitude', 'serial_number' => 'SN1', 'year' => 2024],
        ]);
    }

    private function pdf(string $nombre = 'carta.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, $kb, 'application/pdf');
    }

    public function test_la_carta_trae_los_datos_de_la_venta_y_el_activo_guardado(): void
    {
        $this->enterprise->update(['razon_social' => 'Splendid Farms S.A. de C.V.', 'rfc' => 'SFA010101AAA', 'ciudad' => 'Culiacán']);
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', 'SF-AF-000007-V01')
            ->assertJsonPath('data.empresa.razon_social', 'Splendid Farms S.A. de C.V.')
            ->assertJsonPath('data.empresa.rfc', 'SFA010101AAA')
            ->assertJsonPath('data.activo.serial_number', 'SN1')
            ->assertJsonPath('data.venta.fecha', '2026-05-20')
            ->assertJsonPath('data.venta.lugar', 'Culiacán, Sin.')
            ->assertJsonPath('data.venta.precio', '15000.00')
            ->assertJsonPath('data.venta.forma_pago', 'Transferencia')
            ->assertJsonPath('data.venta.comprador.nombre', 'Comercial Rivera S.A.')
            ->assertJsonPath('data.venta.comprador.domicilio', 'Calle 5 #123, Culiacán')
            ->assertJsonPath('data.venta.comprador.identificacion', 'INE 1234')
            ->assertJsonPath('data.venta.autoriza', 'Gerencia')
            ->assertJsonPath('data.venta.revertida', false);
    }

    public function test_el_folio_cuenta_las_ventas_del_activo(): void
    {
        $this->venta->update(['reversed_at' => now(), 'reversal_reason' => 'Error']);
        $segunda = $this->crearSalidaDirecta($this->activo, ['type' => 'venta', 'sale_price' => '1.00', 'buyer_name' => 'Otro', 'asset_snapshot' => ['code' => 'SF-AF-000007']]);
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->getJson(self::URL_SALIDAS."/{$segunda->id}/carta")->assertOk()->assertJsonPath('data.folio', 'SF-AF-000007-V02');
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta")->assertOk()->assertJsonPath('data.venta.revertida', true);
    }

    public function test_solo_las_ventas_tienen_carta(): void
    {
        $desecho = $this->crearSalidaDirecta($this->crearActivoDepreciable());
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        $this->getJson(self::URL_SALIDAS."/{$desecho->id}/carta")->assertNotFound();
    }

    public function test_sube_y_reemplaza_la_carta_firmada_en_el_disco_privado(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $respuesta = $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])
            ->assertOk()
            ->assertJsonPath('data.tiene_carta_firmada', true)
            ->assertJsonMissingPath('data.signed_document_path');
        $primera = $this->venta->fresh()->signed_document_path;
        Storage::disk('local')->assertExists($primera);
        $this->assertStringStartsWith("fixed-asset-disposals/{$this->venta->id}/", $primera);
        $this->assertNotNull($respuesta->json('data.signed_uploaded_at'));

        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf('nueva.pdf')])->assertOk();
        $segunda = $this->venta->fresh()->signed_document_path;
        $this->assertNotSame($primera, $segunda);
        Storage::disk('local')->assertMissing($primera);
        Storage::disk('local')->assertExists($segunda);
    }

    public function test_valida_el_tipo_y_el_tamano_del_archivo(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf('grande.pdf', 11000)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", [])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($this->venta->fresh()->signed_document_path);
    }

    public function test_la_carta_firmada_solo_aplica_a_ventas_vigentes(): void
    {
        $desecho = $this->crearSalidaDirecta($this->crearActivoDepreciable());
        $this->venta->update(['reversed_at' => now(), 'reversal_reason' => 'Error']);
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $this->postJson(self::URL_SALIDAS."/{$desecho->id}/carta-firmada", ['file' => $this->pdf()])
            ->assertStatus(422)->assertJsonValidationErrors('salida');
        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])
            ->assertStatus(422)->assertJsonValidationErrors('salida');
    }

    public function test_descarga_la_carta_firmada_y_sin_archivo_responde_404(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada")->assertNotFound();

        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])->assertOk();

        $this->get(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada")->assertOk();
    }

    public function test_los_permisos_y_el_alcance_de_la_carta(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));
        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])->assertForbidden();

        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['create']));
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta")->assertForbidden();
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada")->assertForbidden();

        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $ajena = $this->crearSalidaDirecta(
            FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $sbp->id, 'code' => 'SBP-AF-000001'])),
            ['type' => 'venta', 'sale_price' => '1.00', 'buyer_name' => 'X'],
        );
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));
        $this->getJson(self::URL_SALIDAS."/{$ajena->id}/carta")->assertNotFound();
        $this->postJson(self::URL_SALIDAS."/{$ajena->id}/carta-firmada", ['file' => $this->pdf()])->assertNotFound();
        $this->getJson(self::URL_SALIDAS."/{$ajena->id}/carta-firmada")->assertNotFound();
    }

    public function test_la_ruta_de_la_carta_firmada_no_sale_en_la_bitacora_ni_en_el_json(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $respuesta = $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])->assertOk();
        $ruta = $this->venta->fresh()->signed_document_path;
        $this->assertNotEmpty($ruta);

        $this->assertStringNotContainsString('fixed-asset-disposals/', $respuesta->getContent());
        $respuesta->assertJsonMissingPath('data.signed_document_path');
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}")->assertOk()->assertJsonMissingPath('data.signed_document_path');

        $filas = ActivityLog::where('model', 'FixedAssetDisposal')->get();
        $this->assertNotEmpty($filas);
        foreach ($filas as $fila) {
            $contenido = json_encode($fila->old_values).json_encode($fila->new_values);
            $this->assertStringNotContainsString('fixed-asset-disposals/', $contenido);
            $this->assertStringNotContainsString($ruta, $contenido);
        }
    }
}
