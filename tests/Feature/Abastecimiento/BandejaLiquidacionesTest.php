<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Liquidaciones de Abastecimiento en la bandeja de pendientes del perfil (`/api/pending-approvals`, plan 2, Task 10):
 * con o sin empleado ligado, solo quien puede decidir el paso actual la ve y la resuelve. Cada liquidación es de dos
 * recepciones de 10 cajas a $100 (MXN): $2,000.
 */
class BandejaLiquidacionesTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures, CreatesVacacionesFixtures;

    private ApprovalProcess $proceso;

    private User $creador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $this->proceso = ApprovalProcess::findByCode(ApprovalProcess::SUPPLY_SETTLEMENTS);
        $this->assertNotNull($this->proceso);
        $this->creador = $this->usuarioConAcceso();
    }

    // ---------------------------------------------------------------- ayudas

    private function usuarioConAcceso(): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $usuario->id, 'enterprise_id' => $this->sbp->id],
            ['is_active' => true, 'granted_at' => now()],
        );

        return $usuario;
    }

    private function regla(int $paso, string $tipo, array $atributos = []): ApprovalFlowStep
    {
        return ApprovalFlowStep::create(array_merge([
            'approval_process_id' => $this->proceso->id, 'enterprise_id' => null, 'step_order' => $paso,
            'approver_type' => $tipo, 'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ], $atributos));
    }

    /** Usuario específico SIN empleado ligado, con acceso a SBP. */
    private function directorSinEmpleado(int $paso): User
    {
        $usuario = $this->usuarioConAcceso();
        $this->regla($paso, 'user', ['user_id' => $usuario->id]);

        return $usuario;
    }

    /** Usuario específico CON empleado activo de SBP. */
    private function aprobadorConEmpleado(int $paso): User
    {
        $empleado = $this->empleadoConUsuario($this->sbp);
        UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $empleado->user_id, 'enterprise_id' => $this->sbp->id],
            ['is_active' => true, 'granted_at' => now()],
        );
        $this->regla($paso, 'user', ['user_id' => $empleado->user_id]);

        return $empleado->user;
    }

    /** Regla de puesto con un empleado activo de SBP ligado a un usuario con acceso. */
    private function aprobadorPorPuesto(int $paso): User
    {
        $puesto = $this->puesto($this->sbp, 'Gerente de compras '.$paso, 3, true);
        $empleado = $this->empleadoConUsuario($this->sbp, ['position_id' => $puesto->id]);
        UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $empleado->user_id, 'enterprise_id' => $this->sbp->id],
            ['is_active' => true, 'granted_at' => now()],
        );
        $this->regla($paso, 'position', ['position_id' => $puesto->id]);

        return $empleado->user;
    }

    /** Liquidación enviada a revisión por el creador (la cadena se congela con las reglas ya creadas). */
    private function enRevision(int $recepciones = 2): Liquidacion
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['created_by' => $this->creador->id]);
        for ($i = 0; $i < $recepciones; $i++) {
            $r = $this->crearRecepcion($convenio, ['fecha' => '2026-06-0'.($i + 2), 'liquidacion_id' => $liq->id]);
            LiquidacionDetalle::create([
                'liquidacion_id' => $liq->id, 'recepcion_id' => $r->id, 'cantidad' => 10, 'kilos' => 0, 'rezaga_kg' => 0,
                'precio' => 100, 'porcentaje_productor' => 100, 'subtotal' => 1000, 'descuento' => 0, 'a_pagar' => 1000,
            ]);
        }
        $liq->update(['cantidad' => 10 * $recepciones, 'bruto' => 1000 * $recepciones, 'monto_calculado' => 1000 * $recepciones, 'monto_final' => 1000 * $recepciones]);

        return DB::transaction(fn () => FlujoLiquidacion::enviar($liq->fresh(), $this->creador));
    }

    private function entrada(array $procesos): ?array
    {
        return collect($procesos)->firstWhere('code', 'supply_settlements');
    }

    private function resumenDe(User $usuario): ?array
    {
        Sanctum::actingAs($usuario);

        return $this->entrada($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
    }

    private function listaDe(User $usuario): ?array
    {
        Sanctum::actingAs($usuario);

        return $this->entrada($this->getJson('/api/pending-approvals')->assertOk()->json('data.processes'));
    }

    private function url(Liquidacion $liq, string $accion = ''): string
    {
        return "/api/pending-approvals/supply_settlement/{$liq->id}".($accion === '' ? '' : "/{$accion}");
    }

    private function avisosDe(User $usuario)
    {
        return SystemNotification::where('user_id', $usuario->id)->get();
    }

    // ---------------------------------------------------------------- quién la ve

    public function test_un_usuario_especifico_sin_empleado_la_ve_en_el_resumen_y_la_lista(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        $this->assertNull($director->employee);

        $resumen = $this->resumenDe($director);
        $this->assertSame(1, $resumen['pending_count']);
        $this->assertSame('enterprise', $resumen['scope']);
        $this->assertSame('Liquidaciones de abastecimiento', $resumen['name']);
        $this->assertSame($this->proceso->id, $resumen['process_id']);

        $lista = $this->listaDe($director);
        $this->assertSame(1, $lista['pending_count']);
        $this->assertSame($liq->id, $lista['items'][0]['id']);
    }

    public function test_un_usuario_especifico_con_empleado_la_ve_una_sola_vez(): void
    {
        $aprobador = $this->aprobadorConEmpleado(1);
        $this->enRevision();
        Sanctum::actingAs($aprobador);

        $procesos = $this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes');
        $this->assertCount(1, collect($procesos)->where('code', 'supply_settlements'));
        $this->assertSame(1, $this->entrada($procesos)['pending_count']);

        $procesos = $this->getJson('/api/pending-approvals')->assertOk()->json('data.processes');
        $this->assertCount(1, collect($procesos)->where('code', 'supply_settlements'));
        $this->assertCount(1, $this->entrada($procesos)['items']);
    }

    public function test_un_aprobador_por_puesto_con_empleado_la_ve(): void
    {
        $gerente = $this->aprobadorPorPuesto(1);
        $liq = $this->enRevision();

        $this->assertSame(1, $this->resumenDe($gerente)['pending_count']);
        $this->assertSame($liq->id, $this->listaDe($gerente)['items'][0]['id']);
    }

    public function test_el_item_trae_las_claves_de_la_bandeja(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();

        $item = $this->listaDe($director)['items'][0];

        $this->assertSame('supply_settlement', $item['type']);
        $this->assertSame($liq->folio.' · Productor de prueba', $item['title']);
        $this->assertSame($liq->convenio->folio.' · '.$liq->convenio->variedad->nombre, $item['subtitle']);
        $this->assertStringContainsString('$2,000.00 MXN', $item['description']);
        $this->assertStringContainsString('01/06/2026', $item['description']);
        $this->assertSame('Splendid by Porvenir', $item['enterprise']);
        $this->assertNotNull($item['date']);
        $this->assertSame(1, $item['step']);
        $this->assertSame(1, $item['steps_total']);
        $this->assertSame('Usuario: '.$director->name, $item['step_description']);
        $this->assertEquals(2000, $item['amount']);
        $this->assertSame('MXN', $item['currency']);
        $this->assertSame($liq->periodo_inicio->format('Y-m-d'), $item['period_start']);
        $this->assertSame($liq->periodo_fin->format('Y-m-d'), $item['period_end']);
    }

    public function test_quien_no_es_elegible_no_la_ve(): void
    {
        $this->directorSinEmpleado(1);
        $ajeno = $this->usuarioConAcceso();
        $this->enRevision();

        $this->assertNull($this->resumenDe($ajeno));
        $this->assertNull($this->listaDe($ajeno));
    }

    public function test_el_creador_no_la_ve_ni_con_el_permiso_de_aprobar(): void
    {
        // Sin cadena configurada decide quien tenga liquidaciones.aprobar: aquí es el propio creador.
        $this->otorgarAbs($this->creador, $this->sbp, 'liquidaciones', ['aprobar']);
        $otro = $this->usuarioConAcceso();
        $this->otorgarAbs($otro, $this->sbp, 'liquidaciones', ['aprobar']);
        $liq = $this->enRevision();

        $this->assertNull($this->resumenDe($this->creador));
        $this->assertNull($this->listaDe($this->creador));
        $this->assertSame($liq->id, $this->listaDe($otro)['items'][0]['id']);
    }

    public function test_la_entrada_no_aparece_sin_pendientes(): void
    {
        $director = $this->directorSinEmpleado(1);

        $this->assertNull($this->resumenDe($director));
        $this->assertNull($this->listaDe($director));

        Sanctum::actingAs($director);
        $this->assertSame(0, $this->getJson('/api/pending-approvals/summary')->json('data.total_pending'));
    }

    public function test_un_usuario_de_otra_empresa_no_la_ve(): void
    {
        $director = $this->directorSinEmpleado(1);
        $this->enRevision();
        UserEnterpriseAccess::where('user_id', $director->id)->update(['is_active' => false]);

        $this->assertNull($this->resumenDe($director));
        Sanctum::actingAs($director);
        $this->getJson($this->url(Liquidacion::first()))->assertStatus(404);
    }

    public function test_la_liquidacion_solo_se_muestra_al_aprobador_del_paso_actual(): void
    {
        $a = $this->directorSinEmpleado(1);
        $b = $this->aprobadorPorPuesto(2);
        $this->enRevision();

        $this->assertNotNull($this->resumenDe($a));
        $this->assertNull($this->resumenDe($b), 'El paso 2 no le toca hasta que el 1 apruebe.');
    }

    // ---------------------------------------------------------------- detalle

    public function test_el_detalle_lo_ve_quien_decide_y_los_demas_reciben_404(): void
    {
        $director = $this->directorSinEmpleado(1);
        $ajeno = $this->usuarioConAcceso();
        $liq = $this->enRevision();

        Sanctum::actingAs($director);
        $detalle = $this->getJson($this->url($liq))->assertOk()->json('data');
        $this->assertSame($liq->id, $detalle['id']);
        $this->assertSame('supply_settlement', $detalle['type']);
        $this->assertSame('en_revision', $detalle['status']);
        $this->assertCount(2, $detalle['detalles']);
        $this->assertCount(1, $detalle['approvals']);
        $this->assertSame('waiting', $detalle['approvals'][0]['decision']);
        $this->assertEquals(2000, $detalle['totals']['monto_final']);
        $this->assertSame('Productor de prueba', $detalle['productor']['nombre']);

        Sanctum::actingAs($ajeno);
        $this->getJson($this->url($liq))->assertStatus(404);
        Sanctum::actingAs($this->creador);
        $this->getJson($this->url($liq))->assertStatus(404);

        $inexistente = $this->getJson('/api/pending-approvals/supply_settlement/999999')->assertStatus(404);
        $this->assertSame('Registro no encontrado', $inexistente->json('message'));
    }

    public function test_quien_ya_decidio_un_paso_sigue_viendo_el_detalle(): void
    {
        $a = $this->directorSinEmpleado(1);
        $b = $this->aprobadorPorPuesto(2);
        $liq = $this->enRevision();

        Sanctum::actingAs($a);
        $this->postJson($this->url($liq, 'approve'))->assertOk();

        $this->getJson($this->url($liq))->assertOk()->assertJsonPath('data.approvals.0.decision', 'approved');
        Sanctum::actingAs($b);
        $this->getJson($this->url($liq))->assertOk();
    }

    // ---------------------------------------------------------------- aprobar

    public function test_un_usuario_sin_empleado_aprueba_y_la_liquidacion_queda_aprobada(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'), ['comment' => 'Todo en orden'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'aprobada');

        $this->assertSame('aprobada', $liq->fresh()->status);
        $paso = LiquidacionAprobacion::where('liquidacion_id', $liq->id)->first();
        $this->assertSame('approved', $paso->decision);
        $this->assertSame($director->id, $paso->decided_by);
        $this->assertSame('Todo en orden', $paso->comment);
    }

    public function test_un_usuario_con_empleado_aprueba(): void
    {
        $aprobador = $this->aprobadorConEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($aprobador);

        $this->postJson($this->url($liq, 'approve'))->assertOk();

        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    public function test_la_aprobacion_intermedia_avanza_la_cadena(): void
    {
        $a = $this->directorSinEmpleado(1);
        $b = $this->aprobadorPorPuesto(2);
        $liq = $this->enRevision();

        Sanctum::actingAs($a);
        $this->postJson($this->url($liq, 'approve'))
            ->assertOk()
            ->assertJsonPath('data.status', 'en_revision')
            ->assertJsonPath('data.paso_actual', 2);

        $this->assertNull($this->resumenDe($a), 'Quien ya decidió ya no la tiene pendiente.');
        $this->assertSame(1, $this->resumenDe($b)['pending_count']);
        $this->assertSame(2, $this->listaDe($b)['items'][0]['step']);
        $this->assertSame(2, $this->listaDe($b)['items'][0]['steps_total']);

        Sanctum::actingAs($b);
        $this->postJson($this->url($liq, 'approve'))->assertOk()->assertJsonPath('data.status', 'aprobada');
    }

    public function test_quien_no_es_elegible_no_aprueba_ni_cambia_nada(): void
    {
        $this->directorSinEmpleado(1);
        $ajeno = $this->usuarioConAcceso();
        $liq = $this->enRevision();
        Sanctum::actingAs($ajeno);

        $this->postJson($this->url($liq, 'approve'))->assertStatus(404);
        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'No'])->assertStatus(404);

        $this->assertSame('en_revision', $liq->fresh()->status);
        $this->assertSame(0, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('decision', '!=', 'waiting')->count());
    }

    public function test_el_creador_no_aprueba_su_propia_liquidacion(): void
    {
        $this->otorgarAbs($this->creador, $this->sbp, 'liquidaciones', ['aprobar']);
        $this->otorgarAbs($this->usuarioConAcceso(), $this->sbp, 'liquidaciones', ['aprobar']);
        $liq = $this->enRevision();
        Sanctum::actingAs($this->creador);

        $this->postJson($this->url($liq, 'approve'))->assertStatus(404);
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    public function test_un_paso_viejo_responde_422_y_no_decide_nada(): void
    {
        $director = $this->directorSinEmpleado(1);
        $this->aprobadorPorPuesto(2);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'), ['step' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('liquidacion');

        $this->assertSame(1, $liq->fresh()->paso_actual);
        $this->assertSame('waiting', LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('step_order', 1)->value('decision'));
    }

    public function test_el_paso_vigente_en_el_cuerpo_se_acepta(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'), ['step' => 1])->assertOk();
    }

    public function test_aprobar_dos_veces_no_avanza_la_cadena_dos_veces(): void
    {
        $director = $this->directorSinEmpleado(1);
        $this->aprobadorPorPuesto(2);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'))->assertOk();
        $this->postJson($this->url($liq, 'approve'))->assertStatus(422)->assertJsonValidationErrors('liquidacion');

        $this->assertSame(2, $liq->fresh()->paso_actual);
        $this->assertSame(1, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('decision', 'approved')->count());
    }

    // ---------------------------------------------------------------- rechazar

    public function test_rechazar_exige_motivo(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'reject'))->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    public function test_un_usuario_sin_empleado_rechaza_con_motivo_y_regresa_a_borrador(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'Los kilos no cuadran'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'borrador');

        $fresca = $liq->fresh();
        $this->assertSame('borrador', $fresca->status);
        $this->assertSame('Los kilos no cuadran', $fresca->rechazo_motivo);
        $this->assertSame($director->id, $fresca->rechazada_by);
        $this->assertNull($this->resumenDe($director));
    }

    public function test_un_usuario_con_empleado_rechaza(): void
    {
        $aprobador = $this->aprobadorConEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($aprobador);

        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'Falta una recepción'])->assertOk();

        $this->assertSame('borrador', $liq->fresh()->status);
    }

    public function test_una_regla_que_solo_aprueba_no_puede_rechazar(): void
    {
        $usuario = $this->usuarioConAcceso();
        $this->regla(1, 'user', ['user_id' => $usuario->id, 'can_reject' => false]);
        $liq = $this->enRevision();
        Sanctum::actingAs($usuario);

        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'No'])
            ->assertStatus(422)->assertJsonValidationErrors('liquidacion');
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    public function test_un_paso_viejo_al_rechazar_responde_422(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'No', 'step' => 3])->assertStatus(422);
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    // ---------------------------------------------------------------- avisos y eventos

    public function test_aprobar_un_paso_intermedio_avisa_al_siguiente_aprobador_y_a_nadie_mas(): void
    {
        $a = $this->directorSinEmpleado(1);
        $b = $this->aprobadorPorPuesto(2);
        $liq = $this->enRevision();
        // Se envió con el servicio (sin la capa HTTP): todavía no hay avisos de ningún paso.
        $antesA = $this->avisosDe($a)->count();
        $this->assertSame(0, $antesA);
        $this->assertCount(0, $this->avisosDe($b));

        Sanctum::actingAs($a);
        $this->postJson($this->url($liq, 'approve'))->assertOk();

        $this->assertCount(1, $this->avisosDe($b));
        $this->assertStringContainsString($liq->folio, $this->avisosDe($b)->first()->message);
        $this->assertSame('/profile?tab=approvals', $this->avisosDe($b)->first()->action_url);
        $this->assertCount($antesA, $this->avisosDe($a));
        $this->assertCount(0, $this->avisosDe($this->creador));
    }

    public function test_rechazar_avisa_al_creador_con_el_motivo(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'reject'), ['rejection_reason' => 'Falta firma'])->assertOk();

        $avisos = $this->avisosDe($this->creador);
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Falta firma', $avisos->first()->message);
        $this->assertSame('/splendidbyporvenir/administration/abastecimiento/liquidaciones', $avisos->first()->action_url);
    }

    public function test_decidir_desde_la_bandeja_difunde_el_cambio_de_la_liquidacion(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Event::fake([AbastecimientoUpdated::class]);
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'))->assertOk();

        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->data['entidad'] === 'liquidacion'
            && $e->data['id'] === $liq->id
            && $e->data['status'] === 'aprobada');
    }

    public function test_un_fallo_del_aviso_no_rompe_la_decision(): void
    {
        $director = $this->directorSinEmpleado(1);
        $liq = $this->enRevision();
        Event::listen(AbastecimientoUpdated::class, fn () => throw new \RuntimeException('Reverb caído'));
        Sanctum::actingAs($director);

        $this->postJson($this->url($liq, 'approve'))->assertOk();

        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    // ---------------------------------------------------------------- los demás tipos no cambian

    public function test_un_tipo_desconocido_sigue_dando_400_y_sin_empleado_403(): void
    {
        $con = $this->aprobadorConEmpleado(1);
        Sanctum::actingAs($con);
        $this->postJson('/api/pending-approvals/cosa_rara/1/approve')->assertStatus(400);

        $sin = $this->usuarioConAcceso();
        Sanctum::actingAs($sin);
        $this->postJson('/api/pending-approvals/cosa_rara/1/approve')->assertStatus(403);
        $this->postJson('/api/pending-approvals/incident/1/approve')->assertStatus(403);
        $this->getJson('/api/pending-approvals/cosa_rara/1')->assertStatus(404);
    }

    public function test_el_filtro_por_proceso_deja_la_entrada_de_liquidaciones_solo_cuando_se_pide(): void
    {
        $aprobador = $this->aprobadorConEmpleado(1);
        $this->enRevision();
        Sanctum::actingAs($aprobador);

        $otro = $this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes');
        $this->assertNull($this->entrada($otro));

        $mio = $this->getJson('/api/pending-approvals?process=supply_settlements')->assertOk()->json('data.processes');
        $this->assertNotNull($this->entrada($mio));
    }

    public function test_el_total_pendiente_suma_las_liquidaciones(): void
    {
        $director = $this->directorSinEmpleado(1);
        $this->enRevision();
        Sanctum::actingAs($director);

        $this->assertSame(1, $this->getJson('/api/pending-approvals/summary')->json('data.total_pending'));
        $this->assertTrue($this->getJson('/api/pending-approvals/summary')->json('data.can_approve'));
        $this->assertSame(1, $this->getJson('/api/pending-approvals')->json('data.total_pending'));
    }
}
