<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\User;
use App\Services\Abastecimiento\AprobadorLiquidaciones;
use App\Services\Abastecimiento\CadenaLiquidacion;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Cadena de revisión y flujo de la liquidación (plan 2, Task 6): enviar, aprobar, rechazar y cancelar.
 * El convenio por omisión de los fixtures es de compra directa por caja a $100 en MXN; cada recepción
 * es de 10 cajas, así que dos recepciones suman $2,000.
 */
class LiquidacionFlujoTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures, CreatesVacacionesFixtures;

    private ApprovalProcess $proceso;

    private User $creador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $this->proceso = ApprovalProcess::findByCode(ApprovalProcess::SUPPLY_SETTLEMENTS);
        $this->assertNotNull($this->proceso, 'El seeder del módulo debe dejar el proceso supply_settlements.');

        $this->creador = $this->usuarioCon([]);
    }

    // ---------------------------------------------------------------- ayudas

    /** Usuario sin rol de administrador con acceso a SBP y los permisos de liquidaciones indicados. */
    private function usuarioCon(array $permisos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', $permisos);

        return $usuario;
    }

    private function regla(int $paso, string $tipo, array $atributos = []): ApprovalFlowStep
    {
        return ApprovalFlowStep::create(array_merge([
            'approval_process_id' => $this->proceso->id, 'enterprise_id' => null, 'step_order' => $paso,
            'approver_type' => $tipo, 'approval_scope' => 'enterprise', 'can_approve' => true, 'can_reject' => true, 'is_active' => true,
        ], $atributos));
    }

    private function reglaUsuario(int $paso, User $usuario, array $atributos = []): ApprovalFlowStep
    {
        return $this->regla($paso, 'user', ['user_id' => $usuario->id] + $atributos);
    }

    /** Puesto que puede aprobar con un empleado activo de SBP ligado a un usuario; devuelve [regla, usuario]. */
    private function reglaPuesto(int $paso, string $nombre = 'Gerente de compras', array $atributosEmpleado = []): array
    {
        $puesto = $this->puesto($this->sbp, $nombre, 3, true);
        $empleado = $this->empleadoConUsuario($this->sbp, ['position_id' => $puesto->id] + $atributosEmpleado);
        $this->darAcceso($empleado->user);

        return [$this->regla($paso, 'position', ['position_id' => $puesto->id]), $empleado->user, $empleado];
    }

    /** Borrador del convenio con N recepciones de 10 cajas ya ligadas, con su detalle (como lo deja el alta). */
    private function borrador(?User $creador = null, int $recepciones = 2, array $convenioOver = []): Liquidacion
    {
        $convenio = $this->crearConvenio($this->sbp, $convenioOver);
        $liq = $this->crearLiquidacion($convenio, ['created_by' => ($creador ?? $this->creador)->id]);

        for ($i = 0; $i < $recepciones; $i++) {
            $r = $this->crearRecepcion($convenio, ['fecha' => '2026-06-0'.($i + 2), 'liquidacion_id' => $liq->id]);
            LiquidacionDetalle::create([
                'liquidacion_id' => $liq->id, 'recepcion_id' => $r->id, 'cantidad' => 10, 'kilos' => 0, 'rezaga_kg' => 0,
                'precio' => 100, 'porcentaje_productor' => 100, 'subtotal' => 1000, 'descuento' => 0, 'a_pagar' => 1000,
            ]);
        }
        $liq->update(['cantidad' => 10 * $recepciones, 'bruto' => 1000 * $recepciones, 'monto_calculado' => 1000 * $recepciones, 'monto_final' => 1000 * $recepciones]);

        return $liq->fresh();
    }

    private function enviar(Liquidacion $liq, ?User $usuario = null): Liquidacion
    {
        return DB::transaction(fn () => FlujoLiquidacion::enviar($liq, $usuario ?? $this->creador));
    }

    /** El paso que quien decide ve como actual (el de la base ahora mismo); pasa explícito el que se quiera simular. */
    private function pasoVigente(Liquidacion $liq): int
    {
        return (int) $liq->fresh()->paso_actual;
    }

    /** Acceso activo de un usuario a SBP (como EmpresaDeLaRuta lo exige), sin permisos de liquidaciones. */
    private function darAcceso(User $usuario, $empresa = null): User
    {
        \App\Models\UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $usuario->id, 'enterprise_id' => ($empresa ?? $this->sbp)->id],
            ['is_active' => true, 'granted_at' => now()],
        );

        return $usuario;
    }

    private function aprobar(Liquidacion $liq, User $usuario, ?string $comentario = null, ?int $pasoEsperado = null): Liquidacion
    {
        $pasoEsperado ??= $this->pasoVigente($liq);

        return DB::transaction(fn () => FlujoLiquidacion::aprobar($liq, $usuario, $comentario, $pasoEsperado));
    }

    private function rechazar(Liquidacion $liq, User $usuario, string $motivo = 'Los kilos no cuadran', ?int $pasoEsperado = null): Liquidacion
    {
        $pasoEsperado ??= $this->pasoVigente($liq);

        return DB::transaction(fn () => FlujoLiquidacion::rechazar($liq, $usuario, $motivo, $pasoEsperado));
    }

    private function cancelar(Liquidacion $liq, User $usuario, string $motivo = 'Se capturó dos veces'): Liquidacion
    {
        return DB::transaction(fn () => FlujoLiquidacion::cancelar($liq, $usuario, $motivo));
    }

    /** Ejecuta la acción y comprueba que da 422 con el mensaje en la clave indicada. */
    private function assertInvalida(string $clave, callable $accion, ?string $contiene = null): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un 422 en «{$clave}».");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors(), 'Errores: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            if ($contiene !== null) {
                $this->assertStringContainsString($contiene, $e->errors()[$clave][0]);
            }
        }
    }

    /** @return array<int, string> decisión de cada paso de una ronda, por número de paso */
    private function decisiones(Liquidacion $liq, int $ronda): array
    {
        return LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('ronda', $ronda)
            ->orderBy('step_order')->pluck('decision', 'step_order')->all();
    }

    private function recepcionesLigadas(Liquidacion $liq): int
    {
        return Recepcion::where('liquidacion_id', $liq->id)->count();
    }

    // ---------------------------------------------------------------- cadena en serie

    public function test_cadena_de_dos_pasos_en_serie_llega_a_aprobada(): void
    {
        $a = $this->usuarioCon([]);
        [, $b] = $this->reglaPuesto(2);
        $this->reglaUsuario(1, $a);

        $liq = $this->enviar($this->borrador());

        $this->assertSame('en_revision', $liq->status);
        $this->assertSame(1, $liq->ronda);
        $this->assertSame(1, $liq->paso_actual);
        $this->assertSame($this->creador->id, $liq->enviada_by);
        $this->assertNotNull($liq->enviada_at);
        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $this->decisiones($liq, 1));
        $paso1 = CadenaLiquidacion::pasoActual($liq);
        $this->assertSame(1, $paso1->step_order);
        $this->assertSame(['Usuario: '.$a->name], [$paso1->description]);

        // El del puesto no puede saltarse el paso 1, ni el usuario A decidir el paso 2 antes.
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $b));

        $liq = $this->aprobar($liq, $a, '  De acuerdo  ');
        $this->assertSame('en_revision', $liq->status);
        $this->assertSame(2, $liq->paso_actual);
        $primero = LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('step_order', 1)->first();
        $this->assertSame('approved', $primero->decision);
        $this->assertSame($a->id, $primero->decided_by);
        $this->assertSame('De acuerdo', $primero->comment);
        $this->assertNotNull($primero->decided_at);

        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $a));

        $liq = $this->aprobar($liq, $b);
        $this->assertSame('aprobada', $liq->status);
        $this->assertNull($liq->paso_actual);
        $this->assertSame($b->id, $liq->aprobada_by);
        $this->assertNotNull($liq->aprobada_at);
        $this->assertSame([1 => 'approved', 2 => 'approved'], $this->decisiones($liq, 1));
        $this->assertNull(CadenaLiquidacion::pasoActual($liq));
    }

    public function test_la_cadena_se_congela_al_enviar_y_usa_solo_pasos_de_la_empresa_o_globales(): void
    {
        $global = $this->usuarioCon([]);
        $deSbp = $this->usuarioCon([]);
        $deOtra = $this->usuarioCon([]);
        $this->reglaUsuario(1, $global);
        $this->reglaUsuario(2, $deSbp, ['enterprise_id' => $this->sbp->id]);
        $this->reglaUsuario(3, $deOtra, ['enterprise_id' => $this->otra->id]);
        $this->reglaUsuario(4, $this->usuarioCon([]), ['is_active' => false]);

        $liq = $this->enviar($this->borrador());

        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $this->decisiones($liq, 1));

        // Una regla nueva después del envío no cambia la cadena ya congelada.
        $this->reglaUsuario(3, $this->usuarioCon([]), ['enterprise_id' => $this->sbp->id]);
        $liq = $this->aprobar($liq, $global);
        $liq = $this->aprobar($liq, $deSbp);
        $this->assertSame('aprobada', $liq->status);
    }

    public function test_los_pasos_se_numeran_de_1_a_n_aunque_la_configuracion_salte_numeros(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $this->reglaUsuario(5, $a);
        $this->reglaUsuario(9, $b);

        $liq = $this->enviar($this->borrador());

        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $this->decisiones($liq, 1));
        $liq = $this->aprobar($liq, $a);
        $this->assertSame(2, $liq->paso_actual);
    }

    // ---------------------------------------------------------------- alternativas

    public function test_pasos_con_el_mismo_numero_son_alternativas_y_cualquiera_decide(): void
    {
        $a = $this->usuarioCon([]);
        [, $b] = $this->reglaPuesto(1);
        $this->reglaUsuario(1, $a);

        $liq = $this->enviar($this->borrador());

        $this->assertSame([1 => 'waiting'], $this->decisiones($liq, 1));
        $paso = CadenaLiquidacion::pasoActual($liq);
        $this->assertCount(2, $paso->rules);
        $this->assertStringContainsString(' o ', $paso->description);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], AprobadorLiquidaciones::elegibles($liq, $paso)->pluck('id')->all());

        $liq = $this->aprobar($liq, $b);
        $this->assertSame('aprobada', $liq->status);
        $this->assertSame($b->id, $liq->aprobada_by);

        // La otra alternativa sirve igual en otra liquidación.
        $otra = $this->enviar($this->borrador());
        $this->assertSame('aprobada', $this->aprobar($otra, $a)->status);
    }

    public function test_puede_rechazar_solo_si_la_regla_lo_permite(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a, ['can_reject' => false]);
        $liq = $this->enviar($this->borrador());

        $this->assertInvalida('liquidacion', fn () => $this->rechazar($liq, $a));
        $this->assertSame('aprobada', $this->aprobar($liq, $a)->status);
    }

    // ---------------------------------------------------------------- sin pasos configurados

    public function test_sin_pasos_configurados_decide_quien_tenga_el_permiso_de_aprobar(): void
    {
        $aprobador = $this->usuarioCon(['aprobar']);
        $sinPermiso = $this->usuarioCon(['view', 'create']);
        $admin = User::factory()->create(['role' => 'admin']);

        $liq = $this->enviar($this->borrador());

        $this->assertSame('en_revision', $liq->status);
        $this->assertSame([1 => 'waiting'], $this->decisiones($liq, 1));
        $paso = CadenaLiquidacion::pasoActual($liq);
        $this->assertSame('Quien tenga el permiso de aprobar', $paso->description);
        $this->assertSame([], $paso->rules);
        $this->assertEqualsCanonicalizing([$aprobador->id, $admin->id], AprobadorLiquidaciones::elegibles($liq, $paso)->pluck('id')->all());

        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $sinPermiso));
        $this->assertSame('en_revision', $liq->fresh()->status);

        $this->assertSame('aprobada', $this->aprobar($liq, $aprobador)->status);

        // El admin del sistema también decide sin tener la concesión.
        $otra = $this->enviar($this->borrador());
        $this->assertSame('aprobada', $this->aprobar($otra, $admin)->status);
    }

    public function test_un_proceso_apagado_o_sin_pasos_activos_equivale_a_sin_cadena(): void
    {
        $this->reglaUsuario(1, $this->usuarioCon([]), ['is_active' => false]);
        $aprobador = $this->usuarioCon(['aprobar']);

        $liq = $this->enviar($this->borrador());
        $this->assertSame([], CadenaLiquidacion::pasoActual($liq)->rules);
        $this->assertSame('aprobada', $this->aprobar($liq, $aprobador)->status);

        $this->proceso->update(['is_active' => false]);
        $otra = $this->enviar($this->borrador());
        $this->assertSame([], CadenaLiquidacion::pasoActual($otra)->rules);
    }

    // ---------------------------------------------------------------- creador, enviador y pasos repetidos

    public function test_quien_creo_o_envio_no_aprueba_aunque_este_en_la_cadena(): void
    {
        $quienEnvia = $this->usuarioCon([]);
        $this->reglaUsuario(1, $this->creador);
        $this->reglaUsuario(1, $quienEnvia);
        $this->reglaUsuario(1, $this->usuarioCon([]));

        $liq = $this->enviar($this->borrador(), $quienEnvia);

        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $this->creador), 'creó, editó o envió');
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $quienEnvia), 'creó, editó o envió');
        $this->assertInvalida('liquidacion', fn () => $this->rechazar($liq, $quienEnvia));
        $this->assertSame('en_revision', $liq->fresh()->status);
        $this->assertSame([1 => 'waiting'], $this->decisiones($liq, 1));

        $paso = CadenaLiquidacion::pasoActual($liq);
        $elegibles = AprobadorLiquidaciones::elegibles($liq, $paso)->pluck('id')->all();
        $this->assertNotContains($this->creador->id, $elegibles);
        $this->assertNotContains($quienEnvia->id, $elegibles);
        $this->assertCount(1, $elegibles);
    }

    public function test_quien_creo_con_el_permiso_de_aprobar_no_decide_el_paso_sin_cadena(): void
    {
        $creadorAprobador = $this->usuarioCon(['aprobar']);
        $otro = $this->usuarioCon(['aprobar']);

        $liq = $this->enviar($this->borrador($creadorAprobador), $this->usuarioCon([]));

        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $creadorAprobador));
        $this->assertSame('aprobada', $this->aprobar($liq, $otro)->status);
    }

    public function test_nadie_decide_dos_pasos_de_la_misma_liquidacion(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $a);
        $this->reglaUsuario(2, $b);

        $liq = $this->enviar($this->borrador());
        $liq = $this->aprobar($liq, $a);

        $paso2 = CadenaLiquidacion::pasoActual($liq);
        $this->assertSame([$b->id], AprobadorLiquidaciones::elegibles($liq, $paso2)->pluck('id')->all());
        $this->assertFalse(AprobadorLiquidaciones::puedeDecidir($a, $liq, $paso2));
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $a), 'otro paso');
        $this->assertSame(2, $liq->fresh()->paso_actual);

        $this->assertSame('aprobada', $this->aprobar($liq, $b)->status);
    }

    // ---------------------------------------------------------------- enviar: validaciones

    public function test_paso_sin_elegibles_da_422_al_enviar_y_no_cambia_nada(): void
    {
        $this->reglaUsuario(1, $this->usuarioCon([]));
        // Puesto sin ningún empleado con usuario: el paso 2 queda sin nadie.
        $puesto = $this->puesto($this->sbp, 'Gerente fantasma', 3, true);
        $this->regla(2, 'position', ['position_id' => $puesto->id]);
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq), 'paso 2');

        $liq->refresh();
        $this->assertSame('borrador', $liq->status);
        $this->assertSame(0, $liq->ronda);
        $this->assertNull($liq->enviada_at);
        $this->assertNull($liq->paso_actual);
        $this->assertSame(0, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->count());
        $this->assertSame(2, $this->recepcionesLigadas($liq));
    }

    public function test_el_unico_elegible_siendo_el_creador_o_quien_envia_deja_el_paso_sin_elegibles(): void
    {
        $this->reglaUsuario(1, $this->creador);
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq, $this->creador));
        $this->assertSame('borrador', $liq->fresh()->status);

        $otro = $this->usuarioCon([]);
        $this->assertSame('borrador', $liq->fresh()->status);
        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq, $otro));
    }

    public function test_sin_cadena_y_sin_nadie_con_permiso_de_aprobar_no_se_puede_enviar(): void
    {
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq));
        $this->assertSame('borrador', $liq->fresh()->status);
    }

    public function test_puesto_sin_empleado_activo_con_usuario_en_la_empresa_no_cuenta_como_elegible(): void
    {
        $puesto = $this->puesto($this->sbp, 'Gerente', 3, true);
        $this->regla(1, 'position', ['position_id' => $puesto->id]);

        $baja = $this->empleadoConUsuario($this->sbp, ['position_id' => $puesto->id, 'status' => 'terminated']); $this->darAcceso($baja->user);
        $this->empleadoConUsuario($this->otra, ['position_id' => $puesto->id]);
        $this->empleado($this->sbp, ['position_id' => $puesto->id]); // sin usuario
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq));

        // El puesto deja de poder aprobar: tampoco cuenta.
        $activo = $this->empleadoConUsuario($this->sbp, ['position_id' => $puesto->id]); $this->darAcceso($activo->user);
        $puesto->update(['can_approve' => false]);
        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq));

        $puesto->update(['can_approve' => true]);
        $liq = $this->enviar($liq);
        $paso = CadenaLiquidacion::pasoActual($liq);
        $this->assertSame([$activo->user_id], AprobadorLiquidaciones::elegibles($liq, $paso)->pluck('id')->all());
        $this->assertFalse(AprobadorLiquidaciones::puedeDecidir($baja->user, $liq, $paso));
    }

    public function test_dos_pasos_que_solo_puede_decidir_la_misma_persona_no_se_pueden_enviar(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $a);
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq), 'misma persona');
        $this->assertSame('borrador', $liq->fresh()->status);
    }

    public function test_enviar_exige_monto_final_mayor_a_cero(): void
    {
        $this->usuarioCon(['aprobar']);

        // Sin ajuste: sin precio no hay monto; con ajuste en cero el error va al monto ajustado.
        $liq = $this->borrador(null, 1);
        $liq->update(['monto_ajustado' => 0, 'motivo_ajuste' => 'Condonado']);
        $this->assertInvalida('monto_ajustado', fn () => $this->enviar($liq));
        $this->assertSame('borrador', $liq->fresh()->status);

        $conPrecioCero = $this->borrador(null, 1, ['precio' => 0.0001]);
        $this->assertInvalida('recepcion_ids', fn () => $this->enviar($conPrecioCero));
        $this->assertSame('borrador', $conPrecioCero->fresh()->status);
    }

    public function test_enviar_recalcula_y_congela_totales_y_detalle(): void
    {
        $this->usuarioCon(['aprobar']);
        $liq = $this->borrador(null, 2);
        // El precio cambió desde que se guardó el borrador: al enviar se recalcula con el vigente.
        Convenio::findOrFail($liq->convenio_id)->precios()->update(['precio' => 120]);
        $liq->update(['monto_calculado' => 1, 'monto_final' => 1, 'bruto' => 1]);

        $liq = $this->enviar($liq);

        $this->assertEquals(2400, $liq->monto_final);
        $this->assertEquals(2400, $liq->monto_calculado);
        $this->assertEquals(2400, $liq->bruto);
        $this->assertEquals(20, $liq->cantidad);
        $detalles = LiquidacionDetalle::where('liquidacion_id', $liq->id)->orderBy('id')->get();
        $this->assertCount(2, $detalles);
        $this->assertEquals([120, 120], $detalles->pluck('precio')->map(fn ($p) => (float) $p)->all());
        $this->assertEquals([1200, 1200], $detalles->pluck('a_pagar')->map(fn ($p) => (float) $p)->all());
        $this->assertSame(2, $this->recepcionesLigadas($liq));
    }

    public function test_enviar_respeta_el_monto_ajustado_del_borrador(): void
    {
        $this->usuarioCon(['aprobar']);
        $liq = $this->borrador(null, 2);
        $liq->update(['monto_ajustado' => 1500, 'motivo_ajuste' => 'Acuerdo en campo']);

        $liq = $this->enviar($liq);

        $this->assertEquals(2000, $liq->monto_calculado);
        $this->assertEquals(1500, $liq->monto_ajustado);
        $this->assertEquals(1500, $liq->monto_final);
        $this->assertSame('Acuerdo en campo', $liq->motivo_ajuste);
    }

    public function test_enviar_sin_precio_vigente_usa_el_422_de_la_calculadora(): void
    {
        $this->usuarioCon(['aprobar']);
        $liq = $this->borrador(null, 1);
        Convenio::findOrFail($liq->convenio_id)->precios()->delete();

        $this->assertInvalida('recepcion_ids', fn () => $this->enviar($liq), 'precio vigente');
        $this->assertSame('borrador', $liq->fresh()->status);
    }

    public function test_solo_se_puede_enviar_un_borrador(): void
    {
        $this->usuarioCon(['aprobar']);
        $liq = $this->enviar($this->borrador());

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq));
        $this->assertSame(1, $liq->fresh()->ronda);
        $this->assertSame(1, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->count());

        foreach (['aprobada', 'pagada', 'cancelada'] as $estado) {
            $otra = $this->borrador();
            $otra->update(['status' => $estado]);
            $this->assertInvalida('liquidacion', fn () => $this->enviar($otra));
        }
    }

    // ---------------------------------------------------------------- rechazo y nueva ronda

    public function test_rechazo_regresa_a_borrador_con_motivo_y_un_segundo_envio_crea_la_ronda_2(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $b);

        $liq = $this->enviar($this->borrador());
        $liq = $this->aprobar($liq, $a);

        $liq = $this->rechazar($liq, $b, '  Falta el ticket de la báscula  ');

        $this->assertSame('borrador', $liq->status);
        $this->assertSame('Falta el ticket de la báscula', $liq->rechazo_motivo);
        $this->assertSame($b->id, $liq->rechazada_by);
        $this->assertNotNull($liq->rechazada_at);
        $this->assertNull($liq->paso_actual);
        $this->assertSame(1, $liq->ronda);
        $this->assertSame(2, $this->recepcionesLigadas($liq), 'Rechazar no libera las recepciones');
        $this->assertSame([1 => 'approved', 2 => 'rejected'], $this->decisiones($liq, 1));
        $rechazado = LiquidacionAprobacion::where('liquidacion_id', $liq->id)->where('step_order', 2)->first();
        $this->assertSame('Falta el ticket de la báscula', $rechazado->comment);
        $this->assertSame($b->id, $rechazado->decided_by);
        $this->assertNull(CadenaLiquidacion::pasoActual($liq));

        // Quien creó puede volver a enviarla; la ronda 1 queda como estaba y nace la 2.
        $liq = $this->enviar($liq);
        $this->assertSame('en_revision', $liq->status);
        $this->assertSame(2, $liq->ronda);
        $this->assertSame(1, $liq->paso_actual);
        $this->assertSame([1 => 'approved', 2 => 'rejected'], $this->decisiones($liq, 1));
        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $this->decisiones($liq, 2));
        $this->assertSame(4, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->count());

        // En la ronda nueva nadie arrastra lo decidido antes: A y B pueden volver a decidir sus pasos.
        $liq = $this->aprobar($liq, $a);
        $liq = $this->aprobar($liq, $b);
        $this->assertSame('aprobada', $liq->status);
        $this->assertSame([1 => 'approved', 2 => 'rejected'], $this->decisiones($liq, 1));
        $this->assertSame([1 => 'approved', 2 => 'approved'], $this->decisiones($liq, 2));
    }

    public function test_el_rechazo_cierra_los_pasos_pendientes_de_la_ronda(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $this->usuarioCon([]));

        $liq = $this->enviar($this->borrador());
        $liq = $this->rechazar($liq, $a);

        $this->assertSame([1 => 'rejected', 2 => 'skipped'], $this->decisiones($liq, 1));
    }

    public function test_rechazar_exige_motivo_y_estado_en_revision(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->enviar($this->borrador());

        $this->assertInvalida('motivo', fn () => $this->rechazar($liq, $a, '   '));
        $this->assertInvalida('motivo', fn () => $this->rechazar($liq, $a, str_repeat('x', 501)));
        $this->assertSame('en_revision', $liq->fresh()->status);

        $liq = $this->rechazar($liq, $a);
        $this->assertInvalida('liquidacion', fn () => $this->rechazar($liq, $a));
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $a));
    }

    public function test_el_comentario_de_aprobacion_se_limita_a_500_caracteres(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->enviar($this->borrador());

        $this->assertInvalida('comentario', fn () => $this->aprobar($liq, $a, str_repeat('x', 501)));
        $this->assertSame('en_revision', $liq->fresh()->status);

        $liq = $this->aprobar($liq, $a, '   ');
        $this->assertNull(LiquidacionAprobacion::where('liquidacion_id', $liq->id)->value('comment'));
    }

    // ---------------------------------------------------------------- doble clic y dos aprobadores

    public function test_aprobar_dos_veces_seguidas_el_mismo_paso_avanza_la_cadena_una_sola_vez(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $b);
        $enviada = $this->enviar($this->borrador());

        // Los dos clics llegan con la misma copia vieja de la liquidación (paso 1).
        $this->aprobar($enviada, $a, null, 1);
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($enviada, $a, null, 1));

        $liq = $enviada->fresh();
        $this->assertSame(2, $liq->paso_actual);
        $this->assertSame('en_revision', $liq->status);
        $this->assertSame([1 => 'approved', 2 => 'waiting'], $this->decisiones($liq, 1));
    }

    public function test_dos_aprobadores_del_mismo_paso_el_segundo_recibe_422_con_el_paso_esperado(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $c = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(1, $b);
        $this->reglaUsuario(2, $b);
        $this->reglaUsuario(2, $c);
        $enviada = $this->enviar($this->borrador());

        $this->aprobar($enviada, $a, null, 1);

        // B también tenía abierto el paso 1: aunque podría decidir el paso 2, no es lo que pidió aprobar.
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($enviada, $b, null, 1), 'cambió de paso');
        $this->assertInvalida('liquidacion', fn () => $this->rechazar($enviada, $b, 'No', 1), 'cambió de paso');

        $liq = $enviada->fresh();
        $this->assertSame(2, $liq->paso_actual);
        $this->assertSame([1 => 'approved', 2 => 'waiting'], $this->decisiones($liq, 1));

        $this->assertSame('aprobada', $this->aprobar($enviada, $c, null, 2)->status);
    }

    public function test_aprobar_una_liquidacion_que_ya_no_esta_en_revision_da_422(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);

        $borrador = $this->borrador();
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($borrador, $a));

        $liq = $this->enviar($borrador);
        $this->aprobar($liq, $a);
        // La copia vieja sigue diciendo en_revision; el servicio relee y ve aprobada.
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $a));
        $this->assertSame('aprobada', $liq->fresh()->status);
    }

    // ---------------------------------------------------------------- anticipos al aprobar

    public function test_al_aprobar_se_aplican_los_anticipos_del_productor_y_puede_quedar_pagada(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->borrador(null, 2);
        $anticipo = $this->crearAbono(\App\Models\Abastecimiento\Productor::findOrFail($liq->productor_id), ['monto' => 2000]);
        $liq = $this->enviar($liq);

        $this->assertSame(0, AbonoAplicacion::count());
        $liq = $this->aprobar($liq, $a);

        $this->assertSame('pagada', $liq->status);
        $this->assertSame($a->id, $liq->aprobada_by);
        $this->assertNotNull($liq->aprobada_at);
        $this->assertSame(1, AbonoAplicacion::where('abono_id', $anticipo->id)->where('liquidacion_id', $liq->id)->count());
        $this->assertEquals(2000, AbonoAplicacion::where('liquidacion_id', $liq->id)->sum('monto'));
    }

    public function test_un_anticipo_parcial_deja_la_liquidacion_aprobada(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->borrador(null, 2);
        $this->crearAbono(\App\Models\Abastecimiento\Productor::findOrFail($liq->productor_id), ['monto' => 500]);
        $liq = $this->aprobar($this->enviar($liq), $a);

        $this->assertSame('aprobada', $liq->status);
        $this->assertEquals(500, AbonoAplicacion::where('liquidacion_id', $liq->id)->sum('monto'));
    }

    // ---------------------------------------------------------------- cancelar

    public function test_cancelar_un_borrador_libera_las_recepciones(): void
    {
        $liq = $this->borrador();
        $this->assertSame(2, $this->recepcionesLigadas($liq));

        $liq = $this->cancelar($liq, $this->creador, '  Se capturó dos veces  ');

        $this->assertSame('cancelada', $liq->status);
        $this->assertSame('Se capturó dos veces', $liq->cancelada_motivo);
        $this->assertSame($this->creador->id, $liq->cancelada_by);
        $this->assertNotNull($liq->cancelada_at);
        $this->assertSame(0, $this->recepcionesLigadas($liq));
        $this->assertSame(0, Recepcion::where('convenio_id', $liq->convenio_id)->whereNotNull('liquidacion_id')->count());
    }

    public function test_cancelar_en_revision_cierra_los_pasos_y_libera_las_recepciones(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $this->usuarioCon([]));
        $liq = $this->aprobar($this->enviar($this->borrador()), $a);

        $liq = $this->cancelar($liq, $this->creador);

        $this->assertSame('cancelada', $liq->status);
        $this->assertNull($liq->paso_actual);
        $this->assertSame([1 => 'approved', 2 => 'skipped'], $this->decisiones($liq, 1));
        $this->assertSame(0, $this->recepcionesLigadas($liq));
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($liq, $a));
    }

    public function test_cancelar_una_aprobada_sin_aplicaciones_libera_las_recepciones(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->aprobar($this->enviar($this->borrador()), $a);
        $this->assertSame('aprobada', $liq->status);

        $liq = $this->cancelar($liq, $this->creador);

        $this->assertSame('cancelada', $liq->status);
        $this->assertSame(0, $this->recepcionesLigadas($liq));
    }

    public function test_cancelar_una_aprobada_con_aplicaciones_da_422_y_no_cambia_nada(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->aprobar($this->enviar($this->borrador()), $a);
        $abono = $this->crearAbono(\App\Models\Abastecimiento\Productor::findOrFail($liq->productor_id), ['monto' => 300]);
        AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => 300]);

        $this->assertInvalida('liquidacion', fn () => $this->cancelar($liq, $this->creador), 'abonos');

        $liq->refresh();
        $this->assertSame('aprobada', $liq->status);
        $this->assertNull($liq->cancelada_at);
        $this->assertSame(2, $this->recepcionesLigadas($liq));
    }

    public function test_cancelar_una_pagada_o_ya_cancelada_da_422(): void
    {
        $a = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $liq = $this->borrador(null, 2);
        $this->crearAbono(\App\Models\Abastecimiento\Productor::findOrFail($liq->productor_id), ['monto' => 2000]);
        $pagada = $this->aprobar($this->enviar($liq), $a);
        $this->assertSame('pagada', $pagada->status);

        $this->assertInvalida('liquidacion', fn () => $this->cancelar($pagada, $this->creador));
        $this->assertSame('pagada', $pagada->fresh()->status);

        $cancelada = $this->cancelar($this->borrador(), $this->creador);
        $this->assertInvalida('liquidacion', fn () => $this->cancelar($cancelada, $this->creador));
    }

    public function test_cancelar_exige_motivo(): void
    {
        $liq = $this->borrador();

        $this->assertInvalida('motivo', fn () => $this->cancelar($liq, $this->creador, '  '));
        $this->assertSame('borrador', $liq->fresh()->status);
        $this->assertSame(2, $this->recepcionesLigadas($liq));
    }

    public function test_las_recepciones_liberadas_se_pueden_liquidar_de_nuevo(): void
    {
        $liq = $this->borrador();
        $this->cancelar($liq, $this->creador);

        $disponibles = \App\Services\Abastecimiento\ReglasLiquidacion::recepcionesDisponibles(
            Convenio::findOrFail($liq->convenio_id), '2026-06-01', '2026-06-28',
        );
        $this->assertCount(2, $disponibles);
    }

    // ---------------------------------------------------------------- avisos a quien decide

    public function test_decididores_de_la_liquidacion_son_los_elegibles_del_paso_actual(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(2, $b);

        $liq = $this->enviar($this->borrador());
        $this->assertSame([$a->id], FlujoLiquidacion::decididoresDe($liq)->pluck('id')->all());

        $liq = $this->aprobar($liq, $a);
        $this->assertSame([$b->id], FlujoLiquidacion::decididoresDe($liq)->pluck('id')->all());

        $liq = $this->aprobar($liq, $b);
        $this->assertCount(0, FlujoLiquidacion::decididoresDe($liq));
    }

    // ---------------------------------------------------------------- acceso a la empresa

    public function test_una_regla_de_usuario_sin_acceso_a_la_empresa_deja_el_paso_sin_elegibles(): void
    {
        $sinAcceso = User::factory()->create(['role' => 'user']);
        $this->reglaUsuario(1, $sinAcceso); // regla global, pero el usuario no entra a SBP
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq), 'paso 1');
        $this->assertSame('borrador', $liq->fresh()->status);

        // Con acceso activo a SBP ya es elegible; con el acceso apagado vuelve a quedar fuera.
        $this->darAcceso($sinAcceso);
        $enviada = $this->enviar($liq);
        $paso = CadenaLiquidacion::pasoActual($enviada);
        $this->assertSame([$sinAcceso->id], AprobadorLiquidaciones::elegibles($enviada, $paso)->pluck('id')->all());
        $this->assertTrue(AprobadorLiquidaciones::puedeDecidir($sinAcceso, $enviada, $paso));

        \App\Models\UserEnterpriseAccess::where('user_id', $sinAcceso->id)->update(['is_active' => false]);
        $this->assertCount(0, AprobadorLiquidaciones::elegibles($enviada, $paso));
        $this->assertFalse(AprobadorLiquidaciones::puedeDecidir($sinAcceso, $enviada, $paso));
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($enviada, $sinAcceso, null, 1));
    }

    public function test_acceso_solo_a_otra_empresa_no_vuelve_elegible_al_usuario(): void
    {
        $deOtra = User::factory()->create(['role' => 'user']);
        $this->darAcceso($deOtra, $this->otra);
        $this->reglaUsuario(1, $deOtra);

        $this->assertInvalida('liquidacion', fn () => $this->enviar($this->borrador()));
    }

    public function test_el_paso_sin_cadena_tambien_exige_acceso_a_la_empresa(): void
    {
        // Concesión de aprobar sin acceso activo a la empresa: no cuenta.
        $conPermisoSinAcceso = $this->usuarioCon(['aprobar']);
        \App\Models\UserEnterpriseAccess::where('user_id', $conPermisoSinAcceso->id)->update(['is_active' => false]);
        $liq = $this->borrador();

        $this->assertInvalida('liquidacion', fn () => $this->enviar($liq));

        $conAcceso = $this->usuarioCon(['aprobar']);
        $enviada = $this->enviar($liq);
        $paso = CadenaLiquidacion::pasoActual($enviada);
        $this->assertSame([$conAcceso->id], AprobadorLiquidaciones::elegibles($enviada, $paso)->pluck('id')->all());
        $this->assertFalse(AprobadorLiquidaciones::puedeDecidir($conPermisoSinAcceso, $enviada, $paso));
    }

    public function test_los_administradores_no_necesitan_fila_de_acceso(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->reglaUsuario(1, $admin);

        $enviada = $this->enviar($this->borrador());

        $this->assertSame('aprobada', $this->aprobar($enviada, $admin)->status);
    }

    // ---------------------------------------------------------------- ficha de empleado de otra empresa

    public function test_la_regla_de_puesto_usa_la_ficha_del_usuario_en_la_empresa_de_la_liquidacion(): void
    {
        $puesto = $this->puesto($this->sbp, 'Gerente de compras', 3, true);
        $this->regla(1, 'position', ['position_id' => $puesto->id]);

        // Dos fichas con el mismo usuario: la de la otra empresa se crea primero (la «primera» de employee()).
        $usuario = $this->darAcceso(User::factory()->create(['role' => 'user']));
        $this->empleado($this->otra, ['user_id' => $usuario->id]);
        $this->empleado($this->sbp, ['user_id' => $usuario->id, 'position_id' => $puesto->id]);

        $liq = $this->borrador();
        $this->assertSame($usuario->id, optional($usuario->employee()->first())->user_id);
        $this->assertNotSame($this->sbp->id, (int) $usuario->employee()->first()->enterprise_id, 'La prueba necesita que la primera ficha sea la de otra empresa.');

        $enviada = $this->enviar($liq);
        $paso = CadenaLiquidacion::pasoActual($enviada);
        $this->assertSame([$usuario->id], AprobadorLiquidaciones::elegibles($enviada, $paso)->pluck('id')->all());
        $this->assertSame('aprobada', $this->aprobar($enviada, $usuario)->status);
    }

    public function test_la_ficha_de_otra_empresa_no_habilita_ni_bloquea_por_si_sola(): void
    {
        $puesto = $this->puesto($this->sbp, 'Gerente', 3, true);
        $this->regla(1, 'position', ['position_id' => $puesto->id]);

        // Solo tiene ficha activa con el puesto en OTRA empresa: no es elegible en SBP.
        $soloOtra = $this->darAcceso(User::factory()->create(['role' => 'user']));
        $this->empleado($this->otra, ['user_id' => $soloOtra->id, 'position_id' => $puesto->id]);
        $this->assertInvalida('liquidacion', fn () => $this->enviar($this->borrador()));

        // Regla de usuario: una ficha dada de baja en otra empresa no le quita el paso en SBP.
        $this->proceso->steps()->delete();
        $conBajaAjena = $this->darAcceso(User::factory()->create(['role' => 'user']));
        $this->empleado($this->otra, ['user_id' => $conBajaAjena->id, 'status' => 'terminated']);
        $this->reglaUsuario(1, $conBajaAjena);
        $enviada = $this->enviar($this->borrador());
        $this->assertSame('aprobada', $this->aprobar($enviada, $conBajaAjena)->status);
    }

    // ---------------------------------------------------------------- paso esperado obligatorio

    public function test_aprobar_con_el_paso_que_ya_avanzo_da_422_aunque_el_usuario_sea_elegible_del_siguiente(): void
    {
        $a = $this->usuarioCon([]);
        $b = $this->usuarioCon([]); // alternativa del paso 1 y elegible del paso 2
        $c = $this->usuarioCon([]);
        $this->reglaUsuario(1, $a);
        $this->reglaUsuario(1, $b);
        $this->reglaUsuario(2, $b);
        $this->reglaUsuario(2, $c);
        $enviada = $this->enviar($this->borrador());

        $this->aprobar($enviada, $a, null, 1);

        // B tenía en pantalla el paso 1 y hace clic cuando ya va en el 2: no debe aprobar el 2 sin verlo.
        $this->assertInvalida('liquidacion', fn () => $this->aprobar($enviada, $b, null, 1), 'La liquidación ya cambió de paso; recarga la pantalla.');
        $liq = $enviada->fresh();
        $this->assertSame('en_revision', $liq->status);
        $this->assertSame(2, $liq->paso_actual);
        $this->assertSame([1 => 'approved', 2 => 'waiting'], $this->decisiones($liq, 1));

        // Viendo el paso 2, B sí lo decide.
        $this->assertSame('aprobada', $this->aprobar($enviada, $b, null, 2)->status);
    }

    public function test_el_paso_esperado_es_obligatorio_en_aprobar_y_rechazar(): void
    {
        foreach (['aprobar', 'rechazar'] as $metodo) {
            $firma = (new \ReflectionMethod(FlujoLiquidacion::class, $metodo))->getParameters();
            $ultimo = end($firma);
            $this->assertSame('pasoEsperado', $ultimo->getName());
            $this->assertFalse($ultimo->isOptional(), "{$metodo}: pasoEsperado debe ser obligatorio");
            $this->assertFalse($ultimo->allowsNull());
        }
    }
}
