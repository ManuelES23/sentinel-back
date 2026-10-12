<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\AgendaUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Agenda por vendedor: sin agenda.equipo el usuario solo lee y modifica los
 * eventos de su propio CrmVendedor ACTIVO. crear/editar ya no cuentan como
 * gerencia (el perfil vendedor los tiene). Con equipo, cualquier vendedor de
 * la empresa.
 */
class AgendaVisibilidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MENSAJE_AJENA = 'No puedes ver o modificar la agenda de otro vendedor.';

    private const MENSAJE_SIN_VENDEDOR = 'Tu usuario no está vinculado a un vendedor activo.';

    private CrmVendedor $propio;

    private CrmAgenda $eventoPropio;

    private CrmAgenda $eventoAjeno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario',
            'activo' => true,
        ]);

        $this->eventoPropio = $this->evento($this->propio, 'Mío');
        $this->eventoAjeno = $this->evento($this->vendedor, 'Ajeno secreto');
    }

    private function evento(CrmVendedor $vendedor, string $titulo, array $extra = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $vendedor->empresa_id, 'vendedor_id' => $vendedor->id,
            'tipo' => 'llamada', 'titulo' => $titulo,
            'fecha_inicio' => now()->addHour(), 'fecha_fin' => now()->addHours(2),
        ], $extra));
    }

    private function permisos(array $permisos): void
    {
        $this->otorgarPermisosCrm('agenda', 'agenda', $permisos);
    }

    private function listar(int $vendedorId, string $extra = '')
    {
        return $this->withHeaders($this->crmHeaders())->getJson("/api/crm/agenda?vendedor_id={$vendedorId}{$extra}");
    }

    private function crear(array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', array_merge([
            'tipo' => 'visita', 'titulo' => 'Nuevo evento',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->propio->id,
        ], $datos));
    }

    private function idsDe($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function assertAjenoIntacto(): void
    {
        $fresco = $this->eventoAjeno->fresh();
        $this->assertNotNull($fresco);
        $this->assertSame('Ajeno secreto', $fresco->titulo);
        $this->assertSame($this->vendedor->id, (int) $fresco->vendedor_id);
        $this->assertFalse($fresco->completado);
    }

    // ── index ─────────────────────────────────────────────────────────────

    public function test_el_perfil_vendedor_lee_solo_su_agenda(): void
    {
        $this->permisos(['ver', 'crear', 'editar']);

        $this->assertSame([$this->eventoPropio->id], $this->idsDe($this->listar($this->propio->id)->assertOk()));

        $response = $this->listar($this->vendedor->id);
        $response->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);
        $this->assertStringNotContainsString('Ajeno secreto', $response->getContent());
    }

    public function test_los_vencidos_de_otro_vendedor_tambien_responden_403(): void
    {
        $this->permisos(['ver', 'crear', 'editar']);
        $this->evento($this->vendedor, 'Vencido ajeno', [
            'fecha_inicio' => now()->subDays(2), 'fecha_fin' => now()->subDays(2)->addHour(),
        ]);

        $this->listar($this->vendedor->id, '&vencidos=1')->assertForbidden();
    }

    public function test_con_equipo_lee_la_agenda_de_cualquier_vendedor(): void
    {
        $this->permisos(['ver', 'equipo']);

        $this->assertSame([$this->eventoAjeno->id], $this->idsDe($this->listar($this->vendedor->id)->assertOk()));
        $this->assertSame([$this->eventoPropio->id], $this->idsDe($this->listar($this->propio->id)->assertOk()));
    }

    public function test_sin_vendedor_vinculado_ni_equipo_responde_403_con_mensaje_claro(): void
    {
        $this->propio->update(['user_id' => null]);
        $this->permisos(['ver', 'crear', 'editar']);

        $this->listar($this->propio->id)->assertForbidden()->assertJsonPath('message', self::MENSAJE_SIN_VENDEDOR);
        $this->listar($this->vendedor->id)->assertForbidden()->assertJsonPath('message', self::MENSAJE_SIN_VENDEDOR);
    }

    public function test_con_vendedor_inactivo_y_sin_equipo_no_lee_ni_su_agenda(): void
    {
        $this->propio->update(['activo' => false]);
        $this->permisos(['ver', 'crear', 'editar']);

        $this->listar($this->propio->id)->assertForbidden()->assertJsonPath('message', self::MENSAJE_SIN_VENDEDOR);
    }

    public function test_el_equipo_de_otra_empresa_no_cuenta(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);
        $this->otorgarTodosLosPermisosCrm($otra);
        $this->permisos(['ver', 'crear', 'editar']);

        $this->listar($this->vendedor->id)->assertForbidden();
    }

    // ── store ─────────────────────────────────────────────────────────────

    public function test_sin_equipo_no_crea_eventos_para_otro_vendedor(): void
    {
        Event::fake([AgendaUpdated::class]);
        $this->permisos(['ver', 'crear', 'editar']);

        $this->crear(['vendedor_id' => $this->vendedor->id])
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);

        $this->assertSame(2, CrmAgenda::count());
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_sin_equipo_crea_eventos_en_su_agenda(): void
    {
        $this->permisos(['ver', 'crear']);

        $this->crear([])->assertCreated()->assertJsonPath('data.vendedor_id', $this->propio->id);

        $this->assertSame(2, CrmAgenda::where('vendedor_id', $this->propio->id)->count());
    }

    public function test_con_equipo_crea_eventos_para_otro_vendedor_de_la_empresa(): void
    {
        $this->permisos(['crear', 'equipo']);

        $this->crear(['vendedor_id' => $this->vendedor->id])->assertCreated();

        $this->assertSame(2, CrmAgenda::where('vendedor_id', $this->vendedor->id)->count());
    }

    public function test_con_equipo_no_crea_eventos_para_un_vendedor_de_otra_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Otro tenant', 'activo' => true]);
        $this->permisos(['crear', 'equipo']);

        $this->crear(['vendedor_id' => $vendedorOtra->id])->assertUnprocessable()->assertJsonValidationErrors('vendedor_id');

        $this->assertSame(0, CrmAgenda::where('vendedor_id', $vendedorOtra->id)->count());
    }

    // ── update / completar / destroy ──────────────────────────────────────

    public function test_sin_equipo_no_edita_el_evento_de_otro_vendedor(): void
    {
        Event::fake([AgendaUpdated::class]);
        $this->permisos(['ver', 'crear', 'editar']);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$this->eventoAjeno->id}", ['titulo' => 'Robado']);

        $response->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);
        $this->assertStringNotContainsString('Ajeno secreto', $response->getContent());
        $this->assertAjenoIntacto();
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_put_vacio_sobre_un_evento_ajeno_no_lo_revela(): void
    {
        $this->permisos(['ver', 'crear', 'editar']);

        $response = $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$this->eventoAjeno->id}", []);

        $response->assertForbidden();
        $this->assertStringNotContainsString('Ajeno secreto', $response->getContent());
    }

    public function test_sin_equipo_edita_su_propio_evento(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$this->eventoPropio->id}", ['titulo' => 'Mío editado'])
            ->assertOk();

        $this->assertSame('Mío editado', $this->eventoPropio->fresh()->titulo);
    }

    public function test_con_equipo_edita_el_evento_de_otro_vendedor(): void
    {
        $this->permisos(['editar', 'equipo']);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$this->eventoAjeno->id}", ['titulo' => 'Editado por gerencia'])
            ->assertOk();

        $this->assertSame('Editado por gerencia', $this->eventoAjeno->fresh()->titulo);
    }

    public function test_sin_equipo_no_completa_el_evento_de_otro_vendedor(): void
    {
        Event::fake([AgendaUpdated::class]);
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        $this->eventoAjeno->update(['entidad_type' => CrmCliente::class, 'entidad_id' => $cliente->id]);
        $this->permisos(['ver', 'crear', 'editar']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$this->eventoAjeno->id}/completar", ['resultado' => 'Hecho'])
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);

        $this->assertAjenoIntacto();
        $this->assertSame(0, CrmActividad::count());
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_sin_equipo_completa_su_propio_evento(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$this->eventoPropio->id}/completar")
            ->assertOk();

        $this->assertTrue($this->eventoPropio->fresh()->completado);
    }

    public function test_sin_equipo_completa_su_evento_ligado_a_su_oportunidad_con_siguiente_paso(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia', 'etapa' => 'calificado',
        ]);
        $this->eventoPropio->update(['entidad_type' => CrmOportunidad::class, 'entidad_id' => $oportunidad->id]);
        $this->permisos(['ver', 'crear', 'editar']);

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$this->eventoPropio->id}/completar", [
                'resultado' => 'Pidió la propuesta',
                'siguiente' => ['tipo' => 'llamada', 'titulo' => 'Llamar de nuevo', 'fecha_inicio' => now()->addDays(2)->toDateTimeString()],
            ]);

        $response->assertOk();
        $this->assertTrue($this->eventoPropio->fresh()->completado);
        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $oportunidad->id,
            'vendedor_id' => $this->propio->id, 'resultado' => 'Pidió la propuesta', 'fuente' => 'agenda',
        ]);
        $siguiente = CrmAgenda::where('titulo', 'Llamar de nuevo')->first();
        $this->assertNotNull($siguiente);
        $this->assertSame($this->propio->id, (int) $siguiente->vendedor_id);
        $this->assertSame(CrmOportunidad::class, $siguiente->entidad_type);
        $this->assertSame($oportunidad->id, (int) $siguiente->entidad_id);
        $this->assertSame($siguiente->id, $response->json('data.siguiente_evento.id'));
    }

    public function test_sin_equipo_no_elimina_el_evento_de_otro_vendedor_aunque_tenga_eliminar(): void
    {
        Event::fake([AgendaUpdated::class]);
        $this->permisos(['ver', 'crear', 'editar', 'eliminar']);

        $this->withHeaders($this->crmHeaders())
            ->deleteJson("/api/crm/agenda/{$this->eventoAjeno->id}")
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);

        $this->assertAjenoIntacto();
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_con_equipo_elimina_el_evento_de_otro_vendedor(): void
    {
        $this->permisos(['eliminar', 'equipo']);

        $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/agenda/{$this->eventoAjeno->id}")->assertOk();

        $this->assertNull($this->eventoAjeno->fresh());
    }

    public function test_sin_vendedor_activo_no_modifica_ni_su_evento(): void
    {
        $this->propio->update(['activo' => false]);
        $this->permisos(['ver', 'crear', 'editar', 'eliminar']);

        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$this->eventoPropio->id}", ['titulo' => 'Cambio'])
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_SIN_VENDEDOR);
        $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/agenda/{$this->eventoPropio->id}")->assertForbidden();

        $this->assertSame('Mío', $this->eventoPropio->fresh()->titulo);
    }

    public function test_el_evento_de_otra_empresa_sigue_respondiendo_404(): void
    {
        $otra = $this->crearOtraEmpresa();
        $vendedorOtra = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Otro tenant', 'activo' => true]);
        $deOtra = $this->evento($vendedorOtra, 'De otra');
        $this->permisos(['ver', 'editar', 'eliminar', 'equipo']);

        $this->withHeaders($this->crmHeaders())->putJson("/api/crm/agenda/{$deOtra->id}", ['titulo' => 'X'])->assertNotFound();
        $this->withHeaders($this->crmHeaders())->deleteJson("/api/crm/agenda/{$deOtra->id}")->assertNotFound();
        $this->assertSame('De otra', $deOtra->fresh()->titulo);
    }
}
