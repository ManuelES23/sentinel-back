<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class PerfilVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    public function test_solicita_desde_el_perfil_con_origen_perfil(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa);
        Sanctum::actingAs($empleado->user);

        $respuesta = $this->postJson('/api/profile/vacation-request', [
            'start_date' => $this->lunes->toDateString(),
            'end_date' => $this->lunes->copy()->addDays(6)->toDateString(),
        ])->assertOk()->assertJsonPath('status', 'success');

        $solicitud = VacationRequest::findOrFail($respuesta->json('data.id'));
        $this->assertSame(VacationRequest::SOURCE_PERFIL, $solicitud->source);
        $this->assertSame(5, $solicitud->days_requested);
        $this->assertSame($empleado->user_id, $solicitud->created_by);
    }

    public function test_una_regla_incumplida_responde_400_como_antes(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa, ['hire_date' => now()->subMonths(3)->toDateString()]);
        Sanctum::actingAs($empleado->user);

        $this->postJson('/api/profile/vacation-request', [
            'start_date' => $this->lunes->toDateString(),
            'end_date' => $this->lunes->copy()->addDays(4)->toDateString(),
        ])->assertStatus(400)->assertJsonPath('status', 'error')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'antigüedad'));
    }

    public function test_cancela_la_suya_pero_no_la_de_otro_ni_una_aprobada(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa);
        $propia = $this->solicitud($empleado, 'pending', 3);
        $aprobada = $this->solicitud($empleado, 'approved', 3);
        $ajena = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        Sanctum::actingAs($empleado->user);

        $this->deleteJson("/api/profile/vacation-request/{$propia->id}")->assertOk();
        $this->assertSame('cancelled', $propia->fresh()->status);
        $this->deleteJson("/api/profile/vacation-request/{$ajena->id}")->assertForbidden();
        $this->deleteJson("/api/profile/vacation-request/{$aprobada->id}")->assertStatus(400);
    }

    public function test_el_perfil_muestra_el_mismo_saldo_que_rh(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa);
        $this->solicitud($empleado, 'approved', 5);
        $this->solicitud($empleado, 'pending', 2);
        Sanctum::actingAs($empleado->user);

        $saldo = $this->getJson('/api/profile')->assertOk()->json('data.vacation.balance');

        $this->assertSame(35, $saldo['available_days']);
        $this->assertSame(5, $saldo['used_days']);
        $this->assertSame(2, $saldo['pending_days']);
        $this->assertSame(42, $saldo['accumulated_days']);
    }

    public function test_el_perfil_lista_solicitudes_del_anio_siguiente_pero_no_las_de_anios_pasados(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa);
        $siguiente = $this->solicitud($empleado, 'pending', 3, ['vacation_year' => today()->year + 1]);
        $pasada = $this->solicitud($empleado, 'cancelled', 3, ['vacation_year' => today()->year - 1]);
        Sanctum::actingAs($empleado->user);

        $ids = collect($this->getJson('/api/profile')->assertOk()->json('data.vacation.requests'))->pluck('id');

        $this->assertTrue($ids->contains($siguiente->id));
        $this->assertFalse($ids->contains($pasada->id));
    }
}