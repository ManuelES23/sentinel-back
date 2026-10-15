<?php
// sentinel-back/tests/Feature/ActivosFijos/BandejaAsignacionesResumenTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\Area;
use App\Models\AssetCategory;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class BandejaAsignacionesResumenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const BASE = '/api/splendidfarms/administration/activos-fijos/asignaciones';
    private const BASE_GE = '/api/grupoesplendido/administration/activos-fijos/asignaciones';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 10, 0, 0, 'America/Mazatlan'));
        $this->setUpAssetFixtures();
        $this->otorgarActivos($this->actingUser, $this->enterprise, 'asignaciones', self::PERMISOS_CRUD);
        $this->otorgarActivos($this->actingUser, $this->corporativo, 'asignaciones', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Asignación con fechas controladas; `activo` son overrides del activo. */
    private function asignacion(array $datos = []): FixedAssetAssignment
    {
        $activo = $this->crearActivo($datos['activo'] ?? []);
        unset($datos['activo']);

        return FixedAssetAssignment::create(array_merge([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id,
            'assignee_name' => 'Ana Ruiz',
            'assigned_at' => '2026-10-01',
            'assigned_by' => $this->actingUser->id,
            'condition_out' => 'bueno',
            'asset_snapshot' => ['code' => $activo->code],
        ], $datos));
    }

    /** El juego de datos de los bordes: ver «Review Focus» 2 del plan. */
    private function sembrar(): array
    {
        return [
            'a' => $this->asignacion(['assigned_at' => '2026-10-09']),                                  // pendiente, 6 días, del mes
            'b' => $this->asignacion(['assigned_at' => '2026-09-14']),                                  // pendiente, 31 días: atrasada
            'c' => $this->asignacion(['assigned_at' => '2026-09-15']),                                  // pendiente, 30 días: no atrasada
            'd' => $this->asignacion(['assigned_at' => '2026-03-01', 'signed_document_path' => 'x.pdf']), // activa firmada
            'd2' => $this->asignacion(['assigned_at' => '2026-10-01', 'signed_document_path' => 'y.pdf']), // día 1: del mes
            'e' => $this->asignacion(['assigned_at' => '2025-01-10', 'returned_at' => '2026-10-02', 'condition_in' => 'bueno', 'return_reason' => 'otro']),
            'f' => $this->asignacion(['assigned_at' => '2026-08-01', 'returned_at' => '2026-09-30', 'condition_in' => 'bueno', 'return_reason' => 'otro']),
        ];
    }

    public function test_el_resumen_cuenta_activas_cartas_atrasadas_y_el_mes(): void
    {
        $this->sembrar();

        $this->getJson(self::BASE.'/resumen')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.activas', 5)
            ->assertJsonPath('data.cartas_por_firmar', 3)
            ->assertJsonPath('data.cartas_atrasadas', 1)
            ->assertJsonPath('data.entregas_mes', 2)
            ->assertJsonPath('data.devoluciones_mes', 1)
            ->assertJsonPath('data.mes', '2026-10');
    }

    public function test_el_resumen_respeta_el_alcance_y_el_filtro_de_empresa_en_ge(): void
    {
        $this->sembrar();
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $this->asignacion(['activo' => ['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001'], 'assigned_at' => '2026-10-03']);

        $this->getJson(self::BASE.'/resumen')->assertOk()->assertJsonPath('data.activas', 5);
        // Una empresa no visible se ignora: siguen saliendo las cifras de SF.
        $this->getJson(self::BASE.'/resumen?enterprise_id='.$porvenir->id)->assertOk()->assertJsonPath('data.activas', 5);

        $this->getJson(self::BASE_GE.'/resumen')->assertOk()->assertJsonPath('data.activas', 6)->assertJsonPath('data.entregas_mes', 3);
        $this->getJson(self::BASE_GE.'/resumen?enterprise_id='.$porvenir->id)
            ->assertOk()
            ->assertJsonPath('data.activas', 1)
            ->assertJsonPath('data.cartas_por_firmar', 1);
    }

    public function test_el_resumen_trae_las_opciones_de_area_tipo_y_empresas(): void
    {
        $area = Area::create(['code' => 'ARE-90', 'name' => 'Recepción', 'slug' => 'recepcion-90', 'is_active' => true]);
        $this->asignacion(['area_id' => $area->id]);
        $this->asignacion();

        $this->getJson(self::BASE.'/resumen')
            ->assertOk()
            ->assertJsonCount(1, 'data.opciones.areas')
            ->assertJsonPath('data.opciones.areas.0.id', $area->id)
            ->assertJsonPath('data.opciones.areas.0.name', 'Recepción')
            ->assertJsonCount(1, 'data.opciones.tipos')
            ->assertJsonPath('data.opciones.tipos.0.name', 'Equipos de cómputo')
            ->assertJsonCount(0, 'data.opciones.empresas');

        $nombres = collect($this->getJson(self::BASE_GE.'/resumen')->assertOk()->json('data.opciones.empresas'))->pluck('name');
        $this->assertTrue($nombres->contains('Splendid Farms'));
        $this->assertTrue($nombres->contains('Grupo Espléndido'));
    }

    public function test_las_opciones_de_sf_excluyen_areas_y_tipos_que_solo_estan_en_otra_empresa(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $soloSp = Area::create(['code' => 'ARE-92', 'name' => 'Muelle SP', 'slug' => 'muelle-sp', 'is_active' => true]);
        $tipoSp = AssetCategory::create(['code' => 'TAC-009', 'name' => 'Maquinaria SP', 'icon' => 'Truck', 'is_active' => true]);
        $this->asignacion();
        $this->asignacion([
            'activo' => ['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001', 'category_id' => $tipoSp->id],
            'area_id' => $soloSp->id,
        ]);

        $sf = $this->getJson(self::BASE.'/resumen')->assertOk()->json('data.opciones');
        $this->assertSame([], $sf['areas']);
        $this->assertSame(['Equipos de cómputo'], collect($sf['tipos'])->pluck('name')->all());

        $ge = $this->getJson(self::BASE_GE.'/resumen')->assertOk()->json('data.opciones');
        $this->assertSame(['Muelle SP'], collect($ge['areas'])->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Equipos de cómputo', 'Maquinaria SP'], collect($ge['tipos'])->pluck('name')->all());
    }

    public function test_cartas_pendientes_de_la_mas_antigua_con_dias_y_limite(): void
    {
        $datos = $this->sembrar();

        $this->getJson(self::BASE.'/cartas-pendientes')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.data.0.id', $datos['b']->id)
            ->assertJsonPath('data.data.0.dias_sin_firma', 31)
            ->assertJsonPath('data.data.1.id', $datos['c']->id)
            ->assertJsonPath('data.data.1.dias_sin_firma', 30)
            ->assertJsonPath('data.data.2.id', $datos['a']->id)
            ->assertJsonPath('data.data.2.asset.category.name', 'Equipos de cómputo')
            ->assertJsonPath('data.data.2.activa', true)
            ->assertJsonPath('data.data.2.tiene_carta_firmada', false);

        $this->getJson(self::BASE.'/cartas-pendientes?limit=2')->assertOk()->assertJsonCount(2, 'data.data')->assertJsonPath('data.total', 3);
        $this->getJson(self::BASE.'/cartas-pendientes?limit=abc')->assertOk()->assertJsonCount(3, 'data.data');
        $this->getJson(self::BASE.'/cartas-pendientes?limit=999')->assertOk()->assertJsonCount(3, 'data.data');
    }

    public function test_las_rutas_de_la_bandeja_requieren_view(): void
    {
        $sinNada = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($sinNada);
        $this->getJson(self::BASE.'/resumen')->assertForbidden();
        $this->getJson(self::BASE.'/cartas-pendientes')->assertForbidden();

        // Con acceso a la empresa y otro permiso: el 403 viene del permiso, no del alcance.
        $soloCrear = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($soloCrear, $this->enterprise, 'asignaciones', ['create']);
        Sanctum::actingAs($soloCrear);
        $this->getJson(self::BASE.'/resumen')->assertForbidden();
        $this->getJson(self::BASE.'/cartas-pendientes')->assertForbidden();
    }

    public function test_una_empresa_sin_acceso_responde_403(): void
    {
        $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');

        $this->getJson('/api/splendidbyporvenir/administration/activos-fijos/asignaciones/resumen')->assertForbidden();
    }

    public function test_los_parametros_no_escalares_se_ignoran(): void
    {
        $this->sembrar();

        $this->getJson(self::BASE.'/resumen?enterprise_id[]=1')->assertOk()->assertJsonPath('data.activas', 5);
        $this->getJson(self::BASE.'/cartas-pendientes?limit[]=1&enterprise_id[a]=2')->assertOk()->assertJsonCount(3, 'data.data');
    }

    public function test_las_rutas_nuevas_no_chocan_con_las_de_una_asignacion(): void
    {
        $this->asignacion();

        $this->getJson(self::BASE.'/resumen')->assertOk()->assertJsonStructure(['data' => ['activas']]);
        $this->getJson(self::BASE.'/cartas-pendientes')->assertOk()->assertJsonStructure(['data' => ['data', 'total']]);
        // El índice sigue igual.
        $this->getJson(self::BASE.'?estado=todas')->assertOk()->assertJsonCount(1, 'data.data');
    }
}
