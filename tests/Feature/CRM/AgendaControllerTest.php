<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmVendedor;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class AgendaControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga los permisos dados al actingUser. */
    private function otorgarPermisosAgenda(array $slugs): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            [
                'name' => 'CRM Comercial',
                'description' => 'CRM Comercial',
                'path' => '/'.$this->enterprise->slug.'/crm',
                'is_active' => true,
            ],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'agenda'],
            ['name' => 'Agenda', 'order' => 1, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'agenda'],
            ['name' => 'Agenda', 'order' => 1, 'is_active' => true],
        );

        foreach (['ver', 'crear', 'editar', 'eliminar'] as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodulo->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'order' => 1, 'is_active' => true],
            );

            if (in_array($slug, $slugs, true)) {
                UserSubmodulePermission::create([
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $submodulo->id,
                    'permission_type_id' => $tipo->id,
                    'is_granted' => true,
                ]);
            }
        }
    }

    private function crearVendedorPropio(): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
        ]);
    }

    public function test_crea_un_evento_con_permiso_crear(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'llamada',
            'titulo' => 'Llamar a Juan',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crm_agenda', [
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'titulo' => 'Llamar a Juan',
        ]);
    }

    public function test_rechaza_crear_sin_permiso_crear(): void
    {
        $this->otorgarPermisosAgenda(['ver']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now()->toDateTimeString(), 'fecha_fin' => now()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_rechaza_fecha_fin_anterior_a_fecha_inicio(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('fecha_fin');
    }

    public function test_rechaza_recordatorio_posterior_al_inicio(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'recordatorio_at' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('recordatorio_at');
    }

    public function test_crea_evento_ligado_a_una_entidad(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cliente X', 'email' => 'cliente@x.com',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'visita', 'titulo' => 'Visitar cliente',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
            'entidad_tipo' => 'cliente', 'entidad_id' => $cliente->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('crm_agenda', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $cliente->id,
        ]);
    }

    public function test_rechaza_entidad_relacionada_de_otra_empresa(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $clienteAjeno = CrmCliente::create([
            'empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno', 'email' => 'a@a.com',
        ]);

        $response = $this->withHeaders($this->crmHeaders())->postJson('/api/crm/agenda', [
            'tipo' => 'visita', 'titulo' => 'X',
            'fecha_inicio' => now()->addDay()->toDateTimeString(),
            'fecha_fin' => now()->addDay()->addHour()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
            'entidad_tipo' => 'cliente', 'entidad_id' => $clienteAjeno->id,
        ]);

        $response->assertStatus(404);
    }

    public function test_completar_genera_una_actividad_cuando_hay_entidad_relacionada(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cliente X', 'email' => 'cliente@x.com',
        ]);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmCliente::class, 'entidad_id' => $cliente->id,
            'tipo' => 'tarea', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$evento->id}/completar");

        $response->assertOk();
        $this->assertTrue($evento->fresh()->completado);
        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $cliente->id,
            'tipo' => 'nota', 'fuente' => 'agenda',
        ]);
    }

    public function test_completar_no_genera_actividad_sin_entidad_relacionada(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'Llamada libre',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$evento->id}/completar");

        $response->assertOk();
        $this->assertDatabaseCount('crm_actividades', 0);
    }

    public function test_completar_dos_veces_da_422(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'X', 'completado' => true,
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/agenda/{$evento->id}/completar");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Este evento ya fue marcado como completado.');
    }

    public function test_editar_recordatorio_resetea_recordatorio_enviado_at(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now()->addDay(), 'fecha_fin' => now()->addDay()->addHour(),
            'recordatorio_at' => now()->subHour(),
            'recordatorio_enviado_at' => now()->subMinutes(30),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$evento->id}", [
                'recordatorio_at' => now()->addHours(2)->toDateTimeString(),
            ]);

        $response->assertOk();
        $this->assertNull($evento->fresh()->recordatorio_enviado_at);
    }

    public function test_eliminar_requiere_permiso_eliminar(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->deleteJson("/api/crm/agenda/{$evento->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('crm_agenda', ['id' => $evento->id]);
    }

    public function test_elimina_con_permiso_eliminar(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'eliminar']);
        $evento = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'X',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->deleteJson("/api/crm/agenda/{$evento->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('crm_agenda', ['id' => $evento->id]);
    }

    public function test_ver_propio_rechaza_consultar_vendedor_id_ajeno(): void
    {
        $this->otorgarPermisosAgenda(['ver']);
        $this->crearVendedorPropio();

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/agenda?vendedor_id={$this->vendedor->id}");

        $response->assertStatus(403);
    }

    public function test_ver_propio_permite_consultar_su_propio_vendedor(): void
    {
        $this->otorgarPermisosAgenda(['ver']);
        $vendedorPropio = $this->crearVendedorPropio();
        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $vendedorPropio->id,
            'tipo' => 'llamada', 'titulo' => 'Mío',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/agenda?vendedor_id={$vendedorPropio->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_con_permiso_crear_puede_consultar_cualquier_vendedor(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/agenda?vendedor_id={$this->vendedor->id}");

        $response->assertOk();
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'crear']);
        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'Dentro de rango',
            'fecha_inicio' => now()->addDays(5), 'fecha_fin' => now()->addDays(5)->addHour(),
        ]);
        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'titulo' => 'Fuera de rango',
            'fecha_inicio' => now()->addDays(60), 'fecha_fin' => now()->addDays(60)->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/agenda?vendedor_id={$this->vendedor->id}&desde=".now()->toDateString()."&hasta=".now()->addDays(10)->toDateString());

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Dentro de rango');
    }

    public function test_no_ve_eventos_de_otra_empresa(): void
    {
        $this->otorgarPermisosAgenda(['ver', 'editar']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        $eventoAjeno = CrmAgenda::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'tipo' => 'llamada', 'titulo' => 'Ajeno',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/agenda/{$eventoAjeno->id}", ['titulo' => 'Hackeado']);

        $response->assertStatus(404);
    }
}
