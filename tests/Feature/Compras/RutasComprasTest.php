<?php

namespace Tests\Feature\Compras;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\Compras\PermisosCompras;
use App\Services\Compras\RutasCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class RutasComprasTest extends TestCase
{
    use CreatesComprasFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    public function test_splendid_farms_tiene_compras_en_administracion(): void
    {
        $rutas = new RutasCompras();

        $this->assertSame(['administration', 'compras', 'requisiciones'], $rutas->ruta('splendidfarms', 'requisiciones'));
        $this->assertSame(['administration', 'compras', 'ordenes-compra'], $rutas->ruta('splendidfarms', 'ordenes-compra'));
        $this->assertSame('/splendidfarms/administration/compras/recepciones', $rutas->url('splendidfarms', 'recepciones'));
    }

    public function test_las_demas_empresas_siguen_en_inventario(): void
    {
        $rutas = new RutasCompras();

        $this->assertSame(['inventario', 'compras', 'ordenes-compra'], $rutas->ruta('splendidbyporvenir', 'ordenes-compra'));
        $this->assertSame('/splendidbyporvenir/inventario/compras/recepciones', $rutas->url('splendidbyporvenir', 'recepciones'));
    }

    public function test_sin_empresa_cae_en_splendid_farms(): void
    {
        $rutas = new RutasCompras();

        $this->assertSame('/splendidfarms/administration/compras/requisiciones', $rutas->url(null, 'requisiciones'));
    }

    public function test_cotizar_se_lee_en_la_ubicacion_nueva_y_no_aplica_en_otra_empresa(): void
    {
        $user = User::factory()->create();
        $this->otorgarCotizar($user);
        $permisos = app(PermisosCompras::class);

        $this->assertTrue($permisos->puedeCotizar($user, $this->empresa));

        $otra = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Empresa de prueba', 'is_active' => true]);
        $this->assertFalse($permisos->puedeCotizar($user, $otra));
    }

    public function test_gestionar_sigue_leyendose_en_inventario_para_la_otra_empresa(): void
    {
        $otra = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Empresa de prueba', 'is_active' => true]);
        $user = User::factory()->create();
        $this->otorgarGestionar($user, $otra);

        $this->assertTrue(app(PermisosCompras::class)->puedeGestionar($user, $otra));
    }
}
