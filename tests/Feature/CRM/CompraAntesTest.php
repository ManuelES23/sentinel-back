<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class CompraAntesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmCliente $cliente;

    private CrmOportunidad $actual;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $this->cliente = $this->cliente();
        $this->actual = $this->oportunidad($this->cliente);
    }

    private function cliente(): CrmCliente
    {
        return CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente '.++$this->n,
            'estatus' => 'activo',
        ]);
    }

    private function oportunidad(CrmCliente $cliente, ?int $empresaId = null): CrmOportunidad
    {
        return CrmOportunidad::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Op '.++$this->n,
        ]);
    }

    private function producto(string $nombre, bool $activo = true, ?int $empresaId = null): CrmProducto
    {
        return CrmProducto::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => $nombre, 'precio' => 1,
            'unidad_medida' => 'kg', 'activo' => $activo,
        ]);
    }

    /** Cotización con un renglón por producto. */
    private function cotizacion(CrmOportunidad $op, array $productos, string $estado = 'aprobado', ?string $fecha = null): CrmCotizacion
    {
        $cot = CrmCotizacion::create([
            'empresa_id' => $op->empresa_id, 'oportunidad_id' => $op->id, 'folio' => 'COT-'.++$this->n,
            'estado' => $estado, 'fecha_emision' => $fecha ?? now()->subDays(10)->toDateString(),
            'vigencia_dias' => 15, 'descuento_global_pct' => 0, 'subtotal' => 0, 'total' => 0,
        ]);
        foreach ($productos as $p) {
            CrmOportunidadProducto::create([
                'oportunidad_id' => $op->id, 'cotizacion_id' => $cot->id, 'producto_id' => $p->id,
                'descripcion' => $p->nombre, 'cantidad' => 1, 'precio_unitario' => 1,
            ]);
        }

        return $cot;
    }

    private function consultar(?CrmOportunidad $op = null)
    {
        $op ??= $this->actual;

        return $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$op->id}/compro-antes");
    }

    private function nombres($response): array
    {
        return collect($response->json('data'))->pluck('nombre')->all();
    }

    public function test_ordena_por_frecuencia_y_luego_por_recencia(): void
    {
        [$a, $b, $c] = [$this->producto('A'), $this->producto('B'), $this->producto('C')];
        $op = $this->oportunidad($this->cliente);
        $this->cotizacion($op, [$a, $b], 'aprobado', now()->subDays(40)->toDateString());
        $this->cotizacion($op, [$a, $c], 'aprobado', now()->subDays(5)->toDateString());
        $this->cotizacion($op, [$b], 'aprobado', now()->subDays(60)->toDateString());

        // A: 2 cotizaciones (reciente hace 5), B: 2 (reciente hace 40), C: 1.
        $this->assertSame(['A', 'B', 'C'], $this->nombres($this->consultar()->assertOk()));
    }

    public function test_cuenta_cotizaciones_distintas_no_renglones(): void
    {
        [$a, $b] = [$this->producto('A'), $this->producto('B')];
        $op = $this->oportunidad($this->cliente);
        $this->cotizacion($op, [$a, $a, $a], 'aprobado', now()->subDays(1)->toDateString());
        $this->cotizacion($op, [$b], 'aprobado', now()->subDays(20)->toDateString());
        $this->cotizacion($op, [$b], 'aprobado', now()->subDays(30)->toDateString());

        $this->assertSame(['B', 'A'], $this->nombres($this->consultar()));
    }

    public function test_devuelve_clasificacion_y_unidad_del_producto(): void
    {
        $marca = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'M']);
        $linea = CrmLinea::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'L']);
        $p = $this->producto('A');
        $p->update(['clave' => 'K1', 'marca_id' => $marca->id, 'linea_id' => $linea->id]);
        $this->cotizacion($this->oportunidad($this->cliente), [$p]);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.0.clave', 'K1')
            ->assertJsonPath('data.0.unidad_medida', 'kg')
            ->assertJsonPath('data.0.marca.nombre', 'M')
            ->assertJsonPath('data.0.linea.nombre', 'L');
    }

    public function test_ignora_cotizaciones_no_aprobadas(): void
    {
        $p = $this->producto('A');
        $op = $this->oportunidad($this->cliente);
        foreach (['borrador', 'enviado', 'rechazado', 'superado'] as $estado) {
            $this->cotizacion($op, [$p], $estado);
        }

        $this->assertSame([], $this->nombres($this->consultar()));
    }

    public function test_ignora_cotizaciones_de_hace_mas_de_12_meses(): void
    {
        $viejo = $this->producto('Viejo');
        $limite = $this->producto('Limite');
        $op = $this->oportunidad($this->cliente);
        $this->cotizacion($op, [$viejo], 'aprobado', now()->subMonths(12)->subDay()->toDateString());
        $this->cotizacion($op, [$limite], 'aprobado', now()->subMonths(12)->toDateString());

        $this->assertSame(['Limite'], $this->nombres($this->consultar()));
    }

    public function test_ignora_otro_cliente_y_otra_empresa(): void
    {
        $p = $this->producto('A');
        $this->cotizacion($this->oportunidad($this->cliente()), [$p]);

        $otra = $this->crearOtraEmpresa();
        $this->cotizacion($this->oportunidad($this->cliente, $otra->id), [$this->producto('Z', true, $otra->id)]);

        $this->assertSame([], $this->nombres($this->consultar()));
    }

    public function test_incluye_cotizaciones_de_otras_oportunidades_del_mismo_cliente_y_la_propia(): void
    {
        $this->cotizacion($this->oportunidad($this->cliente), [$this->producto('A')]);
        $this->cotizacion($this->actual, [$this->producto('B')], 'aprobado', now()->subDays(50)->toDateString());

        $this->assertEqualsCanonicalizing(['A', 'B'], $this->nombres($this->consultar()));
    }

    public function test_ignora_productos_inactivos(): void
    {
        $this->cotizacion($this->oportunidad($this->cliente), [$this->producto('Activo'), $this->producto('Inactivo', false)]);

        $this->assertSame(['Activo'], $this->nombres($this->consultar()));
    }

    public function test_tope_de_8(): void
    {
        $productos = [];
        for ($i = 1; $i <= 10; $i++) {
            $productos[] = $this->producto("P{$i}");
        }
        $this->cotizacion($this->oportunidad($this->cliente), $productos);

        $this->assertCount(8, $this->consultar()->json('data'));
    }

    public function test_oportunidad_de_prospecto_devuelve_lista_vacia(): void
    {
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto']);
        $op = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'De prospecto',
        ]);
        $this->cotizacion($this->oportunidad($this->cliente), [$this->producto('A')]);

        $this->consultar($op)->assertOk()->assertJsonPath('data', []);
    }

    public function test_sin_permiso_de_ver_oportunidades_responde_403(): void
    {
        UserSubmodulePermission::query()->where('user_id', $this->actingUser->id)->delete();

        $this->consultar()->assertForbidden();
    }

    public function test_oportunidad_ajena_sin_equipo_responde_como_la_ficha(): void
    {
        UserSubmodulePermission::query()->where('user_id', $this->actingUser->id)->delete();
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Propio', 'activo' => true,
        ]);
        $ajena = $this->oportunidad($this->cliente);

        $ficha = $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidades/{$ajena->id}");
        $ficha->assertForbidden();
        $this->consultar($ajena)->assertStatus($ficha->status());
    }
}
