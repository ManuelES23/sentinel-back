<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\CotizacionUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Las cotizaciones heredan la visibilidad de su oportunidad
 * (VisibilidadOportunidades): leerlas exige cotizaciones.ver y escribirlas su
 * propio permiso, y en ambos casos poder VER la oportunidad. Sin
 * oportunidades.equipo, solo las de las oportunidades del vendedor propio.
 */
class CotizacionVisibilidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const LEER_AJENA = 'No puedes ver las cotizaciones de otro vendedor.';

    private const MODIFICAR_AJENA = 'No puedes modificar las cotizaciones de otro vendedor.';

    private CrmVendedor $propio;

    private CrmOportunidad $propia;

    private CrmOportunidad $ajena;

    private CrmCotizacion $cotPropia;

    private CrmCotizacion $cotAjenaBorrador;

    private CrmCotizacion $cotAjenaEnviada;

    private CrmProducto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        $this->propia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia', 'etapa' => 'propuesta',
        ]);
        $this->ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena secreta', 'etapa' => 'propuesta',
        ]);
        $this->producto = CrmProducto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 100]);

        $this->cotPropia = $this->cotizacion($this->propia, 'enviado', 100);
        $this->cotAjenaBorrador = $this->cotizacion($this->ajena, 'borrador', 5000, 'Notas ajenas');
        $this->cotAjenaEnviada = $this->cotizacion($this->ajena, 'enviado', 7000);
    }

    private function cotizacion(CrmOportunidad $oportunidad, string $estado, float $total, ?string $notas = null): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $oportunidad->empresa_id, 'oportunidad_id' => $oportunidad->id,
            'folio' => 'COT-'.str_pad((string) (CrmCotizacion::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'estado' => $estado, 'fecha_emision' => now()->toDateString(),
            'descuento_global_pct' => 0, 'subtotal' => $total, 'total' => $total, 'notas' => $notas,
        ]);
    }

    private function cot(array $permisos): void
    {
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', $permisos);
    }

    private function opo(array $permisos): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
    }

    private function leer(string $uri)
    {
        return $this->withHeaders($this->crmHeaders())->getJson('/api/crm/'.$uri);
    }

    private function pedir(string $metodo, string $uri, array $datos = [])
    {
        return $this->json($metodo, '/api/crm/'.$uri, $datos, $this->crmHeaders());
    }

    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function assertAjenasIntactas(): void
    {
        $this->assertSame('borrador', $this->cotAjenaBorrador->fresh()->estado);
        $this->assertSame('Notas ajenas', $this->cotAjenaBorrador->fresh()->notas);
        $this->assertSame('enviado', $this->cotAjenaEnviada->fresh()->estado);
        $this->assertSame('propuesta', $this->ajena->fresh()->etapa);
        $this->assertNull($this->ajena->fresh()->fecha_cierre_real);
    }

    // ── GET crm/cotizaciones ──────────────────────────────────────────────

    public function test_el_listado_del_vendedor_solo_trae_las_cotizaciones_de_sus_oportunidades(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver']);

        $response = $this->leer('cotizaciones')->assertOk();

        $this->assertSame([$this->cotPropia->id], $this->ids($response));
        $response->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('Ajena secreta', $response->getContent());
    }

    public function test_el_listado_sin_equipo_responde_403_al_pedir_otro_vendedor(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver']);

        $this->leer("cotizaciones?vendedor_id={$this->vendedor->id}")->assertForbidden();
        $this->assertSame([$this->cotPropia->id], $this->ids($this->leer("cotizaciones?vendedor_id={$this->propio->id}")->assertOk()));
    }

    public function test_el_listado_con_equipo_trae_todas_y_filtra_por_vendedor(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver', 'equipo']);

        $todas = $this->leer('cotizaciones')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame(
            collect([$this->cotPropia->id, $this->cotAjenaBorrador->id, $this->cotAjenaEnviada->id])->sort()->values()->all(),
            $this->ids($todas),
        );
        $this->assertSame(
            collect([$this->cotAjenaBorrador->id, $this->cotAjenaEnviada->id])->sort()->values()->all(),
            $this->ids($this->leer("cotizaciones?vendedor_id={$this->vendedor->id}")->assertOk()),
        );
    }

    public function test_el_listado_exige_ver_cotizaciones_ver_oportunidades_y_vendedor_activo(): void
    {
        $this->opo(['ver']);
        $this->leer('cotizaciones')->assertForbidden()->assertJsonPath('message', 'No tienes permiso para ver cotizaciones.');

        $this->cot(['ver']);
        $this->propio->update(['activo' => false]);
        $this->leer('cotizaciones')->assertForbidden()->assertJsonPath('message', 'Tu usuario no está vinculado a un vendedor activo.');
    }

    public function test_el_listado_sin_ver_oportunidades_responde_403(): void
    {
        $this->cot(['ver']);

        $this->leer('cotizaciones')->assertForbidden()->assertJsonPath('message', 'No tienes permiso para ver oportunidades.');
    }

    public function test_la_cotizacion_de_una_oportunidad_eliminada_solo_la_ve_el_equipo(): void
    {
        $huerfana = $this->cotizacion($this->propia, 'borrador', 10);
        $this->propia->delete();
        $this->cot(['ver']);
        $this->opo(['ver']);

        $this->assertSame([], $this->ids($this->leer('cotizaciones')->assertOk()));
        $this->leer("cotizaciones/{$huerfana->id}")->assertForbidden();

        $this->opo(['equipo']);
        $this->assertContains($huerfana->id, $this->ids($this->leer('cotizaciones')->assertOk()));
        $this->leer("cotizaciones/{$huerfana->id}")->assertOk();
    }

    // ── GET crm/cotizaciones/{id} ─────────────────────────────────────────

    public function test_show_de_una_cotizacion_ajena_responde_403_sin_revelarla(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver']);

        $response = $this->leer("cotizaciones/{$this->cotAjenaBorrador->id}");

        $response->assertForbidden()->assertJsonPath('message', self::LEER_AJENA);
        $this->assertStringNotContainsString('Notas ajenas', $response->getContent());
        $this->leer("cotizaciones/{$this->cotPropia->id}")->assertOk()->assertJsonPath('data.id', $this->cotPropia->id);
    }

    public function test_show_exige_ver_cotizaciones(): void
    {
        $this->opo(['ver', 'equipo']);

        $this->leer("cotizaciones/{$this->cotPropia->id}")->assertForbidden();
    }

    public function test_show_con_equipo_lee_cualquier_cotizacion(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver', 'equipo']);

        $this->leer("cotizaciones/{$this->cotAjenaBorrador->id}")->assertOk()->assertJsonPath('data.notas', 'Notas ajenas');
    }

    // ── escrituras ────────────────────────────────────────────────────────

    public function test_editar_y_enviar_una_cotizacion_ajena_responden_403_sin_efectos(): void
    {
        Event::fake([CotizacionUpdated::class, OportunidadUpdated::class]);
        $this->cot(['ver', 'crear', 'editar']);
        $this->opo(['ver']);

        $this->pedir('PUT', "cotizaciones/{$this->cotAjenaBorrador->id}", ['notas' => 'Cambio'])
            ->assertForbidden()->assertJsonPath('message', self::MODIFICAR_AJENA);
        $this->pedir('PATCH', "cotizaciones/{$this->cotAjenaBorrador->id}/enviar")
            ->assertForbidden()->assertJsonPath('message', self::MODIFICAR_AJENA);

        $this->assertAjenasIntactas();
        Event::assertNotDispatched(CotizacionUpdated::class);
    }

    public function test_editar_y_enviar_una_cotizacion_propia_funciona(): void
    {
        $borrador = $this->cotizacion($this->propia, 'borrador', 10);
        $this->cot(['ver', 'editar']);
        $this->opo(['ver']);

        $this->pedir('PUT', "cotizaciones/{$borrador->id}", ['notas' => 'Mía'])->assertOk();
        $this->pedir('PATCH', "cotizaciones/{$borrador->id}/enviar")->assertOk();

        $this->assertSame('enviado', $borrador->fresh()->estado);
        $this->assertSame('Mía', $borrador->fresh()->notas);
    }

    public function test_aprobar_o_rechazar_sin_equipo_una_ajena_responde_403_sin_cerrar_la_oportunidad(): void
    {
        Event::fake([CotizacionUpdated::class, OportunidadUpdated::class]);
        $this->cot(['ver', 'aprobar', 'rechazar']);
        $this->opo(['ver']);
        $historial = CrmOportunidadEtapa::count();

        $this->pedir('PATCH', "cotizaciones/{$this->cotAjenaEnviada->id}/aprobar")
            ->assertForbidden()->assertJsonPath('message', self::MODIFICAR_AJENA);
        $this->pedir('PATCH', "cotizaciones/{$this->cotAjenaEnviada->id}/rechazar")
            ->assertForbidden()->assertJsonPath('message', self::MODIFICAR_AJENA);

        $this->assertAjenasIntactas();
        $this->assertSame($historial, CrmOportunidadEtapa::count());
        Event::assertNotDispatched(CotizacionUpdated::class);
        Event::assertNotDispatched(OportunidadUpdated::class);
    }

    public function test_la_gerencia_con_equipo_aprueba_la_cotizacion_de_otro_vendedor(): void
    {
        $this->cot(['ver', 'aprobar', 'rechazar']);
        $this->opo(['ver', 'equipo']);

        $this->pedir('PATCH', "cotizaciones/{$this->cotAjenaEnviada->id}/aprobar")->assertOk();

        $this->assertSame('aprobado', $this->cotAjenaEnviada->fresh()->estado);
        $this->assertSame('cerrado_ganado', $this->ajena->fresh()->etapa);
    }

    public function test_el_aprobador_sin_equipo_aprueba_las_de_sus_oportunidades(): void
    {
        $this->cot(['ver', 'aprobar']);
        $this->opo(['ver']);

        $this->pedir('PATCH', "cotizaciones/{$this->cotPropia->id}/aprobar")->assertOk();

        $this->assertSame('cerrado_ganado', $this->propia->fresh()->etapa);
    }

    // ── crm/oportunidades/{id}/cotizaciones ───────────────────────────────

    public function test_las_cotizaciones_anidadas_de_una_oportunidad_ajena_responden_403(): void
    {
        $this->cot(['ver']);
        $this->opo(['ver']);

        $response = $this->leer("oportunidades/{$this->ajena->id}/cotizaciones");

        $response->assertForbidden()->assertJsonPath('message', self::LEER_AJENA);
        $this->assertStringNotContainsString('Notas ajenas', $response->getContent());
        $this->assertSame([$this->cotPropia->id], $this->ids($this->leer("oportunidades/{$this->propia->id}/cotizaciones")->assertOk()));
    }

    public function test_las_cotizaciones_anidadas_exigen_ver_cotizaciones(): void
    {
        $this->opo(['ver', 'equipo']);

        $this->leer("oportunidades/{$this->propia->id}/cotizaciones")->assertForbidden();
    }

    public function test_crear_una_cotizacion_sobre_una_oportunidad_ajena_responde_403_sin_crear_nada(): void
    {
        Event::fake([CotizacionUpdated::class]);
        $this->cot(['ver', 'crear']);
        $this->opo(['ver']);
        $cotizaciones = CrmCotizacion::withTrashed()->count();

        $this->pedir('POST', "oportunidades/{$this->ajena->id}/cotizaciones", [
            'fecha_emision' => now()->toDateString(),
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 100]],
        ])->assertForbidden()->assertJsonPath('message', self::MODIFICAR_AJENA);

        $this->assertSame($cotizaciones, CrmCotizacion::withTrashed()->count());
        $this->assertSame(0, CrmOportunidadProducto::count());
        Event::assertNotDispatched(CotizacionUpdated::class);
    }

    public function test_con_equipo_cotiza_sobre_la_oportunidad_de_otro_vendedor(): void
    {
        $this->cot(['ver', 'crear']);
        $this->opo(['ver', 'equipo']);

        $this->pedir('POST', "oportunidades/{$this->ajena->id}/cotizaciones", [
            'fecha_emision' => now()->toDateString(),
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 100]],
        ])->assertCreated();
    }

    // ── tenant ────────────────────────────────────────────────────────────

    public function test_la_cotizacion_de_otra_empresa_sigue_respondiendo_404_y_su_equipo_no_cuenta(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);
        $this->otorgarTodosLosPermisosCrm($otra);
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Otro tenant', 'activo' => true]);
        $opOtra = CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $vendedorOtra->id, 'nombre' => 'De otra', 'etapa' => 'propuesta',
        ]);
        $cotOtra = $this->cotizacion($opOtra, 'enviado', 1);
        $this->cot(['ver', 'aprobar']);
        $this->opo(['ver']);

        $this->leer("cotizaciones/{$cotOtra->id}")->assertNotFound();
        $this->pedir('PATCH', "cotizaciones/{$cotOtra->id}/aprobar")->assertNotFound();
        $this->leer("oportunidades/{$opOtra->id}/cotizaciones")->assertNotFound();
        // El equipo concedido en la otra empresa no abre las ajenas de esta.
        $this->leer("cotizaciones/{$this->cotAjenaEnviada->id}")->assertForbidden();
        $this->assertSame([$this->cotPropia->id], $this->ids($this->leer('cotizaciones')->assertOk()));
        $this->assertSame('enviado', $cotOtra->fresh()->estado);
    }
}
