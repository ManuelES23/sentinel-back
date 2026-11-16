<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\ContactoUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contacto con quien se habló en POST crm/seguimientos y POST crm/actividades
 * (CRM SBP: prospectos como empresas). Con la bandera apagada nada cambia.
 */
class ContactoEnSeguimientoTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MENSAJE_FALTA = 'Elige con quién hablaste o agrega un contacto.';

    private const MENSAJE_AJENO = 'El contacto no pertenece a esta empresa.';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmOportunidad $oportunidad;

    private CrmEtiquetaActividad $etiqueta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'La Huerta', 'estatus' => 'activo']);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'La Huerta · Pepper', 'monto_esperado' => 1000,
        ]);
        $this->etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Seguimiento']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function encender(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['prospecto_como_empresa' => true]);
    }

    private function contactoDe(CrmCliente|CrmProspecto $entidad, string $nombre = 'Ana'): CrmContacto
    {
        return CrmContacto::create([
            'empresa_id' => $entidad->empresa_id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'nombre' => $nombre, 'es_principal' => true,
        ]);
    }

    private function seguimiento(array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/seguimientos', array_merge([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => [
                'tipo' => 'llamada', 'descripcion' => 'Habló con el comprador', 'etiqueta_id' => $this->etiqueta->id,
            ],
        ], $datos));
    }

    private function siguiente(): array
    {
        return [
            'tipo' => 'llamada', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-09-20 09:00:00', 'etiqueta_id' => $this->etiqueta->id,
        ];
    }

    private function actividad(array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/actividades', array_merge([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'tipo' => 'llamada', 'descripcion' => 'Habló con el comprador',
            'fecha_actividad' => '2026-09-14 09:00:00', 'etiqueta_id' => $this->etiqueta->id,
        ], $datos));
    }

    // ---- POST crm/seguimientos ----

    public function test_seguimiento_llamada_sin_contacto_con_bandera_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->seguimiento()
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);

        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_seguimiento_con_contacto_nuevo_lo_crea_y_lo_liga_a_actividad_y_siguiente(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([ActividadUpdated::class, AgendaUpdated::class, ContactoUpdated::class]);

        $respuesta = $this->seguimiento([
            'siguiente' => $this->siguiente(),
            'contacto_nuevo' => ['nombre' => 'Luis Gómez', 'cargo' => 'Compras', 'telefono' => '6671234567'],
        ])->assertStatus(201);

        $contacto = CrmContacto::where('nombre', 'Luis Gómez')->firstOrFail();
        $this->assertSame(CrmCliente::class, $contacto->entidad_type);
        $this->assertSame($this->cliente->id, $contacto->entidad_id);
        $this->assertTrue($contacto->es_principal);

        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
        $this->assertSame($contacto->id, CrmAgenda::firstOrFail()->contacto_id);

        $respuesta->assertJsonPath('data.actividad.contacto.nombre', 'Luis Gómez')
            ->assertJsonPath('data.actividad.contacto.cargo', 'Compras')
            ->assertJsonPath('data.evento.contacto.id', $contacto->id);

        Event::assertDispatched(ContactoUpdated::class, fn ($e) => $e->action === 'created' && $e->data['nombre'] === 'Luis Gómez');
        Event::assertDispatched(ActividadUpdated::class, fn ($e) => $e->data['contacto']['id'] === $contacto->id);
        Event::assertDispatched(AgendaUpdated::class, fn ($e) => $e->data['contacto']['id'] === $contacto->id);
    }

    public function test_seguimiento_con_contacto_existente_lo_liga(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $contacto = $this->contactoDe($this->cliente);

        $this->seguimiento(['siguiente' => $this->siguiente(), 'contacto_id' => $contacto->id])->assertStatus(201);

        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
        $this->assertSame($contacto->id, CrmAgenda::firstOrFail()->contacto_id);
        $this->assertDatabaseCount('crm_contactos', 1);
    }

    public function test_vendedor_sin_permiso_de_editar_prospectos_crea_contacto_en_linea(): void
    {
        // Solo lo mínimo del seguimiento: registrar actividades y ver su oportunidad.
        $this->otorgarPermisosCrm('actividades', 'actividades', ['crear']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->encender();

        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Nuevo', 'estatus' => 'nuevo']);
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $prospecto->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Rancho Nuevo · Pepper', 'monto_esperado' => 500,
        ]);

        // El mismo usuario no puede crear contactos por el endpoint de contactos...
        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/contactos', [
            'entidad_tipo' => 'prospecto', 'entidad_id' => $prospecto->id, 'nombre' => 'Directo',
        ])->assertStatus(403);

        // ...pero sí en línea, porque el permiso es el de la actividad.
        $this->seguimiento([
            'entidad_id' => $oportunidad->id,
            'contacto_nuevo' => ['nombre' => 'Marta Ríos', 'cargo' => 'Gerente'],
        ])->assertStatus(201);

        $contacto = CrmContacto::where('nombre', 'Marta Ríos')->firstOrFail();
        $this->assertSame(CrmProspecto::class, $contacto->entidad_type);
        $this->assertSame($prospecto->id, $contacto->entidad_id);
        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_seguimiento_nota_sin_contacto_pasa(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->seguimiento(['actividad' => [
            'tipo' => 'nota', 'descripcion' => 'Nota interna', 'etiqueta_id' => $this->etiqueta->id,
        ]])->assertStatus(201);

        $this->assertNull(CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_solo_siguiente_sin_contacto_pasa(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->seguimiento(['actividad' => null, 'siguiente' => $this->siguiente()])->assertStatus(201);

        $this->assertNull(CrmAgenda::firstOrFail()->contacto_id);
    }

    public function test_whatsapp_enlace_exige_contacto(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $actividad = ['tipo' => 'whatsapp', 'descripcion' => 'Envié el mensaje', 'fuente' => 'whatsapp_enlace'];

        $this->seguimiento(['actividad' => $actividad])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);

        $contacto = $this->contactoDe($this->cliente);
        $this->seguimiento(['actividad' => $actividad, 'contacto_id' => $contacto->id])->assertStatus(201);
        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_bandera_apagada_sin_contacto_pasa_como_hoy(): void
    {
        $this->otorgarTodosLosPermisosCrm();

        $this->seguimiento()->assertStatus(201);

        $this->assertNull(CrmActividad::firstOrFail()->contacto_id);
        $this->assertDatabaseCount('crm_contactos', 0);
    }

    public function test_bandera_apagada_con_contacto_ajeno_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $otro = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro Cliente', 'estatus' => 'activo']);
        $ajeno = $this->contactoDe($otro);

        $this->seguimiento(['contacto_id' => $ajeno->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);

        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_contacto_ajeno_con_bandera_encendida_no_deja_nada_guardado(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $otro = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro Cliente', 'estatus' => 'activo']);
        $ajeno = $this->contactoDe($otro);

        $this->seguimiento(['siguiente' => $this->siguiente(), 'contacto_id' => $ajeno->id])->assertStatus(422);

        $this->assertDatabaseCount('crm_actividades', 0);
        $this->assertDatabaseCount('crm_agenda', 0);
    }

    public function test_contacto_nuevo_vacio_responde_422_en_lugar_de_500(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->seguimiento(['contacto_nuevo' => new \stdClass])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);
    }

    public function test_contacto_nuevo_sin_nombre_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->seguimiento(['contacto_nuevo' => ['cargo' => 'Compras']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contacto_nuevo.nombre');
    }

    // ---- POST crm/actividades ----

    public function test_post_actividades_exige_contacto_con_bandera(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->actividad()
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);
        $this->assertDatabaseCount('crm_actividades', 0);

        Event::fake([ActividadUpdated::class, ContactoUpdated::class]);
        $contacto = $this->contactoDe($this->cliente);
        $this->actividad(['contacto_id' => $contacto->id])
            ->assertStatus(201)
            ->assertJsonPath('data.contacto.id', $contacto->id)
            ->assertJsonPath('data.contacto.nombre', 'Ana');
        Event::assertNotDispatched(ContactoUpdated::class);

        $this->actividad(['tipo' => 'nota'])->assertStatus(201);

        $this->actividad(['contacto_nuevo' => ['nombre' => 'Nuevo Contacto']])->assertStatus(201);
        Event::assertDispatched(ContactoUpdated::class, fn ($e) => $e->data['nombre'] === 'Nuevo Contacto');
    }

    public function test_post_actividades_bandera_apagada_sin_contacto_pasa_y_ajeno_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();

        $this->actividad()->assertStatus(201);

        $otro = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro Cliente', 'estatus' => 'activo']);
        $this->actividad(['contacto_id' => $this->contactoDe($otro)->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);
    }

    public function test_historial_devuelve_contacto_con_nombre_y_cargo(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $contacto = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'nombre' => 'Ana López', 'cargo' => 'Compras', 'es_principal' => true,
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->oportunidad->id,
            'tipo' => 'llamada', 'descripcion' => 'Llamada', 'fecha_actividad' => '2026-09-14 09:00:00',
            'vendedor_id' => $this->propio->id, 'contacto_id' => $contacto->id,
        ]);

        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/oportunidad/{$this->oportunidad->id}/historial")
            ->assertOk()
            ->assertJsonPath('data.0.contacto', ['id' => $contacto->id, 'nombre' => 'Ana López', 'cargo' => 'Compras']);

        // También en la ficha del cliente (que trae las actividades de sus oportunidades).
        $this->withHeaders($this->crmHeaders())->getJson("/api/crm/cliente/{$this->cliente->id}/historial")
            ->assertOk()
            ->assertJsonPath('data.0.contacto.nombre', 'Ana López');
    }
}
