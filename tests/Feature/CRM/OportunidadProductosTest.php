<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OportunidadProductosTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const BASE_URL = '/api/crm/oportunidades';

    private CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => true]);
        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente de prueba',
            'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
    }

    private function producto(string $nombre = 'Kent Mango', bool $activo = true, ?int $empresaId = null): CrmProducto
    {
        return CrmProducto::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id,
            'nombre' => $nombre,
            'commodity' => 'Mango',
            'precio' => 10,
            'unidad_medida' => 'caja',
            'activo' => $activo,
        ]);
    }

    private function payload(array $productos, array $extra = []): array
    {
        return array_merge([
            'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Venta de mango',
            'productos' => $productos,
        ], $extra);
    }

    private function linea(CrmProducto $p, $cantidad, $precio): array
    {
        return ['producto_id' => $p->id, 'cantidad' => $cantidad, 'precio_unitario' => $precio];
    }

    private function crear(array $productos, array $extra = []): CrmOportunidad
    {
        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload($productos, $extra))->assertCreated();

        return CrmOportunidad::latest('id')->first();
    }

    private function lineasDe(CrmOportunidad $o)
    {
        return CrmOportunidadProducto::where('oportunidad_id', $o->id)->whereNull('cotizacion_id')->orderBy('id')->get();
    }

    public function test_store_calcula_monto_con_productos(): void
    {
        $a = $this->producto('Kent Mango');
        $b = $this->producto('Ataulfo Mango');

        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([
            $this->linea($a, 1200, 14.50),
            $this->linea($b, 300, 9.25),
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.productos.0.producto.nombre', 'Kent Mango')
            ->assertJsonCount(2, 'data.productos');
        $op = CrmOportunidad::latest('id')->first();
        $this->assertEquals(20175.00, (float) $op->monto_esperado);
        $this->assertCount(2, $this->lineasDe($op));
    }

    public function test_store_ignora_monto_enviado(): void
    {
        $a = $this->producto();
        $op = $this->crear([$this->linea($a, 10, 5)], ['monto_esperado' => 1]);

        $this->assertEquals(50.00, (float) $op->monto_esperado);
    }

    public function test_store_sin_productos_422(): void
    {
        $datos = $this->payload([]);
        unset($datos['productos']);

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $datos)
            ->assertStatus(422)->assertJsonValidationErrors('productos');
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_store_productos_vacio_422(): void
    {
        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([]))
            ->assertStatus(422)->assertJsonValidationErrors('productos');
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_producto_de_otra_empresa_422(): void
    {
        $otra = $this->crearOtraEmpresa();
        $ajeno = $this->producto('Ajeno', true, $otra->id);

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($ajeno, 1, 1)]))
            ->assertStatus(422)->assertJsonValidationErrors('productos.0.producto_id');
    }

    public function test_producto_repetido_422(): void
    {
        $a = $this->producto();

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([
            $this->linea($a, 1, 1), $this->linea($a, 2, 2),
        ]))->assertStatus(422);
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_cantidad_cero_422(): void
    {
        $a = $this->producto();

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($a, 0, 5)]))
            ->assertStatus(422)->assertJsonValidationErrors('productos.0.cantidad');
    }

    public function test_cantidad_demasiado_grande_422(): void
    {
        $a = $this->producto();

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($a, 100000000, 1)]))
            ->assertStatus(422)->assertJsonValidationErrors('productos.0.cantidad');
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_precio_demasiado_grande_422(): void
    {
        $a = $this->producto();

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($a, 1, 10000000000)]))
            ->assertStatus(422)->assertJsonValidationErrors('productos.0.precio_unitario');
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_total_demasiado_grande_422_en_store(): void
    {
        $a = $this->producto();

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($a, 10000000, 2000)]))
            ->assertStatus(422)->assertJsonValidationErrors('productos')
            ->assertJsonPath('errors.productos.0', 'El total de la oportunidad excede el máximo permitido.');
        $this->assertSame(0, CrmOportunidad::count());
        $this->assertSame(0, CrmOportunidadProducto::count());
    }

    public function test_total_demasiado_grande_422_en_update_sin_cambiar_nada(): void
    {
        $a = $this->producto();
        $b = $this->producto('Ataulfo');
        $o = $this->crear([$this->linea($a, 10, 5)]);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$o->id, [
            'productos' => [$this->linea($a, 5000000, 1500), $this->linea($b, 5000000, 1500)],
        ])->assertStatus(422)->assertJsonValidationErrors('productos');

        $this->assertEquals(50, $o->fresh()->monto_esperado);
        $this->assertCount(1, $this->lineasDe($o));
    }

    public function test_total_en_el_limite_pasa(): void
    {
        $a = $this->producto();

        $o = $this->crear([$this->linea($a, 99999999, 99.99)]);

        $this->assertEqualsWithDelta(9998999900.01, (float) $o->fresh()->monto_esperado, 0.001);
    }

    public function test_redondeo_cajas_decimales(): void
    {
        $a = $this->producto('A');
        $b = $this->producto('B');
        $c = $this->producto('C');

        $op = $this->crear([
            $this->linea($a, 0.5, 14.35),
            $this->linea($b, 2.25, 10.10),
            $this->linea($c, 1, 0.33),
        ]);
        $this->assertEquals(30.23, (float) $op->monto_esperado);

        $d = $this->producto('D');
        $op2 = $this->crear([$this->linea($d, 1, 14.335)]);
        $this->assertEquals(14.34, (float) $op2->monto_esperado);
        $this->assertEquals(14.34, (float) $this->lineasDe($op2)->first()->precio_unitario);
    }

    public function test_update_reemplaza_lineas_y_recalcula(): void
    {
        $a = $this->producto('A');
        $b = $this->producto('B');
        $op = $this->crear([$this->linea($a, 10, 10), $this->linea($b, 5, 4)]);
        $this->assertEquals(120.00, (float) $op->monto_esperado);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'productos' => [$this->linea($b, 3, 7)],
            'monto_esperado' => 999,
        ])->assertOk()->assertJsonCount(1, 'data.productos');

        $lineas = $this->lineasDe($op);
        $this->assertCount(1, $lineas);
        $this->assertSame($b->id, (int) $lineas->first()->producto_id);
        $this->assertEquals(21.00, (float) $op->fresh()->monto_esperado);
    }

    public function test_update_conserva_producto_inactivo_ya_presente_pero_rechaza_nuevo_inactivo(): void
    {
        $a = $this->producto('Viejo');
        $op = $this->crear([$this->linea($a, 2, 10)]);
        $a->update(['activo' => false]);
        $nuevoInactivo = $this->producto('Descontinuado', false);

        // Ya presente: se conserva.
        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'productos' => [$this->linea($a, 4, 10)],
        ])->assertOk();
        $this->assertEquals(40.00, (float) $op->fresh()->monto_esperado);

        // Nuevo inactivo: se rechaza y nada cambia.
        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'productos' => [$this->linea($a, 4, 10), $this->linea($nuevoInactivo, 1, 1)],
        ])->assertStatus(422)->assertJsonPath('message', 'El producto «Descontinuado» está inactivo.');
        $this->assertCount(1, $this->lineasDe($op));
        $this->assertEquals(40.00, (float) $op->fresh()->monto_esperado);
    }

    public function test_store_rechaza_producto_inactivo(): void
    {
        $inactivo = $this->producto('Descontinuado', false);

        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($inactivo, 1, 1)]))
            ->assertStatus(422)->assertJsonPath('message', 'El producto «Descontinuado» está inactivo.');
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_update_sin_productos_en_abierta_pasa(): void
    {
        $a = $this->producto();
        $op = $this->crear([$this->linea($a, 10, 10)]);
        $otroVendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Otro vendedor',
            'email' => 'otro@example.com',
            'activo' => true,
        ]);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, ['vendedor_id' => $otroVendedor->id])
            ->assertOk()->assertJsonCount(1, 'data.productos');

        $this->assertCount(1, $this->lineasDe($op));
        $this->assertEquals(100.00, (float) $op->fresh()->monto_esperado);
    }

    public function test_update_sin_productos_ignora_monto_enviado(): void
    {
        $a = $this->producto();
        $op = $this->crear([$this->linea($a, 10, 10)]);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, ['monto_esperado' => 999])
            ->assertOk();

        $this->assertEquals(100.00, (float) $op->fresh()->monto_esperado);
        $this->assertCount(1, $this->lineasDe($op));
    }

    public function test_update_ignora_monto_en_oportunidad_antigua_sin_lineas(): void
    {
        $op = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Antigua',
            'monto_esperado' => 7500,
        ]);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, ['monto_esperado' => 1, 'notas' => 'x'])
            ->assertOk();

        $this->assertEquals(7500.00, (float) $op->fresh()->monto_esperado);
        $this->assertSame('x', $op->fresh()->notas);
    }

    public function test_cerrada_rechaza_cambio_de_productos(): void
    {
        $a = $this->producto();
        $op = $this->crear([$this->linea($a, 10, 10)]);
        $op->update(['etapa' => 'cerrado_ganado']);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'productos' => [$this->linea($a, 1, 1)],
        ])->assertStatus(422)->assertJsonPath('message', 'Esta oportunidad está cerrada; sus productos no se pueden cambiar.');

        $this->assertEquals(100.00, (float) $op->fresh()->monto_esperado);
        $this->assertEquals(10, (float) $this->lineasDe($op)->first()->cantidad);
    }

    public function test_cerrada_acepta_notas_sin_tocar_monto(): void
    {
        $a = $this->producto();
        $op = $this->crear([$this->linea($a, 10, 10)]);
        $op->update(['etapa' => 'cerrado_perdido']);

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'notas' => 'Se perdió por precio', 'monto_esperado' => 1,
        ])->assertOk();

        $op->refresh();
        $this->assertSame('Se perdió por precio', $op->notas);
        $this->assertEquals(100.00, (float) $op->monto_esperado);
        $this->assertCount(1, $this->lineasDe($op));
    }

    public function test_lineas_de_cotizacion_no_se_mezclan(): void
    {
        $a = $this->producto('A');
        $b = $this->producto('B');
        $op = $this->crear([$this->linea($a, 10, 10)]);

        $cot = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL.'/'.$op->id.'/cotizaciones', [
            'fecha_emision' => now()->toDateString(),
            'lineas' => [$this->linea($a, 7, 3)],
        ])->assertCreated();
        $cotizacionId = $cot->json('data.id');

        $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL.'/'.$op->id, [
            'productos' => [$this->linea($b, 2, 5)],
        ])->assertOk()->assertJsonCount(1, 'data.productos')->assertJsonPath('data.productos.0.producto_id', $b->id);

        $lineasCot = CrmOportunidadProducto::where('cotizacion_id', $cotizacionId)->get();
        $this->assertCount(1, $lineasCot);
        $this->assertEquals(7, (float) $lineasCot->first()->cantidad);
        $this->assertCount(1, $this->lineasDe($op));
        $this->assertSame($b->id, (int) $this->lineasDe($op)->first()->producto_id);
    }

    public function test_empresa_sin_interruptor_igual_que_hoy(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => false]);
        $a = $this->producto();

        // La empresa tiene el commodity «Mango» y no captura productos: desde la
        // Task 4 del CRM «oportunidad como eje» el tipo de producto es obligatorio.
        $datos = $this->payload([], ['tipo_producto' => 'Mango']);
        unset($datos['productos']);
        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $datos + ['monto_esperado' => 5000])
            ->assertCreated()->assertJsonPath('data.monto_esperado', '5000.00');

        $antes = CrmOportunidad::count();
        $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $this->payload([$this->linea($a, 1, 1)], ['tipo_producto' => 'Mango']))
            ->assertStatus(422)->assertJsonValidationErrors('productos');
        $this->assertSame($antes, CrmOportunidad::count());
        $this->assertSame(0, CrmOportunidadProducto::count());
    }

    public function test_evento_lleva_productos(): void
    {
        Event::fake([OportunidadUpdated::class]);
        $a = $this->producto('A');
        $b = $this->producto('B');

        $this->crear([$this->linea($a, 1, 1), $this->linea($b, 2, 2)]);

        Event::assertDispatched(
            OportunidadUpdated::class,
            fn (OportunidadUpdated $e) => count($e->data['productos'] ?? []) === 2
        );
    }
}
