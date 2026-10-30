<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Task 4 del CRM «oportunidad como eje» (spec 2026-10-30 §7): perder con
 * `editar`, ganar a mano con `cerrar`, cierre y reasignación de eventos
 * pendientes, conversión de prospecto y `tipo_producto`.
 */
class OportunidadCierreYMovimientosTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const BASE_URL = '/api/crm/oportunidades';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmOportunidad $propia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario',
            'activo' => true,
        ]);

        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
        ]);

        $this->propia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia', 'etapa' => 'negociacion',
            'monto_esperado' => 100,
        ]);
    }

    private function permisos(array $permisos): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
    }

    private function cambiarEtapa(CrmOportunidad $oportunidad, array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->patchJson(self::BASE_URL."/{$oportunidad->id}/cambiar-etapa", $datos);
    }

    private function evento(CrmOportunidad $oportunidad, int $vendedorId, bool $completado = false, string $titulo = 'Llamar'): CrmAgenda
    {
        return CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorId,
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $oportunidad->id,
            'tipo' => 'llamada', 'titulo' => $titulo, 'completado' => $completado,
            'fecha_inicio' => now()->addHour(), 'fecha_fin' => now()->addHours(2),
        ]);
    }

    // ── Cierre ────────────────────────────────────────────────────────────

    public function test_vendedor_marca_perdida_con_editar(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio'])
            ->assertOk()
            ->assertJsonPath('data.etapa', 'cerrado_perdido');

        $fresca = $this->propia->fresh();
        $this->assertSame('cerrado_perdido', $fresca->etapa);
        $this->assertSame('Precio', $fresca->motivo_perdida);
        $this->assertNotNull($fresca->fecha_cierre_real);
    }

    public function test_perder_sin_editar_responde_403_aunque_tenga_cerrar(): void
    {
        $this->permisos(['ver', 'cerrar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio'])->assertForbidden();

        $this->assertSame('negociacion', $this->propia->fresh()->etapa);
    }

    public function test_ganada_a_mano_exige_cerrar(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_ganado', 'forzar' => true])->assertForbidden();
        $this->assertSame('negociacion', $this->propia->fresh()->etapa);

        $this->permisos(['cerrar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_ganado', 'forzar' => true])->assertOk();
        $this->assertSame('cerrado_ganado', $this->propia->fresh()->etapa);
    }

    public function test_ganar_con_forzar_deja_por_cobrar(): void
    {
        $this->permisos(['ver', 'editar', 'cerrar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_ganado', 'forzar' => true])
            ->assertOk()
            ->assertJsonPath('data.estado_cobro', 'por_cobrar');

        $fresca = $this->propia->fresh();
        $this->assertSame('por_cobrar', $fresca->estado_cobro);
        $this->assertNotNull($fresca->cobro_actualizado_at);
    }

    public function test_perder_cierra_eventos_pendientes(): void
    {
        $this->permisos(['ver', 'editar']);
        $pendiente = $this->evento($this->propia, $this->propio->id);
        $otroVendedor = $this->evento($this->propia, $this->vendedor->id, titulo: 'De otro');
        $hecho = $this->evento($this->propia, $this->propio->id, completado: true, titulo: 'Hecho');

        $otra = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Otra', 'etapa' => 'calificado',
        ]);
        $deOtra = $this->evento($otra, $this->propio->id, titulo: 'De otra oportunidad');
        $actividadesAntes = CrmActividad::count();

        Event::fake([AgendaUpdated::class, OportunidadUpdated::class]);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio'])->assertOk();

        $this->assertTrue($pendiente->fresh()->completado);
        $this->assertTrue($otroVendedor->fresh()->completado);
        $this->assertTrue($hecho->fresh()->completado);
        $this->assertFalse($deOtra->fresh()->completado);

        // Se cierran solos: sin actividad ni siguiente paso.
        $this->assertSame($actividadesAntes, CrmActividad::count());
        $this->assertSame(4, CrmAgenda::count());

        Event::assertDispatchedTimes(AgendaUpdated::class, 2);
        Event::assertDispatched(AgendaUpdated::class, function (AgendaUpdated $e) use ($pendiente) {
            return $e->action === 'completed'
                && $e->data['id'] === $pendiente->id
                && $e->data['completado'] === true
                && isset($e->data['vendedor']['nombre']);
        });
    }

    public function test_ganar_no_toca_los_eventos_pendientes(): void
    {
        $this->permisos(['ver', 'editar', 'cerrar']);
        $pendiente = $this->evento($this->propia, $this->propio->id);

        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_ganado', 'forzar' => true])->assertOk();

        $this->assertFalse($pendiente->fresh()->completado);
    }

    // ── Reasignar ─────────────────────────────────────────────────────────

    public function test_reasignar_mueve_eventos_pendientes(): void
    {
        $this->permisos(['ver', 'editar', 'equipo']);
        $pendiente = $this->evento($this->propia, $this->propio->id);
        $hecho = $this->evento($this->propia, $this->propio->id, completado: true, titulo: 'Hecho');

        $otra = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Otra', 'etapa' => 'calificado',
        ]);
        $deOtra = $this->evento($otra, $this->propio->id, titulo: 'De otra oportunidad');

        Event::fake([AgendaUpdated::class, OportunidadUpdated::class]);

        $this->withHeaders($this->crmHeaders())
            ->putJson(self::BASE_URL."/{$this->propia->id}", ['vendedor_id' => $this->vendedor->id])
            ->assertOk()
            ->assertJsonPath('data.vendedor_id', $this->vendedor->id);

        $this->assertSame($this->vendedor->id, (int) $pendiente->fresh()->vendedor_id);
        // Lo ya hecho se queda con quien lo hizo; lo de otra oportunidad no se mueve.
        $this->assertSame($this->propio->id, (int) $hecho->fresh()->vendedor_id);
        $this->assertSame($this->propio->id, (int) $deOtra->fresh()->vendedor_id);

        Event::assertDispatchedTimes(AgendaUpdated::class, 1);
        Event::assertDispatched(AgendaUpdated::class, function (AgendaUpdated $e) use ($pendiente) {
            return $e->action === 'updated'
                && $e->data['id'] === $pendiente->id
                && (int) $e->data['vendedor_id'] === $this->vendedor->id
                && $e->data['vendedor']['nombre'] === $this->vendedor->nombre;
        });
    }

    public function test_guardar_con_el_mismo_vendedor_no_mueve_eventos(): void
    {
        $this->permisos(['ver', 'editar', 'equipo']);
        $pendiente = $this->evento($this->propia, $this->propio->id);
        Event::fake([AgendaUpdated::class, OportunidadUpdated::class]);

        $this->withHeaders($this->crmHeaders())
            ->putJson(self::BASE_URL."/{$this->propia->id}", ['vendedor_id' => $this->propio->id, 'nombre' => 'Renombrada'])
            ->assertOk();

        $this->assertSame($this->propio->id, (int) $pendiente->fresh()->vendedor_id);
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    // ── Convertir ─────────────────────────────────────────────────────────

    public function test_convertir_mueve_abiertas_y_ganadas_al_cliente(): void
    {
        $this->otorgarPermisosCrm('clientes', 'clientes', ['crear']);
        $prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Nuevo',
            'vendedor_id' => $this->vendedor->id, 'estatus' => 'nuevo',
        ]);
        $crear = fn (string $nombre, string $etapa) => CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => $nombre, 'etapa' => $etapa,
        ]);
        $abierta = $crear('Abierta', 'propuesta');
        $ganada = $crear('Ganada', 'cerrado_ganado');
        $perdida = $crear('Perdida', 'cerrado_perdido');

        Event::fake([OportunidadUpdated::class]);

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$prospecto->id}/convertir-cliente")
            ->assertCreated();
        $clienteId = $respuesta->json('data.id');

        foreach ([$abierta, $ganada] as $oportunidad) {
            $fresca = $oportunidad->fresh();
            $this->assertSame($clienteId, (int) $fresca->cliente_id);
            $this->assertNull($fresca->prospecto_id);
        }
        $this->assertSame($prospecto->id, (int) $perdida->fresh()->prospecto_id);
        $this->assertNull($perdida->fresh()->cliente_id);

        Event::assertDispatchedTimes(OportunidadUpdated::class, 2);
        Event::assertDispatched(OportunidadUpdated::class, function (OportunidadUpdated $e) use ($abierta, $clienteId) {
            return $e->action === 'updated'
                && $e->data['id'] === $abierta->id
                && (int) $e->data['cliente_id'] === $clienteId
                && $e->data['prospecto_id'] === null
                && $e->data['cliente']['id'] === $clienteId
                && $e->data['monto_origen']['tipo'] === 'sin_monto';
        });
    }

    public function test_convertir_no_toca_oportunidades_de_otros_prospectos(): void
    {
        $this->otorgarPermisosCrm('clientes', 'clientes', ['crear']);
        $uno = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Uno', 'estatus' => 'nuevo']);
        $dos = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Dos', 'estatus' => 'nuevo']);
        $deDos = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $dos->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'De dos', 'etapa' => 'calificado',
        ]);

        $this->withHeaders($this->crmHeaders())->postJson("/api/crm/prospectos/{$uno->id}/convertir-cliente")->assertCreated();

        $this->assertSame($dos->id, (int) $deDos->fresh()->prospecto_id);
        $this->assertNull($deDos->fresh()->cliente_id);
    }

    // ── tipo_producto ─────────────────────────────────────────────────────

    private function conCommodities(array $commodities = ['Mango', 'Corn']): void
    {
        foreach ($commodities as $i => $commodity) {
            CrmProducto::create([
                'empresa_id' => $this->enterprise->id, 'nombre' => "Producto $i", 'precio' => 0,
                'unidad_medida' => 'caja', 'commodity' => $commodity,
            ]);
        }
    }

    private function crear(array $extra = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, $extra + [
            'nombre' => 'Nueva', 'cliente_id' => $this->cliente->id, 'vendedor_id' => $this->propio->id,
        ]);
    }

    private function editar(CrmOportunidad $oportunidad, array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$oportunidad->id}", $datos);
    }

    public function test_tipo_producto_obligatorio_con_commodities(): void
    {
        $this->permisos(['ver', 'crear']);
        $this->conCommodities();

        $this->crear()->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
        $this->assertSame(0, CrmOportunidad::where('nombre', 'Nueva')->count());

        $this->crear(['tipo_producto' => 'Mango'])
            ->assertCreated()
            ->assertJsonPath('data.tipo_producto', 'Mango');
        $this->assertSame('Mango', CrmOportunidad::where('nombre', 'Nueva')->value('tipo_producto'));
    }

    public function test_tipo_producto_invalido_422(): void
    {
        $this->permisos(['ver', 'crear']);
        $this->conCommodities();

        $this->crear(['tipo_producto' => 'Banana'])->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
    }

    public function test_los_commodities_de_otra_empresa_no_cuentan(): void
    {
        $this->permisos(['ver', 'crear']);
        $otra = $this->crearOtraEmpresa();
        CrmProducto::create([
            'empresa_id' => $otra->id, 'nombre' => 'Pimiento', 'precio' => 0,
            'unidad_medida' => 'caja', 'commodity' => 'Pepper',
        ]);

        $this->crear()->assertCreated();
        $this->crear(['tipo_producto' => 'Cualquiera'])->assertCreated();
    }

    public function test_sin_commodities_no_se_exige(): void
    {
        $this->permisos(['ver', 'crear']);

        $this->crear()->assertCreated()->assertJsonPath('data.tipo_producto', null);
        $this->crear(['tipo_producto' => 'Lo que sea'])->assertCreated()->assertJsonPath('data.tipo_producto', 'Lo que sea');
        $this->crear(['tipo_producto' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
    }

    public function test_con_productos_en_oportunidad_es_opcional_y_no_se_valida(): void
    {
        $this->permisos(['ver', 'crear']);
        $this->conCommodities();
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['productos_en_oportunidad' => true]);
        $producto = CrmProducto::first();
        $lineas = ['productos' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 10]]];

        $this->crear($lineas)->assertCreated();
        $this->crear($lineas + ['tipo_producto' => 'Banana'])->assertCreated()->assertJsonPath('data.tipo_producto', 'Banana');
    }

    public function test_editar_el_tipo_exige_uno_de_la_lista_solo_si_cambia(): void
    {
        $this->permisos(['ver', 'editar']);
        $this->conCommodities();
        $this->propia->update(['tipo_producto' => 'Legado']);

        // Un valor viejo que ya estaba guardado no se revalida si no cambia.
        $this->editar($this->propia, ['nombre' => 'Renombrada', 'tipo_producto' => 'Legado'])->assertOk();
        // Omitirlo tampoco molesta.
        $this->editar($this->propia, ['nombre' => 'Otra vez'])->assertOk();

        $this->editar($this->propia, ['tipo_producto' => 'Banana'])->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
        $this->assertSame('Legado', $this->propia->fresh()->tipo_producto);

        $this->editar($this->propia, ['tipo_producto' => 'Corn'])->assertOk()->assertJsonPath('data.tipo_producto', 'Corn');
        $this->assertSame('Corn', $this->propia->fresh()->tipo_producto);
    }

    public function test_editar_no_permite_borrar_el_tipo_cuando_es_obligatorio(): void
    {
        $this->permisos(['ver', 'editar']);
        $this->conCommodities();
        $this->propia->update(['tipo_producto' => 'Mango']);

        $this->editar($this->propia, ['tipo_producto' => null])->assertStatus(422)->assertJsonValidationErrors('tipo_producto');
        $this->assertSame('Mango', $this->propia->fresh()->tipo_producto);

        // Una oportunidad vieja sin tipo sí puede guardarse sin él.
        $this->propia->update(['tipo_producto' => null]);
        $this->editar($this->propia, ['nombre' => 'Sin tipo', 'tipo_producto' => null])->assertOk();
    }

    public function test_editar_sin_commodities_acepta_cualquier_texto(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->editar($this->propia, ['tipo_producto' => 'Libre'])->assertOk()->assertJsonPath('data.tipo_producto', 'Libre');
        $this->editar($this->propia, ['tipo_producto' => str_repeat('a', 101)])->assertStatus(422);
    }

    public function test_commodities_de_devuelve_distintos_no_vacios_y_ordenados(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->conCommodities(['Mango', 'Mango', 'Corn', null, '']);
        CrmProducto::create([
            'empresa_id' => $otra->id, 'nombre' => 'Pimiento', 'precio' => 0,
            'unidad_medida' => 'caja', 'commodity' => 'Pepper',
        ]);

        $this->assertSame(['Corn', 'Mango'], CrmProducto::commoditiesDe($this->enterprise->id)->all());
        $this->assertSame(['Pepper'], CrmProducto::commoditiesDe($otra->id)->all());
    }
}
