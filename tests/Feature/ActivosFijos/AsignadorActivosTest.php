<?php
// sentinel-back/tests/Feature/ActivosFijos/AsignadorActivosTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\Area;
use App\Models\FixedAssetAssignment;
use App\Services\ActivosFijos\AsignadorActivos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class AsignadorActivosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private AsignadorActivos $asignador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->asignador = app(AsignadorActivos::class);
    }

    private function areaDeLaEntidad(): Area
    {
        $area = Area::create(['code' => 'ARE-'.$this->contadorFixtures++, 'name' => 'Recepción', 'slug' => 'recepcion-'.$this->contadorFixtures, 'is_active' => true]);
        DB::table('entity_area')->insert([
            'entity_id' => $this->entity->id, 'area_id' => $area->id, 'is_active' => true,
            'allows_inventory' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $area;
    }

    private function assertRegla(callable $accion, string $clave): void
    {
        try {
            $accion();
            $this->fail('Se esperaba una ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors());
        }
    }

    public function test_asignar_copia_los_datos_marca_en_uso_y_sincroniza_el_area(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $area = $this->areaDeLaEntidad();

        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id, [
            'area_id' => $area->id, 'assigned_at' => '2026-10-07', 'notes' => 'Entrega en oficina',
        ]), $this->actingUser);

        $this->assertSame($this->enterprise->id, $asignacion->enterprise_id);
        $this->assertSame('Ana Pérez', $asignacion->assignee_name);
        $this->assertSame('employee', $asignacion->assignee_type);
        $this->assertSame($this->actingUser->id, $asignacion->assigned_by);
        $this->assertSame($activo->code, $asignacion->asset_snapshot['code']);
        $this->assertSame('Dell', $asignacion->asset_snapshot['brand']);
        $this->assertSame('Laptops', $asignacion->asset_snapshot['subcategory']);
        $activo->refresh();
        $this->assertSame('en_uso', $activo->status);
        $this->assertSame($area->id, $activo->area_id);
    }

    public function test_un_activo_en_uso_sin_responsable_se_puede_asignar(): void
    {
        $activo = $this->crearActivo(['status' => 'en_uso']);
        $empleado = $this->crearEmpleado($this->enterprise);

        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id), $this->actingUser);

        $this->assertTrue($asignacion->activa);
    }

    public function test_los_estados_bloqueados_y_los_inactivos_no_se_asignan(): void
    {
        $empleado = $this->crearEmpleado($this->enterprise);

        foreach (AsignadorActivos::ESTADOS_BLOQUEADOS as $estado) {
            $activo = $this->crearActivo(['status' => $estado]);
            $this->assertNotNull($this->asignador->motivoBloqueo($activo));
            $this->assertRegla(fn () => $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id), $this->actingUser), 'asset');
        }

        $inactivo = $this->crearActivo(['is_active' => false]);
        $this->assertRegla(fn () => $this->asignador->asignar($inactivo, $this->datosAsignacion('employee', $empleado->id), $this->actingUser), 'asset');
        $this->assertSame(0, FixedAssetAssignment::count());
    }

    public function test_no_permite_dos_asignaciones_activas_del_mismo_activo(): void
    {
        $activo = $this->crearActivo();
        $a = $this->crearEmpleado($this->enterprise);
        $b = $this->crearEmpleado($this->enterprise);
        $this->asignador->asignar($activo, $this->datosAsignacion('employee', $a->id), $this->actingUser);

        $this->assertRegla(fn () => $this->asignador->asignar($activo, $this->datosAsignacion('employee', $b->id), $this->actingUser), 'asset');
        $this->assertSame(1, FixedAssetAssignment::count());
    }

    public function test_no_asigna_a_una_persona_de_otra_empresa(): void
    {
        $activo = $this->crearActivo();
        $ajeno = $this->crearEmpleado($this->corporativo);

        $this->assertRegla(fn () => $this->asignador->asignar($activo, $this->datosAsignacion('employee', $ajeno->id), $this->actingUser), 'assignee_id');
        $this->assertSame('disponible', $activo->fresh()->status, 'una asignación fallida no cambia el estado');
    }

    public function test_devolver_cierra_la_asignacion_y_deja_el_activo_disponible(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-10-01']), $this->actingUser);

        $devuelta = $this->asignador->devolver($asignacion, [
            'returned_at' => '2026-10-07', 'condition_in' => 'regular', 'return_reason' => 'renuncia', 'return_notes' => 'Con rayones',
        ], $this->actingUser);

        $this->assertSame('2026-10-07', $devuelta->returned_at->toDateString());
        $this->assertSame('regular', $devuelta->condition_in);
        $this->assertSame('renuncia', $devuelta->return_reason);
        $this->assertSame($this->actingUser->id, $devuelta->returned_by);
        $this->assertFalse($devuelta->activa);
        $this->assertSame('disponible', $activo->fresh()->status);
    }

    public function test_devolver_respeta_un_estado_bloqueado_del_activo(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id), $this->actingUser);
        $activo->update(['status' => 'en_mantenimiento']);

        $this->asignador->devolver($asignacion, ['condition_in' => 'malo', 'return_reason' => 'dano'], $this->actingUser);

        $this->assertSame('en_mantenimiento', $activo->fresh()->status);
    }

    public function test_no_se_devuelve_dos_veces_ni_con_fecha_anterior_a_la_entrega(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-10-05']), $this->actingUser);

        $this->assertRegla(fn () => $this->asignador->devolver($asignacion, ['returned_at' => '2026-10-04', 'condition_in' => 'bueno', 'return_reason' => 'otro'], $this->actingUser), 'returned_at');

        $this->asignador->devolver($asignacion, ['returned_at' => '2026-10-06', 'condition_in' => 'bueno', 'return_reason' => 'otro'], $this->actingUser);
        $this->assertRegla(fn () => $this->asignador->devolver($asignacion, ['condition_in' => 'bueno', 'return_reason' => 'otro'], $this->actingUser), 'asignacion');
    }

    public function test_reasignar_cierra_la_actual_y_abre_la_nueva(): void
    {
        $activo = $this->crearActivo();
        $a = $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $b = $this->crearEmpleadoSf($this->enterprise, ['first_name' => 'Luis', 'last_name' => 'Gómez']);
        $primera = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $a->id, ['assigned_at' => '2026-10-01']), $this->actingUser);

        $nueva = $this->asignador->reasignar(
            $activo,
            ['returned_at' => '2026-10-07', 'condition_in' => 'bueno', 'return_reason' => 'cambio_puesto'],
            $this->datosAsignacion('sf_employee', $b->id, ['assigned_at' => '2026-10-07']),
            $this->actingUser,
        );

        $this->assertFalse($primera->fresh()->activa);
        $this->assertTrue($nueva->activa);
        $this->assertSame('Luis Gómez', $nueva->assignee_name);
        $this->assertSame('en_uso', $activo->fresh()->status);
        $this->assertSame(2, FixedAssetAssignment::count());
    }

    public function test_reasignar_es_atomico_si_la_nueva_falla(): void
    {
        $activo = $this->crearActivo();
        $a = $this->crearEmpleado($this->enterprise);
        $ajeno = $this->crearEmpleado($this->corporativo);
        $primera = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $a->id), $this->actingUser);

        $this->assertRegla(fn () => $this->asignador->reasignar(
            $activo,
            ['condition_in' => 'bueno', 'return_reason' => 'otro'],
            $this->datosAsignacion('employee', $ajeno->id),
            $this->actingUser,
        ), 'assignee_id');

        $this->assertTrue($primera->fresh()->activa, 'la devolución se revierte');
        $this->assertSame('en_uso', $activo->fresh()->status);
        $this->assertSame(1, FixedAssetAssignment::count());
    }

    public function test_reasignar_sin_asignacion_activa_falla(): void
    {
        $activo = $this->crearActivo();
        $a = $this->crearEmpleado($this->enterprise);

        $this->assertRegla(fn () => $this->asignador->reasignar(
            $activo,
            ['condition_in' => 'bueno', 'return_reason' => 'otro'],
            $this->datosAsignacion('employee', $a->id),
            $this->actingUser,
        ), 'asset');
    }

    public function test_corregir_solo_funciona_con_una_asignacion_activa(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);
        $area = $this->areaDeLaEntidad();
        $asignacion = $this->asignador->asignar($activo, $this->datosAsignacion('employee', $empleado->id), $this->actingUser);

        $corregida = $this->asignador->corregir($asignacion, [
            'accessories' => 'Cargador, funda y mouse', 'condition_out' => 'regular', 'area_id' => $area->id, 'assignee_name' => 'Intento de cambio',
        ]);

        $this->assertSame('Cargador, funda y mouse', $corregida->accessories);
        $this->assertSame('regular', $corregida->condition_out);
        $this->assertSame($area->id, $activo->fresh()->area_id);
        $this->assertNotSame('Intento de cambio', $corregida->assignee_name, 'el responsable no se corrige');

        $this->asignador->devolver($asignacion, ['condition_in' => 'bueno', 'return_reason' => 'otro'], $this->actingUser);
        $this->assertRegla(fn () => $this->asignador->corregir($asignacion, ['notes' => 'tarde']), 'asignacion');
    }
}
