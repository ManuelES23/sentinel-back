<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $this->assertTrue(
            $filas->contains(fn ($fila) => $fila->action === 'update' && array_key_exists('signed_uploaded_at', $fila->new_values ?? [])),
            'Debe existir una fila update de la bitácora con signed_uploaded_at.',
        );
        foreach ($filas as $fila) {
            $contenido = json_encode($fila->old_values).json_encode($fila->new_values);
            $this->assertStringNotContainsString('fixed-asset-disposals/', $contenido);
            $this->assertStringNotContainsString($ruta, $contenido);
        }
    }

    public function test_la_subida_de_la_carta_firmada_deja_una_fila_update_en_la_bitacora(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));

        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])->assertOk();

        $actualizaciones = ActivityLog::where('model', 'FixedAssetDisposal')
            ->where('model_id', $this->venta->id)->where('action', 'update')->get();
        $this->assertCount(1, $actualizaciones);
        $nuevos = $actualizaciones->first()->new_values;
        $this->assertArrayHasKey('signed_uploaded_at', $nuevos);
        $this->assertArrayHasKey('signed_uploaded_by', $nuevos);
        $this->assertArrayNotHasKey('signed_document_path', $nuevos);
        $this->assertArrayNotHasKey('signed_document_path', $actualizaciones->first()->old_values);
    }

    public function test_una_salida_ajena_responde_404_antes_que_403_en_la_carta_y_la_carta_firmada(): void
    {
        $sbp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SBP');
        $ajena = $this->crearSalidaDirecta(
            FixedAsset::create($this->validFixedAssetPayload(['enterprise_id' => $sbp->id, 'code' => 'SBP-AF-000001'])),
            ['type' => 'venta', 'sale_price' => '1.00', 'buyer_name' => 'X'],
        );

        // Sin `view`: el 404 de la empresa ajena va antes que el 403 del permiso.
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['create']));
        $this->getJson(self::URL_SALIDAS."/{$ajena->id}/carta")->assertNotFound();
        $this->getJson(self::URL_SALIDAS."/{$ajena->id}/carta-firmada")->assertNotFound();

        // Sin `create`: igual al subir.
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));
        $this->postJson(self::URL_SALIDAS."/{$ajena->id}/carta-firmada", ['file' => $this->pdf()])->assertNotFound();

        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_un_archivo_invalido_no_deja_huerfanos_ni_pisa_la_carta_anterior(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));
        $url = self::URL_SALIDAS."/{$this->venta->id}/carta-firmada";

        $this->postJson($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $this->postJson($url, ['file' => $this->pdf('grande.pdf', 11000)])->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->postJson($url, ['file' => $this->pdf()])->assertOk();
        $vigente = $this->venta->fresh()->signed_document_path;

        $this->postJson($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);

        $this->assertSame([$vigente], Storage::disk('local')->allFiles());
        $this->assertSame($vigente, $this->venta->fresh()->signed_document_path);
    }

    public function test_si_falla_el_guardado_se_borra_el_archivo_nuevo_y_se_conserva_el_anterior(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));
        $url = self::URL_SALIDAS."/{$this->venta->id}/carta-firmada";
        $this->postJson($url, ['file' => $this->pdf()])->assertOk();
        $vigente = $this->venta->fresh()->signed_document_path;

        FixedAssetDisposal::updating(function () {
            throw new \RuntimeException('Falla simulada del guardado.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson($url, ['file' => $this->pdf('nueva.pdf')]);
            $this->fail('El guardado debió fallar.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falla simulada del guardado.', $e->getMessage());
        } finally {
            FixedAssetDisposal::flushEventListeners();
        }

        $this->assertSame([$vigente], Storage::disk('local')->allFiles());
        $this->assertSame($vigente, $this->venta->fresh()->signed_document_path);
    }

    public function test_la_descarga_de_la_carta_firmada_trae_su_tipo_y_nosniff(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view', 'create']));
        $this->postJson(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada", ['file' => $this->pdf()])->assertOk();

        $respuesta = $this->get(self::URL_SALIDAS."/{$this->venta->id}/carta-firmada")->assertOk();

        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('Content-Type'));
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_la_carta_de_una_salida_sin_copia_del_activo_no_falla(): void
    {
        Sanctum::actingAs($this->usuarioSalidas($this->enterprise, ['view']));

        // Sin llaves en la copia (arreglo vacío) y con el literal JSON `null`.
        $this->venta->update(['asset_snapshot' => []]);
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', 'SIN-CODIGO-V01')
            ->assertJsonPath('data.activo', [])
            ->assertJsonPath('data.venta.comprador.nombre', 'Comercial Rivera S.A.');

        DB::table('fixed_asset_disposals')->where('id', $this->venta->id)->update(['asset_snapshot' => 'null']);
        $this->getJson(self::URL_SALIDAS."/{$this->venta->id}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', 'SIN-CODIGO-V01')
            ->assertJsonPath('data.activo', []);
    }
}
