<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Regresión de seguridad: la mayoría de los controladores CRM solo validaban
 * el tenant (empresa) y no el permiso de submódulo. Cualquier usuario con
 * acceso a la empresa podía crear/editar/eliminar clientes, prospectos,
 * oportunidades, catálogos, y aprobar o rechazar cotizaciones.
 *
 * Las escrituras exigen ahora el permiso de submódulo correspondiente
 * (CrmPermisosSeeder). Las lecturas siguen abiertas dentro del tenant porque
 * alimentan selects de otros módulos (useCrmCatalogos, formularios).
 */
class CrmPermisosEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected CrmCliente $cliente;
    protected CrmProspecto $prospecto;
    protected CrmOportunidad $oportunidad;
    protected CrmProducto $producto;
    protected CrmEmpresaExterna $empresaExterna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        (new CrmPermisosSeeder())->run();

        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto', 'estatus' => 'nuevo',
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad', 'etapa' => 'calificado',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Producto', 'precio' => 100,
        ]);
        $this->empresaExterna = CrmEmpresaExterna::create([
            'empresa_id' => $this->enterprise->id, 'razon_social' => 'Proveedor SA',
        ]);
    }

    private function crearCotizacionDirecta(string $estado): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $this->oportunidad->id,
            'folio' => 'COT-'.str_pad((string) (CrmCotizacion::count() + 1), 5, '0', STR_PAD_LEFT),
            'estado' => $estado, 'fecha_emision' => now()->toDateString(),
            'descuento_global_pct' => 0, 'subtotal' => 0, 'total' => 0,
        ]);
    }

    /** Crea y envía una cotización por la API (con crear+editar), lista para aprobar/rechazar. */
    private function crearCotizacionEnviadaPorApi(): int
    {
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['crear', 'editar']);

        $id = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$this->oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 100]],
            ])->assertCreated()->json('data.id');

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/enviar")->assertOk();

        return $id;
    }

    public function test_todas_las_escrituras_sin_permiso_responden_403_y_no_modifican_datos(): void
    {
        $cotizacionBorrador = $this->crearCotizacionDirecta('borrador');
        $cotizacionEnviada = $this->crearCotizacionDirecta('enviado');
        $cli = $this->cliente->id;
        $pro = $this->prospecto->id;
        $opo = $this->oportunidad->id;
        $ext = $this->empresaExterna->id;

        $escrituras = [
            ['POST', '/api/crm/clientes', ['nombre' => 'Nuevo']],
            ['PUT', "/api/crm/clientes/{$cli}", ['nombre' => 'Cambiado']],
            ['DELETE', "/api/crm/clientes/{$cli}", []],
            ['PATCH', "/api/crm/clientes/{$cli}/asignar-vendedor", ['vendedor_id' => $this->vendedor->id]],

            ['POST', '/api/crm/prospectos', ['nombre' => 'Nuevo']],
            ['PUT', "/api/crm/prospectos/{$pro}", ['nombre' => 'Cambiado']],
            ['DELETE', "/api/crm/prospectos/{$pro}", []],
            ['POST', "/api/crm/prospectos/{$pro}/convertir-cliente", []],
            ['PATCH', "/api/crm/prospectos/{$pro}/asignar-vendedor", ['vendedor_id' => $this->vendedor->id]],

            ['POST', '/api/crm/oportunidades', ['nombre' => 'Nueva', 'cliente_id' => $cli, 'vendedor_id' => $this->vendedor->id]],
            ['PUT', "/api/crm/oportunidades/{$opo}", ['nombre' => 'Cambiada']],
            ['DELETE', "/api/crm/oportunidades/{$opo}", []],
            ['PATCH', "/api/crm/oportunidades/{$opo}/cambiar-etapa", ['etapa' => 'propuesta']],
            ['PATCH', "/api/crm/oportunidades/{$opo}/cambiar-etapa", ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio']],

            ['POST', "/api/crm/oportunidades/{$opo}/cotizaciones", [
                'fecha_emision' => now()->toDateString(),
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1, 'precio_unitario' => 100]],
            ]],
            ['PUT', "/api/crm/cotizaciones/{$cotizacionBorrador->id}", ['notas' => 'Cambio']],
            ['PATCH', "/api/crm/cotizaciones/{$cotizacionBorrador->id}/enviar", []],
            ['PATCH', "/api/crm/cotizaciones/{$cotizacionEnviada->id}/aprobar", []],
            ['PATCH', "/api/crm/cotizaciones/{$cotizacionEnviada->id}/rechazar", []],

            ['POST', '/api/crm/actividades', [
                'entidad_tipo' => 'cliente', 'entidad_id' => $cli, 'tipo' => 'llamada',
                'descripcion' => 'Llamada', 'fecha_actividad' => now()->toDateTimeString(),
            ]],

            ['POST', '/api/crm/empresas-externas', ['razon_social' => 'Nueva SA']],
            ['PUT', "/api/crm/empresas-externas/{$ext}", ['razon_social' => 'Cambiada SA']],
            ['DELETE', "/api/crm/empresas-externas/{$ext}", []],

            ['POST', '/api/crm/contactos', ['entidad_tipo' => 'cliente', 'entidad_id' => $cli, 'nombre' => 'Contacto']],
            ['POST', '/api/crm/contactos', ['entidad_tipo' => 'empresa_externa', 'entidad_id' => $ext, 'nombre' => 'Contacto']],

            ['GET', '/api/crm/vendedores/usuarios-disponibles', []],
            ['POST', '/api/crm/vendedores', ['nombre' => 'Nuevo vendedor']],
            ['PUT', "/api/crm/vendedores/{$this->vendedor->id}", ['nombre' => 'Cambiado']],
            ['PATCH', "/api/crm/vendedores/{$this->vendedor->id}/toggle-activo", []],
            ['DELETE', "/api/crm/vendedores/{$this->vendedor->id}", []],

            ['POST', '/api/crm/regiones', ['nombre' => 'Nueva']],
            ['PUT', "/api/crm/regiones/{$this->region->id}", ['nombre' => 'Cambiada']],
            ['DELETE', "/api/crm/regiones/{$this->region->id}", []],
            ['POST', '/api/crm/zonas', ['region_id' => $this->region->id, 'nombre' => 'Nueva']],
            ['PUT', "/api/crm/zonas/{$this->zona->id}", ['nombre' => 'Cambiada']],
            ['DELETE', "/api/crm/zonas/{$this->zona->id}", []],
            ['POST', '/api/crm/bodegas', ['zona_id' => $this->zona->id, 'nombre' => 'Nueva']],
            ['PUT', "/api/crm/bodegas/{$this->bodega->id}", ['nombre' => 'Cambiada']],
            ['DELETE', "/api/crm/bodegas/{$this->bodega->id}", []],

            ['POST', '/api/crm/productos', ['nombre' => 'Nuevo', 'precio' => 1, 'unidad_medida' => 'pieza']],
            ['PUT', "/api/crm/productos/{$this->producto->id}", ['nombre' => 'Cambiado']],
            ['PATCH', "/api/crm/productos/{$this->producto->id}/toggle-activo", []],
            ['DELETE', "/api/crm/productos/{$this->producto->id}", []],

            ['PUT', '/api/crm/configuracion-comercial', ['descuento_global_habilitado' => false]],
            ['POST', '/api/crm/configuracion-comercial/impuestos', ['nombre' => 'IVA', 'tasa' => 16]],
        ];

        foreach ($escrituras as [$metodo, $uri, $payload]) {
            $response = $this->json($metodo, $uri, $payload, $this->crmHeaders());
            $this->assertSame(403, $response->status(), "{$metodo} {$uri} debió responder 403, respondió {$response->status()}");
        }

        $this->assertSame(1, CrmCliente::count());
        $this->assertSame('Cliente', $this->cliente->fresh()->nombre);
        $this->assertSame(1, CrmProspecto::count());
        $this->assertSame('nuevo', $this->prospecto->fresh()->estatus);
        $this->assertSame(1, CrmOportunidad::count());
        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);
        $this->assertSame('borrador', $cotizacionBorrador->fresh()->estado);
        $this->assertSame('enviado', $cotizacionEnviada->fresh()->estado);
        $this->assertSame(2, CrmCotizacion::count());
        $this->assertSame(0, CrmActividad::count());
        $this->assertSame(0, CrmContacto::count());
        $this->assertSame(1, CrmEmpresaExterna::count());
        $this->assertTrue((bool) $this->vendedor->fresh()->activo);
        $this->assertSame('Producto', $this->producto->fresh()->nombre);
    }

    public function test_las_lecturas_siguen_abiertas_dentro_del_tenant_sin_permisos(): void
    {
        $lecturas = [
            '/api/crm/vendedores', '/api/crm/regiones', '/api/crm/zonas', '/api/crm/bodegas',
            '/api/crm/productos', '/api/crm/productos/buscar?q=Prod', '/api/crm/configuracion-comercial',
            '/api/crm/clientes', '/api/crm/prospectos', '/api/crm/oportunidades', '/api/crm/cotizaciones',
            '/api/crm/empresas-externas', '/api/crm/contactos', '/api/crm/actividades',
        ];

        foreach ($lecturas as $uri) {
            $response = $this->withHeaders($this->crmHeaders())->getJson($uri);
            $this->assertSame(200, $response->status(), "GET {$uri} debió seguir abierto, respondió {$response->status()}");
        }
    }

    public function test_aprobar_cotizacion_exige_el_permiso_aprobar(): void
    {
        $id = $this->crearCotizacionEnviadaPorApi();

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/aprobar")->assertStatus(403);
        $this->assertSame('enviado', CrmCotizacion::find($id)->estado);
        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);

        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['aprobar']);

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/aprobar")->assertOk();
        $this->assertSame('aprobado', CrmCotizacion::find($id)->estado);
        $this->assertSame('cerrado_ganado', $this->oportunidad->fresh()->etapa);
    }

    public function test_rechazar_cotizacion_exige_el_permiso_rechazar_y_aprobar_no_lo_sustituye(): void
    {
        $id = $this->crearCotizacionEnviadaPorApi();
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['aprobar']);

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/rechazar")->assertStatus(403);
        $this->assertSame('enviado', CrmCotizacion::find($id)->estado);

        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['rechazar']);

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/rechazar")->assertOk();
        $this->assertSame('rechazado', CrmCotizacion::find($id)->estado);
    }

    public function test_cerrar_una_oportunidad_exige_el_permiso_cerrar_aunque_tenga_editar(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['editar']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", [
                'etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio',
            ])->assertStatus(403);
        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", [
                'etapa' => 'cerrado_ganado', 'forzar' => true,
            ])->assertStatus(403);
        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", ['etapa' => 'propuesta'])
            ->assertOk();

        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['cerrar']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", [
                'etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio',
            ])->assertOk();
        $this->assertSame('cerrado_perdido', $this->oportunidad->fresh()->etapa);
    }

    public function test_mover_entre_etapas_activas_exige_editar_no_basta_cerrar(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['cerrar']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", ['etapa' => 'propuesta'])
            ->assertStatus(403);
        $this->assertSame('calificado', $this->oportunidad->fresh()->etapa);
    }

    public function test_el_permiso_de_otro_submodulo_no_autoriza(): void
    {
        $this->otorgarPermisosCrm('prospectos', 'prospectos', ['crear']);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/clientes', ['nombre' => 'Nuevo'])->assertStatus(403);
        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/prospectos', ['nombre' => 'Nuevo'])->assertCreated();
    }

    public function test_los_permisos_otorgados_en_otra_empresa_no_autorizan_en_la_propia(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);
        $this->otorgarTodosLosPermisosCrm($otraEmpresa);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/clientes', ['nombre' => 'Nuevo'])->assertStatus(403);
        $this->withHeaders($this->crmHeaders($otraEmpresa->id))->postJson('/api/crm/clientes', ['nombre' => 'Nuevo'])->assertCreated();
    }

    public function test_convertir_prospecto_exige_crear_clientes(): void
    {
        $this->otorgarPermisosCrm('prospectos', 'prospectos', ['crear', 'editar']);

        $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$this->prospecto->id}/convertir-cliente")->assertStatus(403);

        $this->otorgarPermisosCrm('clientes', 'clientes', ['crear']);

        $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$this->prospecto->id}/convertir-cliente")->assertCreated();
        $this->assertSame(2, CrmCliente::count());
    }

    public function test_contactos_exigen_el_permiso_de_la_entidad_padre(): void
    {
        $this->otorgarPermisosCrm('prospectos', 'prospectos', ['editar']);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/contactos', [
            'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id, 'nombre' => 'Contacto',
        ])->assertStatus(403);

        $this->otorgarPermisosCrm('clientes', 'clientes', ['editar']);

        $contactoId = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/contactos', [
            'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id, 'nombre' => 'Contacto',
        ])->assertCreated()->json('data.id');

        $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/contactos/{$contactoId}")->assertOk();
    }

    public function test_contactos_de_empresa_externa_exigen_gestionar_contactos(): void
    {
        $this->otorgarPermisosCrm('empresas-externas', 'empresas-externas', ['editar']);

        $payload = ['entidad_tipo' => 'empresa_externa', 'entidad_id' => $this->empresaExterna->id, 'nombre' => 'Contacto'];

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/contactos', $payload)->assertStatus(403);

        $this->otorgarPermisosCrm('empresas-externas', 'empresas-externas', ['gestionar_contactos']);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/contactos', $payload)->assertCreated();
    }

    public function test_usuarios_disponibles_para_vendedor_exige_crear_o_editar_vendedores(): void
    {
        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/vendedores/usuarios-disponibles')->assertStatus(403);

        $this->otorgarPermisosCrm('catalogos', 'vendedores', ['editar']);

        $this->withHeaders($this->crmHeaders())->getJson('/api/crm/vendedores/usuarios-disponibles')->assertOk();
    }

    public function test_catalogos_exigen_su_propio_submodulo(): void
    {
        $this->otorgarPermisosCrm('catalogos', 'regiones', ['crear']);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/regiones', ['nombre' => 'Nueva'])->assertCreated();
        $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/productos', ['nombre' => 'Nuevo', 'precio' => 1, 'unidad_medida' => 'pieza'])
            ->assertStatus(403);
    }
}
