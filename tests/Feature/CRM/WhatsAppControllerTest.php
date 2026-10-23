<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPlantillaWhatsapp;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Destinatarios y mensaje del botón de WhatsApp (spec 2026-10-12 §5.2, §5.3). */
class WhatsAppControllerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmContacto $principal;

    private CrmContacto $sinWhatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'María López', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Agrícola del Valle', 'telefono' => '662 123 4567',
        ]);
        $this->sinWhatsapp = $this->contacto($this->cliente, 'Ana Ruiz', '662 123 4567 ext. 12');
        $this->principal = $this->contacto($this->cliente, 'Juan Pérez Soto', '(662) 765-4321', principal: true);
    }

    private function contacto($entidad, string $nombre, ?string $telefono, bool $principal = false, ?string $cargo = null): CrmContacto
    {
        return CrmContacto::create([
            'empresa_id' => $entidad->empresa_id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'nombre' => $nombre, 'cargo' => $cargo, 'telefono' => $telefono, 'es_principal' => $principal,
        ]);
    }

    private function oportunidad(?CrmVendedor $vendedor = null, array $datos = []): CrmOportunidad
    {
        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => ($vendedor ?? $this->propio)->id, 'nombre' => 'Riego por goteo 2027',
            'monto_esperado' => 12345, 'etapa' => 'calificado',
        ], $datos));
    }

    private function cotizacion(CrmOportunidad $oportunidad, string $folio, float $total = 98450): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $oportunidad->empresa_id, 'oportunidad_id' => $oportunidad->id, 'folio' => $folio,
            'estado' => 'enviado', 'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => 0,
            'subtotal' => $total, 'total' => $total,
        ]);
    }

    private function plantilla(string $cuerpo, array $datos = []): CrmPlantillaWhatsapp
    {
        return CrmPlantillaWhatsapp::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Plantilla '.uniqid(), 'cuerpo' => $cuerpo,
        ], $datos));
    }

    private function destinatarios(string $tipo, int $id)
    {
        return $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/whatsapp/destinatarios?entidad_tipo={$tipo}&entidad_id={$id}");
    }

    private function renderizar(array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/whatsapp/renderizar', $datos);
    }

    // ---------------------------------------------------------------
    // destinatarios
    // ---------------------------------------------------------------

    public function test_cliente_lista_su_telefono_y_sus_contactos_con_el_principal_primero(): void
    {
        $this->destinatarios('cliente', $this->cliente->id)
            ->assertOk()
            ->assertJsonPath('data.entidad', ['tipo' => 'cliente', 'id' => $this->cliente->id, 'nombre' => 'Agrícola del Valle'])
            ->assertJsonPath('data.vendedor', ['nombre' => 'María López'])
            ->assertJsonCount(3, 'data.destinatarios')
            ->assertJsonPath('data.destinatarios.0', [
                'clave' => 'entidad', 'nombre' => 'Agrícola del Valle', 'rol' => 'Cliente',
                'telefono' => '662 123 4567', 'telefono_whatsapp' => '526621234567',
            ])
            ->assertJsonPath('data.destinatarios.1', [
                'clave' => 'contacto:'.$this->principal->id, 'nombre' => 'Juan Pérez Soto', 'rol' => 'Contacto · principal',
                'telefono' => '(662) 765-4321', 'telefono_whatsapp' => '526627654321',
            ])
            ->assertJsonPath('data.destinatarios.2.clave', 'contacto:'.$this->sinWhatsapp->id);
    }

    public function test_un_telefono_que_no_se_puede_normalizar_sale_con_telefono_whatsapp_null(): void
    {
        $this->destinatarios('cliente', $this->cliente->id)
            ->assertOk()
            ->assertJsonPath('data.destinatarios.2.telefono', '662 123 4567 ext. 12')
            ->assertJsonPath('data.destinatarios.2.telefono_whatsapp', null);
    }

    public function test_un_cliente_sin_telefono_solo_lista_sus_contactos(): void
    {
        $this->cliente->update(['telefono' => null]);

        $this->destinatarios('cliente', $this->cliente->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.destinatarios')
            ->assertJsonPath('data.destinatarios.0.clave', 'contacto:'.$this->principal->id);
    }

    public function test_sin_vendedor_vinculado_devuelve_vendedor_null(): void
    {
        $this->propio->update(['activo' => false]);

        $this->destinatarios('cliente', $this->cliente->id)->assertOk()->assertJsonPath('data.vendedor', null);
    }

    public function test_empresa_externa_solo_lista_sus_contactos(): void
    {
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Transportes del Norte SA']);
        $contacto = $this->contacto($externa, 'Luis Mora', '+1 415 555 2671', cargo: 'Logística');

        $this->destinatarios('empresa_externa', $externa->id)
            ->assertOk()
            ->assertJsonPath('data.entidad.nombre', 'Transportes del Norte SA')
            ->assertJsonCount(1, 'data.destinatarios')
            ->assertJsonPath('data.destinatarios.0', [
                'clave' => 'contacto:'.$contacto->id, 'nombre' => 'Luis Mora', 'rol' => 'Logística',
                'telefono' => '+1 415 555 2671', 'telefono_whatsapp' => '14155552671',
            ]);
    }

    public function test_oportunidad_propia_lista_el_telefono_y_los_contactos_de_su_cliente(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $oportunidad = $this->oportunidad();

        $this->destinatarios('oportunidad', $oportunidad->id)
            ->assertOk()
            ->assertJsonPath('data.entidad', ['tipo' => 'oportunidad', 'id' => $oportunidad->id, 'nombre' => 'Riego por goteo 2027'])
            ->assertJsonCount(3, 'data.destinatarios')
            ->assertJsonPath('data.destinatarios.0.nombre', 'Agrícola del Valle');
    }

    public function test_oportunidad_con_el_cliente_eliminado_usa_el_prospecto(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Viejo', 'telefono' => '6621112233']);
        $oportunidad = $this->oportunidad(datos: ['prospecto_id' => $prospecto->id]);
        $this->cliente->delete();

        $this->destinatarios('oportunidad', $oportunidad->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.destinatarios')
            ->assertJsonPath('data.destinatarios.0.rol', 'Prospecto')
            ->assertJsonPath('data.destinatarios.0.telefono_whatsapp', '526621112233');

        $prospecto->delete();

        $this->destinatarios('oportunidad', $oportunidad->id)->assertOk()->assertJsonCount(0, 'data.destinatarios');
    }

    public function test_oportunidad_que_apunta_a_un_cliente_de_otra_empresa_no_filtra_sus_telefonos(): void
    {
        // Dato corrupto: la FK cliente_id de la oportunidad apunta a otro tenant.
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $ajeno = CrmCliente::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno', 'telefono' => '6620000000']);
        $this->contacto($ajeno, 'Contacto Ajeno', '6629999999');
        $oportunidad = $this->oportunidad(datos: ['cliente_id' => $ajeno->id]);
        $plantilla = $this->plantilla('Hola {{nombre}} de {{entidad}}');

        $this->destinatarios('oportunidad', $oportunidad->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.destinatarios')
            ->assertDontSee('6620000000')
            ->assertDontSee('6629999999');

        $this->renderizar(['plantilla_id' => $plantilla->id, 'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidad->id])
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Hola  de ')
            ->assertJsonPath('data.variables_faltantes', ['nombre', 'entidad']);
    }

    public function test_oportunidad_de_otro_vendedor_sin_equipo_responde_404_y_con_equipo_200(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $ajena = $this->oportunidad($this->vendedor);

        $this->destinatarios('oportunidad', $ajena->id)->assertNotFound();

        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $this->destinatarios('oportunidad', $ajena->id)->assertOk();
    }

    public function test_entidad_de_otra_empresa_o_inexistente_responde_404(): void
    {
        $ajeno = CrmCliente::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno', 'telefono' => '6620000000']);

        $this->destinatarios('cliente', $ajeno->id)->assertNotFound();
        $this->destinatarios('cliente', 999999)->assertNotFound();
    }

    public function test_sin_permiso_de_crear_actividades_responde_403(): void
    {
        $plantilla = $this->plantilla('Hola');
        UserSubmodulePermission::query()->delete();

        $this->destinatarios('cliente', $this->cliente->id)->assertForbidden();
        $this->renderizar(['plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertForbidden();
    }

    public function test_tipo_de_entidad_desconocido_responde_422(): void
    {
        $this->destinatarios('vendedor', $this->vendedor->id)->assertStatus(422)->assertJsonValidationErrors('entidad_tipo');
    }

    // ---------------------------------------------------------------
    // renderizar
    // ---------------------------------------------------------------

    public function test_renderiza_todas_las_variables_para_una_cotizacion(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $oportunidad = $this->oportunidad();
        $cotizacion = $this->cotizacion($oportunidad, 'COT-00012', 1234567.5);
        $plantilla = $this->plantilla('{{nombre}}|{{entidad}}|{{vendedor}}|{{empresa}}|{{oportunidad}}|{{monto}}|{{folio}}|{{total}}');

        $this->renderizar([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidad->id,
            'destinatario_clave' => 'contacto:'.$this->principal->id, 'cotizacion_id' => $cotizacion->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Juan|Agrícola del Valle|María López|Splendid Farms|Riego por goteo 2027|$12,345.00|COT-00012|$1,234,567.50')
            ->assertJsonPath('data.variables_faltantes', []);
    }

    public function test_monto_en_usd_para_empresa_usd(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['moneda' => 'USD']);
        $oportunidad = $this->oportunidad(null, ['monto_esperado' => 17400]);
        $cotizacion = $this->cotizacion($oportunidad, 'COT-00020', 1234567.5);

        $this->renderizar([
            'plantilla_id' => $this->plantilla('{{monto}}|{{total}}')->id,
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidad->id, 'cotizacion_id' => $cotizacion->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'USD 17,400.00|USD 1,234,567.50');
    }

    public function test_monto_en_mxn_sin_cambios(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $oportunidad = $this->oportunidad(null, ['monto_esperado' => 17400]);

        $this->renderizar([
            'plantilla_id' => $this->plantilla('{{monto}}')->id,
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidad->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.mensaje', '$17,400.00');
    }

    public function test_sin_destinatario_el_nombre_es_el_de_la_entidad_y_se_reportan_las_faltantes(): void
    {
        $plantilla = $this->plantilla('Hola {{nombre}}, la cotización {{folio}} por {{total}}.');

        $this->renderizar(['plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Hola Agrícola del Valle, la cotización  por .')
            ->assertJsonPath('data.variables_faltantes', ['folio', 'total']);
    }

    public function test_el_cliente_como_destinatario_usa_su_nombre_completo_y_el_contacto_su_primer_nombre(): void
    {
        $plantilla = $this->plantilla('Hola {{nombre}}');
        $base = ['plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id];

        $this->renderizar($base + ['destinatario_clave' => 'entidad'])->assertOk()->assertJsonPath('data.mensaje', 'Hola Agrícola del Valle');
        $this->renderizar($base + ['destinatario_clave' => 'contacto:'.$this->principal->id])->assertOk()->assertJsonPath('data.mensaje', 'Hola Juan');
    }

    public function test_sin_vendedor_vinculado_reporta_vendedor_como_faltante(): void
    {
        $this->propio->update(['activo' => false]);
        $plantilla = $this->plantilla('Soy {{vendedor}} de {{empresa}}');

        $this->renderizar(['plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertOk()
            ->assertJsonPath('data.mensaje', 'Soy  de Splendid Farms')
            ->assertJsonPath('data.variables_faltantes', ['vendedor']);
    }

    public function test_una_cotizacion_ajena_o_no_relacionada_responde_404(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $propia = $this->oportunidad();
        $deOtroVendedor = $this->cotizacion($this->oportunidad($this->vendedor, ['nombre' => 'Ajena']), 'COT-00020');
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro cliente']);
        $deOtroCliente = $this->cotizacion($this->oportunidad(datos: ['cliente_id' => $otroCliente->id]), 'COT-00021');
        $deOtraOportunidad = $this->cotizacion($this->oportunidad(datos: ['nombre' => 'Otra del mismo cliente']), 'COT-00023');
        $otra = $this->crearOtraEmpresa();
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Vendedor ajeno', 'activo' => true]);
        $deOtraEmpresa = $this->cotizacion(CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $vendedorOtra->id, 'nombre' => 'De otra empresa',
        ]), 'COT-00001');
        $plantilla = $this->plantilla('{{folio}} {{total}}');
        $pedir = fn (array $extra) => $this->renderizar(array_merge([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'oportunidad', 'entidad_id' => $propia->id,
        ], $extra));

        $pedir(['cotizacion_id' => $deOtroVendedor->id])->assertNotFound()->assertJsonMissing(['mensaje' => 'COT-00020 $98,450.00']);
        $pedir(['cotizacion_id' => $deOtroCliente->id])->assertNotFound();
        $pedir(['cotizacion_id' => $deOtraOportunidad->id])->assertNotFound();
        $pedir(['cotizacion_id' => $deOtraEmpresa->id])->assertNotFound();
        $pedir(['cotizacion_id' => 999999])->assertNotFound();

        // Desde la ficha del cliente sí vale una cotización de una oportunidad de ese cliente.
        $this->renderizar([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id,
            'cotizacion_id' => $this->cotizacion($propia, 'COT-00022')->id,
        ])->assertOk()->assertJsonPath('data.mensaje', 'COT-00022 $98,450.00');
    }

    public function test_desde_el_cliente_la_cotizacion_de_otro_vendedor_responde_404_sin_equipo(): void
    {
        // Aquí la ÚNICA barrera es la visibilidad de la oportunidad de la
        // cotización: el cliente es visible y la cotización sí es de ese cliente.
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $ajena = $this->cotizacion($this->oportunidad($this->vendedor, ['nombre' => 'Del otro vendedor']), 'COT-00040', 55500);
        $pedir = fn () => $this->renderizar([
            'plantilla_id' => $this->plantilla('{{folio}} {{total}} {{oportunidad}}')->id,
            'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id, 'cotizacion_id' => $ajena->id,
        ]);

        $pedir()->assertNotFound()
            ->assertDontSee('COT-00040')
            ->assertDontSee('55,500.00')
            ->assertDontSee('Del otro vendedor');

        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $pedir()->assertOk()->assertJsonPath('data.mensaje', 'COT-00040 $55,500.00 Del otro vendedor');
    }

    public function test_renderizar_con_una_oportunidad_de_otro_vendedor_responde_404(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $ajena = $this->oportunidad($this->vendedor, ['nombre' => 'Oportunidad ajena']);

        $this->renderizar([
            'plantilla_id' => $this->plantilla('{{oportunidad}} {{monto}}')->id,
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $ajena->id,
        ])->assertNotFound()->assertDontSee('Oportunidad ajena')->assertDontSee('12,345.00');
    }

    public function test_cotizacion_cuya_oportunidad_es_de_otra_empresa_responde_404(): void
    {
        // FK corrupta: la cotización es de esta empresa pero su oportunidad es de
        // otra, en la que el usuario sí ve al equipo y con el mismo cliente_id.
        $otra = $this->crearOtraEmpresa();
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $tipos = \App\Models\SubmodulePermissionType::whereIn('slug', ['ver', 'equipo'])
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades')
                ->whereHas('module.application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $otra->id)))
            ->get();
        $this->assertCount(2, $tipos);
        foreach ($tipos as $tipo) {
            UserSubmodulePermission::create([
                'user_id' => $this->actingUser->id, 'submodule_id' => $tipo->submodule_id,
                'permission_type_id' => $tipo->id, 'is_granted' => true,
            ]);
        }
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Vendedor ajeno', 'activo' => true]);
        $oportunidadOtra = CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $vendedorOtra->id,
            'cliente_id' => $this->cliente->id, 'nombre' => 'De otra empresa',
        ]);
        $cotizacion = CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidadOtra->id, 'folio' => 'COT-00050',
            'estado' => 'enviado', 'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => 0,
            'subtotal' => 100, 'total' => 100,
        ]);

        $this->renderizar([
            'plantilla_id' => $this->plantilla('{{folio}} {{oportunidad}}')->id,
            'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id, 'cotizacion_id' => $cotizacion->id,
        ])->assertNotFound()->assertDontSee('COT-00050')->assertDontSee('De otra empresa');
    }

    public function test_cotizacion_u_oportunidad_eliminadas_responden_404(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $plantilla = $this->plantilla('{{folio}}');
        $oportunidad = $this->oportunidad();
        $borrada = $this->cotizacion($oportunidad, 'COT-00060');
        $borrada->delete();
        $deOportunidadBorrada = $this->cotizacion($this->oportunidad(datos: ['nombre' => 'Se borrará']), 'COT-00061');
        $deOportunidadBorrada->oportunidad->delete();

        // Cotización eliminada.
        $this->renderizar([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'oportunidad',
            'entidad_id' => $oportunidad->id, 'cotizacion_id' => $borrada->id,
        ])->assertNotFound();
        // Cotización viva cuya oportunidad se eliminó, pedida desde el cliente.
        $this->renderizar([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id, 'cotizacion_id' => $deOportunidadBorrada->id,
        ])->assertNotFound()->assertDontSee('COT-00061');
        // La oportunidad eliminada como entidad.
        $this->renderizar([
            'plantilla_id' => $plantilla->id, 'entidad_tipo' => 'oportunidad',
            'entidad_id' => $deOportunidadBorrada->oportunidad_id,
        ])->assertNotFound();
    }

    public function test_sin_permiso_de_ver_cotizaciones_la_cotizacion_responde_404(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $oportunidad = $this->oportunidad();
        $cotizacion = $this->cotizacion($oportunidad, 'COT-00030');

        $this->renderizar([
            'plantilla_id' => $this->plantilla('{{folio}}')->id, 'entidad_tipo' => 'oportunidad',
            'entidad_id' => $oportunidad->id, 'cotizacion_id' => $cotizacion->id,
        ])->assertNotFound();
    }

    public function test_una_plantilla_guardada_con_utf8_invalido_responde_422_sin_mensaje_null(): void
    {
        // Dato que entró sin pasar por la validación (BD a mano o carga antigua).
        $id = \Illuminate\Support\Facades\DB::table('crm_plantillas_whatsapp')->insertGetId([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Rota', 'cuerpo' => "Hola \xFF {{nombre}}",
            'activo' => true, 'orden' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->renderizar(['plantilla_id' => $id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plantilla_id')
            ->assertJsonMissingPath('data.mensaje');
    }

    public function test_una_plantilla_inactiva_o_de_otra_empresa_responde_404(): void
    {
        $inactiva = $this->plantilla('Hola', ['activo' => false]);
        $ajena = CrmPlantillaWhatsapp::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajena', 'cuerpo' => 'Hola']);
        $base = ['entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id];

        $this->renderizar($base + ['plantilla_id' => $inactiva->id])->assertNotFound();
        $this->renderizar($base + ['plantilla_id' => $ajena->id])->assertNotFound();
    }

    public function test_un_destinatario_que_no_es_del_registro_responde_422(): void
    {
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro cliente']);
        $ajeno = $this->contacto($otroCliente, 'Pedro Gil', '6620001111');
        $base = ['plantilla_id' => $this->plantilla('Hola {{nombre}}')->id, 'entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id];

        $this->renderizar($base + ['destinatario_clave' => 'contacto:'.$ajeno->id])
            ->assertStatus(422)->assertJsonValidationErrors('destinatario_clave');
        $this->renderizar($base + ['destinatario_clave' => 'cualquiera'])
            ->assertStatus(422)->assertJsonValidationErrors('destinatario_clave');
    }
}
