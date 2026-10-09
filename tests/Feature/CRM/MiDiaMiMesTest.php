<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Models\CRM\CrmVendedor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class MiDiaMiMesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private CrmVendedor $propio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver']);
        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
            'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function miDia()
    {
        return $this->withHeaders($this->crmHeaders())->getJson('/api/crm/mi-dia');
    }

    public function test_sin_presupuesto_la_meta_es_null(): void
    {
        $this->miDia()
            ->assertOk()
            ->assertJsonPath('data.mi_mes.mes', 9)
            ->assertJsonPath('data.mi_mes.anio', 2026)
            ->assertJsonPath('data.mi_mes.meta', null)
            ->assertJsonPath('data.mi_mes.ganado', 0)
            ->assertJsonPath('data.mi_mes.embudo.total', 0);
    }

    public function test_con_meta_ganado_pronostico_y_embudo_del_vendedor(): void
    {
        CrmPresupuesto::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id, 'mes' => 9, 'anio' => 2026, 'meta_monto' => 10000, 'meta_clientes' => 1, 'meta_actividades' => 1]);
        CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id, 'nombre' => 'Ganada', 'monto_esperado' => 3000, 'etapa' => 'cerrado_ganado', 'fecha_cierre_real' => '2026-09-05 10:00:00']);
        CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id, 'nombre' => 'Abierta', 'monto_esperado' => 2000, 'probabilidad' => 50, 'etapa' => 'negociacion', 'fecha_cierre_esperada' => '2026-09-20']);
        CrmOportunidad::create(['empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id, 'nombre' => 'De otro', 'monto_esperado' => 9999, 'etapa' => 'cerrado_ganado', 'fecha_cierre_real' => '2026-09-05 10:00:00']);

        $this->miDia()
            ->assertOk()
            ->assertJsonPath('data.mi_mes.meta', 10000)
            ->assertJsonPath('data.mi_mes.ganado', 3000)
            ->assertJsonPath('data.mi_mes.pronostico', 4000)
            ->assertJsonPath('data.mi_mes.embudo.total', 2)
            ->assertJsonPath('data.mi_mes.embudo.ganadas', 1);
    }

    public function test_gerencia_sin_vendedor_recibe_mi_mes_null(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);
        $this->propio->delete();

        $this->miDia()->assertOk()->assertJsonPath('data.mi_mes', null);
    }
}
