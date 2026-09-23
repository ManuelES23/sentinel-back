<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class AgendaCompletarSeguimientoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'crear', 'editar']);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente X']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function evento(bool $conEntidad = true): CrmAgenda
    {
        return CrmAgenda::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'entidad_type' => $conEntidad ? CrmCliente::class : null,
            'entidad_id' => $conEntidad ? $this->cliente->id : null,
            'tipo' => 'tarea',
            'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-09-14 09:00:00',
            'fecha_fin' => '2026-09-14 09:30:00',
        ]);
    }

    private function completar(CrmAgenda $evento, array $body = [])
    {
        return $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/agenda/{$evento->id}/completar", $body);
    }

    public function test_sin_body_se_comporta_como_antes(): void
    {
        $evento = $this->evento();

        $this->completar($evento)
            ->assertOk()
            ->assertJsonPath('message', 'Evento marcado como completado')
            ->assertJsonPath('data.id', $evento->id)
            ->assertJsonPath('data.siguiente_evento', null);

        $this->assertTrue($evento->fresh()->completado);
        $this->assertDatabaseCount('crm_actividades', 1);
        $this->assertDatabaseHas('crm_actividades', ['tipo' => 'nota', 'fuente' => 'agenda', 'resultado' => null]);
        $this->assertDatabaseCount('crm_agenda', 1);
    }

    public function test_guarda_resultado_y_tipo_en_la_actividad(): void
    {
        $this->completar($this->evento(), ['resultado' => 'Aceptó la visita', 'actividad_tipo' => 'llamada'])->assertOk();

        $this->assertDatabaseHas('crm_actividades', [
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'tipo' => 'llamada', 'resultado' => 'Aceptó la visita', 'fuente' => 'agenda',
        ]);
    }

    public function test_con_siguiente_paso_crea_el_evento_con_la_misma_entidad_y_vendedor(): void
    {
        $respuesta = $this->completar($this->evento(), ['siguiente' => [
            'tipo' => 'visita', 'titulo' => 'Visita a Cliente X', 'fecha_inicio' => '2026-09-16 11:00:00', 'duracion_minutos' => 60,
        ]])->assertOk();

        $respuesta->assertJsonPath('data.siguiente_evento.titulo', 'Visita a Cliente X');
        $this->assertDatabaseHas('crm_agenda', [
            'titulo' => 'Visita a Cliente X', 'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'fecha_fin' => '2026-09-16 12:00:00', 'completado' => false,
        ]);
    }

    public function test_evento_sin_entidad_crea_siguiente_sin_entidad_y_sin_actividad(): void
    {
        $this->completar($this->evento(false), ['siguiente' => [
            'tipo' => 'llamada', 'titulo' => 'Otra llamada', 'fecha_inicio' => '2026-09-15 09:00:00',
        ]])->assertOk();

        $this->assertDatabaseCount('crm_actividades', 0);
        $this->assertDatabaseHas('crm_agenda', ['titulo' => 'Otra llamada', 'entidad_type' => null, 'entidad_id' => null]);
    }

    public function test_siguiente_paso_sin_permiso_crear_da_403_y_no_completa(): void
    {
        UserSubmodulePermission::query()->delete();
        $this->otorgarPermisosCrm('agenda', 'agenda', ['ver', 'editar']);
        $evento = $this->evento();

        $this->completar($evento, ['siguiente' => [
            'tipo' => 'llamada', 'titulo' => 'X', 'fecha_inicio' => '2026-09-15 09:00:00',
        ]])->assertForbidden();

        $this->assertFalse($evento->fresh()->completado);
    }
}
