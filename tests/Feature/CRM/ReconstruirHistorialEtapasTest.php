<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ReconstruirHistorialEtapasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Crea una oportunidad "de antes del despliegue": sin historial. */
    private function antigua(string $etapa, array $extra = []): CrmOportunidad
    {
        Carbon::setTestNow('2026-08-10 08:00:00');
        $op = CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Antigua',
            'monto_esperado' => 1000,
            'etapa' => $etapa,
        ], $extra));
        CrmOportunidadEtapa::where('oportunidad_id', $op->id)->delete();

        return $op;
    }

    private function filas(CrmOportunidad $op): array
    {
        return CrmOportunidadEtapa::where('oportunidad_id', $op->id)->orderBy('id')->get()
            ->map(fn ($f) => [$f->etapa_desde, $f->etapa_hasta, $f->cambiado_en?->toDateTimeString(), $f->inferido])
            ->all();
    }

    public function test_abierta_recibe_una_fila_por_etapa_hasta_la_actual(): void
    {
        $op = $this->antigua('propuesta');

        $this->artisan('crm:reconstruir-historial-etapas')->assertSuccessful();

        $this->assertSame([
            [null, 'prospecto', '2026-08-10 08:00:00', true],
            ['prospecto', 'calificado', null, true],
            ['calificado', 'propuesta', null, true],
        ], $this->filas($op));
    }

    public function test_ganada_recibe_las_cuatro_etapas_y_el_cierre_con_su_fecha(): void
    {
        $op = $this->antigua('cerrado_ganado', ['fecha_cierre_real' => '2026-09-01 10:00:00']);

        $this->artisan('crm:reconstruir-historial-etapas')->assertSuccessful();

        $this->assertSame([
            [null, 'prospecto', '2026-08-10 08:00:00', true],
            ['prospecto', 'calificado', null, true],
            ['calificado', 'propuesta', null, true],
            ['propuesta', 'negociacion', null, true],
            ['negociacion', 'cerrado_ganado', '2026-09-01 10:00:00', true],
        ], $this->filas($op));
    }

    public function test_perdida_queda_sin_registro_de_etapa(): void
    {
        $op = $this->antigua('cerrado_perdido', ['fecha_cierre_real' => '2026-09-02 10:00:00', 'motivo_perdida' => 'Precio']);

        $this->artisan('crm:reconstruir-historial-etapas')->assertSuccessful();

        $this->assertSame([
            [null, 'prospecto', '2026-08-10 08:00:00', true],
            [null, 'cerrado_perdido', '2026-09-02 10:00:00', true],
        ], $this->filas($op));
    }

    public function test_es_idempotente_y_no_toca_las_que_ya_tienen_historial(): void
    {
        $antigua = $this->antigua('calificado');
        Carbon::setTestNow('2026-10-01 08:00:00');
        $nueva = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Nueva',
            'monto_esperado' => 500,
        ]);

        $this->artisan('crm:reconstruir-historial-etapas')
            ->expectsOutputToContain('Oportunidades reconstruidas: 1')
            ->assertSuccessful();
        $this->artisan('crm:reconstruir-historial-etapas')
            ->expectsOutputToContain('Oportunidades reconstruidas: 0')
            ->assertSuccessful();

        $this->assertCount(2, $this->filas($antigua));
        $this->assertSame([[null, 'prospecto', '2026-10-01 08:00:00', false]], $this->filas($nueva));
    }

    public function test_incluye_las_borradas(): void
    {
        $op = $this->antigua('prospecto');
        $op->delete();

        $this->artisan('crm:reconstruir-historial-etapas')->assertSuccessful();

        $this->assertCount(1, $this->filas($op));
    }
}
