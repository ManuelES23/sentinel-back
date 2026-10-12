<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Las fichas de cliente (show y resumen) y de prospecto (show) incrustan
 * oportunidades: solo deben traer las que el usuario puede ver según
 * VisibilidadOportunidades::alcance. La ficha en sí sigue abierta.
 */
class FichasOportunidadesVisiblesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmProspecto $prospecto;

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
        $this->prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto', 'estatus' => 'nuevo',
        ]);

        $this->oportunidad('Propia abierta', $this->propio->id, 100, 'calificado', cliente: true);
        $this->oportunidad('Propia ganada', $this->propio->id, 50, 'cerrado_ganado', cliente: true);
        $this->oportunidad('Ajena abierta', $this->vendedor->id, 1000, 'propuesta', cliente: true);
        $this->oportunidad('Prospecto propia', $this->propio->id, 10, 'prospecto', cliente: false);
        $this->oportunidad('Prospecto ajena', $this->vendedor->id, 20, 'prospecto', cliente: false);

        // Fila inconsistente de otra empresa que apunta al mismo cliente y
        // prospecto (y al vendedor propio): nunca debe salir en la ficha.
        $otra = $this->crearOtraEmpresa();
        CrmOportunidad::create([
            'empresa_id' => $otra->id, 'cliente_id' => $this->cliente->id, 'vendedor_id' => $this->propio->id,
            'nombre' => 'Otra empresa cliente', 'monto_esperado' => 7, 'etapa' => 'calificado',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $otra->id, 'prospecto_id' => $this->prospecto->id, 'vendedor_id' => $this->propio->id,
            'nombre' => 'Otra empresa prospecto', 'monto_esperado' => 7, 'etapa' => 'calificado',
        ]);
    }

    private function oportunidad(string $nombre, int $vendedorId, float $monto, string $etapa, bool $cliente): void
    {
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            $cliente ? 'cliente_id' : 'prospecto_id' => $cliente ? $this->cliente->id : $this->prospecto->id,
            'vendedor_id' => $vendedorId,
            'nombre' => $nombre,
            'monto_esperado' => $monto,
            'etapa' => $etapa,
        ]);
    }

    private function nombresDe(array $filas): array
    {
        return collect($filas)->pluck('nombre')->sort()->values()->all();
    }

    private function nombresPorId(array $filas): array
    {
        return CrmOportunidad::whereIn('id', collect($filas)->pluck('id'))->orderBy('nombre')->pluck('nombre')->all();
    }

    private function fichaCliente(): array
    {
        return $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/clientes/{$this->cliente->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Cliente')
            ->json('data.oportunidades');
    }

    private function resumenCliente(): array
    {
        return $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/clientes/{$this->cliente->id}/resumen")
            ->assertOk()
            ->assertJsonPath('data.cliente.nombre', 'Cliente')
            ->json('data');
    }

    private function fichaProspecto(): array
    {
        return $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/prospectos/{$this->prospecto->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Prospecto')
            ->json('data.oportunidades');
    }

    // ── Sin oportunidades.ver: nada ────────────────────────────────────────

    public function test_sin_ver_la_ficha_de_cliente_no_trae_oportunidades(): void
    {
        $this->otorgarPermisosCrm('clientes', 'clientes', ['ver']);

        $this->assertSame([], $this->fichaCliente());
    }

    public function test_sin_ver_el_resumen_de_cliente_no_cuenta_ni_suma_oportunidades(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $resumen = $this->resumenCliente();

        $this->assertSame(0, $resumen['metricas']['oportunidades']);
        $this->assertEquals(0, $resumen['metricas']['valor_pipeline']);
        $this->assertSame([], $resumen['oportunidades']);
    }

    public function test_sin_ver_la_ficha_de_prospecto_no_trae_oportunidades(): void
    {
        $this->assertSame([], $this->fichaProspecto());
    }

    // ── Con oportunidades.equipo: todas las de la empresa ─────────────────

    public function test_con_equipo_la_ficha_de_cliente_trae_todas_las_de_la_empresa(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $this->assertSame(['Ajena abierta', 'Propia abierta', 'Propia ganada'], $this->nombresDe($this->fichaCliente()));
    }

    public function test_con_equipo_el_resumen_de_cliente_cuenta_y_suma_todas_las_de_la_empresa(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $resumen = $this->resumenCliente();

        $this->assertSame(3, $resumen['metricas']['oportunidades']);
        $this->assertEquals(1100, $resumen['metricas']['valor_pipeline']);
        $this->assertSame(['Ajena abierta', 'Propia abierta', 'Propia ganada'], $this->nombresPorId($resumen['oportunidades']));
    }

    public function test_con_equipo_la_ficha_de_prospecto_trae_todas_las_de_la_empresa(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $filas = $this->fichaProspecto();

        $this->assertSame(['Prospecto ajena', 'Prospecto propia'], $this->nombresDe($filas));
        $this->assertSame(
            ['Juan Pérez', 'Vendedor del usuario'],
            collect($filas)->pluck('vendedor.nombre')->sort()->values()->all(),
        );
    }

    // ── Sin equipo: solo las del vendedor propio ──────────────────────────

    public function test_sin_equipo_la_ficha_de_cliente_trae_solo_las_propias(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->assertSame(['Propia abierta', 'Propia ganada'], $this->nombresDe($this->fichaCliente()));
    }

    public function test_sin_equipo_el_resumen_de_cliente_cuenta_y_suma_solo_las_propias(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $resumen = $this->resumenCliente();

        $this->assertSame(2, $resumen['metricas']['oportunidades']);
        $this->assertEquals(100, $resumen['metricas']['valor_pipeline']);
        $this->assertSame(['Propia abierta', 'Propia ganada'], $this->nombresPorId($resumen['oportunidades']));
    }

    public function test_sin_equipo_la_ficha_de_prospecto_trae_solo_las_propias(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->assertSame(['Prospecto propia'], $this->nombresDe($this->fichaProspecto()));
    }

    // ── Sin vendedor vinculado (y sin equipo): nada ───────────────────────

    public function test_sin_equipo_y_sin_vendedor_vinculado_las_fichas_no_traen_oportunidades(): void
    {
        $this->propio->update(['activo' => false]);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->assertSame([], $this->fichaCliente());
        $this->assertSame([], $this->fichaProspecto());

        $resumen = $this->resumenCliente();
        $this->assertSame(0, $resumen['metricas']['oportunidades']);
        $this->assertEquals(0, $resumen['metricas']['valor_pipeline']);
        $this->assertSame([], $resumen['oportunidades']);
    }

    // ── La forma de la respuesta no cambia ────────────────────────────────

    public function test_el_resumen_conserva_sus_claves_aunque_no_vea_oportunidades(): void
    {
        $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/clientes/{$this->cliente->id}/resumen")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'cliente',
                'metricas' => ['contactos', 'oportunidades', 'actividades', 'valor_pipeline'],
                'oportunidades',
                'actividades',
            ]]);
    }
}
