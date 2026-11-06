<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Liquidación en borrador: alta, previa, edición, baja y consulta. El convenio
 * por omisión de los fixtures es de compra directa por caja a $100, MXN, del
 * 1 al 28 de junio de 2026.
 */
class LiquidacionBorradorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function url(string $ruta = 'liquidaciones', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    /** @return array{0: Convenio, 1: Recepcion, 2: Recepcion, 3: Recepcion} el convenio y tres recepciones de 10, 20 y 5 cajas (2, 3 y 4 de junio). */
    private function convenioConTres(array $over = []): array
    {
        $c = $this->crearConvenio($this->sbp, $over);
        $a = $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'cantidad' => 10]);
        $b = $this->crearRecepcion($c, ['fecha' => '2026-06-03', 'cantidad' => 20]);
        $d = $this->crearRecepcion($c, ['fecha' => '2026-06-04', 'cantidad' => 5]);

        return [$c, $a, $b, $d];
    }

    private function cuerpo(Convenio $c, array $ids, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id,
            'periodo_inicio' => '2026-06-01',
            'periodo_fin' => '2026-06-15',
            'recepcion_ids' => $ids,
        ];
    }

    private function liquidar(Convenio $c, array $ids, array $over = []): int
    {
        return $this->postJson($this->url(), $this->cuerpo($c, $ids, $over))->assertStatus(201)->json('data.id');
    }

    // ---- Alta ----

    public function test_alta_guarda_detalle_y_totales_y_liga_las_recepciones(): void
    {
        [$c, $a, $b, $d] = $this->convenioConTres();

        $r = $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $b->id], ['notas' => 'Primera quincena']));

        $r->assertStatus(201)->assertJsonPath('success', true)
            ->assertJsonPath('data.folio', 'LQ-0001')
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.productor_id', $c->productor_id)
            ->assertJsonPath('data.monto_final', 3000)
            ->assertJsonPath('data.bruto', 3000)
            ->assertJsonPath('data.cantidad', 30);

        $id = $r->json('data.id');
        $liq = Liquidacion::findOrFail($id);
        $this->assertSame($this->sbp->id, $liq->enterprise_id);
        $this->assertSame('Primera quincena', $liq->notas);
        $this->assertSame($this->admin->id, $liq->created_by);
        $this->assertSame('3000.00', $liq->monto_final);

        $this->assertSame(2, LiquidacionDetalle::where('liquidacion_id', $id)->count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], LiquidacionDetalle::where('liquidacion_id', $id)->pluck('recepcion_id')->all());
        $this->assertSame($id, $a->fresh()->liquidacion_id);
        $this->assertSame($id, $b->fresh()->liquidacion_id);
        $this->assertNull($d->fresh()->liquidacion_id);
    }

    public function test_una_recepcion_liquidada_ya_no_se_edita_ni_se_borra(): void
    {
        [$c, $a] = $this->convenioConTres();
        $this->liquidar($c, [$a->id]);

        $this->deleteJson($this->url("recepciones/{$a->id}"))->assertStatus(422);
        $this->putJson($this->url("recepciones/{$a->id}"), [
            'convenio_id' => $c->id, 'fecha' => '2026-06-02', 'tipo_carga_id' => $a->tipo_carga_id, 'cantidad' => 3,
        ])->assertStatus(422);
        $this->assertNotNull(Recepcion::find($a->id));
    }

    public function test_los_folios_son_consecutivos(): void
    {
        [$c, $a, $b] = $this->convenioConTres();

        $primera = $this->postJson($this->url(), $this->cuerpo($c, [$a->id]))->assertStatus(201);
        $segunda = $this->postJson($this->url(), $this->cuerpo($c, [$b->id]))->assertStatus(201);

        $this->assertSame('LQ-0001', $primera->json('data.folio'));
        $this->assertSame('LQ-0002', $segunda->json('data.folio'));
    }

    public function test_ajuste_manual_manda_sobre_el_calculo_y_pide_motivo(): void
    {
        [$c, $a, $b] = $this->convenioConTres();

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $b->id], ['monto_ajustado' => 2500]))
            ->assertStatus(422)->assertJsonValidationErrors(['motivo_ajuste']);
        $this->assertSame(0, Liquidacion::count());

        $r = $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $b->id], ['monto_ajustado' => 2500, 'motivo_ajuste' => 'Acuerdo con el productor']))
            ->assertStatus(201);
        $r->assertJsonPath('data.monto_calculado', 3000)->assertJsonPath('data.monto_ajustado', 2500)->assertJsonPath('data.monto_final', 2500);
    }

    // ---- Selección de recepciones ----

    public function test_recepcion_de_otro_convenio_da_422_en_recepcion_ids(): void
    {
        [$c, $a] = $this->convenioConTres();
        $otro = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28']);
        $ajena = $this->crearRecepcion($otro, ['fecha' => '2026-06-02']);

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $ajena->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);

        $this->assertSame(0, Liquidacion::count());
        $this->assertNull($a->fresh()->liquidacion_id);
    }

    public function test_recepcion_fuera_del_periodo_da_422_en_recepcion_ids(): void
    {
        [$c, $a, , $d] = $this->convenioConTres();

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $d->id], ['periodo_fin' => '2026-06-03']))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
        $this->assertSame(0, Liquidacion::count());
    }

    public function test_recepcion_repetida_da_422_en_recepcion_ids(): void
    {
        [$c, $a] = $this->convenioConTres();

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $a->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
        $this->assertSame(0, Liquidacion::count());
    }

    public function test_recepcion_ya_liquidada_da_422_y_la_segunda_alta_no_gana(): void
    {
        [$c, $a, $b] = $this->convenioConTres();
        $primera = $this->liquidar($c, [$a->id]);

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $b->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);

        $this->assertSame(1, Liquidacion::count());
        $this->assertSame($primera, $a->fresh()->liquidacion_id);
        $this->assertNull($b->fresh()->liquidacion_id);
    }

    public function test_recepcion_inexistente_o_de_otra_empresa_da_422(): void
    {
        [$c, $a] = $this->convenioConTres();
        $convenioAjeno = $this->crearConvenio($this->otra);
        $ajena = $this->crearRecepcion($convenioAjeno);

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id, $ajena->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
        $this->postJson($this->url(), $this->cuerpo($c, [999999]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
    }

    public function test_sin_recepciones_da_422(): void
    {
        [$c] = $this->convenioConTres();

        $this->postJson($this->url(), $this->cuerpo($c, []))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
    }

    public function test_convenio_de_otra_empresa_da_422_en_convenio_id(): void
    {
        [$c, $a] = $this->convenioConTres();
        $ajeno = $this->crearConvenio($this->otra);

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id], ['convenio_id' => $ajeno->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['convenio_id']);
        $this->assertSame(0, Liquidacion::count());
    }

    public function test_recepcion_sin_precio_vigente_da_422_en_recepcion_ids(): void
    {
        $c = $this->crearConvenio($this->sbp);
        // Antes de que empiece el precio del convenio: se crea directo, sin las reglas de recepción.
        $sinPrecio = $this->crearRecepcion($c, ['fecha' => '2026-05-30']);

        $this->postJson($this->url(), $this->cuerpo($c, [$sinPrecio->id], ['periodo_inicio' => '2026-05-25']))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
        $this->assertSame(0, Liquidacion::count());
        $this->assertNull($sinPrecio->fresh()->liquidacion_id);
    }

    public function test_convenio_por_kilo_rechaza_recepciones_sin_kilos(): void
    {
        $c = $this->crearConvenio($this->sbp, ['unidad' => 'kg']);
        $sinKilos = $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'cantidad' => 10]);

        $this->postJson($this->url(), $this->cuerpo($c, [$sinKilos->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);

        $conKilos = $this->crearRecepcion($c, ['fecha' => '2026-06-03', 'cantidad' => 10, 'kilos' => 250.5]);
        $this->postJson($this->url(), $this->cuerpo($c, [$conKilos->id]))
            ->assertStatus(201)->assertJsonPath('data.monto_final', 25050)->assertJsonPath('data.kilos', 250.5);
    }

    public function test_periodo_invertido_da_422(): void
    {
        [$c, $a] = $this->convenioConTres();

        $this->postJson($this->url(), $this->cuerpo($c, [$a->id], ['periodo_inicio' => '2026-06-15', 'periodo_fin' => '2026-06-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['periodo_fin']);
    }

    // ---- Previa y disponibles ----

    public function test_previa_calcula_sin_escribir(): void
    {
        [$c, $a, $b] = $this->convenioConTres();

        $r = $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$a->id, $b->id]));

        $r->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.monto_final', 3000)
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.detalles.0.folio', $a->folio)
            ->assertJsonPath('data.detalles.0.fecha', '2026-06-02')
            ->assertJsonPath('data.detalles.0.a_pagar', 1000);
        $this->assertNotNull($r->json('data.detalles.0.tipo_carga.nombre'));

        $this->assertSame(0, Liquidacion::count());
        $this->assertSame(0, LiquidacionDetalle::count());
        $this->assertSame(0, DB::table('abs_folios')->where('prefijo', 'LQ')->count());
        $this->assertNull($a->fresh()->liquidacion_id);
    }

    public function test_previa_valida_la_seleccion_como_el_alta(): void
    {
        [$c, $a] = $this->convenioConTres();
        $this->liquidar($c, [$a->id]);

        $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$a->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);
    }

    public function test_previa_de_una_edicion_acepta_las_recepciones_del_propio_borrador(): void
    {
        [$c, $a, $b] = $this->convenioConTres();
        $id = $this->liquidar($c, [$a->id]);

        $this->postJson($this->url('liquidaciones/previa'), $this->cuerpo($c, [$a->id, $b->id], ['liquidacion_id' => $id]))
            ->assertOk()->assertJsonPath('data.monto_final', 3000);
    }

    public function test_recepciones_disponibles_trae_las_sin_liquidar_del_periodo(): void
    {
        [$c, $a, $b, $d] = $this->convenioConTres();
        $fuera = $this->crearRecepcion($c, ['fecha' => '2026-06-20']);
        $id = $this->liquidar($c, [$a->id]);
        $consulta = "convenio_id={$c->id}&periodo_inicio=2026-06-01&periodo_fin=2026-06-15";

        $r = $this->getJson($this->url('liquidaciones/recepciones-disponibles?'.$consulta))->assertOk();
        $this->assertEqualsCanonicalizing([$b->id, $d->id], array_column($r->json('data'), 'id'));
        $this->assertNotContains($fuera->id, array_column($r->json('data'), 'id'));
        $this->assertSame('2026-06-03', $r->json('data.0.fecha'));
        $this->assertNotNull($r->json('data.0.tipo_carga.nombre'));

        // Al editar el borrador también aparecen las suyas.
        $r = $this->getJson($this->url("liquidaciones/recepciones-disponibles?{$consulta}&liquidacion_id={$id}"))->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $d->id], array_column($r->json('data'), 'id'));
    }

    public function test_recepciones_disponibles_exige_convenio_de_la_empresa(): void
    {
        $ajeno = $this->crearConvenio($this->otra);

        $this->getJson($this->url("liquidaciones/recepciones-disponibles?convenio_id={$ajeno->id}&periodo_inicio=2026-06-01&periodo_fin=2026-06-15"))
            ->assertStatus(422)->assertJsonValidationErrors(['convenio_id']);
    }

    // ---- Edición ----

    public function test_put_recalcula_y_libera_las_recepciones_quitadas(): void
    {
        [$c, $a, $b, $d] = $this->convenioConTres();
        $id = $this->liquidar($c, [$a->id, $b->id]);

        $r = $this->putJson($this->url("liquidaciones/{$id}"), $this->cuerpo($c, [$b->id, $d->id], ['notas' => 'Cambio']));

        $r->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.folio', 'LQ-0001')
            ->assertJsonPath('data.monto_final', 2500)
            ->assertJsonPath('data.cantidad', 25);

        $this->assertNull($a->fresh()->liquidacion_id);
        $this->assertSame($id, $b->fresh()->liquidacion_id);
        $this->assertSame($id, $d->fresh()->liquidacion_id);
        $this->assertEqualsCanonicalizing([$b->id, $d->id], LiquidacionDetalle::where('liquidacion_id', $id)->pluck('recepcion_id')->all());
        $this->assertSame('Cambio', Liquidacion::find($id)->notas);
        $this->assertSame(1, Liquidacion::count());
    }

    public function test_put_con_error_no_cambia_nada(): void
    {
        [$c, $a, $b, $d] = $this->convenioConTres();
        $otra = $this->liquidar($c, [$d->id]);
        $id = $this->liquidar($c, [$a->id]);

        // $d ya está en otra liquidación.
        $this->putJson($this->url("liquidaciones/{$id}"), $this->cuerpo($c, [$b->id, $d->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['recepcion_ids']);

        $this->assertSame($id, $a->fresh()->liquidacion_id);
        $this->assertNull($b->fresh()->liquidacion_id);
        $this->assertSame($otra, $d->fresh()->liquidacion_id);
        $this->assertSame([$a->id], LiquidacionDetalle::where('liquidacion_id', $id)->pluck('recepcion_id')->all());
        $this->assertSame('1000.00', Liquidacion::find($id)->monto_final);
    }

    public function test_put_no_deja_pasar_fuera_de_borrador(): void
    {
        [$c, $a] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c, ['status' => 'en_revision']);

        $this->putJson($this->url("liquidaciones/{$liq->id}"), $this->cuerpo($c, [$a->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
        $this->assertNull($a->fresh()->liquidacion_id);
    }

    // ---- Baja ----

    public function test_delete_libera_todas_las_recepciones_y_borra(): void
    {
        [$c, $a, $b] = $this->convenioConTres();
        $id = $this->liquidar($c, [$a->id, $b->id]);

        $this->deleteJson($this->url("liquidaciones/{$id}"))->assertOk()->assertJsonPath('success', true);

        $this->assertNull(Liquidacion::find($id));
        $this->assertNull($a->fresh()->liquidacion_id);
        $this->assertNull($b->fresh()->liquidacion_id);
        $this->assertSame(0, LiquidacionDetalle::where('liquidacion_id', $id)->count());

        // Las recepciones vuelven a ser editables y liquidables.
        $this->deleteJson($this->url("recepciones/{$b->id}"))->assertOk();
        $this->liquidar($c, [$a->id]);
    }

    public function test_delete_no_deja_pasar_fuera_de_borrador(): void
    {
        [$c, $a] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c, ['status' => 'aprobada']);
        $a->update(['liquidacion_id' => $liq->id]);

        $this->deleteJson($this->url("liquidaciones/{$liq->id}"))
            ->assertStatus(422)->assertJsonValidationErrors(['liquidacion']);
        $this->assertNotNull(Liquidacion::find($liq->id));
        $this->assertSame($liq->id, $a->fresh()->liquidacion_id);
    }

    // ---- Empresa y permisos ----

    public function test_id_de_otra_empresa_da_404(): void
    {
        $ajeno = $this->crearConvenio($this->otra);
        $liq = $this->crearLiquidacion($ajeno);
        [$c, $a] = $this->convenioConTres();

        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(404);
        $this->putJson($this->url("liquidaciones/{$liq->id}"), $this->cuerpo($c, [$a->id]))->assertStatus(404);
        $this->deleteJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(404);
        $this->assertNotNull(Liquidacion::find($liq->id));
    }

    public function test_sin_permiso_da_403_en_todo(): void
    {
        [$c, $a] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c);
        Sanctum::actingAs($this->captor);
        $cuerpo = $this->cuerpo($c, [$a->id]);

        $this->getJson($this->url())->assertStatus(403);
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(403);
        $this->getJson($this->url('liquidaciones/recepciones-disponibles?convenio_id='.$c->id))->assertStatus(403);
        $this->postJson($this->url('liquidaciones/previa'), $cuerpo)->assertStatus(403);
        $this->postJson($this->url(), $cuerpo)->assertStatus(403);
        $this->putJson($this->url("liquidaciones/{$liq->id}"), $cuerpo)->assertStatus(403);
        $this->deleteJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(403);
    }

    public function test_cada_accion_pide_su_permiso(): void
    {
        [$c, $a] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c);
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        Sanctum::actingAs($this->captor);
        $cuerpo = $this->cuerpo($c, [$a->id]);

        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk();
        $this->postJson($this->url(), $cuerpo)->assertStatus(403);
        $this->putJson($this->url("liquidaciones/{$liq->id}"), $cuerpo)->assertStatus(403);
        $this->deleteJson($this->url("liquidaciones/{$liq->id}"))->assertStatus(403);

        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['create']);
        $this->postJson($this->url(), $cuerpo)->assertStatus(201);
    }

    // ---- Consulta ----

    public function test_show_trae_detalle_convenio_productor_aprobaciones_puede_y_empresa(): void
    {
        [$c, $a, $b] = $this->convenioConTres();
        $id = $this->liquidar($c, [$a->id, $b->id]);

        $r = $this->getJson($this->url("liquidaciones/{$id}"))->assertOk()->assertJsonPath('success', true);

        $r->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.folio', 'LQ-0001')
            ->assertJsonPath('data.convenio.folio', $c->folio)
            ->assertJsonPath('data.convenio.modalidad', 'compra_directa')
            ->assertJsonPath('data.convenio.unidad', 'caja')
            ->assertJsonPath('data.productor.nombre', 'Productor de prueba')
            ->assertJsonPath('data.aprobaciones', [])
            ->assertJsonPath('data.empresa.nombre', 'Splendid by Porvenir')
            ->assertJsonPath('data.puede.editar', true)
            ->assertJsonPath('data.puede.enviar', true)
            ->assertJsonPath('data.puede.aprobar', false)
            ->assertJsonPath('data.puede.rechazar', false)
            ->assertJsonPath('data.puede.cancelar', false);
        $this->assertArrayHasKey('razon_social', $r->json('data.empresa'));
        $this->assertCount(2, $r->json('data.detalles'));
        $primero = $r->json('data.detalles.0');
        $this->assertSame($a->folio, $primero['folio']);
        $this->assertSame('2026-06-02', $primero['fecha']);
        $this->assertNotNull($primero['tipo_carga']['nombre']);
        $this->assertEquals(1000, $primero["a_pagar"]);
    }

    public function test_show_fuera_de_borrador_no_ofrece_editar_ni_enviar(): void
    {
        [$c] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c, ['status' => 'en_revision']);

        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()
            ->assertJsonPath('data.puede.editar', false)
            ->assertJsonPath('data.puede.enviar', false);
    }

    public function test_show_sin_permiso_de_editar_no_ofrece_editar_ni_enviar(): void
    {
        [$c] = $this->convenioConTres();
        $liq = $this->crearLiquidacion($c);
        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url("liquidaciones/{$liq->id}"))->assertOk()
            ->assertJsonPath('data.puede.editar', false)
            ->assertJsonPath('data.puede.enviar', false);
    }

    public function test_listado_filtra_y_oculta_canceladas_por_omision(): void
    {
        [$c] = $this->convenioConTres();
        $otro = $this->crearConvenio($this->sbp, ['productor_id' => \App\Models\Abastecimiento\Productor::create([
            'enterprise_id' => $this->sbp->id, 'nombre' => 'Rancho Los Pinos', 'tipo' => 'productor', 'is_active' => true,
        ])->id]);
        $borrador = $this->crearLiquidacion($c);
        $enRevision = $this->crearLiquidacion($otro, ['status' => 'en_revision']);
        $cancelada = $this->crearLiquidacion($c, ['status' => 'cancelada']);
        $ajena = $this->crearLiquidacion($this->crearConvenio($this->otra));

        $ids = fn ($r) => array_column($r->json('data'), 'id');

        $r = $this->getJson($this->url())->assertOk();
        $this->assertEqualsCanonicalizing([$borrador->id, $enRevision->id], $ids($r));
        $this->assertNotContains($ajena->id, $ids($r));
        $this->assertSame($c->folio, collect($r->json('data'))->firstWhere('id', $borrador->id)['convenio']['folio']);

        $this->assertEqualsCanonicalizing([$borrador->id, $enRevision->id, $cancelada->id], $ids($this->getJson($this->url('liquidaciones?con_canceladas=1'))));
        $this->assertSame([$enRevision->id], $ids($this->getJson($this->url('liquidaciones?status=en_revision'))));
        $this->assertSame([$cancelada->id], $ids($this->getJson($this->url('liquidaciones?status=cancelada'))));
        $this->assertSame([$enRevision->id], $ids($this->getJson($this->url('liquidaciones?productor_id='.$otro->productor_id))));
        $this->assertEqualsCanonicalizing([$borrador->id], $ids($this->getJson($this->url('liquidaciones?convenio_id='.$c->id))));
        $this->assertSame([$enRevision->id], $ids($this->getJson($this->url('liquidaciones?search=Pinos'))));
        $this->assertSame([$borrador->id], $ids($this->getJson($this->url('liquidaciones?search='.$borrador->folio))));
        $this->assertEqualsCanonicalizing([$borrador->id, $enRevision->id], $ids($this->getJson($this->url('liquidaciones?temporada_id='.$c->temporada_id))));
        $this->getJson($this->url('liquidaciones?status=inventado'))->assertStatus(422);
    }

    // ---- Tiempo real ----

    public function test_difunde_el_evento_liquidacion_en_alta_edicion_y_baja(): void
    {
        Event::fake([AbastecimientoUpdated::class]);
        [$c, $a, $b] = $this->convenioConTres();

        $id = $this->liquidar($c, [$a->id]);
        $this->putJson($this->url("liquidaciones/{$id}"), $this->cuerpo($c, [$a->id, $b->id]))->assertOk();
        $this->deleteJson($this->url("liquidaciones/{$id}"))->assertOk();

        foreach (['created', 'updated', 'deleted'] as $accion) {
            Event::assertDispatched(
                AbastecimientoUpdated::class,
                fn ($e) => $e->data['entidad'] === 'liquidacion' && $e->action === $accion && $e->data['id'] === $id,
            );
        }
    }
}
