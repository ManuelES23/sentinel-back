<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\User;
use App\Services\Abastecimiento\IndicadoresConvenio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Recepciones: alta, edición y baja con sus reglas, listado filtrado y vista
 * por semana. «Hoy» es el martes 14 de julio de 2026 (semana ISO 29, lunes 13).
 */
class RecepcionesTest extends TestCase
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

        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $ruta = 'recepciones', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    /** CV-001 del prototipo: semanas 26 a 34, $185 por caja, activo. */
    private function cv001(array $over = []): Convenio
    {
        return $this->crearConvenio($this->sbp, $over + [
            'precio' => 185,
            'fecha_inicio' => '2026-06-22', 'fecha_fin' => '2026-08-23',
            'metas' => [
                '2026-06-22' => 2000, '2026-06-29' => 2500, '2026-07-06' => 2500, '2026-07-13' => 3000,
                '2026-07-20' => 3500, '2026-07-27' => 3500, '2026-08-03' => 3000, '2026-08-10' => 2500, '2026-08-17' => 1500,
            ],
        ]);
    }

    private function tipoPrincipal(Convenio $c): int
    {
        return (int) $c->precios()->where('es_principal', true)->value('tipo_carga_id');
    }

    private function cuerpo(Convenio $c, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id,
            'fecha' => '2026-07-14',
            'tipo_carga_id' => $this->tipoPrincipal($c),
            'cantidad' => 1300,
        ];
    }

    private function tipoExtra(string $nombre, $empresa = null): TipoCarga
    {
        return TipoCarga::create(['enterprise_id' => ($empresa ?? $this->sbp)->id, 'nombre' => $nombre, 'is_active' => true]);
    }

    // ---- Alta ----

    public function test_alta_feliz_con_importe_y_semana(): void
    {
        $c = $this->cv001();
        $this->crearRecepcion($c, ['fecha' => '2026-07-13', 'cantidad' => 500]);

        $r = $this->postJson($this->url(), $this->cuerpo($c, ['ticket_proveedor' => 'TK-77', 'notas' => 'Primer corte']));

        $r->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.folio', 'RC-0002')
            ->assertJsonPath('data.convenio_id', $c->id)
            ->assertJsonPath('data.fecha', '2026-07-14')
            ->assertJsonPath('data.ticket_proveedor', 'TK-77')
            ->assertJsonPath('data.liquidada', false)
            ->assertJsonPath('data.created_by', $this->admin->id)
            ->assertJsonPath('data.autor.id', $this->admin->id)
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.semana.lunes', '2026-07-13')
            ->assertJsonPath('data.semana.numero', 29);

        $this->assertEqualsWithDelta(185, $r->json('data.precio'), 0.0001);
        $this->assertEqualsWithDelta(240500, $r->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(3000, $r->json('data.semana.meta'), 0.001);
        $this->assertEqualsWithDelta(1800, $r->json('data.semana.recibido'), 0.001);
        $this->assertDatabaseHas('abs_recepciones', ['folio' => 'RC-0002', 'enterprise_id' => $this->sbp->id, 'created_by' => $this->admin->id]);
    }

    public function test_primer_folio_es_rc_0001_y_son_consecutivos(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(201)->assertJsonPath('data.folio', 'RC-0001');
        $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(201)->assertJsonPath('data.folio', 'RC-0002');
    }

    public function test_fecha_de_hoy_es_valida(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-14']))->assertStatus(201);
    }

    public function test_fecha_futura_422(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-15']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha'])
            ->assertJsonPath('errors.fecha.0', 'La fecha no puede ser futura.');
    }

    public function test_fecha_fuera_del_convenio_422(): void
    {
        $c = $this->cv001(['fecha_fin' => '2026-07-10']);

        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-06-21']))
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha.0', 'La fecha está fuera del convenio (22 jun a 10 jul).');
        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-11']))
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha.0', 'La fecha está fuera del convenio (22 jun a 10 jul).');
        // Los dos extremos son válidos.
        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-06-22']))->assertStatus(201);
        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-10']))->assertStatus(201);
    }

    public function test_formato_de_fecha_invalido_422(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '14/07/2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha']);
    }

    public function test_solo_convenios_activos_aceptan_recepciones_nuevas(): void
    {
        foreach (['borrador', 'suspendido', 'finalizado', 'cancelado'] as $status) {
            $c = $this->cv001(['status' => $status]);

            $this->postJson($this->url(), $this->cuerpo($c))
                ->assertStatus(422)
                ->assertJsonPath('errors.convenio_id.0', 'El convenio no está activo.');
        }
        $this->assertSame(0, Recepcion::count());
    }

    public function test_tipo_de_carga_sin_precio_en_el_convenio_422(): void
    {
        $c = $this->cv001();
        $otro = $this->tipoExtra('Bin');

        $this->postJson($this->url(), $this->cuerpo($c, ['tipo_carga_id' => $otro->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.tipo_carga_id.0', 'Este tipo de carga no tiene precio en el convenio para esa fecha.');
    }

    public function test_tipo_de_carga_cuyo_precio_empieza_despues_de_la_fecha_422(): void
    {
        $c = $this->cv001();
        $bin = $this->tipoExtra('Bin');
        ConvenioPrecio::create([
            'convenio_id' => $c->id, 'tipo_carga_id' => $bin->id, 'precio' => 900,
            'vigencia_inicio' => '2026-07-10', 'es_principal' => false,
        ]);

        $this->postJson($this->url(), $this->cuerpo($c, ['tipo_carga_id' => $bin->id, 'fecha' => '2026-07-09']))
            ->assertStatus(422)->assertJsonValidationErrors(['tipo_carga_id']);
        $r = $this->postJson($this->url(), $this->cuerpo($c, ['tipo_carga_id' => $bin->id, 'fecha' => '2026-07-10', 'cantidad' => 2]));
        $r->assertStatus(201);
        $this->assertEqualsWithDelta(1800, $r->json('data.importe_estimado'), 0.001);
    }

    public function test_el_precio_es_el_vigente_a_la_fecha_de_la_recepcion(): void
    {
        $c = $this->cv001();
        ConvenioPrecio::create([
            'convenio_id' => $c->id, 'tipo_carga_id' => $this->tipoPrincipal($c), 'precio' => 200,
            'vigencia_inicio' => '2026-07-08', 'es_principal' => true,
        ]);

        $antes = $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-07', 'cantidad' => 10]));
        $despues = $this->postJson($this->url(), $this->cuerpo($c, ['fecha' => '2026-07-08', 'cantidad' => 10]));

        $this->assertEqualsWithDelta(185, $antes->json('data.precio'), 0.0001);
        $this->assertEqualsWithDelta(1850, $antes->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(200, $despues->json('data.precio'), 0.0001);
        $this->assertEqualsWithDelta(2000, $despues->json('data.importe_estimado'), 0.001);
    }

    public function test_cantidad_con_decimales_422_y_kilos_con_dos_decimales_ok(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['cantidad' => 12.5]))
            ->assertStatus(422)
            ->assertJsonPath('errors.cantidad.0', 'La cantidad va en números enteros.');

        $r = $this->postJson($this->url(), $this->cuerpo($c, ['cantidad' => 12, 'kilos' => 4860.75]));
        $r->assertStatus(201);
        $this->assertEqualsWithDelta(4860.75, $r->json('data.kilos'), 0.001);
    }

    public function test_cantidad_debe_ser_mayor_a_cero(): void
    {
        $c = $this->cv001();

        foreach ([0, -5, 'abc'] as $cantidad) {
            $this->postJson($this->url(), $this->cuerpo($c, ['cantidad' => $cantidad]))
                ->assertStatus(422)->assertJsonValidationErrors(['cantidad']);
        }
        $this->postJson($this->url(), array_diff_key($this->cuerpo($c), ['cantidad' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors(['cantidad']);
    }

    public function test_kilos_y_rezaga_aceptan_solo_dos_decimales(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100.123]))
            ->assertStatus(422)->assertJsonValidationErrors(['kilos']);
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => 1.005]))
            ->assertStatus(422)->assertJsonValidationErrors(['rezaga_kg']);
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => 1.25]))->assertStatus(201);
    }

    public function test_convenio_por_kilo_valora_y_suma_en_kilos(): void
    {
        $c = $this->cv001(['unidad' => 'kg', 'precio' => 12.5, 'metas' => ['2026-07-13' => 20000]]);

        $r = $this->postJson($this->url(), $this->cuerpo($c, ['cantidad' => 3, 'kilos' => 4860.75]));

        $r->assertStatus(201);
        // 4,860.75 kg × 12.50 = 60,759.375 → 60,759.38; las cajas no intervienen.
        $this->assertEqualsWithDelta(60759.38, $r->json('data.importe_estimado'), 0.005);
        $this->assertEqualsWithDelta(4860.75, $r->json('data.semana.recibido'), 0.001);
        $this->assertEqualsWithDelta(20000, $r->json('data.semana.meta'), 0.001);
    }

    public function test_convenio_por_kilo_exige_kilos(): void
    {
        $c = $this->cv001(['unidad' => 'kg', 'precio' => 12.5, 'metas' => ['2026-07-13' => 20000]]);

        $this->postJson($this->url(), $this->cuerpo($c))
            ->assertStatus(422)->assertJsonValidationErrors(['kilos']);
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['kilos']);
    }

    public function test_consignacion_aplica_el_porcentaje_del_productor(): void
    {
        $c = $this->cv001(['modalidad' => 'consignacion', 'precio' => 200]);
        ConvenioPrecio::where('convenio_id', $c->id)->update(['porcentaje_productor' => 60]);

        $r = $this->postJson($this->url(), $this->cuerpo($c, ['cantidad' => 1000]));

        $r->assertStatus(201);
        // 1000 × 200 × 60 ÷ 100
        $this->assertEqualsWithDelta(120000, $r->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(60, $r->json('data.porcentaje_productor'), 0.001);
    }

    public function test_rezaga_sin_kilos_422(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['rezaga_kg' => 25]))
            ->assertStatus(422)->assertJsonValidationErrors(['kilos']);
    }

    public function test_rezaga_mayor_que_los_kilos_422(): void
    {
        $c = $this->cv001();

        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => 100.5]))
            ->assertStatus(422)->assertJsonValidationErrors(['rezaga_kg']);
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['rezaga_kg']);
        // Igual a los kilos y cero son válidos.
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => 100]))->assertStatus(201);
        $this->postJson($this->url(), $this->cuerpo($c, ['kilos' => 100, 'rezaga_kg' => 0]))->assertStatus(201);
    }

    // ---- Empresa ajena ----

    public function test_convenio_de_otra_empresa_en_el_cuerpo_422(): void
    {
        $ajeno = $this->crearConvenio($this->otra);
        $tipoAjeno = $this->tipoPrincipal($ajeno);

        $this->postJson($this->url(), [
            'convenio_id' => $ajeno->id, 'fecha' => '2026-06-10', 'tipo_carga_id' => $tipoAjeno, 'cantidad' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['convenio_id']);
        $this->assertSame(0, Recepcion::count());
    }

    public function test_tipo_de_carga_de_otra_empresa_422(): void
    {
        $c = $this->cv001();
        $ajeno = $this->crearConvenio($this->otra);

        $this->postJson($this->url(), $this->cuerpo($c, ['tipo_carga_id' => $this->tipoPrincipal($ajeno)]))
            ->assertStatus(422)->assertJsonValidationErrors(['tipo_carga_id']);
    }

    public function test_put_y_delete_de_recepcion_de_otra_empresa_404(): void
    {
        $ajeno = $this->crearConvenio($this->otra);
        $rec = $this->crearRecepcion($ajeno);
        $propio = $this->cv001();

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($propio, ['fecha' => '2026-07-01']))->assertStatus(404);
        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertStatus(404);
        $this->assertNotNull(Recepcion::find($rec->id));
    }

    public function test_put_y_delete_de_id_inexistente_404(): void
    {
        $c = $this->cv001();

        $this->putJson($this->url('recepciones/99999'), $this->cuerpo($c))->assertStatus(404);
        $this->deleteJson($this->url('recepciones/99999'))->assertStatus(404);
    }

    public function test_el_listado_no_mezcla_empresas(): void
    {
        $propio = $this->cv001();
        $this->crearRecepcion($propio);
        $ajeno = $this->crearConvenio($this->otra);
        $this->crearRecepcion($ajeno);

        $r = $this->getJson($this->url('recepciones?temporada_id='.$propio->temporada_id));

        $r->assertOk();
        $this->assertCount(1, $r->json('data.data'));
        // Temporada de otra empresa: 422 (exists acotado).
        $this->getJson($this->url('recepciones?temporada_id='.$ajeno->temporada_id))
            ->assertStatus(422)->assertJsonValidationErrors(['temporada_id']);
    }

    // ---- Edición y baja ----

    public function test_editar_cambia_los_datos_y_el_total_semanal(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 1300, 'created_by' => $this->admin->id]);

        $r = $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['cantidad' => 1500, 'notas' => 'Corregida']));

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $rec->id)
            ->assertJsonPath('data.folio', $rec->folio)
            ->assertJsonPath('data.notas', 'Corregida');
        $this->assertEqualsWithDelta(1500, $r->json('data.semana.recibido'), 0.001);
        $this->assertEqualsWithDelta(277500, $r->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(1500, IndicadoresConvenio::de(Convenio::find($c->id))['semana_actual']['recibido'], 0.001);
        $this->assertSame($this->admin->id, Recepcion::find($rec->id)->created_by);
    }

    public function test_editar_aplica_las_mismas_reglas(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 10]);

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['fecha' => '2026-07-20']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha']);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['cantidad' => 2.5]))
            ->assertStatus(422)->assertJsonValidationErrors(['cantidad']);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['kilos' => 10, 'rezaga_kg' => 11]))
            ->assertStatus(422)->assertJsonValidationErrors(['rezaga_kg']);
        $this->assertEqualsWithDelta(10, (float) Recepcion::find($rec->id)->cantidad, 0.001);
    }

    public function test_editar_pasando_a_otro_convenio_exige_que_este_activo(): void
    {
        $a = $this->cv001();
        $rec = $this->crearRecepcion($a, ['fecha' => '2026-07-14', 'cantidad' => 10]);
        $suspendido = $this->cv001(['status' => 'suspendido']);
        $activo = $this->cv001();

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($suspendido))
            ->assertStatus(422)->assertJsonPath('errors.convenio_id.0', 'El convenio no está activo.');
        $this->assertSame($a->id, Recepcion::find($rec->id)->convenio_id);

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($activo))
            ->assertOk()->assertJsonPath('data.convenio_id', $activo->id);
    }

    public function test_editar_valida_la_fecha_contra_el_convenio_final(): void
    {
        $a = $this->cv001();
        $corto = $this->cv001(['fecha_inicio' => '2026-07-10', 'fecha_fin' => '2026-07-20']);
        $rec = $this->crearRecepcion($a, ['fecha' => '2026-06-23', 'cantidad' => 10]);

        // Cambia de convenio pero la fecha se queda fuera del nuevo.
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($corto, ['fecha' => '2026-06-23']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha']);
    }

    public function test_editar_una_recepcion_de_un_convenio_que_ya_no_esta_activo_422(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 10]);
        Convenio::whereKey($c->id)->update(['status' => 'finalizado']);

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['cantidad' => 20]))
            ->assertStatus(422)->assertJsonPath('errors.convenio_id.0', 'El convenio no está activo.');
    }

    public function test_editar_o_borrar_una_recepcion_liquidada_422(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 10, 'liquidacion_id' => 77]);

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['cantidad' => 20]))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', 'Ya está en una liquidación: no se puede cambiar.');
        $this->deleteJson($this->url("recepciones/{$rec->id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', 'Ya está en una liquidación: no se puede cambiar.');

        $rec->refresh();
        $this->assertEqualsWithDelta(10, (float) $rec->cantidad, 0.001);
        $this->assertNull($rec->deleted_at);
    }

    public function test_borrar_sin_liquidar_quita_la_recepcion_de_los_totales(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 1300]);
        $otra = $this->crearRecepcion($c, ['fecha' => '2026-07-13', 'cantidad' => 200]);

        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertOk()->assertJsonPath('success', true);

        $this->assertSoftDeleted('abs_recepciones', ['id' => $rec->id]);
        $this->assertNull(Recepcion::find($rec->id));
        $i = IndicadoresConvenio::de(Convenio::find($c->id));
        $this->assertEqualsWithDelta(200, $i['recibido'], 0.001);
        $this->assertEqualsWithDelta(200, $i['semana_actual']['recibido'], 0.001);

        $semana = $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'));
        $this->assertEqualsWithDelta(200, $semana->json('data.convenios.0.recibido_semana'), 0.001);
        $this->assertSame([$otra->id], collect($semana->json('data.recepciones'))->pluck('id')->all());
    }

    // ---- Permisos ----

    public function test_sin_permisos_403_en_todo(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url('recepciones?temporada_id='.$c->temporada_id))->assertStatus(403);
        $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'))->assertStatus(403);
        $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(403);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c))->assertStatus(403);
        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertStatus(403);
    }

    public function test_cada_accion_pide_su_permiso(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['cantidad' => 10]);
        Sanctum::actingAs($this->captor);

        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['view']);
        $this->getJson($this->url('recepciones?temporada_id='.$c->temporada_id))->assertOk();
        $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'))->assertOk();
        $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(403);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c))->assertStatus(403);
        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertStatus(403);

        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['create']);
        $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(201)->assertJsonPath('data.autor.id', $this->captor->id);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c))->assertStatus(403);

        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['edit']);
        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, ['cantidad' => 11]))->assertOk();
        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertStatus(403);

        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['delete']);
        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertOk();
    }

    public function test_el_permiso_de_convenios_no_da_acceso_a_recepciones(): void
    {
        $c = $this->cv001();
        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view', 'create', 'edit', 'delete']);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url('recepciones?temporada_id='.$c->temporada_id))->assertStatus(403);
    }

    // ---- Tiempo real ----

    public function test_difunde_el_evento_recepcion_en_alta_edicion_y_baja(): void
    {
        Event::fake([AbastecimientoUpdated::class]);
        $c = $this->cv001();

        $id = $this->postJson($this->url(), $this->cuerpo($c))->assertStatus(201)->json('data.id');
        $this->putJson($this->url("recepciones/{$id}"), $this->cuerpo($c, ['cantidad' => 5]))->assertOk();
        $this->deleteJson($this->url("recepciones/{$id}"))->assertOk();

        foreach (['created', 'updated', 'deleted'] as $accion) {
            Event::assertDispatched(
                AbastecimientoUpdated::class,
                fn ($e) => $e->data['entidad'] === 'recepcion' && $e->action === $accion && $e->data['id'] === $id,
            );
        }
    }

    // ---- Listado ----

    public function test_listado_trae_convenio_tipo_importe_y_autor(): void
    {
        $c = $this->cv001();
        $rec = $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 1300, 'created_by' => $this->admin->id, 'liquidacion_id' => 5]);

        $r = $this->getJson($this->url('recepciones?temporada_id='.$c->temporada_id));

        $r->assertOk()->assertJsonPath('success', true);
        $fila = $r->json('data.data.0');
        $this->assertSame($rec->id, $fila['id']);
        $this->assertSame($rec->folio, $fila['folio']);
        $this->assertSame($c->id, $fila['convenio']['id']);
        $this->assertSame($c->folio, $fila['convenio']['folio']);
        $this->assertSame('caja', $fila['convenio']['unidad']);
        $this->assertSame('MXN', $fila['convenio']['moneda']);
        $this->assertSame('Productor de prueba', $fila['convenio']['productor']['nombre']);
        $this->assertArrayHasKey('nombre', $fila['convenio']['variedad']);
        $this->assertSame($this->tipoPrincipal($c), $fila['tipo_carga']['id']);
        $this->assertEqualsWithDelta(240500, $fila['importe_estimado'], 0.001);
        $this->assertTrue($fila['liquidada']);
        $this->assertSame($this->admin->id, $fila['autor']['id']);
        $this->assertSame(50, $r->json('data.per_page'));
    }

    public function test_listado_filtra_por_semana(): void
    {
        $c = $this->cv001();
        $esta = $this->crearRecepcion($c, ['fecha' => '2026-07-13']);
        $domingo = $this->crearRecepcion($c, ['fecha' => '2026-07-12']);
        $lunesSiguiente = $this->crearRecepcion($c, ['fecha' => '2026-07-14']);
        $t = $c->temporada_id;

        $ids = fn ($r) => collect($r->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$esta->id, $lunesSiguiente->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&lunes=2026-07-13"))));
        $this->assertSame([$domingo->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&lunes=2026-07-06"))));
        $this->getJson($this->url("recepciones?temporada_id={$t}&lunes=2026-07-14"))
            ->assertStatus(422)->assertJsonValidationErrors(['lunes']);
    }

    public function test_listado_filtra_por_convenio_y_busca(): void
    {
        $a = $this->cv001();
        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => 'Rancho Sol', 'is_active' => true]);
        $b = $this->cv001(['productor_id' => $otroProductor->id, 'temporada_id' => $a->temporada_id]);
        $ra = $this->crearRecepcion($a, ['ticket_proveedor' => 'TK-998']);
        $rb = $this->crearRecepcion($b, ['ticket_proveedor' => 'ZZ-1']);
        $t = $a->temporada_id;
        $ids = fn ($r) => collect($r->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$rb->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&convenio_id={$b->id}"))));
        $this->assertSame([$ra->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&search=TK-998"))));
        $this->assertSame([$ra->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&search=".$ra->folio))));
        $this->assertSame([$rb->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&search=Rancho%20Sol"))));
        $this->assertSame([$rb->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&search=".$b->folio))));
        $this->assertSame([], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&search=100%25"))));
    }

    public function test_listado_filtra_con_rezaga_y_sin_liquidar(): void
    {
        $c = $this->cv001();
        $limpia = $this->crearRecepcion($c, ['fecha' => '2026-07-01']);
        $conRezaga = $this->crearRecepcion($c, ['fecha' => '2026-07-02', 'kilos' => 500, 'rezaga_kg' => 25.5]);
        $rezagaCero = $this->crearRecepcion($c, ['fecha' => '2026-07-03', 'kilos' => 500, 'rezaga_kg' => 0]);
        $liquidada = $this->crearRecepcion($c, ['fecha' => '2026-07-04', 'liquidacion_id' => 9]);
        $t = $c->temporada_id;
        $ids = fn ($r) => collect($r->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$conRezaga->id], $ids($this->getJson($this->url("recepciones?temporada_id={$t}&con_rezaga=1"))));
        $this->assertSame(
            [$limpia->id, $conRezaga->id, $rezagaCero->id],
            $ids($this->getJson($this->url("recepciones?temporada_id={$t}&sin_liquidar=1"))),
        );
        $this->assertCount(4, $this->getJson($this->url("recepciones?temporada_id={$t}&sin_liquidar=0"))->json('data.data'));
    }

    public function test_listado_pagina_de_a_50_y_ordena_lo_mas_reciente_primero(): void
    {
        $c = $this->cv001(['fecha_inicio' => '2026-06-01']);
        for ($i = 0; $i < 55; $i++) {
            $this->crearRecepcion($c, ['fecha' => '2026-06-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), 'cantidad' => $i + 1]);
        }
        $t = $c->temporada_id;

        $p1 = $this->getJson($this->url("recepciones?temporada_id={$t}"));
        $p2 = $this->getJson($this->url("recepciones?temporada_id={$t}&page=2"));

        $this->assertCount(50, $p1->json('data.data'));
        $this->assertSame(55, $p1->json('data.total'));
        $this->assertSame(2, $p1->json('data.last_page'));
        $this->assertCount(5, $p2->json('data.data'));
        $fechas = collect($p1->json('data.data'))->pluck('fecha')->all();
        $ordenadas = $fechas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
    }

    public function test_listado_excluye_borradas_y_tiene_consultas_constantes(): void
    {
        $c = $this->cv001();
        $borrada = $this->crearRecepcion($c, ['fecha' => '2026-07-01']);
        $borrada->delete();
        for ($i = 0; $i < 2; $i++) {
            $this->crearRecepcion($c, ['fecha' => '2026-07-02']);
        }
        $t = $c->temporada_id;

        DB::enableQueryLog();
        $chico = $this->getJson($this->url("recepciones?temporada_id={$t}"));
        $pocas = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(2, $chico->json('data.data'));

        for ($i = 0; $i < 12; $i++) {
            $this->crearRecepcion($c, ['fecha' => '2026-07-03']);
        }
        DB::flushQueryLog();
        $grande = $this->getJson($this->url("recepciones?temporada_id={$t}"));
        $muchas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(14, $grande->json('data.data'));
        $this->assertSame($pocas, $muchas, 'El listado no debe consultar el precio por recepción.');
    }

    public function test_listado_exige_temporada(): void
    {
        $this->getJson($this->url())->assertStatus(422)->assertJsonValidationErrors(['temporada_id']);
    }

    // ---- Vista por semana ----

    public function test_semana_arma_la_rejilla_por_dia(): void
    {
        $c = $this->cv001();
        $this->crearRecepcion($c, ['fecha' => '2026-07-13', 'cantidad' => 400]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-13', 'cantidad' => 100]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 1300]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-06', 'cantidad' => 999]); // otra semana

        $r = $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'));

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.lunes', '2026-07-13')
            ->assertJsonPath('data.numero', 29)
            ->assertJsonPath('data.domingo', '2026-07-19')
            ->assertJsonPath('data.dias', ['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17', '2026-07-18']);
        $this->assertCount(1, $r->json('data.convenios'));
        $fila = $r->json('data.convenios.0');
        $this->assertSame($c->id, $fila['id']);
        $this->assertSame($c->folio, $fila['folio']);
        $this->assertSame('Productor de prueba', $fila['productor']['nombre']);
        $this->assertArrayHasKey('nombre', $fila['variedad']);
        $this->assertSame('caja', $fila['unidad']);
        $this->assertEqualsWithDelta(3000, $fila['meta_semana'], 0.001);
        $this->assertEqualsWithDelta(1800, $fila['recibido_semana'], 0.001);
        $this->assertEqualsWithDelta(500, $fila['por_dia']['2026-07-13'], 0.001);
        $this->assertEqualsWithDelta(1300, $fila['por_dia']['2026-07-14'], 0.001);
        $this->assertEqualsWithDelta(0, $fila['por_dia']['2026-07-15'], 0.001);
        $this->assertSame($r->json('data.dias'), array_keys($fila['por_dia']));
        $this->assertCount(3, $r->json('data.recepciones'));
        $this->assertSame('2026-07-14', $r->json('data.recepciones.0.fecha'));
    }

    public function test_semana_omite_convenios_sin_esa_semana_o_no_activos(): void
    {
        $c = $this->cv001();
        $this->cv001(['fecha_inicio' => '2026-07-20', 'fecha_fin' => '2026-08-30', 'metas' => ['2026-07-20' => 100], 'temporada_id' => $c->temporada_id]);
        $this->cv001(['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-07-12', 'metas' => ['2026-06-01' => 100], 'temporada_id' => $c->temporada_id]);
        $this->cv001(['status' => 'suspendido', 'temporada_id' => $c->temporada_id]);
        $this->cv001(['status' => 'borrador', 'temporada_id' => $c->temporada_id]);

        $r = $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'));

        $this->assertSame([$c->id], collect($r->json('data.convenios'))->pluck('id')->all());
    }

    public function test_semana_incluye_convenio_que_empieza_a_mitad_de_semana_y_acepta_el_cruce_de_anio(): void
    {
        $c = $this->cv001([
            'fecha_inicio' => '2026-12-30', 'fecha_fin' => '2027-01-10',
            'metas' => ['2026-12-28' => 100, '2027-01-04' => 100],
        ]);

        $r = $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-12-28'));

        $r->assertOk()->assertJsonPath('data.numero', 53)->assertJsonPath('data.domingo', '2027-01-03');
        $this->assertSame([$c->id], collect($r->json('data.convenios'))->pluck('id')->all());
        $this->assertEqualsWithDelta(100, $r->json('data.convenios.0.meta_semana'), 0.001);
    }

    public function test_semana_no_mezcla_unidades_y_usa_kilos_en_convenios_por_kilo(): void
    {
        $c = $this->cv001(['unidad' => 'kg', 'precio' => 12, 'metas' => ['2026-07-13' => 8000]]);
        $this->crearRecepcion($c, ['fecha' => '2026-07-14', 'cantidad' => 3, 'kilos' => 4860.75]);

        $r = $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-13'));

        $fila = $r->json('data.convenios.0');
        $this->assertSame('kg', $fila['unidad']);
        $this->assertEqualsWithDelta(8000, $fila['meta_semana'], 0.001);
        $this->assertEqualsWithDelta(4860.75, $fila['recibido_semana'], 0.001);
        $this->assertEqualsWithDelta(4860.75, $fila['por_dia']['2026-07-14'], 0.001);
        $this->assertEqualsWithDelta(58329, $r->json('data.recepciones.0.importe_estimado'), 0.001);
    }

    public function test_semana_con_lunes_que_no_es_lunes_422(): void
    {
        $c = $this->cv001();

        $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id.'&lunes=2026-07-14'))
            ->assertStatus(422)->assertJsonValidationErrors(['lunes']);
        $this->getJson($this->url('recepciones/semana?temporada_id='.$c->temporada_id))
            ->assertStatus(422)->assertJsonValidationErrors(['lunes']);
        $this->getJson($this->url('recepciones/semana?lunes=2026-07-13'))
            ->assertStatus(422)->assertJsonValidationErrors(['temporada_id']);
    }

    public function test_semana_de_temporada_ajena_422_y_no_se_confunde_con_el_id(): void
    {
        $ajeno = $this->crearConvenio($this->otra);

        $this->getJson($this->url('recepciones/semana?temporada_id='.$ajeno->temporada_id.'&lunes=2026-06-01'))
            ->assertStatus(422)->assertJsonValidationErrors(['temporada_id']);
    }
}
