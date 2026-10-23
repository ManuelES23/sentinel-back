<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use App\Services\CRM\EmbudoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class PerdidasCategoriaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const RUTA = '/api/crm/dashboard/perdidas-por-categoria';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->ahora('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $fecha): void
    {
        Carbon::setTestNow($fecha);
        CarbonImmutable::setTestNow($fecha);
    }

    private function otorgarPermisosDashboard(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 10, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'dashboard'],
            ['name' => 'Dashboard', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'ejecutivo'] as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => 1, 'is_active' => true],
            );

            if (in_array($slug, $slugs, true)) {
                UserSubmodulePermission::create([
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $submodulo->id,
                    'permission_type_id' => $tipo->id,
                    'is_granted' => true,
                ]);
            }
        }
    }

    /** @return array{0: CrmCategoriaRechazo, 1: array<int, CrmMotivoRechazo>} */
    private function categoria(string $nombre, array $motivos = ['General'], bool $activa = true, ?int $empresaId = null): array
    {
        $categoria = CrmCategoriaRechazo::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => $nombre, 'activo' => $activa,
        ]);
        $modelos = array_map(
            fn (string $m) => CrmMotivoRechazo::create(['categoria_id' => $categoria->id, 'nombre' => $m]),
            $motivos,
        );

        return [$categoria, $modelos];
    }

    private function perdida(?CrmMotivoRechazo $motivo, float $monto = 1000, string $cierre = '2026-10-10 10:00:00', array $extra = []): CrmOportunidad
    {
        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Perdida',
            'monto_esperado' => $monto,
            'etapa' => 'cerrado_perdido',
            'fecha_cierre_real' => $cierre,
            'motivo_rechazo_id' => $motivo?->id,
            'motivo_perdida' => $motivo ? null : 'Texto libre',
        ], $extra));
    }

    private function consultar(string $query = '')
    {
        return $this->withHeaders($this->crmHeaders())->getJson(self::RUTA.$query);
    }

    public function test_cuenta_perdidas_por_categoria_en_el_periodo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [, [$producto]] = $this->categoria('Producto');
        [, [$precio]] = $this->categoria('Precio');

        $this->perdida($producto, 100);
        $this->perdida($producto, 200);
        $this->perdida($producto, 300);
        $this->perdida($precio, 400);
        $this->perdida(null, 10);
        $this->perdida(null, 20);
        $this->perdida($precio, 999, '2026-09-20 10:00:00'); // fuera del mes

        $r = $this->consultar();

        $r->assertOk()
            ->assertJsonPath('data.aplica', true)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.monto', 1030)
            ->assertJsonPath('data.categorias.0.nombre', 'Producto')
            ->assertJsonPath('data.categorias.0.cantidad', 3)
            ->assertJsonPath('data.categorias.0.monto', 600)
            ->assertJsonPath('data.categorias.1.nombre', 'Precio')
            ->assertJsonPath('data.categorias.1.cantidad', 1)
            ->assertJsonPath('data.categorias.1.monto', 400)
            ->assertJsonPath('data.categorias.2.nombre', 'Sin categoría')
            ->assertJsonPath('data.categorias.2.categoriaId', null)
            ->assertJsonPath('data.categorias.2.cantidad', 2)
            ->assertJsonPath('data.categorias.2.monto', 30)
            ->assertJsonPath('data.categorias.2.motivos', []);
        $this->assertCount(3, $r->json('data.categorias'));
    }

    public function test_cuenta_por_fecha_cierre_real_no_por_creacion(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [, [$motivo]] = $this->categoria('Producto');

        $vieja = $this->perdida($motivo, 500);
        $vieja->forceFill(['created_at' => '2025-10-01 10:00:00'])->saveQuietly();
        // Creada este mes pero cerrada antes: no cuenta.
        $this->perdida($motivo, 700, '2026-09-30 23:00:00');

        $this->consultar()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.monto', 500);
    }

    public function test_respeta_vendedor_sin_permiso_ejecutivo(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        $propio = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id, 'nombre' => 'Propio']);
        [, [$motivo]] = $this->categoria('Producto');

        $this->perdida($motivo, 100, extra: ['vendedor_id' => $propio->id]);
        $this->perdida($motivo, 900); // de otro vendedor

        $this->consultar()->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.monto', 100);

        $this->consultar('/detalle?categoria_id=sin')->assertOk()->assertJsonPath('data.total', 0);
        $this->consultar('/detalle?categoria_id='.$motivo->categoria_id)->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_ver_sin_vendedor_propio_devuelve_ceros(): void
    {
        $this->otorgarPermisosDashboard(['ver']);
        [, [$motivo]] = $this->categoria('Producto');
        $this->perdida($motivo);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.categorias', []);
    }

    public function test_exige_permiso_ver(): void
    {
        $this->otorgarPermisosDashboard([]);

        $this->consultar()->assertStatus(403);
        $this->consultar('/detalle?categoria_id=sin')->assertStatus(403);
    }

    public function test_aplica_false_en_empresa_sin_catalogo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $this->perdida(null, 250);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.aplica', false)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.categorias.0.nombre', 'Sin categoría');
    }

    public function test_aplica_true_si_hay_perdidas_con_motivo_aunque_el_catalogo_este_inactivo(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$categoria, [$motivo]] = $this->categoria('Producto');
        $this->perdida($motivo, 100);
        $categoria->update(['activo' => false]);

        // Catálogo ya no utilizable, pero hay pérdidas históricas con motivo.
        $this->consultar()->assertOk()
            ->assertJsonPath('data.aplica', true)
            ->assertJsonPath('data.categorias.0.nombre', 'Producto')
            ->assertJsonPath('data.categorias.0.cantidad', 1);
    }

    public function test_aplica_true_con_catalogo_aunque_no_haya_perdidas(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $this->categoria('Producto');

        $this->consultar()->assertOk()
            ->assertJsonPath('data.aplica', true)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.categorias', []);
    }

    public function test_motivos_desglosados(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [, [$calidad, $variedad]] = $this->categoria('Producto', ['Calidad', 'Variedad']);

        $this->perdida($calidad, 100);
        $this->perdida($calidad, 50);
        $this->perdida($variedad, 25);

        $r = $this->consultar();

        $r->assertOk()
            ->assertJsonPath('data.categorias.0.motivos.0.motivoId', $calidad->id)
            ->assertJsonPath('data.categorias.0.motivos.0.nombre', 'Calidad')
            ->assertJsonPath('data.categorias.0.motivos.0.cantidad', 2)
            ->assertJsonPath('data.categorias.0.motivos.0.monto', 150)
            ->assertJsonPath('data.categorias.0.motivos.1.nombre', 'Variedad')
            ->assertJsonPath('data.categorias.0.motivos.1.cantidad', 1);
        $this->assertCount(2, $r->json('data.categorias.0.motivos'));
    }

    public function test_desempata_por_nombre_y_agrupa_con_categoria_o_motivo_inactivos(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [, [$zeta]] = $this->categoria('Zeta');
        [$vieja, [$motivoViejo]] = $this->categoria('Antigua', activa: false);
        $motivoViejo->update(['activo' => false]);

        $this->perdida($zeta);
        $this->perdida($motivoViejo);

        $this->consultar()->assertOk()
            ->assertJsonPath('data.categorias.0.nombre', 'Antigua')
            ->assertJsonPath('data.categorias.0.categoriaId', $vieja->id)
            ->assertJsonPath('data.categorias.1.nombre', 'Zeta');
    }

    public function test_no_cuenta_borradas_ni_otra_etapa_ni_otra_empresa(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [, [$motivo]] = $this->categoria('Producto');
        $otra = $this->crearOtraEmpresa();
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno']);

        $this->perdida($motivo, 100);
        $this->perdida($motivo, 100)->delete();
        $this->perdida($motivo, 100, extra: ['etapa' => 'cerrado_ganado']);
        $this->perdida(null, 100, extra: ['empresa_id' => $otra->id, 'vendedor_id' => $vendedorOtra->id]);

        $this->consultar()->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_detalle_por_categoria_y_sin_categoria(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        [$producto, [$calidad]] = $this->categoria('Producto', ['Calidad']);
        [, [$precio]] = $this->categoria('Precio');

        $a = $this->perdida($calidad, 100, extra: ['nombre' => 'A']);
        $b = $this->perdida($calidad, 300, extra: ['nombre' => 'B']);
        $this->perdida($precio, 50);
        $sin = $this->perdida(null, 70, extra: ['nombre' => 'Sin']);
        $this->perdida($calidad, 999, '2026-09-01 10:00:00'); // fuera del periodo

        $this->consultar('/detalle?categoria_id='.$producto->id)->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.id', $b->id)
            ->assertJsonPath('data.items.1.id', $a->id)
            ->assertJsonPath('data.items.0.etapa', 'cerrado_perdido')
            ->assertJsonPath('data.items.0.motivoRechazo.categoria', 'Producto')
            ->assertJsonPath('data.items.0.motivoRechazo.motivo', 'Calidad')
            ->assertJsonPath('data.items.0.vendedor.id', $this->vendedor->id);

        $this->consultar('/detalle?categoria_id=sin')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $sin->id)
            ->assertJsonPath('data.items.0.motivoRechazo', null)
            ->assertJsonPath('data.items.0.motivoPerdida', 'Texto libre');
    }

    public function test_detalle_limita_los_items_pero_informa_el_total(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        for ($i = 0; $i < EmbudoService::LIMITE_DETALLE + 2; $i++) {
            $this->perdida(null, $i);
        }

        $r = $this->consultar('/detalle?categoria_id=sin');

        $r->assertOk()->assertJsonPath('data.total', EmbudoService::LIMITE_DETALLE + 2);
        $this->assertCount(EmbudoService::LIMITE_DETALLE, $r->json('data.items'));
    }

    public function test_detalle_valida_categoria_id(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otra = $this->crearOtraEmpresa();
        [$deOtra] = $this->categoria('Ajena', empresaId: $otra->id);

        $this->consultar('/detalle')->assertStatus(422);
        $this->consultar('/detalle?categoria_id=abc')->assertStatus(422);
        $this->consultar('/detalle?categoria_id=999999')->assertStatus(422);
        $this->consultar('/detalle?categoria_id='.$deOtra->id)->assertStatus(422);
    }

    public function test_rango_personalizado_y_vendedor_se_respetan(): void
    {
        $this->otorgarPermisosDashboard(['ver', 'ejecutivo']);
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        [, [$motivo]] = $this->categoria('Producto');

        $this->perdida($motivo, 100, '2026-08-10 10:00:00');
        $this->perdida($motivo, 200, '2026-08-11 10:00:00', ['vendedor_id' => $otro->id]);

        $query = '?periodo=personalizado&desde=2026-08-01&hasta=2026-08-31';
        $this->consultar($query)->assertOk()->assertJsonPath('data.total', 2);
        $this->consultar($query.'&vendedor_id='.$otro->id)->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.monto', 200);
        $this->consultar('/detalle'.$query.'&vendedor_id='.$otro->id.'&categoria_id='.$motivo->categoria_id)
            ->assertOk()->assertJsonPath('data.total', 1);
    }
}
