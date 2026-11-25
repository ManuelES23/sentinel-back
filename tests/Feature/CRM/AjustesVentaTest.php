<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use App\Services\CRM\AjustesOportunidadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Rechazos y ajustes de precio después de la venta, solo SBP (spec 2026-11-25 §3, §6, §7). */
class AjustesVentaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const SIN_VENTA = 'Esta oportunidad no tiene una venta registrada.';

    private CrmOportunidad $oportunidad;

    private CrmProducto $producto;

    private CrmMotivoRechazo $motivoMadurez;

    private CrmMotivoRechazo $motivoPrecio;

    /** Renglón vendido: calibre 9, 5,000 × 28.50. */
    private int $renglon9;

    /** Renglón no vendido: calibre 8. */
    private int $renglon8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente Uno', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba', 'etapa' => 'negociacion',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Mango Ataulfo', 'commodity' => 'Mango', 'precio' => 30,
        ]);

        $precio = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Precio']);
        $calidad = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Calidad']);
        $this->motivoPrecio = CrmMotivoRechazo::create(['categoria_id' => $precio->id, 'nombre' => 'Muy caro']);
        $this->motivoMadurez = CrmMotivoRechazo::create(['categoria_id' => $calidad->id, 'nombre' => 'Madurez']);

        $this->config(['cotizacion_por_caja' => true, 'descuento_global_habilitado' => true]);
    }

    private function config(array $valores): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update($valores);
    }

    /** Registra la venta de la propuesta: solo el calibre 9 se vende (5,000 × 28.50 = 142,500). */
    private function venderElNueve(float $descuento = 0, ?CrmOportunidad $oportunidad = null): CrmOportunidadVenta
    {
        $oportunidad ??= $this->oportunidad;
        $res = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => $descuento,
                'lineas' => [
                    ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja 4 kg', 'cantidad' => 2000, 'precio_unitario' => 31.00],
                    ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 9', 'presentacion' => 'Caja 4 kg', 'cantidad' => 4000, 'precio_unitario' => 28.50],
                ],
            ])->assertCreated();
        $cotizacion = CrmCotizacion::findOrFail($res->json('data.id'));
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$cotizacion->id}/enviar")->assertOk();

        [$id8, $id9] = $cotizacion->lineas()->orderBy('id')->pluck('id')->all();
        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$cotizacion->id}/venta", ['renglones' => [
            ['linea_id' => $id8, 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id9, 'vendido' => true, 'cantidad' => 5000, 'precio' => 28.50],
        ]])->assertOk();

        $venta = CrmOportunidadVenta::where('oportunidad_id', $oportunidad->id)->where('vigente', true)->firstOrFail();
        $renglones = $venta->renglones()->orderBy('id')->get();
        $this->renglon8 = $renglones[0]->id;
        $this->renglon9 = $renglones[1]->id;

        return $venta;
    }

    private function ajustar(array $payload, ?CrmOportunidad $oportunidad = null)
    {
        $oportunidad ??= $this->oportunidad;

        return $this->withHeaders($this->crmHeaders())->postJson("/api/crm/oportunidades/{$oportunidad->id}/ajustes", $payload);
    }

    private function rechazo(int $cajas, array $extra = []): array
    {
        return array_merge([
            'venta_renglon_id' => $this->renglon9, 'tipo' => 'rechazo', 'cajas' => $cajas,
            'motivo_rechazo_id' => $this->motivoMadurez->id,
        ], $extra);
    }

    private function precio(float $nuevo, array $extra = []): array
    {
        return array_merge([
            'venta_renglon_id' => $this->renglon9, 'tipo' => 'precio', 'precio_nuevo' => $nuevo,
            'motivo_rechazo_id' => $this->motivoPrecio->id,
        ], $extra);
    }

    private function anular(CrmOportunidadAjuste|int $ajuste, array $payload, ?CrmOportunidad $oportunidad = null)
    {
        $oportunidad ??= $this->oportunidad;
        $id = $ajuste instanceof CrmOportunidadAjuste ? $ajuste->id : $ajuste;

        return $this->withHeaders($this->crmHeaders())->postJson("/api/crm/oportunidades/{$oportunidad->id}/ajustes/{$id}/anular", $payload);
    }

    private function monto(): float
    {
        return (float) $this->oportunidad->fresh()->monto_esperado;
    }

    // ── rechazo y precio ──────────────────────────────────────────────────────

    public function test_rechazo_de_cajas_baja_el_monto(): void
    {
        $this->venderElNueve();
        $this->oportunidad->update(['estado_cobro' => 'por_cobrar']);
        $antes = $this->oportunidad->fresh();

        $res = $this->ajustar($this->rechazo(1000, ['nota' => 'Fruta muy madura']))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.oportunidad.etapa', 'cerrado_ganado');
        $this->assertEquals(114000.00, (float) $res->json('data.oportunidad.monto_esperado'));
        $this->assertEquals(-28500.00, (float) $res->json('data.ajuste.importe'));

        $op = $this->oportunidad->fresh();
        $this->assertEquals(114000.00, (float) $op->monto_esperado);
        $this->assertSame('cerrado_ganado', $op->etapa);
        $this->assertTrue($op->fecha_cierre_real->equalTo($antes->fecha_cierre_real), 'no mueve la fecha de cierre');
        $this->assertSame('por_cobrar', $op->estado_cobro, 'no toca el estado de cobro');

        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $this->assertSame('rechazo', $ajuste->tipo);
        $this->assertSame(1000, $ajuste->cajas);
        $this->assertNull($ajuste->precio_nuevo);
        $this->assertEquals(-28500.00, (float) $ajuste->importe);
        $this->assertSame($this->renglon9, (int) $ajuste->venta_renglon_id);
        $this->assertSame($this->motivoMadurez->id, (int) $ajuste->motivo_rechazo_id);
        $this->assertSame('Fruta muy madura', $ajuste->nota);
        $this->assertSame(now()->toDateString(), $ajuste->fecha->toDateString());
        $this->assertSame($this->actingUser->id, (int) $ajuste->user_id);
        $this->assertSame($this->enterprise->id, (int) $ajuste->empresa_id);
        $this->assertNull($ajuste->anulado_at);

        $nota = CrmActividad::where('entidad_type', CrmOportunidad::class)->where('entidad_id', $op->id)
            ->where('descripcion', 'like', 'Rechazo:%')->firstOrFail();
        $this->assertSame('nota', $nota->tipo);
        $this->assertSame('sistema', $nota->fuente);
        $this->assertSame('Rechazo: 1,000 cajas Mango Ataulfo Calibre 9 (−$28,500.00) · Calidad › Madurez', $nota->descripcion);
    }

    public function test_ajuste_de_precio_usa_las_cajas_vigentes(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();

        $res = $this->ajustar($this->precio(27.00, ['fecha' => now()->toDateString()]))->assertCreated();

        $this->assertEquals(-6000.00, (float) $res->json('data.ajuste.importe'));
        $this->assertEquals(108000.00, $this->monto());
        $ajuste = CrmOportunidadAjuste::where('tipo', 'precio')->firstOrFail();
        $this->assertEquals(27.00, (float) $ajuste->precio_nuevo);
        $this->assertNull($ajuste->cajas);
        $this->assertEquals(-6000.00, (float) $ajuste->importe);

        $nota = CrmActividad::where('descripcion', 'like', 'Ajuste de precio:%')->firstOrFail();
        $this->assertSame('Ajuste de precio: Mango Ataulfo Calibre 9 $28.50 → $27.00 (−$6,000.00) · Precio › Muy caro', $nota->descripcion);
        $this->assertSame('sistema', $nota->fuente);
    }

    public function test_un_precio_que_sube_da_importe_positivo(): void
    {
        $this->venderElNueve();

        $this->ajustar($this->precio(29.00))->assertCreated();

        $this->assertEquals(2500.00, (float) CrmOportunidadAjuste::firstOrFail()->importe);
        $this->assertEquals(145000.00, $this->monto());
        $nota = CrmActividad::where('descripcion', 'like', 'Ajuste de precio:%')->firstOrFail();
        $this->assertStringContainsString('($2,500.00)', $nota->descripcion);
    }

    public function test_un_segundo_ajuste_de_precio_parte_del_precio_vigente(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->precio(27.00))->assertCreated();   // 5,000 × −1.50 = −7,500

        $this->ajustar($this->precio(26.00))->assertCreated();   // 5,000 × −1.00 = −5,000

        $this->assertEquals([-7500.00, -5000.00], CrmOportunidadAjuste::orderBy('id')->pluck('importe')->map(fn ($i) => (float) $i)->all());
        $this->assertEquals(130000.00, $this->monto());
    }

    public function test_aplica_el_descuento_de_la_venta_a_los_ajustes(): void
    {
        $this->venderElNueve(10);
        $this->assertEquals(128250.00, $this->monto());

        $this->ajustar($this->rechazo(1000))->assertCreated();

        $this->assertEquals(-25650.00, (float) CrmOportunidadAjuste::firstOrFail()->importe);
        $this->assertEquals(102600.00, $this->monto());

        $this->ajustar($this->precio(27.00))->assertCreated();   // 4,000 × −1.50 × 0.9 = −5,400
        $this->assertEquals(-5400.00, (float) CrmOportunidadAjuste::orderByDesc('id')->first()->importe);
        $this->assertEquals(97200.00, $this->monto());
    }

    public function test_los_importes_se_redondean_al_centavo_hacia_arriba_y_el_monto_cuadra(): void
    {
        $this->venderElNueve(7.5);
        $venta = CrmOportunidadVenta::firstOrFail();
        $r9 = $venta->renglones()->where('vendido', true)->firstOrFail();
        $r9->update(['cantidad_vendida' => 333, 'precio_vendido' => 12.34]);
        $this->oportunidad->update(['monto_esperado' => 3801.03]);   // 333 × 12.34 × 0.925 = 3,801.0285

        // 33 × 12.34 × 0.925 = 376.6785 → 376.68
        $this->ajustar($this->rechazo(33))->assertCreated();

        $this->assertEquals(-376.68, (float) CrmOportunidadAjuste::firstOrFail()->importe);
        $this->assertEquals(3424.35, $this->monto());
    }

    /** Vende el calibre 9 como `$cajas` × `$precio` con el descuento dado (ajusta la venta ya registrada). */
    private function ventaChica(int $cajas, float $precio, float $descuento): void
    {
        $this->venderElNueve($descuento);
        CrmOportunidadVenta::firstOrFail()->renglones()->where('vendido', true)
            ->update(['cantidad_vendida' => $cajas, 'precio_vendido' => $precio]);
        $factor = 10000 - (int) round($descuento * 100);
        $this->oportunidad->update(['monto_esperado' => intdiv($cajas * (int) round($precio * 100) * $factor + 5000, 10000) / 100]);
    }

    /** Σ importes vigentes + lo vendido == monto_esperado (al centavo). */
    private function assertMontoCuadra(float $vendido): void
    {
        $suma = (int) round($vendido * 100)
            + CrmOportunidadAjuste::vigentes()->get()->sum(fn ($a) => (int) round((float) $a->importe * 100));
        $this->assertSame($suma, (int) round($this->monto() * 100), 'monto_esperado = vendido + Σ importes vigentes');
    }

    public function test_rechazar_en_pedazos_con_descuento_termina_exactamente_en_cero(): void
    {
        // 2 × 28.54 con 10 %: vendido 51.37; la mitad suelta redondearía a 25.69 y la otra a 25.69 (−0.01).
        $this->ventaChica(2, 28.54, 10);
        $this->assertEquals(51.37, $this->monto());

        $this->ajustar($this->rechazo(1))->assertCreated();
        $this->assertEquals(25.69, $this->monto());
        $this->assertMontoCuadra(51.37);

        $this->ajustar($this->rechazo(1))->assertCreated();
        $this->assertEquals(0.00, $this->monto());
        $this->assertMontoCuadra(51.37);
        $this->assertEquals(
            [-25.68, -25.69],
            CrmOportunidadAjuste::orderBy('id')->pluck('importe')->map(fn ($i) => (float) $i)->all()
        );
        $this->assertSame(-5137, (int) round(CrmOportunidadAjuste::sum('importe') * 100));

        $res = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertOk();
        $this->assertEquals(0.0, $res->json('data.resumen.firme'));
        $this->assertSame(0, $res->json('data.resumen.cajas_firmes'));
    }

    public function test_rechazar_en_tres_pedazos_con_descuento_termina_en_cero(): void
    {
        $this->ventaChica(3, 33.33, 15);

        foreach ([1, 1, 1] as $cajas) {
            $this->ajustar($this->rechazo($cajas))->assertCreated();
            $this->assertMontoCuadra(84.99);
        }

        $this->assertEquals(0.00, $this->monto());
    }

    public function test_un_cambio_de_precio_y_luego_rechazar_todo_termina_en_cero(): void
    {
        $this->ventaChica(2, 28.54, 10);

        $this->ajustar($this->precio(27.33))->assertCreated();   // bruto 54.66 → 49.19
        $this->assertEquals(49.19, $this->monto());
        $this->assertEquals(-2.18, (float) CrmOportunidadAjuste::firstOrFail()->importe);
        $this->assertMontoCuadra(51.37);

        $this->ajustar($this->rechazo(2))->assertCreated();
        $this->assertEquals(0.00, $this->monto());
        $this->assertMontoCuadra(51.37);
    }

    public function test_anular_restaura_exactamente_el_monto_anterior_con_descuento(): void
    {
        $this->ventaChica(2, 28.54, 10);
        $this->ajustar($this->precio(27.33))->assertCreated();
        $this->ajustar($this->rechazo(1))->assertCreated();
        $montoIntermedio = $this->monto();
        $this->ajustar($this->rechazo(1))->assertCreated();
        $this->assertEquals(0.00, $this->monto());

        $this->anular(CrmOportunidadAjuste::orderByDesc('id')->firstOrFail(), ['motivo' => 'Uno'])->assertOk();
        $this->assertSame($montoIntermedio, $this->monto());
        $this->assertMontoCuadra(51.37);

        $this->anular(CrmOportunidadAjuste::vigentes()->orderByDesc('id')->firstOrFail(), ['motivo' => 'Dos'])->assertOk();
        $this->assertEquals(49.19, $this->monto());

        $this->anular(CrmOportunidadAjuste::vigentes()->orderByDesc('id')->firstOrFail(), ['motivo' => 'Tres'])->assertOk();
        $this->assertEquals(51.37, $this->monto());
        $this->assertMontoCuadra(51.37);
    }

    public function test_se_pueden_rechazar_todas_las_cajas_vigentes_pero_no_mas(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();

        $this->ajustar($this->rechazo(4000))->assertCreated();

        $this->assertEquals(0.0, $this->monto());
        $this->ajustar($this->rechazo(1))->assertStatus(422)->assertJsonValidationErrors(['venta_renglon_id']);
        $this->ajustar($this->precio(30.00))->assertStatus(422)->assertJsonValidationErrors(['venta_renglon_id']);
        $this->assertSame(2, CrmOportunidadAjuste::count());
    }

    public function test_no_rechaza_mas_cajas_que_las_vigentes(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();

        $this->ajustar($this->rechazo(4001))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cajas' => 'No puedes rechazar más de 4,000 cajas.']);

        $this->assertSame(1, CrmOportunidadAjuste::count());
        $this->assertEquals(114000.00, $this->monto());
        $this->assertSame(1, CrmActividad::where('descripcion', 'like', 'Rechazo:%')->count());
    }

    public function test_precio_igual_al_vigente_da_422(): void
    {
        $this->venderElNueve();

        $this->ajustar($this->precio(28.50))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precio_nuevo' => 'El precio nuevo es igual al vigente.']);

        $this->ajustar($this->precio(27.00))->assertCreated();
        $this->ajustar($this->precio(27.00))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precio_nuevo' => 'El precio nuevo es igual al vigente.']);
        $this->assertSame(1, CrmOportunidadAjuste::count());
    }

    public function test_precio_invalido_da_422(): void
    {
        $this->venderElNueve();

        foreach ([-1, 'abc', 27.123, null] as $precio) {
            $this->ajustar($this->precio(0, ['precio_nuevo' => $precio]))
                ->assertStatus(422)->assertJsonValidationErrors(['precio_nuevo']);
        }
        // un precio cero sí es válido
        $this->ajustar($this->precio(0))->assertCreated();
        $this->assertEquals(0.0, $this->monto());
    }

    public function test_renglon_no_vendido_o_de_otra_venta_da_422(): void
    {
        $this->venderElNueve();
        $otraOportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->oportunidad->cliente_id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Otra', 'etapa' => 'negociacion',
        ]);
        $ajeno = $this->renglon9;
        $this->venderElNueve(0, $otraOportunidad);   // deja $this->renglon9 apuntando al de la otra venta
        $deLaOtra = $this->renglon9;
        $this->renglon9 = $ajeno;

        foreach ([$this->renglon8, $deLaOtra, 999999, null, 'x'] as $renglon) {
            $this->ajustar($this->rechazo(10, ['venta_renglon_id' => $renglon]))
                ->assertStatus(422)->assertJsonValidationErrors(['venta_renglon_id']);
        }
        $this->assertSame(0, CrmOportunidadAjuste::count());
        $this->assertEquals(142500.00, $this->monto());
    }

    public function test_fecha_futura_o_anterior_a_la_venta_da_422(): void
    {
        $this->venderElNueve();

        $this->ajustar($this->rechazo(10, ['fecha' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha' => 'La fecha no puede ser futura.']);
        $this->ajustar($this->rechazo(10, ['fecha' => now()->subDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha' => 'La fecha no puede ser anterior a la venta.']);
        $this->ajustar($this->rechazo(10, ['fecha' => 'ayer']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha']);
        $this->ajustar($this->rechazo(10, ['fecha' => '2026-02-30']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha']);

        $this->assertSame(0, CrmOportunidadAjuste::count());

        // El mismo día de la venta sí vale.
        $this->ajustar($this->rechazo(10, ['fecha' => now()->toDateString()]))->assertCreated();
    }

    public function test_cajas_decimales_cero_o_negativas_dan_422(): void
    {
        $this->venderElNueve();

        foreach ([1.5, 0, -3, 'diez', null] as $cajas) {
            $this->ajustar($this->rechazo(1, ['cajas' => $cajas]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['cajas' => 'Las cajas deben ser un número entero mayor a cero.']);
        }
        $this->assertSame(0, CrmOportunidadAjuste::count());
        $this->assertEquals(142500.00, $this->monto());
    }

    public function test_tipo_y_motivo_son_obligatorios(): void
    {
        $this->venderElNueve();

        $this->ajustar($this->rechazo(10, ['tipo' => 'otro']))->assertStatus(422)->assertJsonValidationErrors(['tipo']);
        $this->ajustar($this->rechazo(10, ['tipo' => null]))->assertStatus(422)->assertJsonValidationErrors(['tipo']);

        $payload = $this->rechazo(10);
        unset($payload['motivo_rechazo_id']);
        $this->ajustar($payload)->assertStatus(422)->assertJsonValidationErrors(['motivo_rechazo_id']);

        // motivo de otra empresa o desactivado
        $otra = $this->crearOtraEmpresa();
        $categoria = CrmCategoriaRechazo::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena']);
        $ajeno = CrmMotivoRechazo::create(['categoria_id' => $categoria->id, 'nombre' => 'Ajeno']);
        $this->ajustar($this->rechazo(10, ['motivo_rechazo_id' => $ajeno->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['motivo_rechazo_id']);
        $this->motivoMadurez->update(['activo' => false]);
        $this->ajustar($this->rechazo(10))->assertStatus(422)->assertJsonValidationErrors(['motivo_rechazo_id']);

        $this->ajustar($this->rechazo(10, ['motivo_rechazo_id' => $this->motivoPrecio->id, 'nota' => str_repeat('a', 1001)]))
            ->assertStatus(422)->assertJsonValidationErrors(['nota']);

        $this->assertSame(0, CrmOportunidadAjuste::count());
    }

    public function test_un_importe_que_no_cabe_da_422(): void
    {
        $this->venderElNueve();

        $this->ajustar($this->precio(9999999999.99))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precio_nuevo' => 'El monto vendido excede el máximo permitido.']);

        $this->assertSame(0, CrmOportunidadAjuste::count());
        $this->assertEquals(142500.00, $this->monto());
    }

    // ── sin venta ─────────────────────────────────────────────────────────────

    public function test_sin_venta_registrada_da_422(): void
    {
        // Ganada a mano, sin venta (spec decisión 10).
        $this->oportunidad->update(['etapa' => 'cerrado_ganado', 'fecha_cierre_real' => now(), 'monto_esperado' => 5000]);

        $this->ajustar(['venta_renglon_id' => 1, 'tipo' => 'rechazo', 'cajas' => 1, 'motivo_rechazo_id' => $this->motivoMadurez->id])
            ->assertStatus(422)->assertJsonPath('message', self::SIN_VENTA);

        // Todavía abierta, sin venta.
        $this->oportunidad->update(['etapa' => 'negociacion']);
        $this->ajustar(['venta_renglon_id' => 1, 'tipo' => 'rechazo', 'cajas' => 1, 'motivo_rechazo_id' => $this->motivoMadurez->id])
            ->assertStatus(422)->assertJsonPath('message', self::SIN_VENTA);

        $this->assertSame(0, CrmOportunidadAjuste::count());
        $this->assertEquals(5000.00, $this->monto());
    }

    public function test_una_venta_sin_renglones_vendidos_no_acepta_ajustes(): void
    {
        // Venta de una oportunidad perdida: ningún renglón vendido.
        $res = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja 4 kg', 'cantidad' => 10, 'precio_unitario' => 31.00]],
            ])->assertCreated();
        $id = $res->json('data.id');
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/enviar")->assertOk();
        $linea = CrmCotizacion::findOrFail($id)->lineas()->firstOrFail();
        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$id}/venta", ['renglones' => [
            ['linea_id' => $linea->id, 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();
        $this->assertSame('cerrado_perdido', $this->oportunidad->fresh()->etapa);
        $renglon = CrmOportunidadVenta::firstOrFail()->renglones()->firstOrFail();

        $this->ajustar(['venta_renglon_id' => $renglon->id, 'tipo' => 'rechazo', 'cajas' => 1, 'motivo_rechazo_id' => $this->motivoMadurez->id])
            ->assertStatus(422)->assertJsonPath('message', self::SIN_VENTA);
        // Aunque la etapa estuviera ganada, sin renglón vendido no hay venta firme.
        $this->oportunidad->update(['etapa' => 'cerrado_ganado']);
        $this->ajustar(['venta_renglon_id' => $renglon->id, 'tipo' => 'rechazo', 'cajas' => 1, 'motivo_rechazo_id' => $this->motivoMadurez->id])
            ->assertStatus(422)->assertJsonPath('message', self::SIN_VENTA);

        $this->assertSame(0, CrmOportunidadAjuste::count());
    }

    public function test_sin_bandera_da_422(): void
    {
        $this->venderElNueve();
        $this->config(['cotizacion_por_caja' => false]);

        $this->ajustar($this->rechazo(10))
            ->assertStatus(422)->assertJsonPath('message', 'Esta empresa no registra lo vendido por renglón.');
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")
            ->assertStatus(422)->assertJsonPath('message', 'Esta empresa no registra lo vendido por renglón.');

        $this->assertSame(0, CrmOportunidadAjuste::count());
    }

    // ── anular ────────────────────────────────────────────────────────────────

    public function test_anular_el_ultimo_recalcula(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $this->ajustar($this->precio(27.00))->assertCreated();
        $this->assertEquals(108000.00, $this->monto());
        $ultimo = CrmOportunidadAjuste::orderByDesc('id')->firstOrFail();

        $res = $this->anular($ultimo, ['motivo' => 'Error de captura'])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertEquals(114000.00, (float) $res->json('data.oportunidad.monto_esperado'));

        $ultimo->refresh();
        $this->assertNotNull($ultimo->anulado_at);
        $this->assertSame($this->actingUser->id, (int) $ultimo->anulado_por);
        $this->assertSame('Error de captura', $ultimo->motivo_anulacion);
        $this->assertEquals(114000.00, $this->monto());
        $this->assertSame('cerrado_ganado', $this->oportunidad->fresh()->etapa);

        $nota = CrmActividad::where('descripcion', 'like', 'Ajuste anulado:%')->firstOrFail();
        $this->assertSame('Ajuste anulado: Error de captura', $nota->descripcion);
        $this->assertSame('sistema', $nota->fuente);

        // El anterior ahora es el último y se puede anular también.
        $this->anular(CrmOportunidadAjuste::orderBy('id')->firstOrFail(), ['motivo' => 'Se canceló'])->assertOk();
        $this->assertEquals(142500.00, $this->monto());

        // Anulado el ajuste, esas cajas vuelven a poder rechazarse.
        $this->ajustar($this->rechazo(5000))->assertCreated();
        $this->assertEquals(0.0, $this->monto());
    }

    public function test_anular_con_un_motivo_largo_lo_guarda_completo(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $ultimo = CrmOportunidadAjuste::orderByDesc('id')->firstOrFail();
        $motivo = str_repeat('Motivo largo ', 70); // 910 caracteres
        $motivo = rtrim(substr($motivo, 0, 899)).'.'; // 900, sin espacios al final

        $this->anular($ultimo, ['motivo' => $motivo])->assertOk();

        $this->assertSame(900, mb_strlen($ultimo->fresh()->motivo_anulacion));
        $this->assertSame($motivo, $ultimo->fresh()->motivo_anulacion);
    }

    public function test_anular_uno_que_no_es_el_ultimo_da_422(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $this->ajustar($this->precio(27.00))->assertCreated();
        $primero = CrmOportunidadAjuste::orderBy('id')->firstOrFail();

        $this->anular($primero, ['motivo' => 'Quiero quitarlo'])
            ->assertStatus(422)->assertJsonPath('message', 'Solo se puede anular el último ajuste.');

        $this->assertNull($primero->fresh()->anulado_at);
        $this->assertEquals(108000.00, $this->monto());
        $this->assertSame(0, CrmActividad::where('descripcion', 'like', 'Ajuste anulado:%')->count());
    }

    public function test_anular_uno_ya_anulado_da_422(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $this->anular($ajuste, ['motivo' => 'Uno'])->assertOk();

        $this->anular($ajuste, ['motivo' => 'Dos'])
            ->assertStatus(422)->assertJsonPath('message', 'Ese ajuste ya está anulado.');

        $this->assertSame('Uno', $ajuste->fresh()->motivo_anulacion);
        $this->assertSame(1, CrmActividad::where('descripcion', 'like', 'Ajuste anulado:%')->count());
    }

    public function test_anular_exige_motivo(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();

        foreach ([[], ['motivo' => ''], ['motivo' => '   '], ['motivo' => str_repeat('a', 1001)]] as $payload) {
            $this->anular($ajuste, $payload)->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        }

        $this->assertNull($ajuste->fresh()->anulado_at);
        $this->assertEquals(114000.00, $this->monto());
    }

    public function test_un_ajuste_de_otra_oportunidad_da_404(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $otra = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->oportunidad->cliente_id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Otra', 'etapa' => 'negociacion',
        ]);

        $this->anular($ajuste, ['motivo' => 'x'], $otra)->assertNotFound();

        $this->assertNull($ajuste->fresh()->anulado_at);
    }

    // ── lectura ───────────────────────────────────────────────────────────────

    public function test_get_venta_devuelve_renglones_vigentes_ajustes_y_resumen(): void
    {
        $venta = $this->venderElNueve();
        $this->ajustar($this->rechazo(1000, ['nota' => 'Madura']))->assertCreated();

        $res = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.venta.id', $venta->id)
            ->assertJsonPath('data.venta.cotizacion.folio', 'COT-00001')
            ->assertJsonStructure(['data' => [
                'venta' => ['id', 'cotizacion' => ['id', 'folio'], 'registrada_por', 'created_at', 'descuento_global_pct', 'renglones'],
                'ajustes',
                'resumen' => ['vendido', 'rechazado', 'ajustes_precio', 'firme', 'cajas_vendidas', 'cajas_rechazadas', 'cajas_firmes'],
            ]]);

        $renglones = collect($res->json('data.venta.renglones'));
        $this->assertCount(2, $renglones);
        $vendido = $renglones->firstWhere('id', $this->renglon9);
        $this->assertTrue($vendido['vendido']);
        $this->assertSame(5000, $vendido['cantidad_vendida']);
        $this->assertSame(4000, $vendido['cajas_vigentes']);
        $this->assertEquals(28.50, $vendido['precio_vigente']);
        $noVendido = $renglones->firstWhere('id', $this->renglon8);
        $this->assertFalse($noVendido['vendido']);
        $this->assertNull($noVendido['cajas_vigentes']);
        $this->assertNull($noVendido['precio_vigente']);
        $this->assertSame('Muy caro', $noVendido['motivo_rechazo']['nombre']);
        $this->assertSame('Precio', $noVendido['motivo_rechazo']['categoria']['nombre']);

        $ajustes = $res->json('data.ajustes');
        $this->assertCount(1, $ajustes);
        $this->assertSame('rechazo', $ajustes[0]['tipo']);
        $this->assertEquals(-28500.00, $ajustes[0]['importe']);
        $this->assertTrue($ajustes[0]['vigente']);
        $this->assertTrue($ajustes[0]['anulable']);
        $this->assertSame('Madurez', $ajustes[0]['motivo_rechazo']['nombre']);

        $resumen = $res->json('data.resumen');
        $this->assertEquals(142500.00, $resumen['vendido']);
        $this->assertEquals(28500.00, $resumen['rechazado']);
        $this->assertEquals(0.0, $resumen['ajustes_precio']);
        $this->assertEquals(114000.00, $resumen['firme']);
        $this->assertSame(5000, $resumen['cajas_vendidas']);
        $this->assertSame(1000, $resumen['cajas_rechazadas']);
        $this->assertSame(4000, $resumen['cajas_firmes']);
    }

    public function test_get_venta_con_precio_y_anulados(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $this->ajustar($this->precio(27.00))->assertCreated();
        $this->ajustar($this->precio(29.00))->assertCreated();
        $this->anular(CrmOportunidadAjuste::orderByDesc('id')->firstOrFail(), ['motivo' => 'Mal precio'])->assertOk();

        $res = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertOk();

        $resumen = $res->json('data.resumen');
        $this->assertEquals(28500.00, $resumen['rechazado']);
        $this->assertEquals(6000.00, $resumen['ajustes_precio']);   // −Σ importes de precio vigentes
        $this->assertEquals(108000.00, $resumen['firme']);
        $this->assertEquals(108000.00, $this->monto());
        $this->assertEquals(27.00, collect($res->json('data.venta.renglones'))->firstWhere('id', $this->renglon9)['precio_vigente']);

        $ajustes = collect($res->json('data.ajustes'));
        $this->assertCount(3, $ajustes);
        $anulado = $ajustes->firstWhere('vigente', false);
        $this->assertSame('Mal precio', $anulado['motivo_anulacion']);
        $this->assertFalse($anulado['anulable']);
        $this->assertSame([false, true, false], $ajustes->pluck('anulable')->all(), 'solo el último vigente se puede anular');
    }

    public function test_get_venta_sin_venta_devuelve_nulo_y_ceros(): void
    {
        $res = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")
            ->assertOk()
            ->assertJsonPath('data.venta', null)
            ->assertJsonPath('data.ajustes', []);

        $this->assertEquals(0.0, $res->json('data.resumen.firme'));
        $this->assertSame(0, $res->json('data.resumen.cajas_firmes'));
    }

    // ── permisos ──────────────────────────────────────────────────────────────

    public function test_otra_empresa_da_404(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(10))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);
        $headers = $this->crmHeaders($otra->id);

        $this->withHeaders($headers)->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/ajustes", $this->rechazo(10))->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/ajustes/{$ajuste->id}/anular", ['motivo' => 'x'])->assertNotFound();

        $this->assertNull($ajuste->fresh()->anulado_at);
        $this->assertSame(1, CrmOportunidadAjuste::count());
    }

    public function test_sin_oportunidades_editar_da_403_y_sin_ver_no_lee(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(10))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();

        $this->dejarComoVendedor(propio: true, permisos: ['ver']);
        $this->ajustar($this->rechazo(10))->assertForbidden();
        $this->anular($ajuste, ['motivo' => 'x'])->assertForbidden();
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertOk();

        $this->dejarComoVendedor(propio: true, permisos: []);
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertForbidden();

        $this->assertSame(1, CrmOportunidadAjuste::count());
        $this->assertNull($ajuste->fresh()->anulado_at);
    }

    public function test_un_vendedor_con_ver_y_editar_ajusta_su_propia_venta(): void
    {
        $this->venderElNueve();
        $this->dejarComoVendedor(propio: true, permisos: ['ver', 'editar']);

        $this->ajustar($this->rechazo(1000))->assertCreated();
        $this->anular(CrmOportunidadAjuste::firstOrFail(), ['motivo' => 'Error'])->assertOk();
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertOk();
    }

    public function test_vendedor_ajeno_recibe_403_en_todo(): void
    {
        $this->venderElNueve();
        $this->ajustar($this->rechazo(10))->assertCreated();
        $ajuste = CrmOportunidadAjuste::firstOrFail();
        $this->dejarComoVendedor(propio: false, permisos: ['ver', 'editar']);

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$this->oportunidad->id}/venta")->assertForbidden();
        $this->ajustar($this->rechazo(10))->assertForbidden();
        $this->anular($ajuste, ['motivo' => 'x'])->assertForbidden();

        $this->assertSame(1, CrmOportunidadAjuste::count());
        $this->assertNull($ajuste->fresh()->anulado_at);
        $this->assertEquals(142500.00 - 285.00, $this->monto());
    }

    // ── tiempo real ───────────────────────────────────────────────────────────

    public function test_difunde_los_eventos_despues_de_ajustar_y_de_anular(): void
    {
        $this->venderElNueve();
        Event::fake([OportunidadUpdated::class, ActividadUpdated::class]);

        $this->ajustar($this->rechazo(1000))->assertCreated();
        Event::assertDispatched(OportunidadUpdated::class, 1);
        Event::assertDispatched(ActividadUpdated::class, 1);

        $this->anular(CrmOportunidadAjuste::firstOrFail(), ['motivo' => 'Error'])->assertOk();
        Event::assertDispatched(OportunidadUpdated::class, 2);
        Event::assertDispatched(ActividadUpdated::class, 2);
    }

    public function test_no_difunde_nada_si_la_validacion_falla(): void
    {
        $this->venderElNueve();
        Event::fake([OportunidadUpdated::class, ActividadUpdated::class]);

        $this->ajustar($this->rechazo(999999))->assertStatus(422);

        Event::assertNotDispatched(OportunidadUpdated::class);
        Event::assertNotDispatched(ActividadUpdated::class);
    }

    // ── servicio ──────────────────────────────────────────────────────────────

    public function test_vigentes_aplica_los_ajustes_en_orden_de_id(): void
    {
        $venta = $this->venderElNueve();
        $this->ajustar($this->rechazo(1000))->assertCreated();
        $this->ajustar($this->precio(27.00))->assertCreated();
        $this->ajustar($this->rechazo(500))->assertCreated();

        $vigentes = app(AjustesOportunidadService::class)->vigentes($venta);

        $this->assertSame([$this->renglon9], array_keys($vigentes), 'solo lo vendido');
        $this->assertSame(3500, $vigentes[$this->renglon9]['cajas']);
        $this->assertSame(27.0, $vigentes[$this->renglon9]['precio']);
    }

    // ── apoyo ─────────────────────────────────────────────────────────────────

    /** Deja al usuario como vendedor con solo los permisos de oportunidades dados. */
    private function dejarComoVendedor(bool $propio, array $permisos): void
    {
        UserSubmodulePermission::where('user_id', $this->actingUser->id)->delete();

        $yo = CrmVendedor::firstOrCreate(
            ['empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id],
            ['nombre' => 'Vendedor del usuario', 'activo' => true]
        );
        $this->oportunidad->update(['vendedor_id' => $propio ? $yo->id : $this->vendedor->id]);

        if ($permisos !== []) {
            $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
        }
    }
}
