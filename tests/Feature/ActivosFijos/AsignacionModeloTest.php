<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class AsignacionModeloTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
    }

    private function nueva(FixedAsset $activo, array $overrides = []): FixedAssetAssignment
    {
        return FixedAssetAssignment::create(array_merge([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id,
            'assignee_name' => 'Ana Pérez',
            'assigned_at' => '2026-10-07',
            'assigned_by' => $this->actingUser->id,
            'condition_out' => 'bueno',
            'asset_snapshot' => ['code' => $activo->code, 'name' => $activo->name],
        ], $overrides));
    }

    public function test_guarda_la_asignacion_con_fechas_y_copia_del_activo(): void
    {
        $activo = $this->crearActivo();

        $asignacion = $this->nueva($activo)->fresh();

        $this->assertSame('2026-10-07', $asignacion->assigned_at->toDateString());
        $this->assertNull($asignacion->returned_at);
        $this->assertSame($activo->code, $asignacion->asset_snapshot['code']);
        $this->assertTrue($asignacion->activa);
    }

    public function test_el_activo_conoce_su_asignacion_activa_y_su_historial(): void
    {
        $activo = $this->crearActivo();
        $this->nueva($activo, ['returned_at' => '2026-10-08', 'condition_in' => 'bueno', 'return_reason' => 'otro']);
        $vigente = $this->nueva($activo);

        $this->assertSame($vigente->id, $activo->asignacionActiva->id);
        $this->assertCount(2, $activo->asignaciones);
    }

    public function test_la_ruta_del_archivo_firmado_no_se_serializa(): void
    {
        $asignacion = $this->nueva($this->crearActivo(), [
            'signed_document_path' => 'fixed-asset-assignments/1/carta.pdf',
        ]);

        $json = $asignacion->fresh()->toArray();

        $this->assertArrayNotHasKey('signed_document_path', $json);
        $this->assertTrue($json['tiene_carta_firmada']);
    }

    public function test_los_scopes_separan_activas_y_devueltas(): void
    {
        $activo = $this->crearActivo();
        $this->nueva($activo, ['returned_at' => '2026-10-08', 'condition_in' => 'bueno', 'return_reason' => 'otro']);
        $this->nueva($activo);

        $this->assertSame(1, FixedAssetAssignment::activas()->count());
        $this->assertSame(1, FixedAssetAssignment::devueltas()->count());
    }
}
