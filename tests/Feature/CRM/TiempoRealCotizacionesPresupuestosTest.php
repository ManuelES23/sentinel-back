<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\CotizacionUpdated;
use App\Events\CRM\PresupuestoUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Cotizaciones y presupuestos no emitían nada: dos personas trabajando sobre
 * la misma oportunidad no se enteraban de los cambios de la otra hasta
 * recargar a mano.
 */
class TiempoRealCotizacionesPresupuestosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private CrmOportunidad $oportunidad;

    private CrmProducto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente',
            'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad de prueba',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Producto',
            'precio' => 250,
        ]);
    }

    private function crearCotizacion(): array
    {
        return $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'descuento_global_pct' => 0,
                'lineas' => [
                    ['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => 250],
                ],
            ])
            ->json();
    }

    public function test_crear_una_cotizacion_avisa_en_tiempo_real(): void
    {
        Event::fake([CotizacionUpdated::class]);

        $this->crearCotizacion();

        Event::assertDispatched(
            CotizacionUpdated::class,
            fn (CotizacionUpdated $e) => $e->action === 'created'
                && $e->data['oportunidad_id'] === $this->oportunidad->id
        );
    }

    public function test_enviar_una_cotizacion_avisa_en_tiempo_real(): void
    {
        $cotizacion = $this->crearCotizacion();

        Event::fake([CotizacionUpdated::class]);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/cotizaciones/{$cotizacion['data']['id']}/enviar")
            ->assertOk();

        Event::assertDispatched(
            CotizacionUpdated::class,
            fn (CotizacionUpdated $e) => $e->action === 'updated' && $e->data['estado'] === 'enviado'
        );
    }

    public function test_la_cotizacion_viaja_por_el_canal_de_cotizaciones(): void
    {
        $evento = new CotizacionUpdated('created', ['id' => 1, 'empresa_id' => $this->enterprise->id]);

        $this->assertSame(
            "private-module.{$this->enterprise->slug}.crm.cotizaciones",
            $evento->broadcastOn()[0]->name
        );
    }

    public function test_crear_un_presupuesto_avisa_en_tiempo_real(): void
    {
        Event::fake([PresupuestoUpdated::class]);

        $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/presupuestos', [
                'vendedor_id' => $this->vendedor->id,
                'mes' => 8,
                'anio' => 2026,
                'meta_monto' => 10000,
            ])
            ->assertCreated();

        Event::assertDispatched(
            PresupuestoUpdated::class,
            fn (PresupuestoUpdated $e) => $e->action === 'created'
        );
    }

    public function test_editar_un_presupuesto_avisa_en_tiempo_real(): void
    {
        $presupuesto = CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'mes' => 9,
            'anio' => 2026,
            'meta_monto' => 5000,
        ]);

        Event::fake([PresupuestoUpdated::class]);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/presupuestos/{$presupuesto->id}", ['meta_monto' => 7500])
            ->assertOk();

        Event::assertDispatched(
            PresupuestoUpdated::class,
            fn (PresupuestoUpdated $e) => $e->action === 'updated'
        );
    }

    public function test_el_presupuesto_viaja_por_el_canal_de_presupuestos(): void
    {
        $evento = new PresupuestoUpdated('created', ['id' => 1, 'empresa_id' => $this->enterprise->id]);

        $this->assertSame(
            "private-module.{$this->enterprise->slug}.crm.presupuestos",
            $evento->broadcastOn()[0]->name
        );
    }
}
