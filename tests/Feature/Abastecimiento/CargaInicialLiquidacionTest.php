<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Plan 3, Task 5: liquidación de carga inicial. Nace `aprobada` (origen `carga_inicial`) a nombre de quien la
 * captura, sin cadena ni avisos; con recepciones calcula como un borrador y en modo solo monto cuenta en el saldo
 * pero no en lo recibido, en «sin liquidar» ni en el avance del convenio. Incluye el ruling 1: quien tiene solo
 * `liquidaciones.carga-inicial` usa las lecturas de apoyo del formulario (recepciones disponibles y vista previa).
 */
class CargaInicialLiquidacionTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private User $cargador;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $this->cargador = $this->usuarioCon(['view', 'carga-inicial', 'delete']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $ruta = 'liquidaciones'): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    private function usuarioCon(array $permisos): User
    {
        $usuario = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', $permisos);

        return $usuario;
    }

    private function como(User $usuario): void
    {
        Sanctum::actingAs($usuario);
    }

    private function cuerpoMonto(Convenio $convenio, array $over = []): array
    {
        return array_merge([
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-28',
            'monto' => 5000, 'motivo' => 'Saldo pendiente de la temporada pasada',
        ], $over);
    }

    private function cuerpoRecepciones(Convenio $convenio, array $ids, array $over = []): array
    {
        return array_merge([
            'convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-28',
            'recepcion_ids' => $ids,
        ], $over);
    }

    private function cargar(array $cuerpo, ?User $usuario = null)
    {
        $this->como($usuario ?? $this->cargador);

        return $this->postJson($this->url('liquidaciones/carga-inicial'), $cuerpo);
    }

    /** Consignación por kilo a $10, 70 % para el productor y 2 % de rezaga aceptada. */
    private function convenioConsignacion(): Convenio
    {
        $convenio = $this->crearConvenio($this->sbp, [
            'modalidad' => 'consignacion', 'unidad' => 'kg', 'porcentaje_rezaga' => 2, 'precio' => 10,
        ]);
        $convenio->precios()->update(['porcentaje_productor' => 70]);

        return $convenio->fresh(['precios']);
    }

    /** @return array<int, int> ids de 3 recepciones con 5 % de rezaga real (1 000 + 500 + 500 kg; 40 + 30 + 30 de rezaga). */
    private function recepcionesConRezaga(Convenio $convenio): array
    {
        $ids = [];
        foreach ([['2026-06-02', 1000, 40], ['2026-06-03', 500, 30], ['2026-06-04', 500, 30]] as [$fecha, $kilos, $rezaga]) {
            $ids[] = $this->crearRecepcion($convenio, ['fecha' => $fecha, 'cantidad' => 10, 'kilos' => $kilos, 'rezaga_kg' => $rezaga])->id;
        }

        return $ids;
    }

    private function saldo(Convenio $convenio): array
    {
        return SaldoProductor::porProductores($this->sbp, [$convenio->productor_id])[$convenio->productor_id]['MXN'] ?? [];
    }

    // ---------------------------------------------------------------- modo con recepciones

    public function test_con_recepciones_calcula_igual_que_un_borrador_y_queda_aprobada(): void
    {
        $convenio = $this->convenioConsignacion();
        $ids = $this->recepcionesConRezaga($convenio);

        // La vista previa de un borrador con esas mismas recepciones es el cálculo de referencia.
        $this->como($this->admin);
        $previa = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpoRecepciones($convenio, $ids))
            ->assertOk()->json('data');
        // Bruto 20 000; rezaga real 5 % contra 2 % aceptada = 3 % de excedente (600); (20 000 − 600) × 70 % = 13 580.
        $this->assertEqualsWithDelta(13580, $previa['monto_final'], 0.001);

        Event::fake([AbastecimientoUpdated::class]);
        SystemNotification::query()->delete();

        $r = $this->cargar($this->cuerpoRecepciones($convenio, $ids, ['notas' => 'Cierre de 2025']))->assertStatus(201);
        $r->assertJsonPath('data.status', 'aprobada')
            ->assertJsonPath('data.origen', 'carga_inicial')
            ->assertJsonPath('data.notas', 'Cierre de 2025');
        $this->assertEqualsWithDelta($previa['monto_final'], $r->json('data.monto_final'), 0.001);
        $this->assertEqualsWithDelta($previa['monto_calculado'], $r->json('data.monto_calculado'), 0.001);
        $this->assertEqualsWithDelta($previa['bruto'], $r->json('data.bruto'), 0.001);
        $this->assertEqualsWithDelta($previa['descuento'], $r->json('data.descuento'), 0.001);
        $this->assertEqualsWithDelta($previa['rezaga_real_pct'], $r->json('data.rezaga_real_pct'), 0.0001);
        $this->assertCount(3, $r->json('data.detalles'));

        $liq = Liquidacion::findOrFail($r->json('data.id'));
        $this->assertSame('LQ-0001', $liq->folio);
        $this->assertSame($this->cargador->id, $liq->aprobada_by);
        $this->assertNotNull($liq->aprobada_at);
        $this->assertSame($this->cargador->id, $liq->created_by);
        $this->assertSame([$this->cargador->id], $liq->intervinieron);
        $this->assertSame(0, (int) $liq->ronda);
        $this->assertNull($liq->paso_actual);

        // Las recepciones quedan ligadas y bloqueadas.
        $this->assertSame([$liq->id], Recepcion::whereIn('id', $ids)->pluck('liquidacion_id')->unique()->values()->all());
        $this->assertSame(3, LiquidacionDetalle::where('liquidacion_id', $liq->id)->count());
        $this->como($this->admin);
        $this->deleteJson($this->url("recepciones/{$ids[0]}"))->assertStatus(422);

        // Sin cadena ni avisos.
        $this->assertSame(0, LiquidacionAprobacion::where('liquidacion_id', $liq->id)->count());
        $this->assertSame(0, SystemNotification::count());
    }

    public function test_con_recepciones_acepta_monto_ajustado_con_motivo_y_lo_exige(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id;

        $this->cargar($this->cuerpoRecepciones($convenio, [$id], ['monto_ajustado' => 900]))
            ->assertStatus(422)->assertJsonValidationErrors('motivo_ajuste');
        $this->assertSame(0, Liquidacion::count());

        $this->cargar($this->cuerpoRecepciones($convenio, [$id], ['monto_ajustado' => 900, 'motivo_ajuste' => 'Descuento pactado']))
            ->assertStatus(201)
            ->assertJsonPath('data.monto_final', 900)
            ->assertJsonPath('data.monto_calculado', 1000);
    }

    public function test_recepciones_de_otro_convenio_o_ya_liquidadas_dan_422_y_no_dejan_nada(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $propia = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id;
        $otro = $this->crearConvenio($this->sbp, ['productor_id' => Productor::create([
            'enterprise_id' => $this->sbp->id, 'nombre' => 'Otro productor', 'tipo' => 'productor', 'is_active' => true,
        ])->id]);
        $ajena = $this->crearRecepcion($otro, ['fecha' => '2026-06-02'])->id;
        $liquidada = $this->crearRecepcion($convenio, ['fecha' => '2026-06-03']);
        $liquidada->update(['liquidacion_id' => $this->crearLiquidacion($convenio, ['status' => 'borrador'])->id]);
        $antes = Liquidacion::count();

        $this->cargar($this->cuerpoRecepciones($convenio, [$propia, $ajena]))
            ->assertStatus(422)->assertJsonValidationErrors('recepcion_ids');
        $this->cargar($this->cuerpoRecepciones($convenio, [$propia, $liquidada->id]))
            ->assertStatus(422)->assertJsonValidationErrors('recepcion_ids');

        $this->assertSame($antes, Liquidacion::count());
        $this->assertNull(Recepcion::find($propia)->liquidacion_id);

        // El folio del siguiente intento sigue siendo el que tocaba (el fallido no consumió ninguno).
        $this->cargar($this->cuerpoRecepciones($convenio, [$propia]))->assertStatus(201)->assertJsonPath('data.folio', 'LQ-0002');
    }

    // ---------------------------------------------------------------- modo solo monto

    public function test_solo_monto_guarda_el_monto_en_todas_las_columnas_y_nada_de_recepciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $r = $this->cargar($this->cuerpoMonto($convenio, ['monto' => 12345.67, 'notas' => 'Carga de arranque']))->assertStatus(201);
        $r->assertJsonPath('data.status', 'aprobada')->assertJsonPath('data.origen', 'carga_inicial')
            ->assertJsonPath('data.detalles', [])->assertJsonPath('data.aprobaciones', []);

        $liq = Liquidacion::findOrFail($r->json('data.id'));
        $this->assertSame('12345.67', (string) $liq->monto_calculado);
        $this->assertSame('12345.67', (string) $liq->monto_ajustado);
        $this->assertSame('12345.67', (string) $liq->monto_final);
        $this->assertSame('Saldo pendiente de la temporada pasada', $liq->motivo_ajuste);
        foreach (['cantidad', 'kilos', 'bruto', 'descuento'] as $columna) {
            $this->assertEquals(0, $liq->{$columna}, $columna);
        }
        $this->assertSame('MXN', $liq->moneda);
        $this->assertSame($convenio->productor_id, $liq->productor_id);
        $this->assertSame($this->cargador->id, $liq->aprobada_by);
        $this->assertSame([$this->cargador->id], $liq->intervinieron);
        $this->assertSame(0, LiquidacionDetalle::count());
        $this->assertSame(0, LiquidacionAprobacion::count());
    }

    public function test_solo_monto_valida_monto_motivo_y_periodo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $casos = [
            'motivo corto' => [['motivo' => 'Muy corto'], 'motivo'],
            'sin motivo' => [['motivo' => null], 'motivo'],
            'monto cero' => [['monto' => 0], 'monto'],
            'monto negativo' => [['monto' => -10], 'monto'],
            'tres decimales' => [['monto' => 100.123], 'monto'],
            'monto no numérico' => [['monto' => 'mucho'], 'monto'],
            'periodo futuro' => [['periodo_fin' => '2099-01-01'], 'periodo_fin'],
            'fin antes del inicio' => [['periodo_inicio' => '2026-06-20', 'periodo_fin' => '2026-06-10'], 'periodo_fin'],
            'recepciones y monto a la vez' => [['recepcion_ids' => [1]], 'recepcion_ids'],
        ];
        foreach ($casos as $nombre => [$over, $campo]) {
            $this->cargar($this->cuerpoMonto($convenio, $over))->assertStatus(422)->assertJsonValidationErrors($campo);
        }

        // Ni recepciones ni monto.
        $this->cargar(['convenio_id' => $convenio->id, 'periodo_inicio' => '2026-06-01', 'periodo_fin' => '2026-06-28'])
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids', 'monto']);

        $this->assertSame(0, Liquidacion::count());
    }

    public function test_solo_monto_cuenta_en_el_saldo_pero_no_en_lo_recibido_ni_en_sin_liquidar_ni_en_el_avance(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']); // 10 cajas = $1 000, sin liquidar

        $consultar = function () use ($convenio): array {
            $this->como($this->admin);
            $tablero = $this->getJson($this->url("tablero?temporada_id={$convenio->temporada_id}"))->assertOk()->json('data');
            $ficha = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk()->json('data');

            return [$tablero, $ficha, $this->saldo($convenio)];
        };
        [$tableroAntes, $fichaAntes, $saldoAntes] = $consultar();
        $this->assertEqualsWithDelta(1000, $saldoAntes['sin_liquidar'], 0.001);
        $this->assertEqualsWithDelta(0, $saldoAntes['por_pagar'], 0.001);

        $this->cargar($this->cuerpoMonto($convenio, ['monto' => 5000]))->assertStatus(201);

        [$tableroDespues, $fichaDespues, $saldoDespues] = $consultar();

        // Dinero: sube liquidado y por pagar, del productor, del convenio y del tablero.
        $this->assertEqualsWithDelta(5000, $saldoDespues['liquidado'], 0.001);
        $this->assertEqualsWithDelta(5000, $saldoDespues['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(5000, $fichaDespues['indicadores']['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(5000, $tableroDespues['kpis']['saldo_por_pagar'], 0.001);

        // Lo recibido, «sin liquidar» y el avance no se mueven.
        $this->assertEqualsWithDelta(1000, $saldoDespues['sin_liquidar'], 0.001);
        $this->assertSame($tableroAntes['kpis']['sin_liquidar'], $tableroDespues['kpis']['sin_liquidar']);
        $this->assertEqualsWithDelta(1000, $tableroDespues['kpis']['sin_liquidar'], 0.001);
        $this->assertSame($tableroAntes['kpis']['valor_recibido'], $tableroDespues['kpis']['valor_recibido']);
        $this->assertSame($tableroAntes['kpis']['cumplimiento'], $tableroDespues['kpis']['cumplimiento']);
        $this->assertSame(
            Arr::except($tableroAntes['convenios'][0]['indicadores'], ['por_pagar']),
            Arr::except($tableroDespues['convenios'][0]['indicadores'], ['por_pagar']),
        );
        $this->assertSame(
            Arr::except($fichaAntes['indicadores'], ['por_pagar']),
            Arr::except($fichaDespues['indicadores'], ['por_pagar']),
        );
        $this->assertEqualsWithDelta(10, $fichaDespues['indicadores']['recibido'], 0.001);
    }

    // ---------------------------------------------------------------- abonos y anticipos

    public function test_un_abono_posterior_la_paga(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->cargar($this->cuerpoMonto($convenio, ['monto' => 600]))->assertStatus(201)->json('data.id');

        $this->como($this->admin);
        $this->postJson($this->url('abonos'), [
            'productor_id' => $convenio->productor_id, 'fecha' => '2026-06-10', 'monto' => 600, 'moneda' => 'MXN',
            'metodo_pago' => 'transferencia', 'aplicaciones' => [['liquidacion_id' => $id, 'monto' => 600]],
        ])->assertStatus(201);

        $this->assertSame('pagada', Liquidacion::find($id)->status);
        $this->assertEqualsWithDelta(0, $this->saldo($convenio)['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(600, $this->saldo($convenio)['liquidado'], 0.001);
    }

    public function test_un_anticipo_existente_se_aplica_solo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = Productor::findOrFail($convenio->productor_id);
        $abono = $this->crearAbono($productor, ['monto' => 300, 'fecha' => '2026-05-20']);

        // Cubre todo: queda pagada y sobran 100 de anticipo.
        $id = $this->cargar($this->cuerpoMonto($convenio, ['monto' => 200]))->assertStatus(201)->assertJsonPath('data.status', 'pagada')->json('data.id');
        $this->assertEqualsWithDelta(200, (float) AbonoAplicacion::where('abono_id', $abono->id)->where('liquidacion_id', $id)->sum('monto'), 0.001);
        $saldo = $this->saldo($convenio);
        $this->assertEqualsWithDelta(100, $saldo['anticipos_sin_aplicar'], 0.001);
        $this->assertEqualsWithDelta(0, $saldo['por_pagar'], 0.001);

        // Los 100 que sobran cubren parte de la siguiente; el resto queda por pagar.
        $this->cargar($this->cuerpoMonto($convenio, ['monto' => 250]))->assertStatus(201)->assertJsonPath('data.status', 'aprobada');
        $saldo = $this->saldo($convenio);
        $this->assertEqualsWithDelta(0, $saldo['anticipos_sin_aplicar'], 0.001);
        $this->assertEqualsWithDelta(150, $saldo['por_pagar'], 0.001);
    }

    // ---------------------------------------------------------------- convenio y permisos

    public function test_un_convenio_en_borrador_o_cancelado_da_422(): void
    {
        foreach (['borrador', 'cancelado'] as $estado) {
            $convenio = $this->crearConvenio($this->sbp, ['status' => $estado]);
            $this->cargar($this->cuerpoMonto($convenio))->assertStatus(422)->assertJsonValidationErrors('convenio_id');
        }
        $this->assertSame(0, Liquidacion::count());

        // Suspendido o finalizado sí admiten la carga: el convenio ya existió.
        foreach (['suspendido', 'finalizado'] as $estado) {
            $this->cargar($this->cuerpoMonto($this->crearConvenio($this->sbp, ['status' => $estado])))->assertStatus(201);
        }
    }

    public function test_un_convenio_de_otra_empresa_o_inexistente_da_422(): void
    {
        $ajeno = $this->crearConvenio($this->otra);

        $this->cargar($this->cuerpoMonto($ajeno))->assertStatus(422)->assertJsonValidationErrors('convenio_id');
        $this->cargar($this->cuerpoMonto($ajeno, ['convenio_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('convenio_id');
    }

    public function test_sin_el_permiso_carga_inicial_da_403_tambien_con_create(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $sinNada = $this->captor;
        $soCreate = $this->usuarioCon(['create']);
        $todoMenosCarga = $this->usuarioCon(['view', 'create', 'edit', 'delete', 'aprobar']);
        foreach ([$sinNada, $soCreate, $todoMenosCarga] as $usuario) {
            $this->cargar($this->cuerpoMonto($convenio), $usuario)->assertStatus(403);
        }
        $this->assertSame(0, Liquidacion::count());

        // Quien tiene solo `carga-inicial` sí puede (no necesita `view`).
        $this->cargar($this->cuerpoMonto($convenio), $this->usuarioCon(['carga-inicial']))->assertStatus(201);
        // Y el administrador entra por el bypass.
        $this->cargar($this->cuerpoMonto($convenio), $this->admin)->assertStatus(201);
    }

    public function test_bloquea_al_productor_antes_que_abonos_y_liquidaciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearAbono(Productor::findOrFail($convenio->productor_id), ['monto' => 300]);
        $this->como($this->cargador);

        // SQLite no bloquea de verdad: se comprueba el ORDEN de las tablas dentro de la transacción.
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$sql) {
            $sql[] = '#BEGIN';
        });
        $this->postJson($this->url('liquidaciones/carga-inicial'), $this->cuerpoMonto($convenio, ['monto' => 200]))->assertStatus(201);

        $tx = array_slice($sql, array_search('#BEGIN', $sql, true) + 1);
        $productor = $dinero = null;
        foreach ($tx as $i => $s) {
            $productor ??= str_starts_with($s, 'select * from "abs_productores"') ? $i : null;
            $dinero ??= preg_match('/"(abs_liquidaciones|abs_abonos|abs_abono_aplicaciones)"/', $s) ? $i : null;
        }
        $this->assertNotNull($productor, 'Debe bloquear la fila del productor.');
        $this->assertNotNull($dinero);
        $this->assertLessThan($dinero, $productor, "El productor va antes que el dinero. Primera de dinero: {$tx[$dinero]}");
    }

    public function test_el_evento_va_recortado_sin_montos_ni_notas(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        Event::fake([AbastecimientoUpdated::class]);

        $id = $this->cargar($this->cuerpoMonto($convenio, ['notas' => 'Dato sensible']))->assertStatus(201)->json('data.id');

        Event::assertDispatched(AbastecimientoUpdated::class, function ($e) use ($id, $convenio) {
            if ($e->data['entidad'] !== 'liquidacion' || $e->action !== 'created') {
                return false;
            }
            $this->assertEqualsCanonicalizing(
                ['entidad', 'id', 'folio', 'status', 'convenio_id', 'productor_id', 'moneda'],
                array_keys($e->data),
            );

            return $e->data['id'] === $id && $e->data['status'] === 'aprobada' && $e->data['convenio_id'] === $convenio->id;
        });
    }

    // ---------------------------------------------------------------- cancelar y listado

    public function test_se_cancela_con_las_reglas_de_siempre_y_libera_sus_recepciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $ids = [$this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id, $this->crearRecepcion($convenio, ['fecha' => '2026-06-03'])->id];
        $id = $this->cargar($this->cuerpoRecepciones($convenio, $ids))->assertStatus(201)->json('data.id');
        $this->assertEqualsWithDelta(2000, $this->saldo($convenio)['por_pagar'], 0.001);

        // Exige el motivo.
        $this->postJson($this->url("liquidaciones/{$id}/cancelar"), [])->assertStatus(422)->assertJsonValidationErrors('motivo');

        $this->postJson($this->url("liquidaciones/{$id}/cancelar"), ['motivo' => 'Capturada por error'])
            ->assertOk()->assertJsonPath('data.status', 'cancelada');
        $this->assertSame(0, Recepcion::whereIn('id', $ids)->whereNotNull('liquidacion_id')->count());
        $saldo = $this->saldo($convenio);
        $this->assertEqualsWithDelta(0, $saldo['por_pagar'], 0.001);
        $this->assertEqualsWithDelta(2000, $saldo['sin_liquidar'], 0.001);
    }

    public function test_con_abonos_aplicados_no_se_cancela(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearAbono(Productor::findOrFail($convenio->productor_id), ['monto' => 50]);
        $id = $this->cargar($this->cuerpoMonto($convenio, ['monto' => 200]))->assertStatus(201)->json('data.id');

        $this->postJson($this->url("liquidaciones/{$id}/cancelar"), ['motivo' => 'Ya no va'])
            ->assertStatus(422)->assertJsonValidationErrors('liquidacion');
        $this->assertSame('aprobada', Liquidacion::find($id)->status);
    }

    public function test_aparece_en_la_columna_aprobada_con_su_origen_y_solo_se_puede_cancelar(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->cargar($this->cuerpoMonto($convenio))->assertStatus(201)->json('data.id');

        $lista = collect($this->getJson($this->url('liquidaciones'))->assertOk()->json('data'));
        $fila = $lista->firstWhere('id', $id);
        $this->assertSame('aprobada', $fila['status']);
        $this->assertSame('carga_inicial', $fila['origen']);
        $this->assertSame('normal', $this->crearLiquidacion($convenio)->origen);

        $puede = $this->getJson($this->url("liquidaciones/{$id}"))->assertOk()->json('data.puede');
        $this->assertSame(['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => true], $puede);

        // Sin `delete` no puede cancelar.
        $this->como($this->usuarioCon(['view']));
        $this->assertFalse($this->getJson($this->url("liquidaciones/{$id}"))->assertOk()->json('data.puede.cancelar'));
    }

    // ---------------------------------------------------------------- ruling 1: lecturas de apoyo del formulario

    public function test_quien_solo_tiene_carga_inicial_usa_recepciones_disponibles_y_vista_previa(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id;
        $soloCarga = $this->usuarioCon(['carga-inicial']);

        $this->como($soloCarga);
        $this->getJson($this->url("liquidaciones/recepciones-disponibles?convenio_id={$convenio->id}&periodo_inicio=2026-06-01&periodo_fin=2026-06-28"))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->postJson($this->url('liquidaciones/previa'), $this->cuerpoRecepciones($convenio, [$id]))
            ->assertOk()->assertJsonPath('data.monto_final', 1000);
    }

    public function test_sin_ningun_permiso_de_liquidaciones_las_lecturas_de_apoyo_siguen_en_403(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id;
        $disponibles = "liquidaciones/recepciones-disponibles?convenio_id={$convenio->id}&periodo_inicio=2026-06-01&periodo_fin=2026-06-28";

        // Sin permisos, solo `view`/`delete`/`aprobar` y permisos de OTRO submódulo no abren el formulario.
        $otroSubmodulo = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($otroSubmodulo, $this->sbp, 'abonos', ['view', 'create']);
        foreach ([$this->captor, $this->usuarioCon(['view', 'delete', 'aprobar']), $otroSubmodulo] as $usuario) {
            $this->como($usuario);
            $this->getJson($this->url($disponibles))->assertStatus(403);
            $this->postJson($this->url('liquidaciones/previa'), $this->cuerpoRecepciones($convenio, [$id]))->assertStatus(403);
        }

        // Los permisos de siempre (`create` o `edit`) se conservan.
        foreach (['create', 'edit'] as $permiso) {
            $this->como($this->usuarioCon([$permiso]));
            $this->getJson($this->url($disponibles))->assertOk();
            $this->postJson($this->url('liquidaciones/previa'), $this->cuerpoRecepciones($convenio, [$id]))->assertOk();
        }
    }

    public function test_carga_inicial_no_se_obtiene_con_create_y_no_abre_el_alta_normal(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $id = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02'])->id;

        // Quien solo tiene `carga-inicial` no puede crear un borrador normal ni editar.
        $this->como($this->usuarioCon(['carga-inicial']));
        $this->postJson($this->url(), $this->cuerpoRecepciones($convenio, [$id]))->assertStatus(403);
        $this->assertSame(0, Liquidacion::count());
    }
}
