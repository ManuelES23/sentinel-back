<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OportunidadResumenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-10-08 10:00:00');
        CarbonImmutable::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function op(array $datos): CrmOportunidad
    {
        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op',
            'monto_esperado' => 1000,
            'probabilidad' => 50,
        ], $datos));
    }

    private function resumen(string $qs = '')
    {
        return $this->withHeaders($this->crmHeaders())->getJson('/api/crm/oportunidades/resumen'.$qs);
    }

    public function test_requiere_permiso_ver(): void
    {
        $this->resumen()->assertStatus(403);
    }

    public function test_devuelve_totales_por_etapa_y_tasa_del_mes(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->op(['etapa' => 'prospecto']);
        $this->op(['etapa' => 'propuesta', 'monto_esperado' => 2000]);
        $this->op(['etapa' => 'cerrado_ganado', 'fecha_cierre_real' => '2026-10-03 10:00:00']);
        $this->op(['etapa' => 'cerrado_perdido', 'fecha_cierre_real' => '2026-10-04 10:00:00']);
        $this->op(['etapa' => 'cerrado_ganado', 'fecha_cierre_real' => '2026-09-05 10:00:00']);

        $this->resumen()
            ->assertOk()
            ->assertJsonPath('data.abiertas', 2)
            ->assertJsonPath('data.monto', 3000)
            ->assertJsonPath('data.ponderado', 1500)
            ->assertJsonCount(4, 'data.porEtapa')
            ->assertJsonPath('data.cerradasMes', 2)
            ->assertJsonPath('data.tasaCierreMes.actual', 50)
            ->assertJsonPath('data.tasaCierreMes.anterior', 100);
    }

    public function test_filtra_por_vendedor(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        $this->op(['etapa' => 'prospecto']);
        $this->op(['etapa' => 'prospecto', 'vendedor_id' => $otro->id]);

        $this->resumen("?vendedor_id={$otro->id}")->assertOk()->assertJsonPath('data.abiertas', 1);
    }

    public function test_vendedor_de_otra_empresa_devuelve_422(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $otraEmpresa = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);

        $this->resumen("?vendedor_id={$ajeno->id}")->assertStatus(422);
    }
}
