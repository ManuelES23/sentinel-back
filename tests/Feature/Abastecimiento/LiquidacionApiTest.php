<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Endpoints del flujo de la liquidación (plan 2, Task 7): enviar, aprobar, rechazar y cancelar por HTTP,
 * sus avisos y las acciones (`puede`) que ve cada usuario; más los pendientes menores del borrador (Task 4).
 * El convenio por omisión de los fixtures es de compra directa por caja a $100 en MXN; cada recepción
 * es de 10 cajas ($1,000).
 */
class LiquidacionApiTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures, CreatesVacacionesFixtures;

    private ApprovalProcess $proceso;

    private User $creador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $this->proceso = ApprovalProcess::findByCode(ApprovalProcess::SUPPLY_SETTLEMENTS);
        $this->creador = $this->usuarioCon(['view', 'create', 'edit', 'delete']);
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $ruta = 'liquidaciones', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function usuarioCon(array $permisos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', $permisos);

        return $usuario;
    }

    private function reglaUsuario(int $paso, User $usuario, array $atributos = []): ApprovalFlowStep
    {
        return ApprovalFlowStep::create(array_merge([
            'approval_process_id' => $this->proceso->id, 'enterprise_id' => null, 'step_order' => $paso,
            'approver_type' => 'user', 'user_id' => $usuario->id, 'approval_scope' => 'enterprise',
            'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ], $atributos));
    }

    /** Quien decide en una cadena configurada solo necesita ver la pantalla: sin `liquidaciones.aprobar`. */
    private function aprobadorEnPaso(int $paso, array $atributos = []): User
    {
        $usuario = $this->usuarioCon(['view']);
        $this->reglaUsuario($paso, $usuario, $atributos);

        return $usuario;
    }

    /** Alta por HTTP como el creador: convenio con N recepciones de 10 cajas. @return array{0: Liquidacion, 1: Convenio} */
    private function borrador(int $recepciones = 2, array $convenioOver = []): array
    {
        $convenio = $this->crearConvenio($this->sbp, $convenioOver);
        $ids = [];
        for ($i = 0; $i < $recepciones; $i++) {
            $ids[] = $this->crearRecepcion($convenio, ['fecha' => '2026-06-0'.($i + 2)])->id;
        }

        Sanctum::actingAs($this->creador);
        $id = $this->postJson($this->url(), [
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'recepcion_ids' => $ids,
        ])->assertStatus(201)->json('data.id');

        return [Liquidacion::findOrFail($id), $convenio];
    }

    private function como(User $usuario): void
    {
        Sanctum::actingAs($usuario);
    }

    private function enviar(Liquidacion $liq, ?User $usuario = null)
    {
        $this->como($usuario ?? $this->creador);

        return $this->postJson($this->url("liquidaciones/{$liq->id}/enviar"));
    }

    private function aprobar(Liquidacion $liq, User $usuario, ?int $paso = null, array $extra = [])
    {
        $this->como($usuario);

        return $this->postJson($this->url("liquidaciones/{$liq->id}/aprobar"), ($paso === null ? [] : ['paso' => $paso]) + $extra);
    }

    private function rechazar(Liquidacion $liq, User $usuario, ?int $paso = null, array $extra = [])
    {
        $this->como($usuario);

        return $this->postJson($this->url("liquidaciones/{$liq->id}/rechazar"), ($paso === null ? [] : ['paso' => $paso]) + $extra);
    }

    private function avisosDe(User $usuario)
    {
        return SystemNotification::where('user_id', $usuario->id)->get();
    }

    // ---------------------------------------------------------------- flujo completo

    public function test_flujo_completo_envia_aprueba_cada_paso_y_queda_aprobada(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        [$liq] = $this->borrador();

        $r = $this->enviar($liq)->assertOk();
        $r->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'en_revision')
            ->assertJsonPath('data.paso_actual', 1)
            ->assertJsonPath('data.ronda', 1)
            ->assertJsonPath('data.puede.enviar', false)
            ->assertJsonPath('data.puede.editar', false)
            ->assertJsonPath('data.puede.cancelar', true);
        $this->assertCount(2, $r->json('data.aprobaciones'));
        $this->assertSame(1, $r->json('data.aprobaciones.0.step_order'));

        $this->aprobar($liq, $a, 1, ['comentario' => 'Todo en orden'])->assertOk()
            ->assertJsonPath('data.status', 'en_revision')
            ->assertJsonPath('data.paso_actual', 2);

        $r = $this->aprobar($liq, $b, 2)->assertOk();
        $r->assertJsonPath('data.status', 'aprobada')->assertJsonPath('data.paso_actual', null);
        $this->assertSame('approved', $r->json('data.aprobaciones.1.decision'));

        $liq->refresh();
        $this->assertSame('aprobada', $liq->status);
        $this->assertSame($b->id, $liq->aprobada_by);
        $this->assertSame('Todo en orden', LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('step_order', 1)->value('comment'));
    }

    public function test_enviar_pide_permiso_de_editar_y_solo_aplica_a_borradores(): void
    {
        $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();

        $soloVer = $this->usuarioCon(['view']);
        $this->enviar($liq, $soloVer)->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Abastecimiento.');
        $this->assertSame('borrador', $liq->fresh()->status);

        $this->enviar($liq)->assertOk();
        // Ya en revisión: el reenvío (doble clic) es un 422 estándar en `liquidacion`.
        $this->enviar($liq)->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
        $this->assertSame(1, $liq->fresh()->ronda);
    }

    public function test_enviar_sin_nadie_que_decida_da_422_estandar_y_no_cambia_nada(): void
    {
        // Cadena con un solo paso cuyo único elegible es el propio creador: nadie puede decidirlo.
        $this->reglaUsuario(1, $this->creador);
        [$liq] = $this->borrador();

        $this->enviar($liq)->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);

        $liq->refresh();
        $this->assertSame('borrador', $liq->status);
        $this->assertSame(0, $liq->ronda);
        $this->assertSame(0, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->count());
    }

    public function test_sin_cadena_decide_quien_tiene_el_permiso_de_aprobar(): void
    {
        $conPermiso = $this->usuarioCon(['view', 'aprobar']);
        $sinPermiso = $this->usuarioCon(['view']);
        [$liq] = $this->borrador();

        $this->enviar($liq)->assertOk()->assertJsonPath('data.paso_actual', 1);

        $this->aprobar($liq, $sinPermiso, 1)->assertStatus(403)->assertJsonPath('message', 'No te toca decidir esta liquidación.');
        $this->aprobar($liq, $conPermiso, 1)->assertOk()->assertJsonPath('data.status', 'aprobada');
    }

    // ---------------------------------------------------------------- rechazo

    public function test_rechazar_regresa_a_borrador_con_el_motivo_y_conserva_las_recepciones(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $r = $this->rechazar($liq, $a, 1, ['motivo' => 'Los kilos no cuadran'])->assertOk();
        $r->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.rechazo_motivo', 'Los kilos no cuadran')
            ->assertJsonPath('data.paso_actual', null)
            ->assertJsonPath('data.puede.editar', false);

        $this->assertSame(2, Recepcion::where('liquidacion_id', $liq->id)->count());
        $this->assertSame('rejected', $r->json('data.aprobaciones.0.decision'));

        // El creador puede corregir y volver a enviar: nueva ronda, la anterior queda en `rondas`.
        $this->como($this->creador);
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()
            ->assertJsonPath('data.puede.editar', true)
            ->assertJsonPath('data.puede.enviar', true);
        $r = $this->enviar($liq)->assertOk()->assertJsonPath('data.ronda', 2);
        $this->assertSame(2, $r->json('data.aprobaciones.0.ronda'));
        $this->assertCount(1, $r->json('data.rondas'));
        $this->assertSame(1, $r->json('data.rondas.0.ronda'));
        $this->assertSame('rejected', $r->json('data.rondas.0.aprobaciones.0.decision'));
    }

    public function test_rechazar_exige_motivo_y_respeta_can_reject(): void
    {
        $sinRechazo = $this->usuarioCon(['view']);
        $this->reglaUsuario(1, $sinRechazo, ['can_reject' => false]);
        $otro = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->rechazar($liq, $otro, 1)->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->rechazar($liq, $otro, 1, ['motivo' => '   '])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->assertSame('en_revision', $liq->fresh()->status);

        // Quien solo puede aprobar no rechaza: 403, y `puede` ya se lo dice.
        $this->rechazar($liq, $sinRechazo, 1, ['motivo' => 'No me gusta'])->assertStatus(403);
        $this->como($sinRechazo);
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()
            ->assertJsonPath('data.puede.aprobar', true)
            ->assertJsonPath('data.puede.rechazar', false);
    }

    // ---------------------------------------------------------------- cancelar

    public function test_cancelar_exige_motivo_y_permiso_y_libera_las_recepciones(): void
    {
        $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->como($this->creador);
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"))->assertStatus(422)->assertJsonValidationErrors(['motivo']);

        $sinBorrar = $this->usuarioCon(['view', 'edit']);
        $this->como($sinBorrar);
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Duplicada'])->assertStatus(403);

        $this->como($this->creador);
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Duplicada'])->assertOk()
            ->assertJsonPath('data.status', 'cancelada')
            ->assertJsonPath('data.cancelada_motivo', 'Duplicada')
            ->assertJsonPath('data.puede.cancelar', false);

        $this->assertSame(0, Recepcion::where('liquidacion_id', $liq->id)->count());
        // Ya cancelada: otro intento es 422.
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Otra vez'])->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
    }

    // ---------------------------------------------------------------- autorización

    public function test_quien_no_es_elegible_recibe_403_y_no_cambia_nada(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        $curioso = $this->usuarioCon(['view']);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        foreach ([$curioso, $b, $this->creador] as $intruso) {
            $this->aprobar($liq, $intruso, 1)->assertStatus(403)->assertJsonPath('message', 'No te toca decidir esta liquidación.');
            $this->rechazar($liq, $intruso, 1, ['motivo' => 'x'])->assertStatus(403);
        }

        $liq->refresh();
        $this->assertSame('en_revision', $liq->status);
        $this->assertSame(1, $liq->paso_actual);
        $this->assertSame(0, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('decision', '!=', 'waiting')->count());
        $this->assertNotNull($a);
    }

    public function test_decidir_exige_poder_ver_la_pantalla_de_liquidaciones(): void
    {
        $sinVer = $this->usuarioCon([]);
        $this->reglaUsuario(1, $sinVer);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->aprobar($liq, $sinVer, 1)->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Abastecimiento.');
        $this->rechazar($liq, $sinVer, 1, ['motivo' => 'x'])->assertStatus(403);
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    public function test_liquidacion_de_otra_empresa_es_404(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $convenio = $this->crearConvenio($this->otra);
        $ajena = $this->crearLiquidacion($convenio);

        $this->aprobar($ajena, $a, 1)->assertStatus(404);
        $this->enviar($ajena)->assertStatus(404);
    }

    // ---------------------------------------------------------------- paso esperado

    public function test_paso_es_obligatorio_en_aprobar_y_rechazar(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->aprobar($liq, $a)->assertStatus(422)->assertJsonValidationErrors(['paso']);
        $this->rechazar($liq, $a, null, ['motivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['paso']);
        $this->aprobar($liq, $a, 0)->assertStatus(422)->assertJsonValidationErrors(['paso']);
        $this->assertSame('en_revision', $liq->fresh()->status);
    }

    public function test_paso_viejo_o_doble_clic_da_422_y_no_avanza_la_cadena_dos_veces(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        $this->aprobadorEnPaso(3);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->aprobar($liq, $a, 1)->assertOk()->assertJsonPath('data.paso_actual', 2);

        // Doble clic de A (el paso ya cambió): 422 en `liquidacion`, no 403, y no se decide nada.
        $this->aprobar($liq, $a, 1)->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
        // B tenía a la vista el paso 1: el paso ya cambió.
        $this->aprobar($liq, $b, 1)->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);

        $liq->refresh();
        $this->assertSame(2, $liq->paso_actual);
        $this->assertSame(1, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('decision', 'approved')->count());

        $this->aprobar($liq, $b, 2)->assertOk()->assertJsonPath('data.paso_actual', 3);
    }

    // ---------------------------------------------------------------- avisos

    public function test_el_aviso_de_paso_llega_solo_a_quienes_pueden_decidirlo(): void
    {
        $a1 = $this->aprobadorEnPaso(1);
        $a2 = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        $ajeno = $this->usuarioCon(['view', 'edit']);
        [$liq] = $this->borrador();

        $this->enviar($liq)->assertOk();

        $this->assertCount(1, $this->avisosDe($a1));
        $this->assertCount(1, $this->avisosDe($a2));
        $this->assertCount(0, $this->avisosDe($b));
        $this->assertCount(0, $this->avisosDe($ajeno));
        $this->assertCount(0, $this->avisosDe($this->creador));

        $aviso = $this->avisosDe($a1)->first();
        $this->assertStringContainsString($liq->folio, $aviso->title.' '.$aviso->message);
        $this->assertSame('/profile?tab=approvals', $aviso->action_url);
        $this->assertSame('Ver pendientes', $aviso->action_label);

        // Al avanzar de paso, el aviso llega al siguiente y a nadie más.
        $this->aprobar($liq, $a1, 1)->assertOk();
        $this->assertCount(1, $this->avisosDe($b));
        $this->assertCount(1, $this->avisosDe($a1));
        $this->assertCount(1, $this->avisosDe($a2));

        // La aprobación final no avisa a ningún aprobador más.
        $this->aprobar($liq, $b, 2)->assertOk();
        $this->assertCount(1, $this->avisosDe($b));
    }

    public function test_el_aviso_a_un_aprobador_sin_view_lleva_a_la_bandeja_del_perfil_y_la_pantalla_le_da_403(): void
    {
        $sinVer = $this->usuarioCon([]);
        $this->reglaUsuario(1, $sinVer);
        [$liq] = $this->borrador();

        $this->enviar($liq)->assertOk();

        $avisos = $this->avisosDe($sinVer);
        $this->assertCount(1, $avisos);
        $this->assertSame('/profile?tab=approvals', $avisos->first()->action_url);
        $this->assertSame('Ver pendientes', $avisos->first()->action_label);

        // La pantalla de Liquidaciones sigue cerrada para él.
        $this->como($sinVer);
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(403);
        $this->aprobar($liq, $sinVer, 1)->assertStatus(403);
    }

    public function test_el_aviso_de_rechazo_lleva_al_creador_a_la_pantalla_de_liquidaciones(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->rechazar($liq, $a, 1, ['motivo' => 'Faltan tickets'])->assertOk();

        $this->assertSame('/splendidbyporvenir/administration/abastecimiento/liquidaciones', $this->avisosDe($this->creador)->first()->action_url);
    }

    public function test_quien_solo_tiene_aprobar_y_no_esta_en_la_cadena_recibe_403(): void
    {
        $enCadena = $this->aprobadorEnPaso(1);
        $soloPermiso = $this->usuarioCon(['view', 'aprobar']);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();

        $this->aprobar($liq, $soloPermiso, 1)->assertStatus(403)->assertJsonPath('message', 'No te toca decidir esta liquidación.');
        $this->rechazar($liq, $soloPermiso, 1, ['motivo' => 'x'])->assertStatus(403);
        $this->assertSame('en_revision', $liq->fresh()->status);
        $this->assertCount(0, $this->avisosDe($soloPermiso));

        $this->aprobar($liq, $enCadena, 1)->assertOk()->assertJsonPath('data.status', 'aprobada');
    }

    public function test_el_fallo_del_aviso_de_un_destinatario_no_detiene_a_los_demas(): void
    {
        $a1 = $this->aprobadorEnPaso(1);
        $a2 = $this->aprobadorEnPaso(1);
        $a3 = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();

        // Falla solo el aviso de a2.
        \App\Models\SystemNotification::creating(function ($n) use ($a2) {
            if ((int) $n->user_id === $a2->id) {
                throw new \RuntimeException('Aviso caído para un destinatario');
            }
        });

        $this->enviar($liq)->assertOk()->assertJsonPath('data.status', 'en_revision');

        $this->assertCount(1, $this->avisosDe($a1));
        $this->assertCount(0, $this->avisosDe($a2));
        $this->assertCount(1, $this->avisosDe($a3));
    }
    public function test_el_rechazo_avisa_a_quien_creo_la_liquidacion_y_a_nadie_mas(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();
        $antesB = $this->avisosDe($b)->count();

        $this->rechazar($liq, $a, 1, ['motivo' => 'Faltan tickets'])->assertOk();

        $avisos = $this->avisosDe($this->creador);
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString($liq->folio, $avisos->first()->title.' '.$avisos->first()->message);
        $this->assertStringContainsString('Faltan tickets', $avisos->first()->message);
        $this->assertSame($antesB, $this->avisosDe($b)->count());
    }

    public function test_un_fallo_al_avisar_no_rompe_la_respuesta(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();

        SystemNotification::creating(fn () => throw new \RuntimeException('Servidor de avisos caído'));

        $this->enviar($liq)->assertOk()->assertJsonPath('data.status', 'en_revision');
        $this->rechazar($liq, $a, 1, ['motivo' => 'x'])->assertOk()->assertJsonPath('data.status', 'borrador');

        $this->assertSame('borrador', $liq->fresh()->status);
        $this->assertSame(0, SystemNotification::count());
    }

    // ---------------------------------------------------------------- puede y ficha

    public function test_puede_refleja_al_usuario_que_consulta(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $b = $this->aprobadorEnPaso(2);
        $lector = $this->usuarioCon(['view']);
        [$liq] = $this->borrador();

        $puede = function (User $u) use ($liq) {
            $this->como($u);

            return $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()->json('data.puede');
        };

        // Borrador: edita y envía quien tiene `edit`; nadie decide.
        $this->assertSame(['editar' => true, 'enviar' => true, 'aprobar' => false, 'rechazar' => false, 'cancelar' => true], $puede($this->creador));
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => false], $puede($lector));

        $this->enviar($liq)->assertOk();
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => true], $puede($this->creador));
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => true, 'rechazar' => true, 'cancelar' => false], $puede($a));
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => false], $puede($b));
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => false], $puede($lector));

        // Con el paso 1 aprobado le toca a B, y A ya no.
        $this->aprobar($liq, $a, 1)->assertOk();
        $this->assertTrue($puede($b)['aprobar']);
        $this->assertFalse($puede($a)['aprobar']);
    }

    public function test_aprobada_solo_se_cancela_si_no_tiene_abonos_aplicados(): void
    {
        $aprobador = $this->usuarioCon(['view', 'aprobar']);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();
        $this->aprobar($liq, $aprobador, 1)->assertOk()->assertJsonPath('data.status', 'aprobada');

        $this->como($this->creador);
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()->assertJsonPath('data.puede.cancelar', true);

        $abono = $this->crearAbono(Productor::findOrFail($liq->productor_id), ['monto' => 100]);
        \App\Models\Abastecimiento\AbonoAplicacion::create([
            'abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => 100,
        ]);

        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()->assertJsonPath('data.puede.cancelar', false);
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
    }

    public function test_la_ficha_trae_paso_actual_pasos_de_la_ronda_y_aprobaciones_con_nombre(): void
    {
        $a = $this->aprobadorEnPaso(1);
        $this->aprobadorEnPaso(2);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();
        $this->aprobar($liq, $a, 1, ['comentario' => 'Visto'])->assertOk();

        $this->como($this->creador);
        $d = $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()->json('data');

        $this->assertSame(2, $d['paso_actual']);
        $this->assertSame([], $d['rondas']);
        $this->assertSame([1, 2], array_column($d['aprobaciones'], 'step_order'));
        $this->assertSame(['approved', 'waiting'], array_column($d['aprobaciones'], 'decision'));
        $this->assertSame($a->name, $d['aprobaciones'][0]['decided_by_name']);
        $this->assertSame('Visto', $d['aprobaciones'][0]['comment']);
    }

    // ---------------------------------------------------------------- tiempo real

    public function test_las_acciones_del_flujo_difunden_la_liquidacion_despues_de_confirmar(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        Event::fake([AbastecimientoUpdated::class]);

        $this->enviar($liq)->assertOk();
        $this->aprobar($liq, $a, 1)->assertOk();

        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'liquidacion' && $e->action === 'updated' && $e->data['id'] === $liq->id && $e->data['status'] === 'en_revision',
        );
        Event::assertDispatched(
            AbastecimientoUpdated::class,
            fn ($e) => $e->data['entidad'] === 'liquidacion' && $e->data['id'] === $liq->id && $e->data['status'] === 'aprobada',
        );
    }

    public function test_un_422_no_difunde_nada(): void
    {
        $a = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();
        $this->enviar($liq)->assertOk();
        Event::fake([AbastecimientoUpdated::class]);

        $this->aprobar($liq, $a, 7)->assertStatus(422);

        Event::assertNotDispatched(AbastecimientoUpdated::class);
    }

    // ---------------------------------------------------------------- menores diferidos de la Task 4

    public function test_el_listado_trae_el_porcentaje_de_rezaga_del_convenio(): void
    {
        [$liq] = $this->borrador(1, ['porcentaje_rezaga' => 5]);

        $this->como($this->creador);
        $fila = $this->getJson($this->url())->assertOk()->json('data.0');
        $this->assertSame($liq->id, $fila['id']);
        $this->assertEquals(5, $fila['convenio']['porcentaje_rezaga']);
    }

    /** Arreglo final I1: el canal del módulo lo recibe cualquiera con acceso al módulo; el evento no lleva dinero. */
    public function test_los_eventos_de_liquidacion_no_llevan_montos_ni_notas(): void
    {
        $a = $this->aprobadorEnPaso(1);
        Event::fake([AbastecimientoUpdated::class]);
        [$liq] = $this->borrador(1, ['porcentaje_rezaga' => 5]);
        $this->enviar($liq)->assertOk();
        $this->aprobar($liq, $a, 1)->assertOk();

        $eventos = Event::dispatched(AbastecimientoUpdated::class)->map(fn ($x) => $x[0])
            ->filter(fn ($e) => $e->data['entidad'] === 'liquidacion');
        $this->assertGreaterThanOrEqual(3, $eventos->count());
        foreach ($eventos as $e) {
            $this->assertSame(['convenio_id', 'entidad', 'folio', 'id', 'moneda', 'productor_id', 'status'], collect($e->data)->keys()->sort()->values()->all());
            foreach (['monto_final', 'monto_calculado', 'monto_ajustado', 'motivo_ajuste', 'bruto', 'descuento', 'notas', 'convenio', 'productor'] as $clave) {
                $this->assertArrayNotHasKey($clave, $e->data);
            }
        }
    }

    public function test_editar_el_borrador_con_otro_convenio_libera_las_recepciones_viejas_y_recalcula(): void
    {
        [$liq, $viejo] = $this->borrador(2);
        $recViejas = Recepcion::where('liquidacion_id', $liq->id)->pluck('id')->all();

        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro productor', 'tipo' => 'productor', 'is_active' => true]);
        $nuevo = $this->crearConvenio($this->sbp, ['productor_id' => $otroProductor->id, 'moneda' => 'USD', 'precio' => 50]);
        $recNueva = $this->crearRecepcion($nuevo, ['fecha' => '2026-06-03']);

        $this->como($this->creador);
        $this->putJson($this->url("liquidaciones/{$liq->id}"), [
            'convenio_id' => $nuevo->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'recepcion_ids' => [$recNueva->id],
        ])->assertOk()
            ->assertJsonPath('data.convenio_id', $nuevo->id)
            ->assertJsonPath('data.productor_id', $otroProductor->id)
            ->assertJsonPath('data.moneda', 'USD')
            ->assertJsonPath('data.monto_final', 500);

        foreach ($recViejas as $id) {
            $this->assertNull(Recepcion::find($id)->liquidacion_id, 'La recepción vieja debe quedar libre.');
        }
        $this->assertSame($liq->id, $recNueva->fresh()->liquidacion_id);
        $this->assertSame(1, \App\Models\Abastecimiento\LiquidacionDetalle::where('liquidacion_id', $liq->id)->count());
        $this->assertNotNull($viejo);
    }

    public function test_una_liquidacion_de_otra_empresa_en_previa_y_disponibles_da_422(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $rec = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']);
        $ajena = $this->crearLiquidacion($this->crearConvenio($this->otra));

        $this->como($this->creador);
        $this->postJson($this->url('liquidaciones/previa'), [
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15',
            'recepcion_ids' => [$rec->id], 'liquidacion_id' => $ajena->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['liquidacion_id']);

        $this->getJson($this->url('liquidaciones/recepciones-disponibles').'?'.http_build_query([
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'liquidacion_id' => $ajena->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['liquidacion_id']);
    }

    public function test_editar_o_borrar_una_liquidacion_cancelada_da_422(): void
    {
        [$liq, $convenio] = $this->borrador(1);
        $rec = Recepcion::where('liquidacion_id', $liq->id)->firstOrFail();

        $this->como($this->creador);
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Error'])->assertOk();

        $this->putJson($this->url("liquidaciones/{$liq->id}"), [
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15', 'recepcion_ids' => [$rec->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
        $this->deleteJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);

        $this->assertSame('cancelada', $liq->fresh()->status);
        $this->assertNull($rec->fresh()->liquidacion_id);
    }

    // ---------------------------------------------------------------- arreglo final I2: separación de funciones

    public function test_quien_edito_un_borrador_ajeno_no_lo_aprueba_aunque_otro_lo_envie(): void
    {
        $editor = $this->usuarioCon(['view', 'edit']);
        $this->reglaUsuario(1, $editor);
        $otro = $this->aprobadorEnPaso(1);
        [$liq, $convenio] = $this->borrador(2);
        $ids = Recepcion::where('liquidacion_id', $liq->id)->orderBy('id')->pluck('id')->all();

        $this->como($editor);
        $this->putJson($this->url("liquidaciones/{$liq->id}"), [
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-15',
            'recepcion_ids' => [$ids[0]], 'monto_ajustado' => 900, 'motivo_ajuste' => 'Descuento acordado',
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$this->creador->id, $editor->id], $liq->fresh()->intervinieron);

        $this->enviar($liq)->assertOk();
        $this->aprobar($liq, $editor, 1)->assertStatus(403);
        $this->assertSame('en_revision', $liq->fresh()->status);

        // El servicio dice lo mismo (aunque alguien llegara sin pasar por el controlador).
        try {
            DB::transaction(fn () => FlujoLiquidacion::aprobar($liq->fresh(), $editor, null, 1));
            $this->fail('Quien editó no debe poder aprobar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('liquidacion', $e->errors());
        }

        // Quien no hizo nada con ella sí la aprueba.
        $this->aprobar($liq, $otro, 1)->assertOk()->assertJsonPath('data.status', 'aprobada');
    }

    public function test_quien_envio_la_ronda_1_no_aprueba_la_ronda_2_aunque_otro_la_reenvie(): void
    {
        $emisor = $this->usuarioCon(['view', 'edit']);
        $this->reglaUsuario(1, $emisor);
        $otro = $this->aprobadorEnPaso(1);
        [$liq] = $this->borrador();

        $this->enviar($liq, $emisor)->assertOk();
        $this->rechazar($liq, $otro, 1, ['motivo' => 'Falta una recepción'])->assertOk();
        // Rechazar conserva a quienes intervinieron.
        $this->assertEqualsCanonicalizing([$this->creador->id, $emisor->id], $liq->fresh()->intervinieron);

        $this->enviar($liq)->assertOk();
        $this->assertSame($this->creador->id, $liq->fresh()->enviada_by);
        $this->assertSame(2, $liq->fresh()->ronda);

        $this->aprobar($liq, $emisor, 1)->assertStatus(403);
        $this->assertSame('en_revision', $liq->fresh()->status);
        $this->aprobar($liq, $otro, 1)->assertOk()->assertJsonPath('data.status', 'aprobada');
    }

    public function test_si_el_unico_elegible_ya_intervino_no_se_puede_enviar(): void
    {
        $editor = $this->usuarioCon(['view', 'edit']);
        $this->reglaUsuario(1, $editor);
        [$liq] = $this->borrador();

        // Lo envía quien solo está en la cadena: se excluye a sí mismo y el paso queda sin nadie.
        $this->enviar($liq, $editor)->assertStatus(422)->assertJsonValidationErrors('liquidacion');
        $this->assertSame('borrador', $liq->fresh()->status);
        $this->assertEqualsCanonicalizing([$this->creador->id], $liq->fresh()->intervinieron);
    }
}
