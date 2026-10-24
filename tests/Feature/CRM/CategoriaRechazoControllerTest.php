<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Catálogo de categorías y motivos de rechazo (spec 2026-10-23 §4.5). */
class CategoriaRechazoControllerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/categorias-rechazo';

    private const MENSAJE_USADO = 'Este motivo ya se usó en oportunidades; desactívalo en lugar de borrarlo.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function conPermisos(array $permisos = ['ver', 'crear', 'editar', 'eliminar']): void
    {
        $this->otorgarPermisosCrm('catalogos', 'categorias-rechazo', $permisos);
    }

    private function categoria(array $datos = [], ?int $empresaId = null): CrmCategoriaRechazo
    {
        return CrmCategoriaRechazo::create(array_merge([
            'empresa_id' => $empresaId ?? $this->enterprise->id,
            'nombre' => 'Precio',
        ], $datos));
    }

    private function motivo(CrmCategoriaRechazo $categoria, array $datos = []): CrmMotivoRechazo
    {
        return CrmMotivoRechazo::create(array_merge([
            'categoria_id' => $categoria->id,
            'nombre' => 'Precio alto',
        ], $datos));
    }

    private function oportunidadConMotivo(CrmMotivoRechazo $motivo): CrmOportunidad
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);

        return CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Perdida',
            'motivo_rechazo_id' => $motivo->id,
        ]);
    }

    private function enviar(string $metodo, string $uri = '', array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->json($metodo, self::URL.$uri, $datos);
    }

    private function enviarMotivo(string $metodo, string $uri, array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->json($metodo, '/api/crm/motivos-rechazo'.$uri, $datos);
    }

    public function test_index_activas_devuelve_solo_lo_utilizable(): void
    {
        $precio = $this->categoria(['nombre' => 'Precio', 'orden' => 1]);
        $this->motivo($precio, ['nombre' => 'Precio alto', 'orden' => 1]);
        $this->motivo($precio, ['nombre' => 'Motivo apagado', 'orden' => 2, 'activo' => false]);

        $apagada = $this->categoria(['nombre' => 'Apagada', 'orden' => 2, 'activo' => false]);
        $this->motivo($apagada, ['nombre' => 'De apagada']);

        $vacia = $this->categoria(['nombre' => 'Sin motivos activos', 'orden' => 3]);
        $this->motivo($vacia, ['nombre' => 'Solo inactivo', 'activo' => false]);

        $this->categoria(['nombre' => 'Sin motivos', 'orden' => 4]);

        $ajena = $this->categoria(['nombre' => 'Ajena'], $this->crearOtraEmpresa()->id);
        $this->motivo($ajena, ['nombre' => 'De ajena']);

        $this->enviar('GET', '?activas=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Precio')
            ->assertJsonCount(1, 'data.0.motivos')
            ->assertJsonPath('data.0.motivos.0.nombre', 'Precio alto');

        // Sin el parámetro: todas las de la empresa con todos sus motivos.
        $todas = $this->enviar('GET')->assertOk();
        $todas->assertJsonCount(4, 'data');
        $this->assertSame(
            ['Precio', 'Apagada', 'Sin motivos activos', 'Sin motivos'],
            collect($todas->json('data'))->pluck('nombre')->all(),
        );
        $this->assertCount(2, $todas->json('data.0.motivos'));
    }

    public function test_index_ordena_por_orden_y_luego_id(): void
    {
        $b = $this->categoria(['nombre' => 'B', 'orden' => 2]);
        $a = $this->categoria(['nombre' => 'A', 'orden' => 1]);
        $this->motivo($a, ['nombre' => 'Segundo', 'orden' => 2]);
        $this->motivo($a, ['nombre' => 'Primero', 'orden' => 1]);
        $this->motivo($b, ['nombre' => 'X']);

        $r = $this->enviar('GET')->assertOk();

        $this->assertSame(['A', 'B'], collect($r->json('data'))->pluck('nombre')->all());
        $this->assertSame(['Primero', 'Segundo'], collect($r->json('data.0.motivos'))->pluck('nombre')->all());
    }

    public function test_index_sin_permiso_de_catalogo_200(): void
    {
        // Vendedor sin ningún permiso de catálogos: el modal de pérdida las necesita.
        $this->motivo($this->categoria());

        $this->enviar('GET', '?activas=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_crear_editar_borrar_categoria_y_motivo_con_permisos(): void
    {
        $this->conPermisos();

        $id = $this->enviar('POST', '', ['nombre' => 'Precio', 'orden' => 1])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Precio')
            ->assertJsonPath('data.activo', true)
            ->json('data.id');

        $this->enviar('PUT', "/{$id}", ['nombre' => 'Precios', 'orden' => 3, 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Precios')
            ->assertJsonPath('data.orden', 3)
            ->assertJsonPath('data.activo', false);

        $motivoId = $this->enviar('POST', "/{$id}/motivos", ['nombre' => 'Precio alto'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Precio alto')
            ->assertJsonPath('data.categoria_id', $id)
            ->json('data.id');

        $this->enviarMotivo('PUT', "/{$motivoId}", ['nombre' => 'Muy caro', 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Muy caro')
            ->assertJsonPath('data.activo', false);

        $this->enviarMotivo('DELETE', "/{$motivoId}")->assertOk();
        $this->assertDatabaseMissing('crm_motivos_rechazo', ['id' => $motivoId]);

        $this->enviar('DELETE', "/{$id}")->assertOk();
        $this->assertDatabaseMissing('crm_categorias_rechazo', ['id' => $id]);
    }

    public function test_sin_permiso_crear_403(): void
    {
        $this->conPermisos(['ver']);
        $categoria = $this->categoria();
        $motivo = $this->motivo($categoria);

        $this->enviar('POST', '', ['nombre' => 'Nueva'])->assertForbidden();
        $this->enviar('PUT', "/{$categoria->id}", ['nombre' => 'Otra'])->assertForbidden();
        $this->enviar('DELETE', "/{$categoria->id}")->assertForbidden();
        $this->enviar('POST', "/{$categoria->id}/motivos", ['nombre' => 'Nuevo'])->assertForbidden();
        $this->enviarMotivo('PUT', "/{$motivo->id}", ['nombre' => 'Otro'])->assertForbidden();
        $this->enviarMotivo('DELETE', "/{$motivo->id}")->assertForbidden();
    }

    public function test_nombre_duplicado_422(): void
    {
        $this->conPermisos();
        $categoria = $this->categoria(['nombre' => 'Precio']);
        $otra = $this->categoria(['nombre' => 'Cliente']);
        $this->motivo($categoria, ['nombre' => 'Precio alto']);

        $duplicada = $this->enviar('POST', '', ['nombre' => 'Precio'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
        $this->assertStringStartsWith('Ya existe', $duplicada->json('errors.nombre.0'));

        $this->enviar('PUT', "/{$otra->id}", ['nombre' => 'Precio'])->assertStatus(422);
        // Conservar el propio nombre al editar sí se permite.
        $this->enviar('PUT', "/{$categoria->id}", ['nombre' => 'Precio', 'orden' => 5])->assertOk();

        $this->enviar('POST', "/{$categoria->id}/motivos", ['nombre' => 'Precio alto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
        // El mismo nombre en otra categoría es válido (único por categoría).
        $this->enviar('POST', "/{$otra->id}/motivos", ['nombre' => 'Precio alto'])->assertCreated();
    }

    public function test_validacion_de_campos(): void
    {
        $this->conPermisos();

        $this->enviar('POST', '', [])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->enviar('POST', '', ['nombre' => str_repeat('a', 101)])->assertStatus(422);
        $this->enviar('POST', '', ['nombre' => 'X', 'orden' => -1])->assertStatus(422)->assertJsonValidationErrors('orden');
        $this->enviar('POST', '', ['nombre' => 'X', 'activo' => 'quizá'])->assertStatus(422)->assertJsonValidationErrors('activo');
    }

    public function test_borrar_motivo_usado_409(): void
    {
        $this->conPermisos();
        $motivo = $this->motivo($this->categoria());
        $this->oportunidadConMotivo($motivo);

        $this->enviarMotivo('DELETE', "/{$motivo->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', self::MENSAJE_USADO);
        $this->assertDatabaseHas('crm_motivos_rechazo', ['id' => $motivo->id]);
    }

    public function test_borrar_categoria_con_motivo_usado_409(): void
    {
        $this->conPermisos();
        $categoria = $this->categoria();
        $this->motivo($categoria, ['nombre' => 'Libre']);
        $this->oportunidadConMotivo($this->motivo($categoria, ['nombre' => 'Usado']));

        $this->enviar('DELETE', "/{$categoria->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', self::MENSAJE_USADO);
        $this->assertDatabaseHas('crm_categorias_rechazo', ['id' => $categoria->id]);
        $this->assertSame(2, CrmMotivoRechazo::where('categoria_id', $categoria->id)->count());
    }

    public function test_categoria_de_otra_empresa_404(): void
    {
        $this->conPermisos();
        $ajena = $this->categoria(['nombre' => 'Ajena'], $this->crearOtraEmpresa()->id);
        $motivoAjeno = $this->motivo($ajena);

        $this->enviar('PUT', "/{$ajena->id}", ['nombre' => 'Mía'])->assertNotFound();
        $this->enviar('DELETE', "/{$ajena->id}")->assertNotFound();
        $this->enviar('POST', "/{$ajena->id}/motivos", ['nombre' => 'Mío'])->assertNotFound();
        $this->enviarMotivo('PUT', "/{$motivoAjeno->id}", ['nombre' => 'Mío'])->assertNotFound();
        $this->enviarMotivo('DELETE', "/{$motivoAjeno->id}")->assertNotFound();
        $this->assertDatabaseHas('crm_categorias_rechazo', ['id' => $ajena->id, 'nombre' => 'Ajena']);
    }

    public function test_empresa_tiene_catalogo(): void
    {
        $this->assertFalse(CrmCategoriaRechazo::empresaTieneCatalogo($this->enterprise->id));

        $categoria = $this->categoria();
        $this->assertFalse(CrmCategoriaRechazo::empresaTieneCatalogo($this->enterprise->id));

        $motivo = $this->motivo($categoria);
        $this->assertTrue(CrmCategoriaRechazo::empresaTieneCatalogo($this->enterprise->id));
        $this->assertFalse(CrmCategoriaRechazo::empresaTieneCatalogo($this->crearOtraEmpresa()->id));

        $motivo->update(['activo' => false]);
        $this->assertFalse(CrmCategoriaRechazo::empresaTieneCatalogo($this->enterprise->id));

        $motivo->update(['activo' => true]);
        $categoria->update(['activo' => false]);
        $this->assertFalse(CrmCategoriaRechazo::empresaTieneCatalogo($this->enterprise->id));
    }

    public function test_relaciones_del_modelo(): void
    {
        $categoria = $this->categoria();
        $motivo = $this->motivo($categoria);
        $oportunidad = $this->oportunidadConMotivo($motivo);

        $this->assertSame($categoria->id, $motivo->categoria->id);
        $this->assertSame($oportunidad->id, $motivo->oportunidades->first()->id);
        $this->assertSame($motivo->id, $oportunidad->motivoRechazo->id);
    }
}
