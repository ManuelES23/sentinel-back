<?php
// sentinel-back/tests/Feature/ActivosFijos/AsignacionApiTest.php

namespace Tests\Feature\ActivosFijos;

use App\Events\FixedAssetUpdated;
use App\Models\Area;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class AsignacionApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const BASE = '/api/splendidfarms/administration/activos-fijos';
    private const BASE_GE = '/api/grupoesplendido/administration/activos-fijos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->otorgarActivos($this->actingUser, $this->enterprise, 'asignaciones', self::PERMISOS_CRUD);
        $this->otorgarActivos($this->actingUser, $this->corporativo, 'asignaciones', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);
    }

    private function usuarioCon(array $permisos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($usuario, $this->enterprise, 'asignaciones', $permisos);

        return $usuario;
    }

    private function asignar(\App\Models\FixedAsset $activo, ?int $empleadoId = null, array $extra = []): FixedAssetAssignment
    {
        $empleadoId ??= $this->crearEmpleado($this->enterprise)->id;
        $id = $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleadoId, $extra))
            ->assertCreated()->json('data.id');

        return FixedAssetAssignment::findOrFail($id);
    }

    private function areaDeLaEntidad(): Area
    {
        $area = Area::create(['code' => 'ARE-'.++$this->contadorFixtures, 'name' => 'Recepción', 'slug' => 'recepcion-'.$this->contadorFixtures, 'is_active' => true]);
        DB::table('entity_area')->insert([
            'entity_id' => $this->entity->id, 'area_id' => $area->id, 'is_active' => true,
            'allows_inventory' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $area;
    }

    // ---- asignar ----

    public function test_asigna_un_activo_y_responde_con_la_asignacion(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $area = $this->areaDeLaEntidad();

        $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id, [
            'area_id' => $area->id, 'assigned_at' => now()->toDateString(), 'notes' => 'Entrega en oficina',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.assignee_name', 'Ana Pérez')
            ->assertJsonPath('data.assignee_type', 'employee')
            ->assertJsonPath('data.activa', true)
            ->assertJsonPath('data.tiene_carta_firmada', false)
            ->assertJsonPath('data.area.id', $area->id)
            ->assertJsonPath('data.entregado_por.id', $this->actingUser->id)
            ->assertJsonMissingPath('data.signed_document_path');

        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_requiere_el_permiso_create(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);
        Sanctum::actingAs($this->usuarioCon(['view', 'edit']));

        $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id))
            ->assertForbidden();
        $this->assertSame(0, FixedAssetAssignment::count());
    }

    public function test_no_asigna_dos_veces_ni_activos_bloqueados(): void
    {
        $activo = $this->crearActivo();
        $this->asignar($activo);
        $otro = $this->crearEmpleado($this->enterprise);

        $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $otro->id))
            ->assertStatus(422)->assertJsonValidationErrors(['asset']);

        $enTaller = $this->crearActivo(['status' => 'en_mantenimiento']);
        $this->postJson(self::BASE."/activos/{$enTaller->id}/asignaciones", $this->datosAsignacion('employee', $otro->id))
            ->assertStatus(422)->assertJsonValidationErrors(['asset']);
    }

    public function test_valida_la_persona_el_area_las_fechas_y_los_catalogos(): void
    {
        $activo = $this->crearActivo();
        $ajeno = $this->crearEmpleado($this->corporativo);
        $propio = $this->crearEmpleado($this->enterprise);
        $areaSinEntidad = Area::create(['code' => 'ARE-X', 'name' => 'Otra', 'slug' => 'otra', 'is_active' => true]);

        $url = self::BASE."/activos/{$activo->id}/asignaciones";

        $this->postJson($url, $this->datosAsignacion('employee', $ajeno->id))
            ->assertStatus(422)->assertJsonValidationErrors(['assignee_id']);
        $this->postJson($url, $this->datosAsignacion('proveedor', $propio->id))
            ->assertStatus(422)->assertJsonValidationErrors(['assignee_type']);
        $this->postJson($url, $this->datosAsignacion('employee', $propio->id, ['area_id' => $areaSinEntidad->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['area_id']);
        $this->postJson($url, $this->datosAsignacion('employee', $propio->id, ['assigned_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['assigned_at']);
        $this->postJson($url, $this->datosAsignacion('employee', $propio->id, ['condition_out' => 'excelente']))
            ->assertStatus(422)->assertJsonValidationErrors(['condition_out']);
    }

    public function test_un_activo_de_otra_empresa_responde_404(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $ajeno = $this->crearActivo(['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001']);
        $empleado = $this->crearEmpleado($this->enterprise);

        $this->postJson(self::BASE."/activos/{$ajeno->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id))->assertNotFound();
        $this->getJson(self::BASE."/activos/{$ajeno->id}/asignaciones")->assertNotFound();
        $this->getJson(self::BASE."/activos/{$ajeno->id}/responsables")->assertNotFound();
    }

    public function test_grupo_esplendido_asigna_un_activo_de_otra_empresa(): void
    {
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);

        $this->postJson(self::BASE_GE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id))
            ->assertCreated();
        $this->assertSame($this->enterprise->id, FixedAssetAssignment::first()->enterprise_id);
    }

    public function test_emite_el_evento_en_tiempo_real_de_la_empresa_del_activo(): void
    {
        Event::fake([FixedAssetUpdated::class]);
        $activo = $this->crearActivo();
        $empleado = $this->crearEmpleado($this->enterprise);

        $this->postJson(self::BASE."/activos/{$activo->id}/asignaciones", $this->datosAsignacion('employee', $empleado->id))->assertCreated();

        Event::assertDispatched(FixedAssetUpdated::class, fn (FixedAssetUpdated $e) => $e->action === 'assignment'
            && $e->empresaActivo === 'splendidfarms'
            && collect($e->broadcastOn())->map->name->all() === [
                'private-module.splendidfarms.administration.activos-fijos',
                'private-module.grupoesplendido.administration.activos-fijos',
            ]);
    }

    public function test_no_se_puede_eliminar_un_activo_con_asignacion_activa(): void
    {
        $activo = $this->crearActivo();
        $asignacion = $this->asignar($activo);

        $this->deleteJson(self::BASE."/activos/{$activo->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['asset']);
        $this->assertNotNull(FixedAsset::find($activo->id), 'el activo sigue existiendo');

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertOk();
        $this->deleteJson(self::BASE."/activos/{$activo->id}")->assertOk();
        $this->assertNull(FixedAsset::find($activo->id));
    }

    // ---- devolver, reasignar y corregir ----

    public function test_devuelve_y_deja_el_activo_disponible(): void
    {
        $activo = $this->crearActivo();
        $asignacion = $this->asignar($activo);

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", [
            'condition_in' => 'regular', 'return_reason' => 'renuncia', 'return_notes' => 'Con rayones',
        ])
            ->assertOk()
            ->assertJsonPath('data.activa', false)
            ->assertJsonPath('data.condition_in', 'regular')
            ->assertJsonPath('data.devuelto_por.id', $this->actingUser->id);

        $this->assertSame('disponible', $activo->fresh()->status);
        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])
            ->assertStatus(422)->assertJsonValidationErrors(['asignacion']);
    }

    public function test_devolver_exige_motivo_y_condicion_y_el_permiso_edit(): void
    {
        $asignacion = $this->asignar($this->crearActivo());

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", [])
            ->assertStatus(422)->assertJsonValidationErrors(['condition_in', 'return_reason']);

        Sanctum::actingAs($this->usuarioCon(['view', 'create']));
        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])
            ->assertForbidden();
    }

    public function test_reasigna_en_una_sola_llamada(): void
    {
        $activo = $this->crearActivo();
        $primera = $this->asignar($activo);
        $nuevo = $this->crearEmpleadoSf($this->enterprise, ['first_name' => 'Luis', 'last_name' => 'Gómez']);

        $this->postJson(self::BASE."/activos/{$activo->id}/reasignar", [
            'devolucion' => ['condition_in' => 'bueno', 'return_reason' => 'cambio_puesto'],
            'asignacion' => $this->datosAsignacion('sf_employee', $nuevo->id),
        ])
            ->assertCreated()
            ->assertJsonPath('data.assignee_name', 'Luis Gómez');

        $this->assertFalse($primera->fresh()->activa);
        $this->assertSame(2, FixedAssetAssignment::count());
        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_reasignar_valida_ambos_bloques(): void
    {
        $activo = $this->crearActivo();
        $this->asignar($activo);

        $this->postJson(self::BASE."/activos/{$activo->id}/reasignar", ['devolucion' => [], 'asignacion' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['devolucion.condition_in', 'devolucion.return_reason', 'asignacion.assignee_type', 'asignacion.assignee_id']);
    }

    public function test_corrige_una_asignacion_activa_pero_no_una_devuelta(): void
    {
        $asignacion = $this->asignar($this->crearActivo());
        $area = $this->areaDeLaEntidad();

        $this->patchJson(self::BASE."/asignaciones/{$asignacion->id}", [
            'accessories' => 'Cargador, funda y mouse', 'condition_out' => 'regular', 'area_id' => $area->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.accessories', 'Cargador, funda y mouse')
            ->assertJsonPath('data.area.id', $area->id);

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertOk();
        $this->patchJson(self::BASE."/asignaciones/{$asignacion->id}", ['notes' => 'tarde'])
            ->assertStatus(422)->assertJsonValidationErrors(['asignacion']);
    }

    public function test_una_asignacion_de_otra_empresa_responde_404(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $activo = $this->crearActivo(['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001']);
        $asignacion = FixedAssetAssignment::create([
            'enterprise_id' => $porvenir->id, 'fixed_asset_id' => $activo->id, 'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id, 'assignee_name' => 'Ana', 'assigned_at' => '2026-10-01',
            'assigned_by' => $this->actingUser->id, 'condition_out' => 'bueno', 'asset_snapshot' => ['code' => 'SP-AF-000001'],
        ]);

        $this->postJson(self::BASE."/asignaciones/{$asignacion->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertNotFound();
        $this->patchJson(self::BASE."/asignaciones/{$asignacion->id}", ['notes' => 'x'])->assertNotFound();
    }

    // ---- consultas ----

    public function test_el_historial_trae_lo_que_necesita_el_formulario(): void
    {
        $activo = $this->crearActivo();
        $area = $this->areaDeLaEntidad();
        $primera = $this->asignar($activo);
        $this->postJson(self::BASE."/asignaciones/{$primera->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertOk();
        $segunda = $this->asignar($activo);

        $this->getJson(self::BASE."/activos/{$activo->id}/asignaciones")
            ->assertOk()
            ->assertJsonPath('data.asignacion_activa_id', $segunda->id)
            ->assertJsonPath('data.asignable', true)
            ->assertJsonPath('data.motivo_bloqueo', null)
            ->assertJsonPath('data.areas.0.id', $area->id)
            ->assertJsonPath('data.asignaciones.0.id', $segunda->id)
            ->assertJsonPath('data.asignaciones.1.id', $primera->id)
            ->assertJsonCount(2, 'data.asignaciones');
    }

    public function test_el_historial_indica_el_motivo_cuando_el_activo_esta_bloqueado(): void
    {
        $activo = $this->crearActivo(['status' => 'en_mantenimiento']);

        $this->getJson(self::BASE."/activos/{$activo->id}/asignaciones")
            ->assertOk()
            ->assertJsonPath('data.asignable', false)
            ->assertJsonPath('data.asignacion_activa_id', null);
    }

    public function test_busca_responsables_de_la_empresa_del_activo(): void
    {
        $activo = $this->crearActivo();
        $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $this->crearEmpleado($this->corporativo, ['first_name' => 'Ana', 'last_name' => 'Ajena']);

        $this->getJson(self::BASE."/activos/{$activo->id}/responsables?q=Ana")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Ana Pérez')
            ->assertJsonPath('data.0.tipo', 'employee');

        Sanctum::actingAs($this->usuarioCon(['view']));
        $this->getJson(self::BASE."/activos/{$activo->id}/responsables?q=Ana")->assertForbidden();
    }

    public function test_la_lista_filtra_por_estado_y_busqueda_y_respeta_el_alcance(): void
    {
        $activa = $this->asignar($this->crearActivo(['name' => 'Laptop Dell']), null, []);
        $devuelta = $this->asignar($this->crearActivo(['name' => 'Impresora HP']));
        $this->postJson(self::BASE."/asignaciones/{$devuelta->id}/devolver", ['condition_in' => 'bueno', 'return_reason' => 'otro'])->assertOk();
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $activoSp = $this->crearActivo(['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001', 'name' => 'Laptop SP']);
        FixedAssetAssignment::create([
            'enterprise_id' => $porvenir->id, 'fixed_asset_id' => $activoSp->id, 'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id, 'assignee_name' => 'Persona SP', 'assigned_at' => '2026-10-01',
            'assigned_by' => $this->actingUser->id, 'condition_out' => 'bueno', 'asset_snapshot' => ['code' => 'SP-AF-000001'],
        ]);

        $this->getJson(self::BASE.'/asignaciones')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $activa->id)
            ->assertJsonPath('data.data.0.asset.name', 'Laptop Dell');

        $this->getJson(self::BASE.'/asignaciones?estado=todas')->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson(self::BASE.'/asignaciones?estado=devueltas')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::BASE.'/asignaciones?estado=todas&search=Impresora')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson(self::BASE.'/asignaciones?estado=otra')->assertStatus(422);

        // Grupo Espléndido ve las de todas las empresas y puede filtrar por una.
        $this->getJson(self::BASE_GE.'/asignaciones')->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson(self::BASE_GE.'/asignaciones?enterprise_id='.$porvenir->id)->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_la_lista_requiere_el_permiso_view(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson(self::BASE.'/asignaciones')->assertStatus(403);
    }

    public function test_sin_view_pero_con_acceso_a_la_empresa_la_lista_y_el_historial_responden_403(): void
    {
        $activo = $this->crearActivo();
        // Acceso a la empresa y permisos de otro tipo: el 403 viene del permiso, no del alcance.
        Sanctum::actingAs($this->usuarioCon(['create']));

        $this->getJson(self::BASE.'/asignaciones')->assertForbidden();
        $this->getJson(self::BASE."/activos/{$activo->id}/asignaciones")->assertForbidden();
    }

    public function test_los_filtros_de_la_lista_y_de_responsables_con_formato_invalido_responden_422(): void
    {
        $activo = $this->crearActivo();

        $this->getJson(self::BASE.'/asignaciones?search[]=x')->assertStatus(422)->assertJsonValidationErrors(['search']);
        $this->getJson(self::BASE.'/asignaciones?area_id[]=1')->assertStatus(422)->assertJsonValidationErrors(['area_id']);
        $this->getJson(self::BASE.'/asignaciones?enterprise_id=abc')->assertStatus(422)->assertJsonValidationErrors(['enterprise_id']);
        $this->getJson(self::BASE.'/asignaciones?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson(self::BASE."/activos/{$activo->id}/responsables?q[]=x")->assertStatus(422)->assertJsonValidationErrors(['q']);
    }
}
