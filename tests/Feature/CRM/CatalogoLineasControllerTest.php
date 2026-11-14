<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Catálogo de marcas, líneas y sublíneas del CRM. */
class CatalogoLineasControllerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/catalogo-lineas';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    private function h(): array
    {
        return $this->crmHeaders();
    }

    private function producto(array $extra): CrmProducto
    {
        return CrmProducto::create($extra + [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'P'.uniqid(), 'precio' => 0, 'unidad_medida' => 'litro',
        ]);
    }

    public function test_listado_con_conteos(): void
    {
        $e = $this->enterprise->id;
        $marca = CrmMarca::create(['empresa_id' => $e, 'nombre' => 'Bayer']);
        CrmMarca::create(['empresa_id' => $e, 'nombre' => 'Basf']);
        $linea = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'FUNGICIDAS']);
        $sub = $linea->sublineas()->create(['nombre' => 'BACTERICIDA']);
        $this->producto(['marca_id' => $marca->id, 'linea_id' => $linea->id, 'sublinea_id' => $sub->id]);
        $this->producto(['marca_id' => $marca->id]);

        $otra = $this->crearOtraEmpresa();
        CrmMarca::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena']);

        $r = $this->withHeaders($this->h())->getJson(self::URL)->assertOk();
        $r->assertJsonCount(2, 'data.marcas')
            ->assertJsonPath('data.marcas.0.nombre', 'Basf')->assertJsonPath('data.marcas.0.productos', 0)
            ->assertJsonPath('data.marcas.1.nombre', 'Bayer')->assertJsonPath('data.marcas.1.productos', 2)
            ->assertJsonPath('data.marcas.1.activo', true)
            ->assertJsonPath('data.lineas.0.nombre', 'FUNGICIDAS')->assertJsonPath('data.lineas.0.productos', 1)
            ->assertJsonPath('data.lineas.0.sublineas.0.nombre', 'BACTERICIDA')
            ->assertJsonPath('data.lineas.0.sublineas.0.productos', 1);
    }

    public function test_alta_y_renombre_con_nombre_repetido_da_422(): void
    {
        $r = $this->withHeaders($this->h())->postJson(self::URL.'/marcas', ['nombre' => ' Bayer '])->assertCreated();
        $r->assertJsonPath('data.nombre', 'Bayer');
        $this->withHeaders($this->h())->postJson(self::URL.'/marcas', ['nombre' => 'BAYER'])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre']);

        $otra = $this->withHeaders($this->h())->postJson(self::URL.'/marcas', ['nombre' => 'Basf'])->assertCreated();
        $id = $otra->json('data.id');
        $this->withHeaders($this->h())->putJson(self::URL.'/marcas/'.$id, ['nombre' => 'bayer'])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->withHeaders($this->h())->putJson(self::URL.'/marcas/'.$id, ['nombre' => 'BASF', 'activo' => false])
            ->assertOk()->assertJsonPath('data.nombre', 'BASF')->assertJsonPath('data.activo', false);
    }

    public function test_lineas_y_sublineas_alta_renombre_y_borrado(): void
    {
        $l = $this->withHeaders($this->h())->postJson(self::URL.'/lineas', ['nombre' => 'FUNGICIDAS'])->assertCreated();
        $lid = $l->json('data.id');
        $this->withHeaders($this->h())->postJson(self::URL.'/lineas', ['nombre' => 'fungicidas'])->assertStatus(422);

        $s = $this->withHeaders($this->h())->postJson(self::URL."/lineas/$lid/sublineas", ['nombre' => 'BACTERICIDA'])->assertCreated();
        $sid = $s->json('data.id');
        $this->withHeaders($this->h())->postJson(self::URL."/lineas/$lid/sublineas", ['nombre' => ' bactericida '])->assertStatus(422);
        $this->withHeaders($this->h())->putJson(self::URL."/sublineas/$sid", ['nombre' => 'Otra'])->assertOk()->assertJsonPath('data.nombre', 'Otra');

        $this->withHeaders($this->h())->deleteJson(self::URL."/sublineas/$sid")->assertOk();
        $this->withHeaders($this->h())->deleteJson(self::URL."/lineas/$lid")->assertOk();
        $this->assertDatabaseCount('crm_lineas', 0);
    }

    public function test_borrar_con_productos_da_422(): void
    {
        $e = $this->enterprise->id;
        $marca = CrmMarca::create(['empresa_id' => $e, 'nombre' => 'Bayer']);
        $linea = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'L']);
        $sub = $linea->sublineas()->create(['nombre' => 'S']);
        $this->producto(['marca_id' => $marca->id, 'linea_id' => $linea->id, 'sublinea_id' => $sub->id]);

        $msg = 'Tiene productos: desactívala en lugar de borrarla.';
        $this->withHeaders($this->h())->deleteJson(self::URL.'/marcas/'.$marca->id)->assertStatus(422)->assertJsonPath('message', $msg);
        $this->withHeaders($this->h())->deleteJson(self::URL.'/lineas/'.$linea->id)->assertStatus(422)->assertJsonPath('message', $msg);
        $this->withHeaders($this->h())->deleteJson(self::URL.'/sublineas/'.$sub->id)->assertStatus(422)->assertJsonPath('message', $msg);
        $this->assertDatabaseCount('crm_marcas', 1);
    }

    public function test_borrar_sin_productos_da_200(): void
    {
        $marca = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Bayer']);
        $this->withHeaders($this->h())->deleteJson(self::URL.'/marcas/'.$marca->id)->assertOk();
        $this->assertDatabaseCount('crm_marcas', 0);
    }

    public function test_sin_permiso_editar_da_403(): void
    {
        $marca = CrmMarca::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Bayer']);
        // Usuario nuevo solo con "crear" en catalogos.productos.
        $this->actingUser = User::factory()->create();
        UserEnterpriseAccess::create([
            'user_id' => $this->actingUser->id, 'enterprise_id' => $this->enterprise->id,
            'is_active' => true, 'granted_at' => now(),
        ]);
        Sanctum::actingAs($this->actingUser);
        $this->otorgarPermisosCrm('catalogos', 'productos', ['crear']);

        $this->withHeaders($this->h())->getJson(self::URL)->assertOk();
        $this->withHeaders($this->h())->postJson(self::URL.'/marcas', ['nombre' => 'Nueva'])->assertCreated();
        $this->withHeaders($this->h())->putJson(self::URL.'/marcas/'.$marca->id, ['nombre' => 'X'])->assertForbidden();
        $this->withHeaders($this->h())->deleteJson(self::URL.'/marcas/'.$marca->id)->assertForbidden();
    }

    public function test_marca_de_otra_empresa_da_404(): void
    {
        $otra = $this->crearOtraEmpresa();
        $marca = CrmMarca::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena']);
        $linea = CrmLinea::create(['empresa_id' => $otra->id, 'nombre' => 'L']);
        $sub = $linea->sublineas()->create(['nombre' => 'S']);

        $this->withHeaders($this->h())->putJson(self::URL.'/marcas/'.$marca->id, ['nombre' => 'X'])->assertNotFound();
        $this->withHeaders($this->h())->deleteJson(self::URL.'/marcas/'.$marca->id)->assertNotFound();
        $this->withHeaders($this->h())->postJson(self::URL.'/lineas/'.$linea->id.'/sublineas', ['nombre' => 'Z'])->assertNotFound();
        $this->withHeaders($this->h())->putJson(self::URL.'/sublineas/'.$sub->id, ['nombre' => 'X'])->assertNotFound();
    }
}
