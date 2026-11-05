<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Convenio;
use App\Models\User;
use App\Services\Abastecimiento\MetasConvenio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class VolumenYEstadosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function url(string $ruta, string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    /** Cuerpo de PUT/POST que repite el convenio tal cual, con los cambios de $over. */
    private function cuerpoDe(Convenio $c, array $over = []): array
    {
        $c->load(['precios', 'metas']);

        return array_merge([
            'temporada_id' => $c->temporada_id,
            'productor_id' => $c->productor_id,
            'variedad_id' => $c->variedad_id,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => 0,
            'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
            'modo_volumen' => $c->modo_volumen,
            'volumen_total' => $c->volumen_total,
            'precios' => $c->precios->map(fn ($p) => [
                'tipo_carga_id' => $p->tipo_carga_id,
                'precio' => $p->precio,
                'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
                'es_principal' => $p->es_principal,
            ])->all(),
        ], $over);
    }

    private function metasDe(Convenio $c): array
    {
        return MetasConvenio::metas(Convenio::findOrFail($c->id));
    }

    // ---- Reparto del volumen total ----

    public function test_total_se_reparte_parejo(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'status' => 'borrador', 'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 27000,
            'fecha_inicio' => '2026-06-22', 'fecha_fin' => '2026-08-23',
        ]);

        $metas = $this->metasDe($c);

        $this->assertCount(9, $metas);
        $this->assertSame('2026-06-22', array_key_first($metas));
        $this->assertSame('2026-08-17', array_key_last($metas));
        $this->assertSame([3000.0], array_values(array_unique($metas)));
        $this->assertSame(27000.0, MetasConvenio::pactado(Convenio::findOrFail($c->id)));
    }

    public function test_total_con_resto_en_la_ultima(): void
    {
        $cajas = $this->crearConvenio($this->sbp, [
            'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 1000, 'unidad' => 'caja',
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-21',
        ]);
        $this->assertSame(['2026-06-01' => 333.0, '2026-06-08' => 333.0, '2026-06-15' => 334.0], $this->metasDe($cajas));

        $kilos = $this->crearConvenio($this->sbp, [
            'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 1000, 'unidad' => 'kg',
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-21',
        ]);
        $this->assertSame(['2026-06-01' => 333.33, '2026-06-08' => 333.33, '2026-06-15' => 333.34], $this->metasDe($kilos));
        $this->assertSame(1000.0, MetasConvenio::pactado(Convenio::findOrFail($kilos->id)));
    }

    public function test_convenio_que_cruza_el_anio(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 3000,
            'fecha_inicio' => '2026-12-21', 'fecha_fin' => '2027-01-10',
        ]);

        $this->assertSame(
            ['2026-12-21' => 1000.0, '2026-12-28' => 1000.0, '2027-01-04' => 1000.0],
            $this->metasDe($c),
        );
    }

    public function test_semanal_devuelve_las_filas_guardadas_y_usa_la_relacion_cargada(): void
    {
        $c = $this->crearConvenio($this->sbp, ['metas' => ['2026-06-08' => 50, '2026-06-01' => 70.5]]);

        $this->assertSame(['2026-06-01' => 70.5, '2026-06-08' => 50.0], $this->metasDe($c));
        $this->assertSame(120.5, MetasConvenio::pactado($c));

        // Con la relación cargada no consulta de nuevo.
        $cargado = Convenio::with('metas')->findOrFail($c->id);
        \DB::enableQueryLog();
        MetasConvenio::metas($cargado);
        $this->assertCount(0, \DB::getQueryLog());
    }

    // ---- Reglas de volumen en alta y edición ----

    public function test_total_exige_volumen_y_no_acepta_metas(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $base = $this->cuerpoDe($c, ['modo_volumen' => 'total']);

        $this->postJson($this->url('convenios'), $base + ['metas' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['volumen_total']);
        $this->postJson($this->url('convenios'), array_merge($base, ['volumen_total' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['volumen_total']);
        $this->postJson($this->url('convenios'), array_merge($base, [
            'volumen_total' => 500, 'metas' => [['semana' => '2026-06-01', 'cantidad' => 5]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['metas']);

        $this->postJson($this->url('convenios'), array_merge($base, ['volumen_total' => 500]))
            ->assertCreated()->assertJsonPath('data.modo_volumen', 'total')->assertJsonCount(0, 'data.metas');
    }

    public function test_pasar_a_total_borra_las_metas_guardadas(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['modo_volumen' => 'total', 'volumen_total' => 800]))
            ->assertOk()->assertJsonCount(0, 'data.metas');
        $this->assertSame(0, $c->metas()->count());
    }

    public function test_semanal_exige_al_menos_una_meta_y_null_no_borra(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['metas']);

        foreach ([null, []] as $metas) {
            $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['metas' => $metas]))
                ->assertStatus(422)->assertJsonValidationErrors(['metas']);
        }
        $this->assertSame(4, $c->metas()->count());
    }

    public function test_semanal_rechaza_semana_que_no_es_lunes(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [['semana' => '2026-06-02', 'cantidad' => 10]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['metas.0.semana']);
        $this->assertSame(1, Convenio::count());
    }

    public function test_semanal_rechaza_semana_fuera_del_convenio(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [
            ['semana' => '2026-06-01', 'cantidad' => 10], ['semana' => '2026-07-06', 'cantidad' => 10],
        ]]))->assertStatus(422)->assertJsonValidationErrors(['metas.1.semana']);
    }

    public function test_semanal_cajas_enteras(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [['semana' => '2026-06-01', 'cantidad' => 12.5]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['metas.0.cantidad']);
        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [['semana' => '2026-06-01', 'cantidad' => 12]]]))
            ->assertCreated();
    }

    public function test_semanal_kilos_aceptan_dos_decimales_y_no_mas(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'unidad' => 'kg']);

        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [['semana' => '2026-06-01', 'cantidad' => 12.345]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['metas.0.cantidad']);
        $this->postJson($this->url('convenios'), $this->cuerpoDe($c, ['metas' => [['semana' => '2026-06-01', 'cantidad' => 12.35]]]))
            ->assertCreated();
    }

    public function test_cambiar_fechas_con_metas_fuera(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']); // metas 1, 8, 15 y 22 de junio
        $antes = $this->metasDe($c);

        // Sin enviar metas: las guardadas se revisan contra las fechas nuevas.
        $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['fecha_fin' => '2026-06-14']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_fin'])
            ->assertJsonPath('errors.fecha_fin.0', 'Hay metas fuera de las nuevas fechas: ajústalas primero.');
        $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['fecha_inicio' => '2026-06-15']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio']);

        $this->assertSame($antes, $this->metasDe($c));
        $this->assertSame('2026-06-28', $c->fresh()->fecha_fin->format('Y-m-d'));

        // Ampliar las fechas sí se puede.
        $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['fecha_fin' => '2026-07-12']))->assertOk();
    }

    public function test_editar_sin_enviar_metas_en_total_ignora_lo_guardado(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 900]);

        $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c, ['fecha_fin' => '2026-06-21']))
            ->assertOk();
        $this->assertSame(['2026-06-01' => 300.0, '2026-06-08' => 300.0, '2026-06-15' => 300.0], $this->metasDe($c));
    }

    public function test_finalizado_y_cancelado_no_se_editan(): void
    {
        foreach (['finalizado', 'cancelado'] as $status) {
            $c = $this->crearConvenio($this->sbp, ['status' => $status]);
            $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c))
                ->assertStatus(422)->assertJsonValidationErrors(['status'])
                ->assertJsonPath('errors.status.0', 'Un convenio finalizado o cancelado no se edita.');
        }

        foreach (['borrador', 'activo', 'suspendido'] as $status) {
            $c = $this->crearConvenio($this->sbp, ['status' => $status]);
            $this->putJson($this->url("convenios/{$c->id}"), $this->cuerpoDe($c))->assertOk();
        }
    }

    // ---- Estados ----

    private function estado(Convenio $c, string $status)
    {
        return $this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => $status]);
    }

    public function test_activar_sin_volumen_422(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'metas' => []]);

        $this->estado($c, 'activo')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('borrador', $c->fresh()->status);

        $total = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 0]);
        $this->estado($total, 'activo')->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_activar_sin_precios_422(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $c->precios()->delete();

        $this->estado($c, 'activo')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('borrador', $c->fresh()->status);
    }

    public function test_activar_ok(): void
    {
        Event::fake([AbastecimientoUpdated::class]);
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);

        $this->estado($c, 'activo')->assertOk()->assertJsonPath('data.status', 'activo');
        $this->assertSame('activo', $c->fresh()->status);
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'convenio' && $e->action === 'updated');

        // En modo total también basta con un volumen mayor a cero.
        $total = $this->crearConvenio($this->sbp, ['status' => 'borrador', 'metas' => [], 'modo_volumen' => 'total', 'volumen_total' => 500]);
        $this->estado($total, 'activo')->assertOk();
    }

    public function test_suspender_reanudar_y_finalizar(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);

        $this->estado($c, 'suspendido')->assertOk()->assertJsonPath('data.status', 'suspendido');
        $this->estado($c, 'activo')->assertOk()->assertJsonPath('data.status', 'activo');
        $this->estado($c, 'suspendido')->assertOk();
        $this->estado($c, 'finalizado')->assertOk()->assertJsonPath('data.status', 'finalizado');
    }

    public function test_cancelar_con_recepciones_422(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $this->crearRecepcion($c);

        $this->estado($c, 'cancelado')->assertStatus(422)->assertJsonValidationErrors(['status'])
            ->assertJsonPath('errors.status.0', 'Tiene recepciones: finalízalo en lugar de cancelarlo.');
        $this->assertSame('activo', $c->fresh()->status);

        $this->estado($c, 'finalizado')->assertOk();
    }

    public function test_cancelar_sin_recepciones_ok_desde_cualquier_estado_abierto(): void
    {
        foreach (['borrador', 'activo', 'suspendido'] as $status) {
            $c = $this->crearConvenio($this->sbp, ['status' => $status]);
            $this->estado($c, 'cancelado')->assertOk()->assertJsonPath('data.status', 'cancelado');
        }
    }

    public function test_transicion_invalida_422(): void
    {
        $borrador = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        foreach (['suspendido', 'finalizado', 'borrador'] as $destino) {
            $this->estado($borrador, $destino)->assertStatus(422)->assertJsonValidationErrors(['status']);
        }

        foreach (['finalizado', 'cancelado'] as $terminal) {
            $c = $this->crearConvenio($this->sbp, ['status' => $terminal]);
            foreach (['activo', 'suspendido', 'finalizado', 'cancelado'] as $destino) {
                $this->estado($c, $destino)->assertStatus(422)->assertJsonValidationErrors(['status']);
            }
            $this->assertSame($terminal, $c->fresh()->status);
        }

        $this->estado($borrador, 'inventado')->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_estado_requiere_permiso_y_es_de_la_empresa(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $ajeno = $this->crearConvenio($this->otra, ['status' => 'activo']);

        $this->estado($ajeno, 'suspendido')->assertNotFound();

        Sanctum::actingAs($this->captor);
        $this->estado($c, 'suspendido')->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['edit']);
        $this->estado($c, 'suspendido')->assertOk();
    }
}
