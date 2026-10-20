<?php

namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

/** El jefe del departamento (departments.manager_id) alimenta el paso «Jefe del departamento» de vacaciones. */
class DepartmentManagerAssignmentTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private Enterprise $enterprise;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->enterprise] = $this->createAuthenticatedRhUser();
    }

    private function departamento(?int $enterpriseId = null): Department
    {
        static $n = 0;
        $n++;

        return Department::create([
            'enterprise_id' => $enterpriseId ?? $this->enterprise->id,
            'code' => 'JD'.$n,
            'name' => 'Depto '.$n,
            'is_active' => true,
        ]);
    }

    public function test_se_asigna_el_jefe_al_editar_el_departamento(): void
    {
        $depto = $this->departamento();
        $jefe = $this->createEmployee($this->enterprise->id);

        $this->putJson("/api/grupoesplendido/rh/departamentos/{$depto->id}", ['manager_id' => $jefe->id])
            ->assertOk()
            ->assertJsonPath('data.manager.id', $jefe->id);

        $this->assertSame($jefe->id, (int) $depto->fresh()->manager_id);
    }

    public function test_se_asigna_el_jefe_al_crear_y_se_puede_quitar(): void
    {
        $jefe = $this->createEmployee($this->enterprise->id);

        $id = $this->postJson('/api/grupoesplendido/rh/departamentos', [
            'enterprise_id' => $this->enterprise->id, 'name' => 'Ventas', 'manager_id' => $jefe->id,
        ])->assertCreated()->assertJsonPath('data.manager.id', $jefe->id)->json('data.id');

        $this->putJson("/api/grupoesplendido/rh/departamentos/{$id}", ['manager_id' => null])->assertOk();

        $this->assertNull(Department::find($id)->manager_id);
    }

    public function test_el_jefe_debe_ser_de_la_misma_empresa(): void
    {
        $depto = $this->departamento();
        $otraEmpresa = Enterprise::create(['name' => 'Otra empresa', 'slug' => 'otra-empresa-jd', 'description' => 'Otra empresa de prueba', 'is_active' => true]);
        $ajeno = $this->createEmployee($otraEmpresa->id);

        $this->putJson("/api/grupoesplendido/rh/departamentos/{$depto->id}", ['manager_id' => $ajeno->id])
            ->assertStatus(422)->assertJsonValidationErrors('manager_id');
        $this->postJson('/api/grupoesplendido/rh/departamentos', [
            'enterprise_id' => $this->enterprise->id, 'name' => 'Compras', 'manager_id' => $ajeno->id,
        ])->assertStatus(422)->assertJsonValidationErrors('manager_id');

        $this->assertNull($depto->fresh()->manager_id);
    }

    public function test_el_jefe_debe_estar_activo(): void
    {
        $depto = $this->departamento();
        $baja = $this->createEmployee($this->enterprise->id, ['status' => Employee::STATUS_TERMINATED]);

        $this->putJson("/api/grupoesplendido/rh/departamentos/{$depto->id}", ['manager_id' => $baja->id])
            ->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_editar_otros_campos_no_toca_al_jefe_ni_lo_revalida(): void
    {
        $jefe = $this->createEmployee($this->enterprise->id);
        $depto = $this->departamento();
        $depto->update(['manager_id' => $jefe->id]);
        $jefe->update(['status' => Employee::STATUS_TERMINATED]);

        $this->putJson("/api/grupoesplendido/rh/departamentos/{$depto->id}", ['name' => 'Renombrado'])->assertOk();

        $this->assertSame($jefe->id, (int) $depto->fresh()->manager_id);
    }
}
