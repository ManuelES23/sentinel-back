<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\VacationRequestApproval;
use App\Services\RH\CadenaAprobacionVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class CadenaAprobacionVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function cadena(): CadenaAprobacionVacaciones
    {
        return app(CadenaAprobacionVacaciones::class);
    }

    /** Coordinador de RH del corporativo con usuario y regla de puesto en el paso dado. */
    private function coordinadorRh(int $paso = 2)
    {
        $puesto = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $this->regla($paso, 'position', ['position_id' => $puesto->id]);

        return $this->empleadoConUsuario($this->corporativo, ['position_id' => $puesto->id]);
    }

    public function test_construye_los_pasos_mas_rh_y_queda_en_el_primero(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->coordinadorRh(2);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);

        $paso = $this->cadena()->construir($solicitud);

        $this->assertSame(1, $paso->step_order);
        $this->assertSame(1, $solicitud->fresh()->current_step);
        $pasos = $solicitud->approvals()->get();
        $this->assertCount(3, $pasos);
        $this->assertSame(['Jefe directo', 'Coordinador de RH', CadenaAprobacionVacaciones::DESCRIPCION_RH], $pasos->pluck('description')->all());
        $this->assertTrue($pasos->last()->is_rh_final);
    }

    public function test_un_paso_sin_aprobador_con_usuario_se_salta(): void
    {
        $jefeSinUsuario = $this->empleado($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->coordinadorRh(2);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefeSinUsuario->id]), 'pending', 3);

        $this->cadena()->construir($solicitud);

        $primero = $solicitud->approvals()->where('step_order', 1)->first();
        $this->assertSame(VacationRequestApproval::SKIPPED, $primero->decision);
        $this->assertSame(CadenaAprobacionVacaciones::SIN_APROBADOR, $primero->comment);
        $this->assertSame(2, $solicitud->fresh()->current_step);
    }

    public function test_si_todo_se_salta_espera_en_rh(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $paso = $this->cadena()->construir($solicitud);

        $this->assertTrue($paso->is_rh_final);
        $this->assertSame(2, $solicitud->fresh()->current_step);
    }

    public function test_sin_reglas_solo_queda_el_paso_de_rh(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $paso = $this->cadena()->construir($solicitud);

        $this->assertTrue($paso->is_rh_final);
        $this->assertSame(1, $solicitud->approvals()->count());
    }

    public function test_reglas_con_el_mismo_numero_son_un_solo_paso(): void
    {
        $gerente = $this->puesto($this->empresa, 'Gerente de área', 4, true, 'enterprise');
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(1, 'position', ['position_id' => $gerente->id]);
        $this->empleadoConUsuario($this->empresa, ['position_id' => $gerente->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $this->cadena()->construir($solicitud);

        $primero = $solicitud->approvals()->where('step_order', 1)->first();
        $this->assertSame('Jefe directo o Gerente de área', $primero->description);
        $this->assertCount(2, $primero->rules);
        $this->assertSame(VacationRequestApproval::WAITING, $primero->decision);
    }

    public function test_tras_la_ultima_aprobacion_rh_ya_no_se_requiere(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $paso = $this->cadena()->construir($solicitud);
        $paso->update(['decision' => VacationRequestApproval::APPROVED, 'decided_by' => $jefe->user_id, 'decided_at' => now()]);

        $this->assertNull($this->cadena()->avanzar($solicitud));

        $rh = $solicitud->approvals()->where('is_rh_final', true)->first();
        $this->assertSame(VacationRequestApproval::SKIPPED, $rh->decision);
        $this->assertSame(CadenaAprobacionVacaciones::NO_REQUERIDO, $rh->comment);
        $this->assertNull($solicitud->fresh()->current_step);
    }

    public function test_la_misma_persona_no_cubre_dos_pasos(): void
    {
        $gerente = $this->puesto($this->empresa, 'Gerente', 2, true, 'enterprise');
        $jefe = $this->empleadoConUsuario($this->empresa, ['position_id' => $gerente->id]);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, 'position', ['position_id' => $gerente->id]);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $paso = $this->cadena()->construir($solicitud);
        $paso->update(['decision' => VacationRequestApproval::APPROVED, 'decided_by' => $jefe->user_id, 'decided_at' => now()]);

        $this->assertNull($this->cadena()->avanzar($solicitud));
        $this->assertSame(VacationRequestApproval::SKIPPED, $solicitud->approvals()->where('step_order', 2)->value('decision'));
    }

    public function test_numera_los_pasos_de_forma_consecutiva(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(5, 'hierarchy_level', ['min_hierarchy_level' => 2]);
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $this->cadena()->construir($solicitud);

        $this->assertSame([1, 2, 3], $solicitud->approvals()->pluck('step_order')->all());
    }

    public function test_cambiar_la_configuracion_no_altera_una_cadena_en_curso(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $regla = $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $solicitud = $this->solicitud($this->empleado($this->empresa, ['reports_to' => $jefe->id]), 'pending', 3);
        $this->cadena()->construir($solicitud);

        $this->regla(1, 'hierarchy_level', ['min_hierarchy_level' => 1]);
        $this->regla(2, 'hierarchy_level', ['min_hierarchy_level' => 1]);

        $this->assertSame(2, $solicitud->approvals()->count());
        $this->assertSame([$regla->id], $solicitud->approvals()->first()->rules);
    }

    public function test_cerrar_pendientes_salta_los_pasos_en_espera(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);
        $this->cadena()->construir($solicitud);

        $this->cadena()->cerrarPendientes($solicitud, 'Solicitud cancelada');

        $this->assertSame(0, $solicitud->approvals()->where('decision', VacationRequestApproval::WAITING)->count());
        $this->assertSame('Solicitud cancelada', $solicitud->approvals()->first()->comment);
    }

    public function test_la_descripcion_de_un_paso_con_muchas_alternativas_cabe_en_la_columna(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $puesto = $this->puesto($this->empresa, 'Puesto alternativo '.$i.' '.str_repeat('x', 40));
            $this->regla(1, 'position', ['position_id' => $puesto->id]);
        }
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'pending', 3);

        $this->cadena()->construir($solicitud);

        $descripcion = $solicitud->approvals()->where('step_order', 1)->value('description');
        $this->assertGreaterThan(200, mb_strlen($descripcion));
        $this->assertLessThanOrEqual(255, mb_strlen($descripcion));
    }
}
