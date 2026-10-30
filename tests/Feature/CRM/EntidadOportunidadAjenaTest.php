<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Actividades, agenda y seguimientos que se cuelgan de una oportunidad exigen
 * poder VERLA (VisibilidadOportunidades). Para el vendedor, una oportunidad
 * ajena responde exactamente igual que una inexistente (cada endpoint con su
 * respuesta de siempre: 404 en actividades y agenda, 422 en seguimientos), así
 * que no sirve para averiguar si existe. Las lecturas de actividades e
 * historial ocultan las actividades de oportunidades ajenas.
 */
class EntidadOportunidadAjenaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MENSAJE_ENTIDAD = 'La entidad relacionada no existe o no pertenece a la empresa.';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmOportunidad $propia;

    private CrmOportunidad $ajena;

    private CrmEtiquetaActividad $etiqueta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        $this->propia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia', 'etapa' => 'calificado',
        ]);
        $this->ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena', 'etapa' => 'calificado',
        ]);

        // Sobre una oportunidad la etiqueta es obligatoria: todos los cuerpos la mandan para que
        // la visibilidad sea lo único que distinga una respuesta de otra.
        $this->etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Seguimiento']);

        // Perfil vendedor: ve oportunidades (solo las suyas), sin equipo.
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->otorgarPermisosCrm('actividades', 'actividades', ['ver', 'crear', 'editar', 'eliminar']);
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'crear', 'editar']);
    }

    private function conEquipoDeOportunidades(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);
    }

    private function enviar(string $metodo, string $uri, array $datos = [])
    {
        return $this->json($metodo, '/api/crm/'.$uri, $datos, $this->crmHeaders());
    }

    /** Compara dos respuestas: mismo status y mismo cuerpo. */
    private function assertMismaRespuesta($esperada, $obtenida): void
    {
        $this->assertSame($esperada->status(), $obtenida->status());
        $this->assertSame($esperada->json(), $obtenida->json());
    }

    private function actividadEn($entidad, string $descripcion): CrmActividad
    {
        return CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'tipo' => 'nota', 'descripcion' => $descripcion, 'fecha_actividad' => now(), 'fuente' => 'manual',
        ]);
    }

    // ── POST crm/agenda ───────────────────────────────────────────────────

    private function evento(array $datos)
    {
        return $this->enviar('POST', 'agenda', array_merge([
            'tipo' => 'visita', 'titulo' => 'Nuevo evento',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->propio->id, 'etiqueta_id' => $this->etiqueta->id,
        ], $datos));
    }

    public function test_agenda_no_liga_un_evento_a_una_oportunidad_ajena_y_responde_como_si_no_existiera(): void
    {
        Event::fake([AgendaUpdated::class]);

        $ajena = $this->evento(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->ajena->id]);
        $inexistente = $this->evento(['entidad_tipo' => 'oportunidad', 'entidad_id' => 999999]);

        $ajena->assertNotFound()->assertJsonPath('message', self::MENSAJE_ENTIDAD);
        $this->assertMismaRespuesta($inexistente, $ajena);
        $this->assertSame(0, CrmAgenda::count());
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_agenda_liga_eventos_a_la_oportunidad_propia(): void
    {
        $this->evento(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->propia->id])->assertCreated();

        $this->assertDatabaseHas('crm_agenda', ['entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->propia->id]);
    }

    public function test_agenda_sin_oportunidades_ver_no_liga_eventos_a_oportunidades(): void
    {
        \App\Models\UserSubmodulePermission::whereHas('permissionType', fn ($q) => $q->where('slug', 'ver'))
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->delete();

        $this->evento(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->propia->id])->assertNotFound();
        $this->assertSame(0, CrmAgenda::count());
    }

    public function test_agenda_con_equipo_de_oportunidades_liga_eventos_a_cualquier_oportunidad(): void
    {
        $this->conEquipoDeOportunidades();

        $this->evento(['entidad_tipo' => 'oportunidad', 'entidad_id' => $this->ajena->id])->assertCreated();
    }

    // ── POST crm/actividades ──────────────────────────────────────────────

    private function actividad(int $oportunidadId)
    {
        return $this->enviar('POST', 'actividades', [
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidadId, 'tipo' => 'llamada',
            'descripcion' => 'Llamada', 'fecha_actividad' => now()->toDateTimeString(),
            'etiqueta_id' => $this->etiqueta->id,
        ]);
    }

    public function test_actividades_no_registra_sobre_una_oportunidad_ajena_y_responde_como_si_no_existiera(): void
    {
        Event::fake([ActividadUpdated::class]);

        $ajena = $this->actividad($this->ajena->id);
        $inexistente = $this->actividad(999999);

        $ajena->assertNotFound()->assertJsonPath('message', self::MENSAJE_ENTIDAD);
        $this->assertMismaRespuesta($inexistente, $ajena);
        $this->assertSame(0, CrmActividad::count());
        Event::assertNotDispatched(ActividadUpdated::class);
    }

    public function test_actividades_registra_sobre_la_propia_y_con_equipo_sobre_cualquiera(): void
    {
        $this->actividad($this->propia->id)->assertCreated();
        $this->actividad($this->ajena->id)->assertNotFound();

        $this->conEquipoDeOportunidades();
        $this->actividad($this->ajena->id)->assertCreated();

        $this->assertSame(1, CrmActividad::where('entidad_id', $this->ajena->id)->count());
    }

    // ── POST crm/seguimientos ─────────────────────────────────────────────

    private function seguimiento(int $oportunidadId)
    {
        return $this->enviar('POST', 'seguimientos', [
            'entidad_tipo' => 'oportunidad', 'entidad_id' => $oportunidadId,
            'actividad' => [
                'tipo' => 'llamada', 'descripcion' => 'Llamé', 'fecha_actividad' => now()->subMinute()->toDateTimeString(),
                'etiqueta_id' => $this->etiqueta->id,
            ],
            'siguiente' => [
                'tipo' => 'llamada', 'titulo' => 'Volver a llamar', 'fecha_inicio' => now()->addDay()->toDateTimeString(),
                'etiqueta_id' => $this->etiqueta->id,
            ],
        ]);
    }

    public function test_seguimientos_no_se_cuelgan_de_una_oportunidad_ajena_y_responden_como_si_no_existiera(): void
    {
        Event::fake([ActividadUpdated::class, AgendaUpdated::class]);

        $ajena = $this->seguimiento($this->ajena->id);
        $inexistente = $this->seguimiento(999999);

        $ajena->assertUnprocessable()->assertJsonValidationErrors('entidad_id');
        $this->assertMismaRespuesta($inexistente, $ajena);
        $this->assertSame(0, CrmActividad::count());
        $this->assertSame(0, CrmAgenda::count());
        Event::assertNotDispatched(ActividadUpdated::class);
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_seguimientos_sobre_la_oportunidad_propia_funcionan(): void
    {
        $this->seguimiento($this->propia->id)->assertCreated();

        $this->assertSame(1, CrmActividad::where('entidad_id', $this->propia->id)->count());
        $this->assertSame(1, CrmAgenda::where('entidad_id', $this->propia->id)->count());
    }

    // ── GET crm/actividades, historial y por id ───────────────────────────

    public function test_el_listado_de_actividades_oculta_las_de_oportunidades_ajenas(): void
    {
        $enCliente = $this->actividadEn($this->cliente, 'En cliente');
        $enPropia = $this->actividadEn($this->propia, 'En propia');
        $this->actividadEn($this->ajena, 'Secreto ajeno');

        $response = $this->enviar('GET', 'actividades')->assertOk();

        $this->assertEqualsCanonicalizing([$enCliente->id, $enPropia->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString('Secreto ajeno', $response->getContent());
        $this->assertSame([], $this->enviar('GET', "actividades?entidad_tipo=oportunidad&entidad_id={$this->ajena->id}")->json('data'));
    }

    public function test_con_equipo_el_listado_de_actividades_trae_todas(): void
    {
        $this->actividadEn($this->cliente, 'En cliente');
        $this->actividadEn($this->ajena, 'En ajena');
        $this->conEquipoDeOportunidades();

        $this->enviar('GET', 'actividades')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_el_historial_de_una_oportunidad_ajena_sale_vacio_como_el_de_una_inexistente(): void
    {
        $this->actividadEn($this->ajena, 'Secreto ajeno');
        $enPropia = $this->actividadEn($this->propia, 'En propia');

        $ajena = $this->enviar('GET', "oportunidad/{$this->ajena->id}/historial")->assertOk();
        $inexistente = $this->enviar('GET', 'oportunidad/999999/historial');

        $this->assertMismaRespuesta($inexistente, $ajena);
        $this->assertSame([$enPropia->id], collect($this->enviar('GET', "oportunidad/{$this->propia->id}/historial")->json('data'))->pluck('id')->all());
    }

    public function test_la_actividad_de_una_oportunidad_ajena_por_id_responde_404_sin_cambios(): void
    {
        Event::fake([ActividadUpdated::class]);
        $deAjena = $this->actividadEn($this->ajena, 'Secreto ajeno');

        $this->enviar('GET', "actividades/{$deAjena->id}")->assertNotFound();
        $this->enviar('PUT', "actividades/{$deAjena->id}", ['descripcion' => 'Cambio'])->assertNotFound();
        $this->enviar('DELETE', "actividades/{$deAjena->id}")->assertNotFound();

        $this->assertSame('Secreto ajeno', $deAjena->fresh()->descripcion);
        Event::assertNotDispatched(ActividadUpdated::class);
    }
}
