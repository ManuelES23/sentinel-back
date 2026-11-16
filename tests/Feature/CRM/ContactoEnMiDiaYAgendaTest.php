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
use App\Services\CRM\SeguimientoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contacto con quien se habló en «Nueva actividad» de Mi día y en la agenda
 * (CRM SBP: prospectos como empresas). Con la bandera apagada nada cambia.
 */
class ContactoEnMiDiaYAgendaTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');

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
        CarbonImmutable::setTestNow();
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

    private function ajeno(): CrmContacto
    {
        $otro = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro Cliente', 'estatus' => 'activo']);

        return $this->contactoDe($otro, 'Ajeno');
    }

    // ---- POST crm/mi-dia/actividades ----

    private function eventoMiDia(): array
    {
        return [
            'tipo' => 'llamada', 'titulo' => 'Llamar para cotizar',
            'fecha_inicio' => '2026-09-15 09:00:00', 'etiqueta_id' => $this->etiqueta->id,
        ];
    }

    private function actividadMiDia(array $datos = []): array
    {
        return array_merge([
            'tipo' => 'llamada', 'descripcion' => 'Habló con el comprador', 'etiqueta_id' => $this->etiqueta->id,
        ], $datos);
    }

    private function nueva(array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/mi-dia/actividades', $datos);
    }

    public function test_nueva_actividad_con_prospecto_nuevo_y_contacto_nuevo_crea_empresa_contacto_y_actividad(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([ActividadUpdated::class, ContactoUpdated::class]);

        $respuesta = $this->nueva([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'actividad' => $this->actividadMiDia(),
            'contacto_nuevo' => ['nombre' => 'Marta Ríos', 'cargo' => 'Gerente', 'telefono' => '6671234567'],
        ])->assertCreated();

        $prospecto = CrmProspecto::firstOrFail();
        $contacto = CrmContacto::firstOrFail();
        $this->assertSame(CrmProspecto::class, $contacto->entidad_type);
        $this->assertSame($prospecto->id, $contacto->entidad_id);
        $this->assertSame('Marta Ríos', $contacto->nombre);
        $this->assertTrue($contacto->es_principal);

        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
        $respuesta->assertJsonPath('data.actividad.contacto.nombre', 'Marta Ríos')
            ->assertJsonPath('data.actividad.contacto.cargo', 'Gerente');

        Event::assertDispatched(ContactoUpdated::class, fn ($e) => $e->action === 'created' && $e->data['nombre'] === 'Marta Ríos');
        Event::assertDispatched(ActividadUpdated::class, fn ($e) => $e->data['contacto']['id'] === $contacto->id);
    }

    public function test_nueva_actividad_con_prospecto_nuevo_y_contacto_nuevo_en_un_evento_liga_el_contacto_al_evento(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([AgendaUpdated::class, ContactoUpdated::class]);

        $respuesta = $this->nueva([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'evento' => $this->eventoMiDia(),
            'contacto_nuevo' => ['nombre' => 'Marta Ríos'],
        ])->assertCreated();

        $contacto = CrmContacto::firstOrFail();
        $this->assertSame($contacto->id, CrmAgenda::firstOrFail()->contacto_id);
        $respuesta->assertJsonPath('data.evento.contacto.id', $contacto->id);
        Event::assertDispatched(AgendaUpdated::class, fn ($e) => $e->data['contacto']['id'] === $contacto->id);
        Event::assertDispatched(ContactoUpdated::class);
    }

    public function test_nueva_actividad_con_prospecto_nuevo_sin_contacto_con_bandera_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->nueva([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'actividad' => $this->actividadMiDia(),
        ])->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);

        $this->assertSame(0, CrmProspecto::count());
        $this->assertSame(0, CrmActividad::count());
        $this->assertSame(1, CrmOportunidad::count());
    }

    public function test_nueva_actividad_con_prospecto_nuevo_y_contacto_id_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $ajeno = $this->ajeno();

        $this->nueva([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'actividad' => $this->actividadMiDia(),
            'contacto_id' => $ajeno->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);

        $this->assertSame(0, CrmProspecto::count());
    }

    public function test_nueva_actividad_con_cliente_elige_contacto_existente_y_ajeno_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $contacto = $this->contactoDe($this->cliente);

        $this->nueva([
            'oportunidad_id' => $this->oportunidad->id,
            'actividad' => $this->actividadMiDia(),
            'contacto_id' => $this->ajeno()->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);
        $this->assertSame(0, CrmActividad::count());

        $this->nueva([
            'oportunidad_id' => $this->oportunidad->id,
            'actividad' => $this->actividadMiDia(),
            'contacto_id' => $contacto->id,
        ])->assertCreated();
        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_nueva_actividad_solo_evento_no_exige_contacto(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->nueva([
            'oportunidad_id' => $this->oportunidad->id,
            'evento' => $this->eventoMiDia(),
        ])->assertCreated();

        $this->assertNull(CrmAgenda::firstOrFail()->contacto_id);
    }

    public function test_nueva_actividad_nota_sin_contacto_pasa(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        $this->nueva([
            'oportunidad_id' => $this->oportunidad->id,
            'actividad' => $this->actividadMiDia(['tipo' => 'nota']),
        ])->assertCreated();

        $this->assertNull(CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_nueva_actividad_bandera_apagada_sin_contacto_pasa_como_hoy(): void
    {
        $this->otorgarTodosLosPermisosCrm();

        $this->nueva([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'actividad' => $this->actividadMiDia(),
        ])->assertCreated();

        $this->assertNull(CrmActividad::firstOrFail()->contacto_id);
        $this->assertSame(0, CrmContacto::count());
    }

    public function test_un_contacto_creado_en_linea_no_queda_ni_se_difunde_si_algo_falla_despues(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([ContactoUpdated::class, ActividadUpdated::class]);

        // El contacto ya está escrito cuando falla el registro de la actividad.
        $this->app->bind(SeguimientoService::class, fn () => new class extends SeguimientoService
        {
            public function crearActividad(int $empresaId, int $vendedorId, Model $entidad, array $datos): CrmActividad
            {
                throw new RuntimeException('falla simulada');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->nueva([
                'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
                'tipo_producto' => 'Pepper',
                'actividad' => $this->actividadMiDia(),
                'contacto_nuevo' => ['nombre' => 'Marta Ríos'],
            ]);
            $this->fail('Debió lanzar la falla simulada.');
        } catch (RuntimeException $e) {
            $this->assertSame('falla simulada', $e->getMessage());
        }

        $this->assertSame(0, CrmContacto::count());
        $this->assertSame(0, CrmProspecto::count());
        Event::assertNotDispatched(ContactoUpdated::class);
    }

    // ---- POST/PUT crm/agenda ----

    private function cuerpoAgenda(array $datos = []): array
    {
        return array_merge([
            'tipo' => 'llamada', 'titulo' => 'Llamar',
            'fecha_inicio' => '2026-09-20 09:00:00', 'fecha_fin' => '2026-09-20 09:30:00',
            'vendedor_id' => $this->propio->id,
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'etiqueta_id' => $this->etiqueta->id,
        ], $datos);
    }

    public function test_agenda_store_sin_contacto_pasa_y_con_contacto_ajeno_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();

        // Nunca obligatorio, ni con la bandera encendida y un tipo que lo exige en una actividad.
        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', $this->cuerpoAgenda())
            ->assertCreated()
            ->assertJsonPath('data.contacto', null);
        $this->assertNull(CrmAgenda::firstOrFail()->contacto_id);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', $this->cuerpoAgenda(['contacto_id' => $this->ajeno()->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);
        $this->assertSame(1, CrmAgenda::count());
    }

    public function test_agenda_store_con_contacto_existente_o_nuevo_lo_liga(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([AgendaUpdated::class, ContactoUpdated::class]);
        $contacto = $this->contactoDe($this->cliente);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', $this->cuerpoAgenda(['contacto_id' => $contacto->id]))
            ->assertCreated()
            ->assertJsonPath('data.contacto.id', $contacto->id)
            ->assertJsonPath('data.contacto.nombre', 'Ana');
        Event::assertNotDispatched(ContactoUpdated::class);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', $this->cuerpoAgenda(['contacto_nuevo' => ['nombre' => 'Luis Gómez']]))
            ->assertCreated()
            ->assertJsonPath('data.contacto.nombre', 'Luis Gómez');

        $nuevo = CrmContacto::where('nombre', 'Luis Gómez')->firstOrFail();
        $this->assertFalse($nuevo->es_principal);
        $this->assertSame($this->cliente->id, $nuevo->entidad_id);
        Event::assertDispatched(ContactoUpdated::class, fn ($e) => $e->data['nombre'] === 'Luis Gómez');
    }

    public function test_agenda_store_con_contacto_y_sin_entidad_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $contacto = $this->contactoDe($this->cliente);

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', $this->cuerpoAgenda([
            'entidad_tipo' => null, 'entidad_id' => null, 'etiqueta_id' => null, 'contacto_id' => $contacto->id,
        ]))->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', 'Este registro no tiene empresa para ligar un contacto.');
    }

    private function eventoDeAgenda(array $datos = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $this->oportunidad->id,
            'tipo' => 'llamada',
            'titulo' => 'Llamar',
            'fecha_inicio' => '2026-09-14 09:00:00',
            'fecha_fin' => '2026-09-14 09:30:00',
            'etiqueta_id' => $this->etiqueta->id,
        ], $datos));
    }

    public function test_agenda_update_cambia_valida_y_conserva_el_contacto(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $contacto = $this->contactoDe($this->cliente);
        $evento = $this->eventoDeAgenda();

        // Sin mandar contacto, el del evento no se toca.
        $evento->update(['contacto_id' => $contacto->id]);
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['titulo' => 'Otro título'])
            ->assertOk()
            ->assertJsonPath('data.contacto.id', $contacto->id);

        // Ajeno: 422 y no cambia nada.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['contacto_id' => $this->ajeno()->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);
        $this->assertSame($contacto->id, $evento->fresh()->contacto_id);

        // Contacto nuevo: se crea y se liga.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['contacto_nuevo' => ['nombre' => 'Luis Gómez']])
            ->assertOk()
            ->assertJsonPath('data.contacto.nombre', 'Luis Gómez');
        $this->assertSame(CrmContacto::where('nombre', 'Luis Gómez')->value('id'), $evento->fresh()->contacto_id);

        // Quitar el contacto: nunca es obligatorio.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['contacto_id' => null])
            ->assertOk()
            ->assertJsonPath('data.contacto', null);
        $this->assertNull($evento->fresh()->contacto_id);
    }

    // ---- PATCH crm/agenda/{id}/completar ----

    private function completar(CrmAgenda $evento, array $body = [])
    {
        return $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/agenda/{$evento->id}/completar", $body);
    }

    public function test_completar_hereda_contacto_del_evento(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $contacto = $this->contactoDe($this->cliente);
        $evento = $this->eventoDeAgenda(['contacto_id' => $contacto->id]);

        $this->completar($evento)->assertOk();

        $actividad = CrmActividad::firstOrFail();
        $this->assertSame('llamada', $actividad->tipo);
        $this->assertSame($contacto->id, $actividad->contacto_id);
        $this->assertSame($contacto->id, $evento->fresh()->contacto_id);
    }

    public function test_completar_con_contacto_en_el_cuerpo_lo_guarda_en_actividad_y_evento(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        Event::fake([AgendaUpdated::class, ContactoUpdated::class]);
        $evento = $this->eventoDeAgenda();

        $this->completar($evento, ['contacto_nuevo' => ['nombre' => 'Luis Gómez', 'cargo' => 'Compras']])
            ->assertOk()
            ->assertJsonPath('data.contacto.nombre', 'Luis Gómez');

        $contacto = CrmContacto::firstOrFail();
        $this->assertSame($contacto->id, CrmActividad::firstOrFail()->contacto_id);
        $this->assertSame($contacto->id, $evento->fresh()->contacto_id);
        Event::assertDispatched(ContactoUpdated::class, fn ($e) => $e->data['nombre'] === 'Luis Gómez');
        Event::assertDispatched(AgendaUpdated::class, fn ($e) => $e->action === 'completed' && $e->data['contacto']['id'] === $contacto->id);
    }

    public function test_completar_con_contacto_del_cuerpo_gana_sobre_el_del_evento(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $delEvento = $this->contactoDe($this->cliente, 'Del evento');
        $otro = CrmContacto::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'nombre' => 'Otro del cliente', 'es_principal' => false,
        ]);
        $evento = $this->eventoDeAgenda(['contacto_id' => $delEvento->id]);

        $this->completar($evento, ['contacto_id' => $otro->id])->assertOk();

        $this->assertSame($otro->id, CrmActividad::firstOrFail()->contacto_id);
        $this->assertSame($otro->id, $evento->fresh()->contacto_id);
    }

    public function test_completar_con_siguiente_paso_lo_programa_con_el_mismo_contacto(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $contacto = $this->contactoDe($this->cliente);
        $evento = $this->eventoDeAgenda(['contacto_id' => $contacto->id]);

        $this->completar($evento, ['siguiente' => [
            'tipo' => 'llamada', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-09-20 09:00:00', 'etiqueta_id' => $this->etiqueta->id,
        ]])->assertOk()
            ->assertJsonPath('data.siguiente_evento.contacto.id', $contacto->id);
    }

    public function test_completar_sin_contacto_con_bandera_y_llamada_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $evento = $this->eventoDeAgenda();

        $this->completar($evento)
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_FALTA);

        $this->assertFalse($evento->fresh()->completado);
        $this->assertSame(0, CrmActividad::count());

        // El tipo elegido al completar también cuenta: una tarea registrada como llamada lo exige.
        $tarea = $this->eventoDeAgenda(['tipo' => 'tarea']);
        $this->completar($tarea, ['actividad_tipo' => 'llamada'])->assertStatus(422);
    }

    public function test_completar_con_contacto_ajeno_responde_422(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $evento = $this->eventoDeAgenda();

        $this->completar($evento, ['contacto_id' => $this->ajeno()->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.contacto_id.0', self::MENSAJE_AJENO);
        $this->assertFalse($evento->fresh()->completado);
    }

    public function test_completar_tarea_sin_contacto_pasa(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $evento = $this->eventoDeAgenda(['tipo' => 'tarea']);

        $this->completar($evento)->assertOk();

        $actividad = CrmActividad::firstOrFail();
        $this->assertSame('nota', $actividad->tipo);
        $this->assertNull($actividad->contacto_id);
    }

    public function test_completar_bandera_apagada_sin_contacto_pasa_como_hoy(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $evento = $this->eventoDeAgenda();

        $this->completar($evento)->assertOk();

        $this->assertNull(CrmActividad::firstOrFail()->contacto_id);
    }

    public function test_completar_evento_sin_entidad_no_exige_contacto(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $this->encender();
        $evento = $this->eventoDeAgenda(['entidad_type' => null, 'entidad_id' => null, 'etiqueta_id' => null]);

        $this->completar($evento)->assertOk();

        $this->assertSame(0, CrmActividad::count());
    }
}
