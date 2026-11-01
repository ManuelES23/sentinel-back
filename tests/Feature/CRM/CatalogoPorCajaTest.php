<?php

namespace Tests\Feature\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmPresentacion;
use App\Models\CRM\CrmProducto;
use App\Models\Cultivo;
use App\Models\UserSubmodulePermission;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Calibres propios por producto y presentaciones por commodity: lo que administra Catálogos. */
class CatalogoPorCajaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    private function producto(string $nombre = 'Kent Mango', ?int $empresaId = null, array $extra = []): CrmProducto
    {
        return CrmProducto::create(array_merge([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => $nombre, 'commodity' => 'Mango',
            'precio' => 0, 'unidad_medida' => 'caja', 'activo' => true,
        ], $extra));
    }

    private function api(string $metodo, string $uri, array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->json($metodo, '/api/crm/'.$uri, $datos);
    }

    public function test_crea_edita_y_borra_un_calibre_propio(): void
    {
        $producto = $this->producto();

        $creado = $this->api('POST', "productos/{$producto->id}/calibres", ['nombre' => '  Calibre 40  ', 'orden' => 2])
            ->assertStatus(201)
            ->assertJsonPath('data.nombre', 'Calibre 40')
            ->assertJsonPath('data.orden', 2)
            ->assertJsonPath('data.activo', true);
        $id = $creado->json('data.id');

        $this->api('PUT', "productos/{$producto->id}/calibres/{$id}", ['nombre' => 'Calibre 41', 'activo' => false, 'orden' => 5])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Calibre 41')
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.orden', 5);

        // Reenviar el mismo nombre al editar no choca consigo mismo.
        $this->api('PUT', "productos/{$producto->id}/calibres/{$id}", ['nombre' => 'Calibre 41'])->assertOk();

        $this->api('GET', "productos/{$producto->id}/calibres")
            ->assertOk()
            ->assertJsonPath('data.agricolas', [])
            ->assertJsonPath('data.propios.0.nombre', 'Calibre 41')
            ->assertJsonPath('data.propios.0.activo', false);

        $this->api('DELETE', "productos/{$producto->id}/calibres/{$id}")->assertOk();
        $this->assertDatabaseCount('crm_producto_calibres', 0);
    }

    public function test_no_acepta_un_calibre_repetido_en_el_mismo_producto(): void
    {
        $producto = $this->producto();
        $otro = $this->producto('Tommy Mango');
        $propio = $producto->calibresPropios()->create(['nombre' => 'Calibre 40']);
        $segundo = $producto->calibresPropios()->create(['nombre' => 'Calibre 41']);

        $this->api('POST', "productos/{$producto->id}/calibres", ['nombre' => ' Calibre 40 '])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->api('PUT', "productos/{$producto->id}/calibres/{$segundo->id}", ['nombre' => 'Calibre 40'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        // En otro producto el mismo nombre sí es válido.
        $this->api('POST', "productos/{$otro->id}/calibres", ['nombre' => 'Calibre 40'])->assertStatus(201);
        $this->assertSame(2, $producto->calibresPropios()->count());
        $this->assertNotNull($propio->fresh());
    }

    public function test_no_toca_calibres_de_un_producto_de_otra_empresa(): void
    {
        $ajeno = $this->producto('Ajeno', $this->crearOtraEmpresa()->id);
        $calibre = $ajeno->calibresPropios()->create(['nombre' => 'Calibre 40']);

        $this->api('GET', "productos/{$ajeno->id}/calibres")->assertNotFound();
        $this->api('POST', "productos/{$ajeno->id}/calibres", ['nombre' => 'Calibre 50'])->assertNotFound();
        $this->api('PUT', "productos/{$ajeno->id}/calibres/{$calibre->id}", ['nombre' => 'X'])->assertNotFound();
        $this->api('DELETE', "productos/{$ajeno->id}/calibres/{$calibre->id}")->assertNotFound();

        $this->assertSame('Calibre 40', $calibre->fresh()->nombre);
        $this->assertSame(1, $ajeno->calibresPropios()->count());
    }

    public function test_un_calibre_de_otro_producto_responde_404(): void
    {
        $producto = $this->producto();
        $otro = $this->producto('Tommy Mango');
        $delOtro = $otro->calibresPropios()->create(['nombre' => 'Calibre 40']);

        $this->api('PUT', "productos/{$producto->id}/calibres/{$delOtro->id}", ['nombre' => 'X'])->assertNotFound();
        $this->api('DELETE', "productos/{$producto->id}/calibres/{$delOtro->id}")->assertNotFound();

        $this->assertSame('Calibre 40', $delOtro->fresh()->nombre);
    }

    public function test_lista_los_agricolas_aparte_y_no_se_pueden_editar(): void
    {
        $cultivo = Cultivo::firstOrCreate(['nombre' => 'Mango']);
        $variedad = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Kent Mango', 'user_id' => $this->actingUser->id]);
        $base = ['cultivo_id' => $cultivo->id, 'variedad_id' => $variedad->id];
        $agricola = Calibre::create($base + ['nombre' => 'Calibre 8', 'valor' => 8, 'is_active' => true, 'order' => 1]);
        Calibre::create($base + ['nombre' => 'Calibre 9', 'valor' => 9, 'is_active' => false, 'order' => 2]);
        Calibre::create($base + ['nombre' => 'Calibre 10', 'valor' => 10, 'is_active' => true, 'order' => 3])->delete();

        $producto = $this->producto('Kent Mango', null, ['variedad_id' => $variedad->id]);
        $producto->calibresPropios()->create(['nombre' => 'Calibre 40']);
        $sinVariedad = $this->producto('Sin variedad');

        $this->api('GET', "productos/{$producto->id}/calibres")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Operación exitosa', 'data' => [
                'agricolas' => ['Calibre 8'],
                'propios' => [['id' => $producto->calibresPropios()->first()->id, 'nombre' => 'Calibre 40', 'orden' => 0, 'activo' => true]],
            ]]);

        $this->api('GET', "productos/{$sinVariedad->id}/calibres")->assertOk()->assertJsonPath('data.agricolas', []);

        // Editar o borrar propios nunca toca la tabla global de calibres.
        $propio = $producto->calibresPropios()->first();
        $this->api('PUT', "productos/{$producto->id}/calibres/{$propio->id}", ['nombre' => 'Calibre 8'])->assertOk();
        $this->api('DELETE', "productos/{$producto->id}/calibres/{$propio->id}")->assertOk();
        $this->assertSame('Calibre 8', $agricola->fresh()->nombre);
        $this->assertTrue($agricola->fresh()->is_active);
    }

    public function test_crea_y_edita_una_presentacion_y_no_repite_nombre_por_commodity(): void
    {
        $creada = $this->api('POST', 'presentaciones', ['commodity' => '  Mango ', 'nombre' => ' Caja 4 kg ', 'orden' => 1])
            ->assertStatus(201)
            ->assertJsonPath('data.commodity', 'Mango')
            ->assertJsonPath('data.nombre', 'Caja 4 kg')
            ->assertJsonPath('data.activo', true);
        $id = $creada->json('data.id');
        $otra = CrmPresentacion::create(['empresa_id' => $this->enterprise->id, 'commodity' => 'Mango', 'nombre' => 'Caja 10 lb']);

        $this->api('POST', 'presentaciones', ['commodity' => 'Mango', 'nombre' => 'Caja 4 kg'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->api('PUT', "presentaciones/{$otra->id}", ['nombre' => 'Caja 4 kg'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        $this->api('PUT', "presentaciones/{$id}", ['nombre' => 'Caja 4 kg', 'activo' => false, 'orden' => 3])
            ->assertOk()
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.orden', 3);

        $this->api('GET', 'presentaciones?commodity=Mango')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->api('DELETE', "presentaciones/{$id}")->assertOk();
        $this->assertDatabaseMissing('crm_presentaciones', ['id' => $id]);
    }

    public function test_la_misma_presentacion_puede_existir_en_otro_commodity(): void
    {
        $this->api('POST', 'presentaciones', ['commodity' => 'Mango', 'nombre' => 'Caja 4 kg'])->assertStatus(201);
        $this->api('POST', 'presentaciones', ['commodity' => 'Aguacate', 'nombre' => 'Caja 4 kg'])->assertStatus(201);

        // Otra empresa también puede repetir el nombre.
        CrmPresentacion::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'commodity' => 'Mango', 'nombre' => 'Caja 10 lb']);
        $this->api('POST', 'presentaciones', ['commodity' => 'Mango', 'nombre' => 'Caja 10 lb'])->assertStatus(201);

        $this->api('GET', 'presentaciones?commodity=Aguacate')->assertOk()->assertJsonCount(1, 'data');
        // Sin filtro: solo las de la empresa del request.
        $this->api('GET', 'presentaciones')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_mover_una_presentacion_de_commodity_sin_mandar_nombre_no_choca_con_otra(): void
    {
        $id = $this->enterprise->id;
        $mango = CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Mango', 'nombre' => 'Caja 4 kg']);
        CrmPresentacion::create(['empresa_id' => $id, 'commodity' => 'Corn', 'nombre' => 'Caja 4 kg']);

        $this->api('PUT', "presentaciones/{$mango->id}", ['commodity' => 'Corn'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertSame('Mango', $mango->fresh()->commodity);

        // Sin choque, mover solo el commodity sí funciona y conserva el nombre.
        $this->api('PUT', "presentaciones/{$mango->id}", ['commodity' => ' Aguacate '])
            ->assertOk()
            ->assertJsonPath('data.commodity', 'Aguacate')
            ->assertJsonPath('data.nombre', 'Caja 4 kg');
    }

    public function test_un_nombre_que_no_es_texto_responde_422_y_no_500(): void
    {
        $producto = $this->producto();
        $calibre = $producto->calibresPropios()->create(['nombre' => 'Calibre 40']);
        $presentacion = CrmPresentacion::create(['empresa_id' => $this->enterprise->id, 'commodity' => 'Mango', 'nombre' => 'Caja']);

        $this->api('POST', "productos/{$producto->id}/calibres", ['nombre' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->api('PUT', "productos/{$producto->id}/calibres/{$calibre->id}", ['nombre' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->api('POST', 'presentaciones', ['commodity' => 'Mango', 'nombre' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->api('POST', 'presentaciones', ['commodity' => ['Mango'], 'nombre' => 'Nueva'])
            ->assertStatus(422)->assertJsonValidationErrors('commodity');
        $this->api('PUT', "presentaciones/{$presentacion->id}", ['nombre' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        // Un filtro que no es texto se ignora en lugar de romper.
        $this->api('GET', 'presentaciones?commodity[]=Mango')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_una_presentacion_de_otra_empresa_responde_404(): void
    {
        $ajena = CrmPresentacion::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'commodity' => 'Mango', 'nombre' => 'Ajena']);

        $this->api('PUT', "presentaciones/{$ajena->id}", ['nombre' => 'X'])->assertNotFound();
        $this->api('DELETE', "presentaciones/{$ajena->id}")->assertNotFound();
        $this->assertSame('Ajena', $ajena->fresh()->nombre);
    }

    public function test_escribir_sin_permiso_responde_403(): void
    {
        // Sin permisos de escritura: otorgar solo «ver» a quien no tiene nada más.
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('catalogos', 'productos', ['ver']);

        $producto = $this->producto();
        $calibre = $producto->calibresPropios()->create(['nombre' => 'Calibre 40']);
        $presentacion = CrmPresentacion::create(['empresa_id' => $this->enterprise->id, 'commodity' => 'Mango', 'nombre' => 'Caja']);

        $this->api('POST', "productos/{$producto->id}/calibres", ['nombre' => 'Calibre 50'])->assertForbidden();
        $this->api('PUT', "productos/{$producto->id}/calibres/{$calibre->id}", ['nombre' => 'X'])->assertForbidden();
        $this->api('DELETE', "productos/{$producto->id}/calibres/{$calibre->id}")->assertForbidden();
        $this->api('POST', 'presentaciones', ['commodity' => 'Mango', 'nombre' => 'Nueva'])->assertForbidden();
        $this->api('PUT', "presentaciones/{$presentacion->id}", ['nombre' => 'X'])->assertForbidden();
        $this->api('DELETE', "presentaciones/{$presentacion->id}")->assertForbidden();

        // La lectura queda abierta dentro de la empresa.
        $this->api('GET', "productos/{$producto->id}/calibres")->assertOk();
        $this->api('GET', 'presentaciones')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $producto->calibresPropios()->count());
    }

    public function test_borrar_una_presentacion_no_cambia_los_renglones_guardados(): void
    {
        $producto = $this->producto();
        $calibre = $producto->calibresPropios()->create(['nombre' => 'Calibre 40']);
        $presentacion = CrmPresentacion::create(['empresa_id' => $this->enterprise->id, 'commodity' => 'Mango', 'nombre' => 'Caja 4 kg']);
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo', 'vendedor_id' => $this->vendedor->id]);
        $op = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Venta',
        ]);
        $linea = CrmOportunidadProducto::create([
            'oportunidad_id' => $op->id, 'producto_id' => $producto->id, 'descripcion' => 'Kent Mango',
            'cantidad' => 10, 'precio_unitario' => 5, 'calibre' => 'Calibre 40', 'presentacion' => 'Caja 4 kg',
        ]);

        $this->api('DELETE', "presentaciones/{$presentacion->id}")->assertOk();
        $this->api('DELETE', "productos/{$producto->id}/calibres/{$calibre->id}")->assertOk();

        $linea->refresh();
        $this->assertSame('Calibre 40', $linea->calibre);
        $this->assertSame('Caja 4 kg', $linea->presentacion);
    }
}
