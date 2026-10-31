<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use App\Services\CRM\SeguimientoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Spec 2026-10-30 §7-§8: toda captura nueva vive dentro de una oportunidad.
 * Agenda, actividades y seguimientos dejan de aceptar cliente o prospecto
 * directo (422), exigen etiqueta cuando la captura es sobre una oportunidad y
 * siguen aceptando empresa externa y, en agenda, sin entidad.
 */
class CapturaExigeOportunidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MENSAJE_CLIENTE = 'Elige una oportunidad o abre una nueva para este cliente.';

    private const MENSAJE_PROSPECTO = 'Elige una oportunidad o abre una nueva para este prospecto.';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmProspecto $prospecto;

    private CrmOportunidad $oportunidad;

    private CrmEtiquetaActividad $etiqueta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->otorgarTodosLosPermisosCrm();

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'La Huerta', 'estatus' => 'activo']);
        $this->prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Nuevo', 'estatus' => 'nuevo']);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'La Huerta · Pepper', 'monto_esperado' => 1000,
        ]);
        $this->etiqueta = $this->etiqueta();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function etiqueta(array $datos = [], ?int $empresaId = null): CrmEtiquetaActividad
    {
        return CrmEtiquetaActividad::create(array_merge([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => 'Seguimiento '.uniqid(),
        ], $datos));
    }

    private function agenda(array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', array_merge([
            'tipo' => 'llamada', 'titulo' => 'Llamar',
            'fecha_inicio' => '2026-09-15 09:00:00', 'fecha_fin' => '2026-09-15 09:30:00',
            'vendedor_id' => $this->propio->id,
        ], $datos));
    }

    private function actividad(array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/actividades', array_merge([
            'tipo' => 'llamada', 'descripcion' => 'Habló con el comprador', 'fecha_actividad' => '2026-09-14 09:00:00',
        ], $datos));
    }

    private function seguimiento(array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/seguimientos', $datos);
    }

    private function eventoDe(CrmCliente|CrmProspecto|CrmOportunidad|CrmEmpresaExterna|null $entidad, array $datos = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id,
            'entidad_type' => $entidad ? $entidad::class : null, 'entidad_id' => $entidad?->id,
            'tipo' => 'llamada', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-09-14 09:00:00', 'fecha_fin' => '2026-09-14 09:30:00',
        ], $datos));
    }

    private function completar(CrmAgenda $evento, array $body = [])
    {
        return $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/agenda/{$evento->id}/completar", $body);
    }

    // ---- Cliente o prospecto directo: 422 ----

    public function test_agenda_con_cliente_422(): void
    {
        $this->agenda(['entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id, 'etiqueta_id' => $this->etiqueta->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entidad_tipo')
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_CLIENTE);

        $this->assertDatabaseCount('crm_agenda', 0);
    }

    public function test_agenda_con_prospecto_422(): void
    {
        $this->agenda(['entidad_tipo' => 'prospecto', 'entidad_id' => $this->prospecto->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_PROSPECTO);

        $this->assertDatabaseCount('crm_agenda', 0);
    }

    public function test_actividad_con_cliente_422(): void
    {
        $this->actividad(['entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_CLIENTE);
        $this->actividad(['entidad_tipo' => 'prospecto', 'entidad_id' => $this->prospecto->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_PROSPECTO);

        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_seguimiento_con_cliente_422(): void
    {
        $cuerpo = ['actividad' => ['tipo' => 'llamada', 'descripcion' => 'Habló con el comprador']];

        $this->seguimiento(['entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id] + $cuerpo)
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_CLIENTE);
        $this->seguimiento(['entidad_tipo' => 'prospecto', 'entidad_id' => $this->prospecto->id] + $cuerpo)
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_PROSPECTO);

        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_exigir_captura_con_oportunidad_deja_pasar_oportunidad_externa_y_nulo(): void
    {
        $servicio = app(SeguimientoService::class);

        foreach (['oportunidad', 'empresa_externa', null] as $tipo) {
            $servicio->exigirCapturaConOportunidad($tipo);
        }
        $this->assertSame(['empresa_externa'], SeguimientoService::ENTIDADES_SIN_OPORTUNIDAD);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $servicio->exigirCapturaConOportunidad('cliente', 'actividad.entidad_tipo');
    }

    public function test_editar_un_evento_viejo_ligado_a_cliente_sigue_funcionando(): void
    {
        $evento = $this->eventoDe($this->cliente);

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['titulo' => 'Nuevo título'])
            ->assertOk();

        $this->assertSame('Nuevo título', $evento->fresh()->titulo);
    }

    public function test_cambiar_un_evento_a_cliente_o_prospecto_422(): void
    {
        $evento = $this->eventoDe(null);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$evento->id}", ['entidad_tipo' => 'cliente', 'entidad_id' => $this->cliente->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.entidad_tipo.0', self::MENSAJE_CLIENTE);

        $this->assertNull($evento->fresh()->entidad_type);
    }

    // ---- Lo que sigue aceptado ----

    public function test_empresa_externa_sigue_aceptada(): void
    {
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Aduana MX']);

        $this->agenda(['entidad_tipo' => 'empresa_externa', 'entidad_id' => $externa->id])->assertCreated();
        $this->actividad(['entidad_tipo' => 'empresa_externa', 'entidad_id' => $externa->id])->assertCreated();
        $this->seguimiento([
            'entidad_tipo' => 'empresa_externa', 'entidad_id' => $externa->id,
            'actividad' => ['tipo' => 'llamada', 'descripcion' => 'Se llamó'],
            'siguiente' => ['tipo' => 'llamada', 'titulo' => 'Otra', 'fecha_inicio' => '2026-09-16 09:00:00'],
        ])->assertCreated();

        $this->assertDatabaseCount('crm_agenda', 2);
        $this->assertDatabaseCount('crm_actividades', 2);
    }

    public function test_agenda_sin_entidad_sigue_aceptada(): void
    {
        $this->agenda()->assertCreated()->assertJsonPath('data.entidad_type', null);
    }

    public function test_oportunidad_se_acepta_en_los_tres_endpoints_con_etiqueta(): void
    {
        $this->agenda(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $this->etiqueta->id])
            ->assertCreated()
            ->assertJsonPath('data.etiqueta.id', $this->etiqueta->id)
            ->assertJsonPath('data.etiqueta.nombre', $this->etiqueta->nombre);
        $this->actividad(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $this->etiqueta->id])
            ->assertCreated()
            ->assertJsonPath('data.etiqueta.id', $this->etiqueta->id);
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => ['tipo' => 'llamada', 'descripcion' => 'Se llamó', 'etiqueta_id' => $this->etiqueta->id],
            'siguiente' => ['tipo' => 'visita', 'titulo' => 'Visita', 'fecha_inicio' => '2026-09-16 09:00:00', 'etiqueta_id' => $this->etiqueta->id],
        ])->assertCreated();

        $this->assertSame(2, CrmActividad::where('etiqueta_id', $this->etiqueta->id)->count());
        $this->assertSame(2, CrmAgenda::where('etiqueta_id', $this->etiqueta->id)->count());
    }

    public function test_whatsapp_enlace_no_exige_etiqueta(): void
    {
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => ['tipo' => 'whatsapp', 'descripcion' => 'Se envió el mensaje', 'fuente' => 'whatsapp_enlace'],
        ])->assertCreated();

        $this->assertDatabaseHas('crm_actividades', ['fuente' => 'whatsapp_enlace', 'etiqueta_id' => null]);

        // Sin esa fuente, la misma actividad sí la exige.
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => ['tipo' => 'whatsapp', 'descripcion' => 'Se envió el mensaje'],
        ])->assertStatus(422)->assertJsonValidationErrors('actividad.etiqueta_id');
    }

    // ---- Etiqueta ----

    public function test_etiqueta_obligatoria_con_oportunidad(): void
    {
        $this->agenda(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.etiqueta_id.0', 'Elige una etiqueta válida.');
        $this->actividad(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('etiqueta_id');
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => ['tipo' => 'llamada', 'descripcion' => 'Se llamó'],
        ])->assertStatus(422)->assertJsonValidationErrors('actividad.etiqueta_id');
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'siguiente' => ['tipo' => 'visita', 'titulo' => 'Visita', 'fecha_inicio' => '2026-09-16 09:00:00'],
        ])->assertStatus(422)->assertJsonValidationErrors('siguiente.etiqueta_id');

        $this->assertDatabaseCount('crm_agenda', 0);
        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_etiqueta_de_otra_empresa_422(): void
    {
        $ajena = $this->etiqueta(['nombre' => 'Ajena'], $this->crearOtraEmpresa()->id);

        $this->agenda(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $ajena->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.etiqueta_id.0', 'Elige una etiqueta válida.');
        $this->actividad(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $ajena->id])
            ->assertStatus(422);
        // También donde la etiqueta es opcional: se valida si viene.
        $this->agenda(['etiqueta_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('etiqueta_id');
    }

    public function test_etiqueta_inactiva_no_se_acepta_al_crear(): void
    {
        $inactiva = $this->etiqueta(['nombre' => 'Apagada', 'activo' => false]);

        $this->agenda(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $inactiva->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.etiqueta_id.0', 'Elige una etiqueta válida.');
        $this->actividad(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id, 'etiqueta_id' => $inactiva->id])
            ->assertStatus(422);
        $this->seguimiento([
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $this->oportunidad->id,
            'actividad' => ['tipo' => 'llamada', 'descripcion' => 'Se llamó', 'etiqueta_id' => $inactiva->id],
        ])->assertStatus(422)->assertJsonValidationErrors('actividad.etiqueta_id');
    }

    public function test_etiqueta_opcional_en_evento_sin_entidad(): void
    {
        $this->agenda(['etiqueta_id' => $this->etiqueta->id])->assertCreated()->assertJsonPath('data.etiqueta_id', $this->etiqueta->id);
    }

    public function test_editar_un_evento_de_oportunidad_exige_etiqueta_si_la_manda(): void
    {
        $evento = $this->eventoDe($this->oportunidad);

        // Un evento viejo sin etiqueta se puede editar sin tocarla.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['titulo' => 'Otro'])->assertOk();
        // Si la manda, debe ser válida y no puede quedar vacía.
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['etiqueta_id' => null])
            ->assertStatus(422)->assertJsonValidationErrors('etiqueta_id');
        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$evento->id}", ['etiqueta_id' => $this->etiqueta->id])
            ->assertOk()->assertJsonPath('data.etiqueta.id', $this->etiqueta->id);
    }

    // ---- Completar ----

    public function test_completar_hereda_etiqueta(): void
    {
        $evento = $this->eventoDe($this->oportunidad, ['etiqueta_id' => $this->etiqueta->id]);

        $this->completar($evento, ['resultado' => 'Aceptó'])->assertOk()
            ->assertJsonPath('data.etiqueta.id', $this->etiqueta->id);

        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->oportunidad->id,
            'fuente' => 'agenda', 'etiqueta_id' => $this->etiqueta->id,
        ]);
    }

    public function test_evento_con_etiqueta_inactiva_se_completa(): void
    {
        $evento = $this->eventoDe($this->oportunidad, ['etiqueta_id' => $this->etiqueta->id]);
        $this->etiqueta->update(['activo' => false]);

        $this->completar($evento)->assertOk();

        $this->assertTrue($evento->fresh()->completado);
        $this->assertDatabaseHas('crm_actividades', ['fuente' => 'agenda', 'etiqueta_id' => $this->etiqueta->id]);
    }

    public function test_siguiente_al_completar_exige_etiqueta_con_oportunidad(): void
    {
        $evento = $this->eventoDe($this->oportunidad, ['etiqueta_id' => $this->etiqueta->id]);
        $siguiente = ['tipo' => 'visita', 'titulo' => 'Visita', 'fecha_inicio' => '2026-09-16 09:00:00'];

        $this->completar($evento, ['siguiente' => $siguiente])
            ->assertStatus(422)->assertJsonValidationErrors('siguiente.etiqueta_id');
        $this->assertFalse($evento->fresh()->completado);

        $this->completar($evento, ['siguiente' => $siguiente + ['etiqueta_id' => $this->etiqueta->id]])
            ->assertOk()
            ->assertJsonPath('data.siguiente_evento.etiqueta_id', $this->etiqueta->id);
    }

    public function test_evento_con_cliente_se_completa_sin_siguiente(): void
    {
        $evento = $this->eventoDe($this->cliente);

        $this->completar($evento, ['resultado' => 'Listo'])->assertOk();

        $this->assertTrue($evento->fresh()->completado);
        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id, 'etiqueta_id' => null,
        ]);
    }

    public function test_evento_con_cliente_y_siguiente_422(): void
    {
        $evento = $this->eventoDe($this->cliente);

        $this->completar($evento, ['siguiente' => [
            'tipo' => 'visita', 'titulo' => 'Visita', 'fecha_inicio' => '2026-09-16 09:00:00',
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.siguiente.0', 'Liga primero el evento a una oportunidad para programar el siguiente paso.');

        $this->assertFalse($evento->fresh()->completado);
        $this->assertDatabaseCount('crm_actividades', 0);
        $this->assertDatabaseCount('crm_agenda', 1);
    }

    // ---- Lo que no cambia ----

    public function test_registros_de_sistema_y_dialpad_no_cambian(): void
    {
        // Prospecto rápido con primer contacto: evento ligado al prospecto, sin etiqueta.
        $respuesta = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/prospectos/rapido', [
            'nombre' => 'Rancho Rápido',
            'primer_contacto' => ['tipo' => 'llamada', 'titulo' => 'Primer contacto', 'fecha_inicio' => '2026-09-15 09:00:00'],
        ])->assertCreated();
        $this->assertDatabaseHas('crm_agenda', [
            'entidad_type' => CrmProspecto::class, 'entidad_id' => $respuesta->json('data.prospecto.id'), 'etiqueta_id' => null,
        ]);

        // Convertir a cliente: la nota de sistema queda en el cliente.
        $convertido = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Por convertir', 'estatus' => 'nuevo']);
        $clienteId = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/prospectos/{$convertido->id}/convertir-cliente")
            ->assertSuccessful()->json('data.id');
        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $clienteId, 'fuente' => 'sistema', 'etiqueta_id' => null,
        ]);

        // Sincronización de Dialpad: la llamada queda ligada al cliente.
        config(['services.dialpad.api_key' => 'fake-api-key', 'services.dialpad.base_url' => 'https://dialpad.test/api/v2']);
        $this->vendedor->update(['email' => 'juan.perez@example.com']);
        $this->cliente->update(['telefono' => '6621234567']);
        Http::fake(['dialpad.test/api/v2/call*' => Http::response(['items' => [[
            'call_id' => 'call-captura', 'direction' => 'inbound', 'duration' => 240000,
            'date_started' => now()->subMinutes(10)->valueOf(),
            'target' => ['email' => 'juan.perez@example.com'], 'contact' => ['phone' => '6621234567'],
        ]], 'cursor' => null], 200)]);
        $this->artisan('crm:sincronizar-dialpad')->assertExitCode(0);

        $this->assertDatabaseHas('crm_actividades', [
            'dialpad_call_id' => 'call-captura', 'fuente' => 'dialpad',
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id, 'etiqueta_id' => null,
        ]);
        $this->assertSame(1, CrmOportunidad::count(), 'Ninguna sincronización ni registro de sistema abre oportunidades.');
    }

    // ---- Ligar un evento a una oportunidad ----

    private function ligar(CrmAgenda $evento, int $oportunidadId)
    {
        return $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/agenda/{$evento->id}/ligar", ['oportunidad_id' => $oportunidadId]);
    }

    public function test_ligar_pone_la_oportunidad(): void
    {
        $evento = $this->eventoDe($this->cliente);

        $this->ligar($evento, $this->oportunidad->id)
            ->assertOk()
            ->assertJsonPath('data.entidad_type', CrmOportunidad::class)
            ->assertJsonPath('data.entidad_id', $this->oportunidad->id)
            ->assertJsonPath('data.vendedor.id', $this->propio->id);

        $this->assertSame(CrmOportunidad::class, $evento->fresh()->entidad_type);
        $this->assertSame($this->oportunidad->id, (int) $evento->fresh()->entidad_id);
    }

    public function test_ligar_un_evento_sin_entidad_a_cualquier_oportunidad_visible(): void
    {
        $this->ligar($this->eventoDe(null), $this->oportunidad->id)->assertOk();
    }

    public function test_ligar_difunde_el_evento_actualizado(): void
    {
        Event::fake([AgendaUpdated::class]);

        $this->ligar($this->eventoDe($this->cliente), $this->oportunidad->id)->assertOk();

        Event::assertDispatched(AgendaUpdated::class);
    }

    public function test_ligar_exige_permiso_editar(): void
    {
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'crear']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);

        $this->ligar($this->eventoDe($this->cliente), $this->oportunidad->id)->assertForbidden();
    }

    public function test_ligar_oportunidad_ajena_404(): void
    {
        // Sin equipo solo se ven las propias: la de otro vendedor responde como inexistente.
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'editar']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena',
        ]);
        $evento = $this->eventoDe($this->cliente);

        $this->ligar($evento, $ajena->id)->assertNotFound();
        $this->ligar($evento, 999999)->assertNotFound();

        $this->assertSame(CrmCliente::class, $evento->fresh()->entidad_type);
    }

    public function test_ligar_oportunidad_de_otra_empresa_404(): void
    {
        $ajena = CrmOportunidad::create([
            'empresa_id' => $this->crearOtraEmpresa()->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'De otra empresa',
        ]);

        $this->ligar($this->eventoDe(null), $ajena->id)->assertNotFound();
    }

    public function test_ligar_perdida_422(): void
    {
        $this->oportunidad->update(['etapa' => 'cerrado_perdido']);

        $this->ligar($this->eventoDe($this->cliente), $this->oportunidad->id)
            ->assertStatus(422)->assertJsonValidationErrors('oportunidad_id');
    }

    public function test_ligar_un_evento_que_ya_tiene_oportunidad_o_es_de_empresa_externa_422(): void
    {
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Aduana MX']);

        $this->ligar($this->eventoDe($this->oportunidad), $this->oportunidad->id)
            ->assertStatus(422)->assertJsonValidationErrors('oportunidad_id');
        $this->ligar($this->eventoDe($externa), $this->oportunidad->id)
            ->assertStatus(422)->assertJsonValidationErrors('oportunidad_id');
    }

    public function test_ligar_una_oportunidad_de_otro_cliente_o_prospecto_422(): void
    {
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'estatus' => 'activo']);

        $this->ligar($this->eventoDe($otroCliente), $this->oportunidad->id)
            ->assertStatus(422)->assertJsonValidationErrors('oportunidad_id');
        $this->ligar($this->eventoDe($this->prospecto), $this->oportunidad->id)
            ->assertStatus(422)->assertJsonValidationErrors('oportunidad_id');
    }

    public function test_ligar_un_evento_de_prospecto_convertido_a_la_oportunidad_de_su_cliente(): void
    {
        $convertido = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Convertido', 'estatus' => 'calificado']);
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $convertido->id, 'nombre' => 'Convertido SA', 'estatus' => 'activo',
        ]);
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Convertido SA',
        ]);

        $this->ligar($this->eventoDe($convertido), $oportunidad->id)->assertOk();
    }

    public function test_ligar_a_una_oportunidad_de_su_prospecto(): void
    {
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'prospecto_id' => $this->prospecto->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Rancho Nuevo',
        ]);

        $this->ligar($this->eventoDe($this->prospecto), $oportunidad->id)->assertOk();
    }

    public function test_ligar_el_evento_de_otro_vendedor_sin_equipo_403(): void
    {
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'editar']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $evento = $this->eventoDe($this->cliente, ['vendedor_id' => $this->vendedor->id]);

        $this->ligar($evento, $this->oportunidad->id)->assertForbidden();
    }
}
