<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmConfiguracionImpuesto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Reglas de monto (spec 2026-10-30 §7): la cotización reemplaza el monto
 * estimado de la oportunidad. Solo aplican con productos_en_oportunidad = false
 * (el default de una empresa nueva).
 */
class MontoOportunidadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected CrmCliente $cliente;
    protected CrmProducto $producto;
    protected CrmOportunidad $oportunidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => false]);
        CrmConfiguracionImpuesto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'IVA', 'tasa' => 16, 'activo' => true, 'orden' => 1,
        ]);

        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 250,
        ]);
        $this->oportunidad = $this->nuevaOportunidad(['monto_esperado' => 5000, 'etapa' => 'calificado']);
    }

    private function nuevaOportunidad(array $atributos = []): CrmOportunidad
    {
        return CrmOportunidad::create($atributos + [
            'empresa_id' => $this->enterprise->id,
            'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad de prueba',
        ]);
    }

    /** POST de una cotización de una sola línea (cantidad 1 salvo que se indique). */
    private function cotizar(float $precio, float $descuento = 0, ?CrmOportunidad $oportunidad = null, float $cantidad = 1)
    {
        $oportunidad ??= $this->oportunidad;

        return $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'descuento_global_pct' => $descuento,
                'lineas' => [
                    ['producto_id' => $this->producto->id, 'cantidad' => $cantidad, 'precio_unitario' => $precio],
                ],
            ]);
    }

    private function enviar(int $cotizacionId): void
    {
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/enviar")->assertOk();
    }

    private function aprobar(int $cotizacionId)
    {
        return $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacionId}/aprobar");
    }

    private function monto(?CrmOportunidad $oportunidad = null): float
    {
        return (float) ($oportunidad ?? $this->oportunidad)->fresh()->monto_esperado;
    }

    // ---------------------------------------------------------------- Monto

    public function test_guardar_cotizacion_en_abierta_reemplaza_el_estimado(): void
    {
        // Líneas 1000, descuento 10 %, IVA 16 %: el monto es la base sin impuestos.
        $respuesta = $this->cotizar(1000, 10)->assertCreated();

        $this->assertEqualsWithDelta(1160 * 0.9, (float) $respuesta->json('data.total'), 0.001);
        $this->assertSame('900.00', $this->oportunidad->fresh()->monto_esperado);
    }

    public function test_editar_cotizacion_actualiza_el_monto(): void
    {
        $id = $this->cotizar(1000)->assertCreated()->json('data.id');
        $this->assertSame(1000.0, $this->monto());

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$id}", [
            'descuento_global_pct' => 20,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => 1500]],
        ])->assertOk();

        $this->assertSame(2400.0, $this->monto());
    }

    public function test_rechazar_vuelve_a_la_mas_reciente_vigente(): void
    {
        $primera = $this->cotizar(100)->json('data.id');
        $segunda = $this->cotizar(200)->json('data.id');
        $this->enviar($segunda);
        $this->assertSame(200.0, $this->monto());

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$segunda}/rechazar")->assertOk();

        $this->assertSame(100.0, $this->monto());
        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $primera, 'estado' => 'borrador']);
    }

    public function test_rechazar_la_unica_conserva_el_valor(): void
    {
        $id = $this->cotizar(300)->json('data.id');
        $this->enviar($id);

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/rechazar")->assertOk();

        $this->assertSame(300.0, $this->monto());
    }

    public function test_aprobar_toma_la_aprobada(): void
    {
        $primera = $this->cotizar(100)->json('data.id');
        $this->cotizar(200);
        $this->assertSame(200.0, $this->monto());
        $this->enviar($primera);

        $this->aprobar($primera)->assertOk();

        $this->assertSame(100.0, $this->monto());
        $this->assertSame('cerrado_ganado', $this->oportunidad->fresh()->etapa);
    }

    public function test_aprobar_por_el_controlador_deja_por_cobrar(): void
    {
        $id = $this->cotizar(100)->json('data.id');
        $this->enviar($id);
        $this->assertNull($this->oportunidad->fresh()->estado_cobro);

        $this->aprobar($id)->assertOk();

        $this->assertSame('por_cobrar', $this->oportunidad->fresh()->estado_cobro);
    }

    public function test_borrador_en_ganada_no_cambia_el_monto(): void
    {
        $id = $this->cotizar(900)->json('data.id');
        $this->enviar($id);
        $this->aprobar($id)->assertOk();

        $this->cotizar(5000)->assertCreated();

        $this->assertSame(900.0, $this->monto());
    }

    public function test_superado_cambia_monto_y_conserva_fecha_cierre_real_y_estado_cobro(): void
    {
        $primera = $this->cotizar(900)->json('data.id');
        $this->enviar($primera);
        $this->aprobar($primera)->assertOk();

        // La venta se registró en septiembre y ya se cobró.
        $this->oportunidad->fresh()->forceFill([
            'fecha_cierre_real' => '2026-09-15 10:00:00',
            'estado_cobro' => 'cobrada',
        ])->saveQuietly();

        $segunda = $this->cotizar(2000)->json('data.id');
        $this->enviar($segunda);
        $this->aprobar($segunda)->assertOk();

        $oportunidad = $this->oportunidad->fresh();
        $this->assertSame(2000.0, (float) $oportunidad->monto_esperado);
        $this->assertSame('2026-09-15 10:00:00', $oportunidad->fecha_cierre_real->format('Y-m-d H:i:s'));
        $this->assertSame('cobrada', $oportunidad->estado_cobro);
        $this->assertSame('cerrado_ganado', $oportunidad->etapa);
        $this->assertDatabaseHas('crm_cotizaciones', ['id' => $primera, 'estado' => 'superado']);
    }

    public function test_perdida_no_cambia(): void
    {
        $id = $this->cotizar(500)->json('data.id');
        $this->oportunidad->fresh()->forceFill([
            'etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio', 'fecha_cierre_real' => now(),
        ])->saveQuietly();

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$id}", [
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 9999]],
        ])->assertOk();

        $this->assertSame(500.0, $this->monto());
    }

    public function test_guardar_cotizacion_no_cambia_la_etapa(): void
    {
        $this->cotizar(500)->assertCreated();

        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);
    }

    public function test_con_productos_en_oportunidad_la_cotizacion_no_toca_el_monto(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => true]);

        $this->cotizar(777)->assertCreated();

        $this->assertSame(5000.0, $this->monto());
    }

    // ------------------------------------- Con productos_en_oportunidad = true

    /** Con el flag encendido la cotización nunca mueve el monto (viene de las líneas de la oportunidad). */
    public function test_con_productos_en_oportunidad_editar_rechazar_y_aprobar_no_tocan_el_monto(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => true]);

        $editable = $this->cotizar(111)->assertCreated()->json('data.id');
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$editable}", [
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 3, 'precio_unitario' => 222]],
        ])->assertOk();
        $this->assertSame(5000.0, $this->monto());

        $aRechazar = $this->cotizar(333)->json('data.id');
        $this->enviar($aRechazar);
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$aRechazar}/rechazar")->assertOk();
        $this->assertSame(5000.0, $this->monto());

        $aAprobar = $this->cotizar(444)->json('data.id');
        $this->enviar($aAprobar);
        $this->aprobar($aAprobar)->assertOk();

        $oportunidad = $this->oportunidad->fresh();
        $this->assertSame('cerrado_ganado', $oportunidad->etapa);
        $this->assertSame(5000.0, (float) $oportunidad->monto_esperado);
    }

    /** Camino legado: el monto que manda el cliente se ignora siempre cuando las líneas calculan el monto. */
    public function test_con_productos_en_oportunidad_el_monto_manual_se_ignora_como_antes(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => true]);

        // Abierta: 200 sin 422, el monto no cambia.
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$this->oportunidad->id}", ['monto_esperado' => 1234, 'nombre' => 'Abierta'])
            ->assertOk();
        $this->assertSame('Abierta', $this->oportunidad->fresh()->nombre);
        $this->assertSame(5000.0, $this->monto());

        // Ganada: tampoco 422 (la regla nueva no aplica) y el monto no cambia.
        $ganada = $this->nuevaOportunidad(['monto_esperado' => 700, 'etapa' => 'cerrado_ganado', 'fecha_cierre_real' => now()]);
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$ganada->id}", ['monto_esperado' => 800])
            ->assertOk();
        $this->assertSame(700.0, $this->monto($ganada));
    }

    // ----------------------- Cotización vigente que no es la más reciente

    public function test_ganada_con_borrador_posterior_de_otro_monto_sigue_atribuida_a_la_aprobada(): void
    {
        $primera = $this->cotizar(900)->assertCreated();
        $this->enviar($primera->json('data.id'));
        $this->aprobar($primera->json('data.id'))->assertOk();

        // Borrador MÁS NUEVO con otro monto: la ganada conserva el monto de la aprobada.
        $this->cotizar(5000)->assertCreated();
        $this->assertSame(900.0, $this->monto());

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertOk()
            ->assertJsonPath('data.monto_origen', ['tipo' => 'cotizacion', 'folio' => $primera->json('data.folio')]);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$this->oportunidad->id}", ['monto_esperado' => 1234])
            ->assertStatus(422)->assertJsonValidationErrors('monto_esperado');
        $this->assertSame(900.0, $this->monto());
    }

    public function test_editar_la_cotizacion_mas_antigua_la_vuelve_el_origen_del_monto(): void
    {
        $antigua = $this->cotizar(100)->assertCreated();
        $reciente = $this->cotizar(200)->assertCreated();
        $this->assertSame(200.0, $this->monto());

        // Editar la más vieja (no la última) fija el monto a la base de ESA cotización.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$antigua->json('data.id')}", [
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 300]],
        ])->assertOk();

        $this->assertSame(300.0, $this->monto());
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertOk()
            ->assertJsonPath('data.monto_origen', ['tipo' => 'cotizacion', 'folio' => $antigua->json('data.folio')]);
        $this->assertNotSame($antigua->json('data.folio'), $reciente->json('data.folio'));

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$this->oportunidad->id}", ['monto_esperado' => 999])
            ->assertStatus(422)->assertJsonValidationErrors('monto_esperado');
        $this->assertSame(300.0, $this->monto());
    }

    // ------------------------------------------------- Edición manual del monto

    public function test_cerrada_no_edita_monto_a_mano(): void
    {
        $ganada = $this->nuevaOportunidad(['monto_esperado' => 700, 'etapa' => 'cerrado_ganado', 'fecha_cierre_real' => now()]);

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/oportunidades/{$ganada->id}", ['monto_esperado' => 800])
            ->assertStatus(422)->assertJsonValidationErrors('monto_esperado');
        $this->assertSame(700.0, $this->monto($ganada));

        // Mandar el mismo valor (al centavo) no es un cambio.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/oportunidades/{$ganada->id}", ['monto_esperado' => 700.004, 'nombre' => 'Renombrada'])
            ->assertOk();
        $this->assertSame('Renombrada', $ganada->fresh()->nombre);
        $this->assertSame(700.0, $this->monto($ganada));
    }

    public function test_monto_de_cotizacion_no_se_edita(): void
    {
        $this->cotizar(900)->assertCreated();

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$this->oportunidad->id}", ['monto_esperado' => 1234])
            ->assertStatus(422)->assertJsonValidationErrors('monto_esperado');

        $this->assertSame(900.0, $this->monto());
    }

    public function test_monto_que_no_coincide_sigue_editable(): void
    {
        // Oportunidad vieja: su cotización vigente tiene otro total que el monto guardado.
        $this->crearCotizacionDirecta($this->oportunidad, 'COT-V0001', 'enviado', 1000);

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertOk()->assertJsonPath('data.monto_origen.tipo', 'estimado');

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$this->oportunidad->id}", ['monto_esperado' => 6000])
            ->assertOk();
        $this->assertSame(6000.0, $this->monto());
    }

    // ---------------------------------------------------------------- Límite

    public function test_cotizacion_que_no_cabe_responde_422_sin_escribir(): void
    {
        $respuesta = $this->cotizar(CrmOportunidad::MONTO_MAXIMO + 1);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('máximo', $respuesta->json('message'));
        $this->assertSame(0, CrmCotizacion::count());
        $this->assertSame(0, DB::table('crm_oportunidad_productos')->count());
        $this->assertSame(5000.0, $this->monto());
    }

    public function test_editar_cotizacion_que_no_cabe_responde_422_sin_escribir(): void
    {
        $id = $this->cotizar(100)->json('data.id');

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$id}", [
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => CrmOportunidad::MONTO_MAXIMO + 1]],
        ])->assertStatus(422);

        $this->assertSame(100.0, $this->monto());
        $this->assertEquals(100.0, (float) CrmCotizacion::find($id)->subtotal);
        $this->assertSame(1, DB::table('crm_oportunidad_productos')->where('cotizacion_id', $id)->count());
    }

    // ---------------------------------------------------------- monto_origen

    public function test_monto_origen_cotizacion_estimado_y_sin_monto(): void
    {
        $sinMonto = $this->nuevaOportunidad(['monto_esperado' => 0]);
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$sinMonto->id}")
            ->assertJsonPath('data.monto_origen', ['tipo' => 'sin_monto']);

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertJsonPath('data.monto_origen', ['tipo' => 'estimado']);

        $folio = $this->cotizar(900)->json('data.folio');
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertJsonPath('data.monto_origen', ['tipo' => 'cotizacion', 'folio' => $folio]);
    }

    public function test_monto_origen_una_cotizacion_rechazada_ya_no_cuenta(): void
    {
        $id = $this->cotizar(900)->json('data.id');
        $this->enviar($id);
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/rechazar")->assertOk();

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}")
            ->assertJsonPath('data.monto_origen', ['tipo' => 'estimado']);
    }

    public function test_monto_origen_en_listado_alta_edicion_y_cambio_de_etapa(): void
    {
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/oportunidades')
            ->assertOk()->assertJsonPath('data.0.monto_origen.tipo', 'estimado');

        $alta = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/oportunidades', [
            'cliente_id' => $this->cliente->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Nueva', 'monto_esperado' => 100,
        ])->assertCreated()->assertJsonPath('data.monto_origen.tipo', 'estimado');
        $this->assertArrayNotHasKey('cotizaciones', $alta->json('data'));

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/oportunidades/{$alta->json('data.id')}", ['nombre' => 'Editada'])
            ->assertOk()->assertJsonPath('data.monto_origen.tipo', 'estimado');

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", ['etapa' => 'propuesta'])
            ->assertOk()->assertJsonPath('data.monto_origen.tipo', 'estimado');
    }

    public function test_listado_no_crece_en_consultas_con_el_numero_de_oportunidades(): void
    {
        $this->crearCotizacionDirecta($this->oportunidad, 'COT-N0001', 'enviado', 5000);

        $consultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($this->crmHeaders())->getJson('/api/crm/oportunidades')->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $con1 = $consultas();

        foreach (range(1, 5) as $i) {
            $o = $this->nuevaOportunidad(['monto_esperado' => 100 * $i]);
            $this->crearCotizacionDirecta($o, 'COT-N1'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'enviado', 100 * $i);
            $this->crearCotizacionDirecta($o, 'COT-N2'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'borrador', 7);
        }

        $this->assertSame($con1, $consultas());
    }

    private function crearCotizacionDirecta(CrmOportunidad $oportunidad, string $folio, string $estado, float $subtotal, float $descuento = 0): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id,
            'oportunidad_id' => $oportunidad->id,
            'folio' => $folio,
            'estado' => $estado,
            'fecha_emision' => now()->toDateString(),
            'descuento_global_pct' => $descuento,
            'subtotal' => $subtotal,
            'total' => round($subtotal * (1 - $descuento / 100) * 1.16, 2),
        ]);
    }
}
