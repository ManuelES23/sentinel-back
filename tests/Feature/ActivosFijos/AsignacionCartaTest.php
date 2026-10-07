<?php
// sentinel-back/tests/Feature/ActivosFijos/AsignacionCartaTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class AsignacionCartaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const BASE = '/api/splendidfarms/administration/activos-fijos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->otorgarActivos($this->actingUser, $this->enterprise, 'asignaciones', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function asignar(array $activoOverrides = [], array $extra = []): FixedAssetAssignment
    {
        $activo = $this->crearActivo($activoOverrides);
        $empleado = $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $id = $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id, $extra))
            ->assertCreated()->json('data.id');

        return FixedAssetAssignment::findOrFail($id);
    }

    // ---- carta firmada ----

    public function test_sube_la_carta_firmada_y_la_guarda_en_el_disco_privado(): void
    {
        $asignacion = $this->asignar();

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/carta-firmada", [
            'file' => UploadedFile::fake()->create('carta.pdf', 200, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.tiene_carta_firmada', true)
            ->assertJsonMissingPath('data.signed_document_path');

        $ruta = $asignacion->fresh()->signed_document_path;
        $this->assertStringStartsWith("fixed-asset-assignments/{$asignacion->id}/", $ruta);
        Storage::disk('local')->assertExists($ruta);
        Storage::disk('public')->assertMissing($ruta);
        $this->assertSame($this->actingUser->id, $asignacion->fresh()->signed_uploaded_by);
    }

    public function test_reemplazar_la_carta_borra_la_anterior(): void
    {
        $asignacion = $this->asignar();
        $url = self::BASE."/asignaciones/{$asignacion->id}/carta-firmada";
        $this->postJson($url, ['file' => UploadedFile::fake()->create('uno.pdf', 50, 'application/pdf')])->assertOk();
        $vieja = $asignacion->fresh()->signed_document_path;

        $this->postJson($url, ['file' => UploadedFile::fake()->image('dos.jpg')])->assertOk();

        $nueva = $asignacion->fresh()->signed_document_path;
        $this->assertNotSame($vieja, $nueva);
        Storage::disk('local')->assertMissing($vieja);
        Storage::disk('local')->assertExists($nueva);
    }

    public function test_rechaza_tipos_y_tamanos_invalidos(): void
    {
        $asignacion = $this->asignar();
        $url = self::BASE."/asignaciones/{$asignacion->id}/carta-firmada";

        $this->postJson($url, ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->postJson($url, ['file' => UploadedFile::fake()->create('enorme.pdf', 10241, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertNull($asignacion->fresh()->signed_document_path);
    }

    public function test_descarga_la_carta_firmada_con_permiso_view_y_404_si_no_hay(): void
    {
        $asignacion = $this->asignar();
        $url = self::BASE."/asignaciones/{$asignacion->id}/carta-firmada";

        $this->getJson($url)->assertNotFound();

        $this->postJson($url, ['file' => UploadedFile::fake()->create('carta.pdf', 50, 'application/pdf')])->assertOk();
        // Solo se comprueba el 200: el tipo MIME de un archivo falso depende de su contenido.
        $this->get($url)->assertOk();

        $lector = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($lector, $this->enterprise, 'asignaciones', ['view']);
        Sanctum::actingAs($lector);
        $this->get($url)->assertOk();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('otra.pdf', 50, 'application/pdf')])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $this->get($url)->assertForbidden();
    }

    public function test_la_ruta_privada_del_archivo_no_entra_a_la_bitacora(): void
    {
        $asignacion = $this->asignar();
        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/carta-firmada", [
            'file' => UploadedFile::fake()->create('carta.pdf', 50, 'application/pdf'),
        ])->assertOk();
        $this->assertNotEmpty($asignacion->fresh()->signed_document_path);

        $registros = ActivityLog::where('model', 'FixedAssetAssignment')->get();
        $this->assertNotEmpty($registros);
        foreach ($registros as $registro) {
            $this->assertStringNotContainsString('signed_document_path', json_encode([$registro->old_values, $registro->new_values]));
        }
        // La subida sí queda auditada: solo se omite la ruta privada, no el resto de los campos.
        $this->assertTrue($registros->contains(fn ($r) => array_key_exists('signed_uploaded_by', (array) $r->new_values)));
    }

    // ---- datos de la carta ----

    public function test_la_carta_trae_empresa_activo_responsable_y_folio(): void
    {
        $this->enterprise->update([
            'razon_social' => 'Splendid Farms S.A. de C.V.', 'rfc' => 'SFA010101AAA',
            'direccion' => 'Carretera 15 km 3', 'ciudad' => 'Culiacán', 'telefono' => '667 000 0000', 'logo' => 'logos/sf.png',
        ]);
        $asignacion = $this->asignar(
            ['name' => 'Laptop Dell Latitude', 'serial_number' => 'SN-123', 'model' => 'Latitude 5530', 'year' => 2024, 'purchase_value' => 18500],
            ['accessories' => 'Cargador y funda', 'notes' => 'Entrega en oficina'],
        );

        $codigo = $asignacion->asset_snapshot['code'];
        $this->getJson(self::BASE."/asignaciones/{$asignacion->id}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', "{$codigo}-R01")
            ->assertJsonPath('data.empresa.nombre', 'Splendid Farms')
            ->assertJsonPath('data.empresa.razon_social', 'Splendid Farms S.A. de C.V.')
            ->assertJsonPath('data.empresa.rfc', 'SFA010101AAA')
            ->assertJsonPath('data.empresa.ciudad', 'Culiacán')
            ->assertJsonPath('data.empresa.logo', 'logos/sf.png')
            ->assertJsonPath('data.activo.name', 'Laptop Dell Latitude')
            ->assertJsonPath('data.activo.serial_number', 'SN-123')
            ->assertJsonPath('data.activo.brand', 'Dell')
            ->assertJsonPath('data.responsable.nombre', 'Ana Pérez')
            ->assertJsonPath('data.responsable.tipo', 'employee')
            ->assertJsonPath('data.entrega.condicion', 'bueno')
            ->assertJsonPath('data.entrega.accesorios', 'Cargador y funda')
            ->assertJsonPath('data.entrega.entregado_por', $this->actingUser->name)
            ->assertJsonPath('data.devolucion', null);
    }

    public function test_el_folio_cuenta_las_entregas_del_mismo_activo_y_la_carta_no_cambia_si_el_activo_cambia(): void
    {
        $activo = $this->crearActivo(['name' => 'Laptop original']);
        $uno = $this->crearEmpleado($this->enterprise);
        $dos = $this->crearEmpleado($this->enterprise, ['first_name' => 'Beto', 'last_name' => 'Ruiz']);
        $primera = $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $uno->id))->assertCreated()->json('data.id');
        $this->postJson(self::BASE."/asignaciones/{$primera}/devolver", ['condition_in' => 'malo', 'return_reason' => 'dano', 'return_notes' => 'Pantalla rota'])->assertOk();
        $segunda = $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $dos->id))->assertCreated()->json('data.id');

        $activo->update(['name' => 'Laptop renombrada']);

        $this->getJson(self::BASE."/asignaciones/{$primera}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', $activo->code.'-R01')
            ->assertJsonPath('data.activo.name', 'Laptop original')
            ->assertJsonPath('data.devolucion.condicion', 'malo')
            ->assertJsonPath('data.devolucion.motivo', 'dano')
            ->assertJsonPath('data.devolucion.notas', 'Pantalla rota');
        $this->getJson(self::BASE."/asignaciones/{$segunda}/carta")
            ->assertOk()
            ->assertJsonPath('data.folio', $activo->code.'-R02');
    }

    public function test_la_carta_de_otra_empresa_responde_404(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $activo = $this->crearActivo(['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001']);
        $ajena = FixedAssetAssignment::create([
            'enterprise_id' => $porvenir->id, 'fixed_asset_id' => $activo->id, 'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id, 'assignee_name' => 'Persona SP', 'assigned_at' => '2026-10-01',
            'assigned_by' => $this->actingUser->id, 'condition_out' => 'bueno', 'asset_snapshot' => ['code' => 'SP-AF-000001'],
        ]);

        $this->getJson(self::BASE."/asignaciones/{$ajena->id}/carta")->assertNotFound();
        $this->getJson(self::BASE."/asignaciones/{$ajena->id}/carta-firmada")->assertNotFound();
        $this->postJson(self::BASE."/asignaciones/{$ajena->id}/carta-firmada", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
    }
}
