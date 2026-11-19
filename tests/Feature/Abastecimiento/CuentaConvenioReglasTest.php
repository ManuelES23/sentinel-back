<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\User;
use App\Services\Abastecimiento\ReglasRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Las recepciones y los convenios respetan el dinero de la cuenta (spec 7 y 9): una recepción con pago vigente o
 * en una liquidación `liquidada` no se edita ni se borra; un convenio liquidado no recibe más recepciones; un
 * convenio con pagos o liquidación no se cancela ni cambia de moneda.
 */
class CuentaConvenioReglasTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function url(string $ruta): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    private function cuerpo(Convenio $c, Recepcion $base, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id,
            'fecha' => $base->fecha->format('Y-m-d'),
            'tipo_carga_id' => $base->tipo_carga_id,
            'cantidad' => 25,
        ];
    }

    private function error(TestResponse $res, string $llave): ?string
    {
        $res->assertStatus(422);

        return $res->json('errors')[$llave][0] ?? null;
    }

    public function test_recepcion_con_pago_vigente_no_se_edita_ni_se_borra_y_al_cancelar_el_pago_si(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($c, ['fecha' => '2026-06-02']);
        $libre = $this->crearRecepcion($c, ['fecha' => '2026-06-03']);
        $pago = $this->crearPago($c, ['folio' => 'AB-0042'], [$r->id => 300]);

        $mensaje = 'Tiene el pago AB-0042: cancela primero el pago.';
        $this->assertSame($mensaje, $this->error($this->putJson($this->url("recepciones/{$r->id}"), $this->cuerpo($c, $r)), 'id'));
        $this->assertSame($mensaje, $this->error($this->deleteJson($this->url("recepciones/{$r->id}")), 'id'));
        $this->assertSame('10.00', $r->fresh()->cantidad);
        $this->assertNull($r->fresh()->deleted_at);

        // Otra recepción del mismo convenio, sin pago, sigue libre.
        $this->putJson($this->url("recepciones/{$libre->id}"), $this->cuerpo($c, $libre))->assertOk();

        // Con varios pagos vigentes se nombra el de menor id; uno cancelado no cuenta.
        $segundo = $this->crearPago($c, ['folio' => 'AB-0043'], [$r->id => 50]);
        $this->assertSame($mensaje, $this->error($this->deleteJson($this->url("recepciones/{$r->id}")), 'id'));
        $pago->update(['status' => 'cancelado']);
        $this->assertSame('Tiene el pago AB-0043: cancela primero el pago.', $this->error($this->deleteJson($this->url("recepciones/{$r->id}")), 'id'));
        $segundo->update(['status' => 'cancelado']);

        $this->putJson($this->url("recepciones/{$r->id}"), $this->cuerpo($c, $r))->assertOk();
        $this->assertSame('25.00', $r->fresh()->cantidad);
        $this->deleteJson($this->url("recepciones/{$r->id}"))->assertOk();
        $this->assertSoftDeleted('abs_recepciones', ['id' => $r->id]);
    }

    public function test_recepcion_liquidada_dice_el_folio_de_la_liquidacion(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($c, ['folio' => 'LQ-0007']);
        $r = $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'liquidacion_id' => $liq->id]);

        $mensaje = 'Está en la liquidación LQ-0007: cancela primero la liquidación.';
        $this->assertSame($mensaje, $this->error($this->putJson($this->url("recepciones/{$r->id}"), $this->cuerpo($c, $r)), 'id'));
        $this->assertSame($mensaje, $this->error($this->deleteJson($this->url("recepciones/{$r->id}")), 'id'));

        // Ligada a una liquidación que ya no existe: el texto de siempre.
        $huerfana = $this->crearRecepcion($c, ['fecha' => '2026-06-03']);
        DB::table('abs_recepciones')->where('id', $huerfana->id)->update(['liquidacion_id' => 987654]);
        $this->assertSame(ReglasRecepcion::MENSAJE_LIQUIDADA, $this->error($this->deleteJson($this->url("recepciones/{$huerfana->id}")), 'id'));

        // Al cancelar la liquidación (que libera sus recepciones) ya se puede.
        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Precio mal capturado'])->assertOk();
        $this->putJson($this->url("recepciones/{$r->id}"), $this->cuerpo($c, $r))->assertOk();
    }

    public function test_alta_en_convenio_liquidado_da_422_y_tras_cancelar_la_liquidacion_si_entra(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($c, ['fecha' => '2026-06-02']);
        $otro = $this->crearConvenio($this->sbp);
        $movible = $this->crearRecepcion($otro, ['fecha' => '2026-06-04']);
        $liq = $this->crearLiquidacion($c);
        DB::table('abs_recepciones')->where('id', $r->id)->update(['liquidacion_id' => $liq->id]);

        $mensaje = 'El convenio ya está liquidado: cancela la liquidación para recibir más.';
        $this->assertSame($mensaje, $this->error($this->postJson($this->url('recepciones'), $this->cuerpo($c, $r, ['fecha' => '2026-06-05'])), 'convenio_id'));
        $this->assertSame(1, Recepcion::where('convenio_id', $c->id)->count());

        // Pasar una recepción de otro convenio a este también se rechaza.
        $this->assertSame($mensaje, $this->error($this->putJson($this->url("recepciones/{$movible->id}"), $this->cuerpo($c, $movible)), 'convenio_id'));
        $this->assertSame($otro->id, $movible->fresh()->convenio_id);

        $this->postJson($this->url("liquidaciones/{$liq->id}/cancelar"), ['motivo' => 'Corregir'])->assertOk();
        $this->postJson($this->url('recepciones'), $this->cuerpo($c, $r, ['fecha' => '2026-06-05']))->assertCreated();
        $this->assertSame(2, Recepcion::where('convenio_id', $c->id)->count());
    }

    public function test_importar_a_un_convenio_liquidado_da_422(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($c, ['fecha' => '2026-06-02']);
        $this->crearLiquidacion($c);
        $tipo = TipoCarga::findOrFail($c->precios()->where('es_principal', true)->value('tipo_carga_id'));
        $fila = [$c->folio, '2026-06-05', $tipo->nombre, 7, 70, null, 'TK-9'];

        $vista = $this->withHeader('Accept', 'application/json')
            ->post($this->url('recepciones/importar/vista-previa'), ['archivo' => $this->crearXlsx([$fila])]);
        $vista->assertOk()->assertJsonPath('data.filas.0.estado', 'error')
            ->assertJsonPath('data.filas.0.errores.0', 'El convenio ya está liquidado: cancela la liquidación para recibir más.');

        $res = $this->withHeader('Accept', 'application/json')
            ->post($this->url('recepciones/importar'), ['archivo' => $this->crearXlsx([$fila])]);
        $res->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(1, Recepcion::count());
    }

    public function test_el_importador_revisa_de_nuevo_bajo_el_bloqueo_si_el_convenio_se_liquido(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $tipoId = (int) $c->precios()->where('es_principal', true)->value('tipo_carga_id');
        $this->crearLiquidacion($c);

        // El validador ya dejó la fila «lista» (el convenio no estaba liquidado); la liquidación llegó después.
        $filas = [['fila' => 2, 'datos' => [
            'convenio_id' => $c->id, 'fecha' => '2026-06-05', 'tipo_carga_id' => $tipoId,
            'cantidad' => 7, 'kilos' => null, 'rezaga_kg' => null, 'ticket_proveedor' => null, 'notas' => null,
        ]]];

        try {
            app(\App\Services\Abastecimiento\ImportadorRecepciones::class)->importar($this->sbp, $this->admin, $filas);
            $this->fail('Debió rechazar el convenio liquidado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(
                ['archivo' => ["El convenio {$c->folio} ya está liquidado; revisa el convenio y vuelve a subir el archivo."]],
                $e->errors(),
            );
        }
        $this->assertSame(0, Recepcion::count());
    }

    public function test_convenio_con_un_adelanto_no_se_cancela_ni_cambia_moneda(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $pago = $this->crearPago($c, ['tipo' => 'adelanto']);

        $this->assertSame(
            'Tiene pagos o una liquidación: cancélalos primero o finaliza el convenio en lugar de cancelarlo.',
            $this->error($this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => 'cancelado']), 'status'),
        );
        $this->assertSame('activo', $c->fresh()->status);

        // Sin recepciones pero con dinero: la moneda queda bloqueada con el mensaje del dinero.
        $cuerpo = $this->cuerpoEdicion($c, ['moneda' => 'USD']);
        $this->assertSame('No se puede cambiar: el convenio ya tiene pagos o una liquidación.', $this->error($this->putJson($this->url("convenios/{$c->id}"), $cuerpo), 'moneda'));
        $this->assertSame('MXN', $c->fresh()->moneda);

        // Finalizar sí se puede; y un pago cancelado ya no cuenta.
        $pago->update(['status' => 'cancelado']);
        $this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => 'cancelado'])->assertOk();
    }

    public function test_convenio_con_liquidacion_sin_recepciones_no_se_cancela(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $this->crearLiquidacion($c);

        $this->assertSame(
            'Tiene pagos o una liquidación: cancélalos primero o finaliza el convenio en lugar de cancelarlo.',
            $this->error($this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => 'cancelado']), 'status'),
        );
        $this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => 'finalizado'])->assertOk();
    }

    public function test_el_saldo_inicial_cancelado_o_una_liquidacion_cancelada_no_bloquean_el_convenio(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $this->crearLiquidacion($c, ['status' => 'cancelada']);
        $this->crearPago($c, ['tipo' => 'saldo_inicial', 'status' => 'cancelado']);

        $this->patchJson($this->url("convenios/{$c->id}/estado"), ['status' => 'cancelado'])->assertOk();
        $this->assertSame(0, Abono::where('convenio_id', $c->id)->where('status', 'aplicado')->count());
        $this->assertSame(0, Liquidacion::where('convenio_id', $c->id)->where('status', 'liquidada')->count());
    }

    /** Cuerpo de PUT convenios/{id} con lo que ya tiene el convenio (precios incluidos) y los cambios de `$over`. */
    private function cuerpoEdicion(Convenio $c, array $over): array
    {
        $c->load('precios', 'metas');

        return $over + [
            'temporada_id' => $c->temporada_id,
            'productor_id' => $c->productor_id,
            'cultivo_id' => $c->cultivo_id,
            'variedad_id' => $c->variedad_id,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => 0,
            'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
            'modo_volumen' => $c->modo_volumen,
            'usa_calibres' => false,
            'precios' => $c->precios->map(fn ($p) => [
                'tipo_carga_id' => $p->tipo_carga_id,
                'precio' => (float) $p->precio,
                'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
                'es_principal' => (bool) $p->es_principal,
            ])->all(),
            'metas' => $c->metas->map(fn ($m) => ['semana' => $m->semana->format('Y-m-d'), 'cantidad' => (float) $m->cantidad])->all(),
        ];
    }
}
