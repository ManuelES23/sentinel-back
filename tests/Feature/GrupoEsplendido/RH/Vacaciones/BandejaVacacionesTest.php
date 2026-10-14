<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class BandejaVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Employee $jefe;

    private VacationRequest $solicitud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $this->solicitud = app(VacationRequestService::class)->crear(
            $this->empleado($this->empresa, ['reports_to' => $this->jefe->id]), $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL
        );
    }

    private function procesoVacaciones(array $procesos): ?array
    {
        return collect($procesos)->firstWhere('code', 'vacation_requests');
    }

    public function test_el_jefe_la_ve_en_su_resumen_y_lista(): void
    {
        Sanctum::actingAs($this->jefe->user);

        $resumen = $this->procesoVacaciones($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
        $this->assertSame(1, $resumen['pending_count']);

        $lista = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));
        $this->assertSame($this->solicitud->id, $lista['items'][0]['id']);
        $this->assertSame(1, $lista['items'][0]['step']);
        $this->assertSame(2, $lista['items'][0]['steps_total']);
        $this->assertSame('Jefe directo', $lista['items'][0]['step_description']);
    }

    public function test_otro_empleado_no_la_ve(): void
    {
        Sanctum::actingAs($this->empleadoConUsuario($this->empresa)->user);

        $proceso = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));

        $this->assertTrue($proceso === null || $proceso['items'] === []);
    }

    public function test_el_corporativo_la_ve_si_su_puesto_esta_en_el_paso_actual(): void
    {
        $puesto = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $coordinador = $this->empleadoConUsuario($this->corporativo, ['position_id' => $puesto->id]);
        $this->regla(1, 'position', ['position_id' => $puesto->id]);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(4);
        $otra = app(VacationRequestService::class)->crear($this->empleado($this->empresa), $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
        Sanctum::actingAs($coordinador->user);

        $proceso = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));

        $this->assertContains($otra->id, array_column($proceso['items'], 'id'));
    }

    public function test_aprobar_y_rechazar_desde_la_bandeja(): void
    {
        Sanctum::actingAs($this->empleadoConUsuario($this->empresa)->user);
        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/approve")->assertForbidden();

        Sanctum::actingAs($this->jefe->user);
        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $lunes = today()->next(Carbon::MONDAY)->addWeeks(6);
        $otra = app(VacationRequestService::class)->crear(
            $this->empleado($this->empresa, ['reports_to' => $this->jefe->id]), $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL
        );
        $this->postJson("/api/pending-approvals/vacation_request/{$otra->id}/reject", ['rejection_reason' => 'Cierre de mes'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_el_detalle_trae_la_cadena(): void
    {
        Sanctum::actingAs($this->jefe->user);

        $detalle = $this->getJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}")->assertOk()->json('data');

        $this->assertSame(['Jefe directo', 'RH Grupo Espléndido'], array_column($detalle['approvals'], 'description'));
    }

    public function test_la_solicitud_mas_antigua_no_se_pierde_entre_muchas_ajenas(): void
    {
        // La solicitud del jefe es la más antigua de todas.
        $this->solicitud->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

        // 205 pendientes de otro empleado, en un paso de RH que el jefe no puede decidir.
        $otro = $this->empleado($this->empresa);
        for ($i = 0; $i < 205; $i++) {
            $ajena = $this->solicitud($otro, 'pending', 1, ['current_step' => 1]);
            $ajena->approvals()->create([
                'step_order' => 1, 'description' => 'RH Grupo Espléndido', 'rules' => [],
                'is_rh_final' => true, 'decision' => 'waiting',
            ]);
        }

        Sanctum::actingAs($this->jefe->user);

        $resumen = $this->procesoVacaciones($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
        $this->assertSame(1, $resumen['pending_count']);

        $lista = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));
        $this->assertSame([$this->solicitud->id], array_column($lista['items'], 'id'));
    }

    public function test_un_empleado_sin_regla_ni_subordinados_ni_rh_no_dispara_el_recorrido_de_solicitudes(): void
    {
        $puesto = $this->puesto($this->empresa, 'Auxiliar');
        Sanctum::actingAs($this->empleadoConUsuario($this->empresa, ['position_id' => $puesto->id])->user);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $procesos = $this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes');
        $consultas = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query');

        $this->assertNull($this->procesoVacaciones($procesos));
        $this->assertFalse($consultas->contains(fn ($sql) => str_contains($sql, 'from "vacation_requests"')), 'No debía consultar vacation_requests.');
    }

    public function test_quien_tiene_una_regla_de_puesto_en_otra_empresa_si_ve_el_proceso(): void
    {
        $puesto = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $this->regla(1, 'position', ['position_id' => $puesto->id, 'enterprise_id' => $this->empresa->id]);
        $coordinador = $this->empleadoConUsuario($this->corporativo, ['position_id' => $puesto->id]);
        Sanctum::actingAs($coordinador->user);

        $procesos = $this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes');

        $this->assertNotNull($this->procesoVacaciones($procesos));
    }
}
