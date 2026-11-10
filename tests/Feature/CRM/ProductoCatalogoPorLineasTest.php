<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Productos con clave, marca, línea y sublínea (catálogo por líneas). */
class ProductoCatalogoPorLineasTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/productos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    private function conBandera(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['catalogo_por_lineas' => true]);
    }

    private function producto(array $extra = []): CrmProducto
    {
        return CrmProducto::create($extra + [
            'empresa_id' => $this->enterprise->id, 'nombre' => 'P'.uniqid(), 'precio' => 0,
            'unidad_medida' => 'litro', 'activo' => true,
        ]);
    }

    public function test_busca_por_clave_y_por_nombre(): void
    {
        $this->producto(['clave' => '110135', 'nombre' => 'KARATE ZEON']);
        $this->producto(['clave' => '10979', 'nombre' => 'BENEVIA']);

        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?search=1101')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'KARATE ZEON')
            ->assertJsonPath('data.0.clave', '110135');
        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?search=benevia')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'BENEVIA');
    }

    public function test_filtra_por_marca_linea_y_sublinea(): void
    {
        $e = $this->enterprise->id;
        $marca = CrmMarca::create(['empresa_id' => $e, 'nombre' => 'Bayer']);
        $fung = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'FUNGICIDAS']);
        $bact = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'BACTERICIDA']);
        $fung->sublineas()->create(['nombre' => 'FUNGICIDAS']);
        $subFungBact = $fung->sublineas()->create(['nombre' => 'BACTERICIDA']);
        $subBact = $bact->sublineas()->create(['nombre' => 'BACTERICIDA']);

        $a = $this->producto(['nombre' => 'A', 'marca_id' => $marca->id, 'linea_id' => $fung->id, 'sublinea_id' => $subFungBact->id]);
        $this->producto(['nombre' => 'B', 'linea_id' => $bact->id, 'sublinea_id' => $subBact->id]);

        $r = $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?sublinea_id='.$subFungBact->id)->assertOk();
        $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.0.marca.nombre', 'Bayer')
            ->assertJsonPath('data.0.linea.nombre', 'FUNGICIDAS')
            ->assertJsonPath('data.0.sublinea.nombre', 'BACTERICIDA');

        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?linea_id='.$bact->id)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'B')->assertJsonPath('data.0.marca', null);
        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?marca_id='.$marca->id)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'A');
    }

    public function test_clave_repetida_escrita_distinto_da_422(): void
    {
        $this->conBandera();
        $this->producto(['clave' => 'ABC-1']);

        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'Otro', 'unidad_medida' => 'litro', 'clave' => ' abc-1 ',
        ])->assertStatus(422)->assertJsonPath('errors.clave.0', 'Ya existe un producto con esa clave.');
    }

    public function test_clave_al_actualizar_ignora_el_propio_registro(): void
    {
        $this->conBandera();
        $p = $this->producto(['clave' => 'ABC-1']);
        $otro = $this->producto(['clave' => 'ZZ']);

        $this->withHeaders($this->crmHeaders())->putJson(self::URL.'/'.$p->id, ['clave' => 'abc-1'])->assertOk();
        $this->withHeaders($this->crmHeaders())->putJson(self::URL.'/'.$otro->id, ['clave' => 'ABC-1'])
            ->assertStatus(422)->assertJsonPath('errors.clave.0', 'Ya existe un producto con esa clave.');
    }

    public function test_sublinea_de_otra_linea_da_422(): void
    {
        $this->conBandera();
        $e = $this->enterprise->id;
        $a = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'A']);
        $b = CrmLinea::create(['empresa_id' => $e, 'nombre' => 'B']);
        $subB = $b->sublineas()->create(['nombre' => 'X']);

        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'P', 'unidad_medida' => 'litro', 'linea_id' => $a->id, 'sublinea_id' => $subB->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['sublinea_id']);

        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'P', 'unidad_medida' => 'litro', 'linea_id' => $b->id, 'sublinea_id' => $subB->id,
        ])->assertCreated()->assertJsonPath('data.sublinea_id', $subB->id);
    }

    public function test_marca_de_otra_empresa_da_422(): void
    {
        $this->conBandera();
        $otra = $this->crearOtraEmpresa();
        $marca = CrmMarca::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena']);

        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'P', 'unidad_medida' => 'litro', 'marca_id' => $marca->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['marca_id']);
    }

    public function test_con_bandera_el_precio_es_opcional(): void
    {
        $this->conBandera();
        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'Sin precio', 'unidad_medida' => 'litro',
        ])->assertCreated();
        $this->assertSame('0.00', CrmProducto::where('nombre', 'Sin precio')->value('precio'));
    }

    public function test_sin_bandera_el_precio_sigue_siendo_obligatorio(): void
    {
        $this->withHeaders($this->crmHeaders())->postJson(self::URL, [
            'nombre' => 'Sin precio', 'unidad_medida' => 'litro',
        ])->assertStatus(422)->assertJsonValidationErrors(['precio']);
    }

    public function test_buscar_por_clave_con_relaciones_y_sin_inactivos(): void
    {
        $this->producto(['clave' => 'K-1', 'nombre' => 'Activo']);
        $this->producto(['clave' => 'K-2', 'nombre' => 'Inactivo', 'activo' => false]);

        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'/buscar?q=K-')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Activo')
            ->assertJsonPath('data.0.clave', 'K-1')->assertJsonPath('data.0.unidad_medida', 'litro');
    }

    public function test_buscar_limita_a_20(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->producto(['nombre' => 'Prod '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->withHeaders($this->crmHeaders())->getJson(self::URL.'/buscar?q=Prod')
            ->assertOk()->assertJsonCount(20, 'data');
    }
}

