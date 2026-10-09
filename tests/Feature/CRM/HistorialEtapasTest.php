<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class HistorialEtapasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crear(array $extra = []): CrmOportunidad
    {
        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op',
            'monto_esperado' => 1000,
        ], $extra));
    }

    public function test_crear_registra_la_etapa_inicial(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $op = $this->crear();

        $fila = CrmOportunidadEtapa::where('oportunidad_id', $op->id)->sole();
        $this->assertNull($fila->etapa_desde);
        $this->assertSame('prospecto', $fila->etapa_hasta);
        $this->assertSame('2026-10-01 09:00:00', $fila->cambiado_en->toDateTimeString());
        $this->assertSame($this->actingUser->id, $fila->user_id);
        $this->assertFalse($fila->inferido);
        $this->assertSame($this->enterprise->id, $fila->empresa_id);
    }

    public function test_cambiar_etapa_por_el_endpoint_registra_desde_y_hasta(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $op = $this->crear();
        Carbon::setTestNow('2026-10-03 12:00:00');

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$op->id}/cambiar-etapa", ['etapa' => 'calificado'])
            ->assertOk();

        $fila = CrmOportunidadEtapa::where('oportunidad_id', $op->id)->orderByDesc('id')->first();
        $this->assertSame('prospecto', $fila->etapa_desde);
        $this->assertSame('calificado', $fila->etapa_hasta);
        $this->assertSame('2026-10-03 12:00:00', $fila->cambiado_en->toDateTimeString());
    }

    public function test_perder_por_el_endpoint_registra_la_etapa_en_que_se_perdio(): void
    {
        $this->otorgarTodosLosPermisosCrm();
        $op = $this->crear(['etapa' => 'propuesta']);

        $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$op->id}/cambiar-etapa", [
                'etapa' => 'cerrado_perdido',
                'motivo_perdida' => 'Precio',
            ])
            ->assertOk();

        $this->assertDatabaseHas('crm_oportunidad_etapas', [
            'oportunidad_id' => $op->id,
            'etapa_desde' => 'propuesta',
            'etapa_hasta' => 'cerrado_perdido',
        ]);
    }

    public function test_cerrar_como_ganada_desde_el_modelo_registra_la_fila(): void
    {
        // CotizacionController cierra con $oportunidad->save(): el observer lo cubre igual.
        $op = $this->crear(['etapa' => 'negociacion']);
        $op->etapa = 'cerrado_ganado';
        $op->fecha_cierre_real = now();
        $op->save();

        $this->assertDatabaseHas('crm_oportunidad_etapas', [
            'oportunidad_id' => $op->id,
            'etapa_desde' => 'negociacion',
            'etapa_hasta' => 'cerrado_ganado',
        ]);
    }

    public function test_editar_sin_cambiar_la_etapa_no_registra_nada(): void
    {
        $op = $this->crear();
        $op->update(['nombre' => 'Otro nombre', 'monto_esperado' => 5000]);

        $this->assertSame(1, CrmOportunidadEtapa::where('oportunidad_id', $op->id)->count());
    }

    public function test_la_relacion_historial_devuelve_las_filas(): void
    {
        $op = $this->crear();
        $op->update(['etapa' => 'calificado']);

        $this->assertSame(['prospecto', 'calificado'], $op->historialEtapas()->orderBy('id')->pluck('etapa_hasta')->all());
    }
}
