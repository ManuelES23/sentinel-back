<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class VacacionesRhApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private User $rh;

    private const BASE = '/api/grupoesplendido/rh/vacaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
        $this->rh = User::factory()->create();
        Sanctum::actingAs($this->rh);
    }

    private function lunes(): Carbon
    {
        return today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    public function test_sin_view_no_lista(): void
    {
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_con_view_lista(): void
    {
        $this->concederVacaciones($this->rh, 'view');
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.0.id', $solicitud->id);
    }

    public function test_rh_captura_fechas_pasadas_con_origen_rh(): void
    {
        $this->concederVacaciones($this->rh, 'create');
        $empleado = $this->empleado($this->empresa);
        $lunesPasado = today()->previous(Carbon::MONDAY)->subWeek();

        $this->postJson(self::BASE, [
            'employee_id' => $empleado->id,
            'start_date' => $lunesPasado->toDateString(),
            'end_date' => $lunesPasado->copy()->addDays(4)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.source', VacationRequest::SOURCE_RH)
            ->assertJsonPath('data.created_by', $this->rh->id)
            ->assertJsonPath('data.days_requested', 5);
    }

    public function test_crear_sin_create_responde_403(): void
    {
        $this->concederVacaciones($this->rh, 'view');

        $this->postJson(self::BASE, ['employee_id' => $this->empleado($this->empresa)->id, 'start_date' => $this->lunes()->toDateString(), 'end_date' => $this->lunes()->toDateString()])
            ->assertForbidden();
    }

    public function test_una_regla_incumplida_responde_422_con_mensaje(): void
    {
        $this->concederVacaciones($this->rh, 'create');
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]);

        $this->postJson(self::BASE, [
            'employee_id' => $empleado->id,
            'start_date' => $this->lunes()->toDateString(),
            'end_date' => $this->lunes()->copy()->addDays(20)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'No tiene suficientes días disponibles'));
    }

    public function test_aprobar_como_rh_requiere_el_permiso_aprobar(): void
    {
        $solicitud = app(\App\Services\RH\VacationRequestService::class)->crear(
            $this->empleado($this->empresa), $this->lunes(), $this->lunes()->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL
        );

        $this->postJson(self::BASE."/{$solicitud->id}/aprobar")->assertForbidden();

        $this->concederVacaciones($this->rh, 'aprobar');
        $this->postJson(self::BASE."/{$solicitud->id}/aprobar")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.resolved_by_rh', true);
    }

    public function test_rechazar_exige_motivo(): void
    {
        $this->concederVacaciones($this->rh, 'aprobar');
        $solicitud = app(\App\Services\RH\VacationRequestService::class)->crear(
            $this->empleado($this->empresa), $this->lunes(), $this->lunes()->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL
        );

        $this->postJson(self::BASE."/{$solicitud->id}/rechazar")->assertStatus(422);
        $this->postJson(self::BASE."/{$solicitud->id}/rechazar", ['rejection_reason' => 'Temporada alta'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_cancelar_una_aprobada_futura_requiere_edit(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3);

        $this->postJson(self::BASE."/{$solicitud->id}/cancelar")->assertForbidden();

        $this->concederVacaciones($this->rh, 'edit');
        $this->postJson(self::BASE."/{$solicitud->id}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_la_migracion_concede_los_permisos_a_quien_ya_entra(): void
    {
        $submodulo = $this->submoduloVacaciones();
        $conAcceso = User::factory()->create();
        $conRevocado = User::factory()->create();
        foreach ([$conAcceso, $conRevocado] as $usuario) {
            UserSubmoduleAccess::create(['user_id' => $usuario->id, 'submodule_id' => $submodulo->id, 'is_active' => true, 'granted_at' => now()]);
        }
        $aprobar = SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', 'aprobar')->first();
        UserSubmodulePermission::create(['user_id' => $conRevocado->id, 'submodule_id' => $submodulo->id, 'permission_type_id' => $aprobar->id, 'is_granted' => false]);

        (require database_path('migrations/2026_10_15_100500_add_rh_vacaciones_permissions.php'))->up();

        $this->assertSame(4, UserSubmodulePermission::where('user_id', $conAcceso->id)->where('is_granted', true)->count());
        $this->assertFalse((bool) UserSubmodulePermission::where('user_id', $conRevocado->id)->where('permission_type_id', $aprobar->id)->value('is_granted'));
        $this->assertSame(3, UserSubmodulePermission::where('user_id', $conRevocado->id)->where('is_granted', true)->count());
    }
}
