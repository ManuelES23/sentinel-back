<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Versiones de la cotización enviada con motivo del cambio, solo SBP (spec 2026-11-25 §4). */
class VersionesCotizacionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmOportunidad $oportunidad;

    private CrmProducto $producto;

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
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Aguacate Hass', 'commodity' => 'Aguacate', 'precio' => 250,
        ]);
    }

    private function sbp(bool $encendido = true): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['cotizacion_por_caja' => $encendido]);
    }

    private function lineas(float $precio = 7.5): array
    {
        return [[
            'producto_id' => $this->producto->id, 'calibre' => 'Calibre 8',
            'presentacion' => 'Caja 8.85 LBS', 'cantidad' => 480, 'precio_unitario' => $precio,
        ]];
    }

    private function crear(float $precio = 7.5): CrmCotizacion
    {
        $res = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => 0,
                'lineas' => $this->lineas($precio),
            ])->assertCreated();

        return CrmCotizacion::findOrFail($res->json('data.id'));
    }

    private function enviada(float $precio = 7.5): CrmCotizacion
    {
        $c = $this->crear($precio);
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$c->id}/enviar")->assertOk();

        return $c->fresh();
    }

    private function editar(CrmCotizacion $c, float $precio, ?string $motivo): TestResponse
    {
        $payload = ['lineas' => $this->lineas($precio)];
        if ($motivo !== null) {
            $payload['motivo_cambio'] = $motivo;
        }

        return $this->withHeaders($this->crmHeaders())->putJson("/api/crm/cotizaciones/{$c->id}", $payload);
    }

    public function test_enviar_en_sbp_crea_la_version_1(): void
    {
        $this->sbp();
        $c = $this->crear();

        $this->assertSame(0, $c->versiones()->count());
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$c->id}/enviar")->assertOk();

        $versiones = $c->versiones()->get();
        $this->assertCount(1, $versiones);
        $v = $versiones->first();
        $this->assertSame(1, $v->numero);
        $this->assertNull($v->motivo);
        $this->assertSame($this->actingUser->id, $v->user_id);
        $this->assertEquals(3600.0, (float) $v->subtotal);
        $this->assertEquals(3600.0, (float) $v->total);
        $this->assertEquals(0.0, (float) $v->descuento_global_pct);
        $this->assertCount(1, $v->renglones);
        $this->assertSame($this->producto->id, $v->renglones[0]['producto_id']);
        $this->assertSame('Aguacate Hass', $v->renglones[0]['descripcion']);
        $this->assertSame('Calibre 8', $v->renglones[0]['calibre']);
        $this->assertSame('Caja 8.85 LBS', $v->renglones[0]['presentacion']);
        $this->assertEquals(480, $v->renglones[0]['cantidad']);
        $this->assertEquals(7.5, $v->renglones[0]['precio_unitario']);
    }

    public function test_editar_una_enviada_en_sbp_exige_motivo(): void
    {
        $this->sbp();
        $c = $this->enviada();

        $this->editar($c, 8.0, null)
            ->assertStatus(422)
            ->assertJsonPath('errors.motivo_cambio.0', 'Escribe el motivo del cambio.');
        $this->editar($c, 8.0, '   ')
            ->assertStatus(422)
            ->assertJsonPath('errors.motivo_cambio.0', 'Escribe el motivo del cambio.');

        $this->assertSame(1, $c->versiones()->count());
        $this->assertEquals(7.5, (float) $c->lineas()->first()->precio_unitario);
    }

    public function test_editar_una_enviada_con_motivo_crea_la_version_siguiente_y_actualiza_el_monto(): void
    {
        $this->sbp();
        $c = $this->enviada(7.5);
        $this->assertEquals(3600.0, (float) $this->oportunidad->fresh()->monto_esperado);

        $this->editar($c, 8.0, 'El cliente pidió mejor precio')->assertOk();

        $versiones = $c->versiones()->get();
        $this->assertCount(2, $versiones);
        $v2 = $versiones->last();
        $this->assertSame(2, $v2->numero);
        $this->assertSame('El cliente pidió mejor precio', $v2->motivo);
        $this->assertSame($this->actingUser->id, $v2->user_id);
        $this->assertEquals(8.0, $v2->renglones[0]['precio_unitario']);
        $this->assertEquals(3840.0, (float) $v2->total);
        $this->assertEquals(3600.0, (float) $versiones->first()->total);
        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertEquals(3840.0, (float) $this->oportunidad->fresh()->monto_esperado);
    }

    public function test_no_se_edita_una_cotizacion_con_venta_registrada(): void
    {
        $this->sbp();
        $c = $this->enviada();
        CrmOportunidadVenta::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $this->oportunidad->id,
            'cotizacion_id' => $c->id, 'vigente' => true, 'descuento_global_pct' => 0,
            'registrada_por' => $this->actingUser->id,
        ]);

        $this->editar($c, 8.0, 'Cambio tardío')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cotización ya tiene venta registrada; registra un ajuste en la oportunidad.');
        $this->assertSame(1, $c->versiones()->count());
    }

    public function test_rechazada_o_superada_no_se_edita(): void
    {
        $this->sbp();
        foreach (['rechazado', 'superado'] as $estado) {
            $c = $this->enviada();
            $c->update(['estado' => $estado]);

            $this->editar($c, 8.0, 'Intento')
                ->assertStatus(422)
                ->assertJsonPath('message', 'Solo se puede editar una cotización en borrador.');
            $this->assertSame(1, $c->versiones()->count());
        }
    }

    public function test_sin_bandera_una_enviada_sigue_sin_editarse_y_enviar_no_crea_version(): void
    {
        $this->sbp(false);
        $c = $this->crear();
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$c->id}/enviar")->assertOk();
        $this->assertSame(0, $c->versiones()->count());

        $this->editar($c, 8.0, 'Motivo')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se puede editar una cotización en borrador.');
        $this->assertSame(0, $c->versiones()->count());
    }

    public function test_un_borrador_en_sbp_se_edita_sin_motivo_y_sin_version(): void
    {
        $this->sbp();
        $c = $this->crear(7.5);

        $this->editar($c, 8.0, null)->assertOk();

        $this->assertSame(0, $c->versiones()->count());
        $this->assertEquals(3840.0, (float) $c->fresh()->total);
    }

    public function test_versiones_lista_la_mas_nueva_primero(): void
    {
        $this->sbp();
        $c = $this->enviada(7.5);
        $this->editar($c, 8.0, 'Subió el precio')->assertOk();

        $res = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$c->id}/versiones")->assertOk();

        $this->assertSame([2, 1], collect($res->json('data'))->pluck('numero')->all());
        $res->assertJsonPath('data.0.motivo', 'Subió el precio')
            ->assertJsonPath('data.0.usuario.id', $this->actingUser->id)
            ->assertJsonPath('data.0.usuario.name', $this->actingUser->name)
            ->assertJsonPath('data.0.renglones.0.descripcion', 'Aguacate Hass')
            ->assertJsonPath('data.1.motivo', null);
        $this->assertEquals(3840.0, (float) $res->json('data.0.total'));
        $this->assertEquals(3840.0, (float) $res->json('data.0.subtotal'));
        $this->assertEquals(0.0, (float) $res->json('data.0.descuento_global_pct'));
        $this->assertNotNull($res->json('data.0.created_at'));
        $this->assertNotNull($res->json('data.0.id'));
    }

    public function test_versiones_respeta_permiso_y_visibilidad(): void
    {
        $this->sbp();
        $c = $this->enviada();

        // Sin cotizaciones.ver: 403.
        UserSubmodulePermission::where('user_id', $this->actingUser->id)->update(['is_granted' => false]);
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$c->id}/versiones")->assertForbidden();
        UserSubmodulePermission::where('user_id', $this->actingUser->id)->update(['is_granted' => true]);
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$c->id}/versiones")->assertOk();

        // Vendedor propio distinto al de la oportunidad, sin oportunidades.equipo: 403.
        CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->quitarEquipo();
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cotizaciones/{$c->id}/versiones")->assertForbidden();
    }

    private function quitarEquipo(): void
    {
        $tipo = SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->get();
        UserSubmodulePermission::where('user_id', $this->actingUser->id)
            ->whereIn('permission_type_id', $tipo->pluck('id'))
            ->update(['is_granted' => false]);
    }
}
