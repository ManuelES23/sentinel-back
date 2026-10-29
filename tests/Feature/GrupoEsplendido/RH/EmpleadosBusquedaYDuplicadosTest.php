<?php

namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

/** Búsqueda por nombre completo, CURP/clave duplicadas con quién las tiene, y eliminados que se pueden restaurar. */
class EmpleadosBusquedaYDuplicadosTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private const BASE = '/api/grupoesplendido/rh/empleados';

    private function datosAlta(int $empresaId, array $extra = []): array
    {
        return array_merge([
            'enterprise_id' => $empresaId,
            'first_name' => 'Nuevo',
            'last_name' => 'Ingreso',
            'hire_date' => now()->toDateString(),
        ], $extra);
    }

    private function ids($respuesta): array
    {
        return collect($respuesta->json('data.data'))->pluck('id')->sort()->values()->all();
    }

    public function test_busca_por_nombre_completo_aunque_los_apellidos_vengan_juntos(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $fernando = $this->createEmployee($empresa->id, ['first_name' => 'FERNANDO FRANCISCO', 'last_name' => 'FIGUEROA VALENZUELA']);
        $this->createEmployee($empresa->id, ['first_name' => 'FERNANDO', 'last_name' => 'LÓPEZ']);

        $this->assertSame([$fernando->id], $this->ids($this->getJson(self::BASE.'?search='.urlencode('Fernando Figueroa'))->assertOk()));
        $this->assertSame([$fernando->id], $this->ids($this->getJson(self::BASE.'?search='.urlencode('figueroa valenzuela fernando'))));
    }

    public function test_busca_tambien_en_el_segundo_apellido(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $ana = $this->createEmployee($empresa->id, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'second_last_name' => 'Castañeda']);

        $this->assertSame([$ana->id], $this->ids($this->getJson(self::BASE.'?search='.urlencode('Ana Castañeda'))));
    }

    public function test_los_comodines_del_texto_se_buscan_literalmente(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $this->createEmployee($empresa->id);

        $this->assertSame([], $this->ids($this->getJson(self::BASE.'?search='.urlencode('%'))));
    }

    public function test_curp_duplicada_dice_quien_la_tiene(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $this->createEmployee($empresa->id, ['first_name' => 'Rosa', 'last_name' => 'Pérez', 'employee_number' => 'GE-0007', 'curp' => 'PEXR800101MSRRSS09']);

        $this->postJson(self::BASE, $this->datosAlta($empresa->id, ['curp' => 'pexr800101msrrss09']))
            ->assertStatus(422)
            ->assertJsonPath('errors.curp.0', 'La CURP ya está registrada para Rosa Pérez (GE-0007).');
    }

    public function test_curp_de_un_empleado_eliminado_avisa_que_hay_que_restaurarlo(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $eliminado = $this->createEmployee($empresa->id, ['first_name' => 'Rosa', 'last_name' => 'Pérez', 'employee_number' => 'GE-0007', 'curp' => 'PEXR800101MSRRSS09']);
        $eliminado->delete();

        $respuesta = $this->postJson(self::BASE, $this->datosAlta($empresa->id, ['curp' => 'PEXR800101MSRRSS09']))->assertStatus(422);

        $this->assertStringContainsString('Rosa Pérez (GE-0007)', $respuesta->json('errors.curp.0'));
        $this->assertStringContainsString('eliminado', $respuesta->json('errors.curp.0'));
        $respuesta->assertJsonPath('existente.id', $eliminado->id)->assertJsonPath('existente.eliminado', true);
    }

    public function test_clave_de_checador_duplicada_dice_quien_la_tiene_y_editarse_a_si_mismo_no_choca(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $rosa = $this->createEmployee($empresa->id, ['first_name' => 'Rosa', 'last_name' => 'Pérez', 'employee_number' => 'GE-0007', 'checker_key' => 'CK-1']);
        $otro = $this->createEmployee($empresa->id);

        $this->putJson(self::BASE."/{$otro->id}", ['checker_key' => 'CK-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.checker_key.0', 'La clave de checador ya está registrada para Rosa Pérez (GE-0007).');
        $this->putJson(self::BASE."/{$rosa->id}", ['checker_key' => 'CK-1', 'first_name' => 'Rosa María'])->assertOk();
    }

    public function test_lista_los_eliminados_y_los_restaura(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $vivo = $this->createEmployee($empresa->id);
        $eliminado = $this->createEmployee($empresa->id);
        $eliminado->delete();

        $this->assertSame([$vivo->id], $this->ids($this->getJson(self::BASE)));
        $this->assertSame([$eliminado->id], $this->ids($this->getJson(self::BASE.'?eliminados=1')));

        $this->postJson(self::BASE."/{$eliminado->id}/restaurar")->assertOk()->assertJsonPath('data.id', $eliminado->id);

        $this->assertNotSoftDeleted('employees', ['id' => $eliminado->id]);
        $this->assertSame(collect([$vivo->id, $eliminado->id])->sort()->values()->all(), $this->ids($this->getJson(self::BASE)));
    }

    public function test_restaurar_uno_que_no_esta_eliminado_responde_404(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $vivo = $this->createEmployee($empresa->id);

        $this->postJson(self::BASE."/{$vivo->id}/restaurar")->assertNotFound();
    }

    public function test_per_page_tiene_tope(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $this->createEmployee($empresa->id);

        $this->getJson(self::BASE.'?per_page=999999')->assertOk()->assertJsonPath('data.per_page', 1000);
        $this->getJson(self::BASE.'?per_page=-5')->assertOk()->assertJsonPath('data.per_page', 1);
    }
}
