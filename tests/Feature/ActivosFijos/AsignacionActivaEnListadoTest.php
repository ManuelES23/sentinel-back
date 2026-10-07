<?php
// sentinel-back/tests/Feature/ActivosFijos/AsignacionActivaEnListadoTest.php

namespace Tests\Feature\ActivosFijos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class AsignacionActivaEnListadoTest extends TestCase
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
    }

    private function asignar($activo, string $nombre): void
    {
        $empleado = $this->crearEmpleado($this->enterprise, ['first_name' => $nombre, 'last_name' => 'Prueba']);
        $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id))->assertCreated();
    }

    public function test_el_listado_y_el_detalle_incluyen_la_asignacion_activa(): void
    {
        $asignado = $this->crearActivo(['name' => 'Con responsable']);
        $libre = $this->crearActivo(['name' => 'Sin responsable']);
        $this->asignar($asignado, 'Ana');

        $respuesta = $this->getJson(self::BASE.'/activos?search=responsable')->assertOk();
        $filas = collect($respuesta->json('data.data'))->keyBy('name');

        $this->assertSame('Ana Prueba', $filas['Con responsable']['asignacion_activa']['assignee_name']);
        $this->assertTrue($filas['Con responsable']['asignacion_activa']['activa']);
        $this->assertFalse($filas['Con responsable']['asignacion_activa']['tiene_carta_firmada']);
        $this->assertArrayNotHasKey('signed_document_path', $filas['Con responsable']['asignacion_activa']);
        $this->assertNull($filas['Sin responsable']['asignacion_activa']);
        $this->getJson(self::BASE."/activos/{$libre->id}")->assertOk()->assertJsonPath('data.asignacion_activa', null);
    }

    public function test_una_asignacion_devuelta_deja_de_aparecer(): void
    {
        $activo = $this->crearActivo();
        $this->asignar($activo, 'Ana');
        $id = $activo->asignacionActiva->id;
        $this->postJson(self::BASE."/asignaciones/{$id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertOk();

        $this->getJson(self::BASE."/activos/{$activo->id}")->assertOk()->assertJsonPath('data.asignacion_activa', null);
    }

    public function test_el_listado_no_hace_consultas_extra_por_cada_activo(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::BASE.'/activos?per_page=100')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->asignar($this->crearActivo(), 'Uno');
        $conUno = $contar();

        foreach (['Dos', 'Tres', 'Cuatro', 'Cinco'] as $nombre) {
            $this->asignar($this->crearActivo(), $nombre);
        }

        $this->assertSame($conUno, $contar());
    }
}
