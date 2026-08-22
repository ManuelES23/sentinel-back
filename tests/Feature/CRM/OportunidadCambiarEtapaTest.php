<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OportunidadCambiarEtapaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected CrmOportunidad $oportunidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad', 'etapa' => 'calificado',
        ]);
    }

    private function cambiarEtapa(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$this->oportunidad->id}/cambiar-etapa", $payload);
    }

    public function test_puede_avanzar_a_la_siguiente_etapa(): void
    {
        $response = $this->cambiarEtapa(['etapa' => 'propuesta']);

        $response->assertOk()->assertJsonPath('data.etapa', 'propuesta');
    }

    public function test_rechaza_retroceder_de_etapa(): void
    {
        $this->oportunidad->update(['etapa' => 'negociacion']);

        $response = $this->cambiarEtapa(['etapa' => 'calificado']);

        $response->assertStatus(422);
        $this->assertEquals('negociacion', $this->oportunidad->fresh()->etapa);
    }

    public function test_requiere_motivo_al_marcar_cerrado_perdido(): void
    {
        $response = $this->cambiarEtapa(['etapa' => 'cerrado_perdido']);

        $response->assertStatus(422);
    }

    public function test_puede_marcar_cerrado_perdido_desde_cualquier_etapa_con_motivo(): void
    {
        $response = $this->cambiarEtapa(['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Se fue con la competencia']);

        $response->assertOk()->assertJsonPath('data.etapa', 'cerrado_perdido');
        $this->assertNotNull($this->oportunidad->fresh()->fecha_cierre_real);
    }

    public function test_rechaza_cerrado_ganado_sin_cotizacion_aprobada_y_sin_forzar(): void
    {
        $response = $this->cambiarEtapa(['etapa' => 'cerrado_ganado']);

        $response->assertStatus(422);
    }

    public function test_permite_forzar_cerrado_ganado_sin_cotizacion_aprobada(): void
    {
        $response = $this->cambiarEtapa(['etapa' => 'cerrado_ganado', 'forzar' => true]);

        $response->assertOk()->assertJsonPath('data.etapa', 'cerrado_ganado');
    }

    public function test_permite_cerrado_ganado_si_ya_hay_una_cotizacion_aprobada(): void
    {
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $this->oportunidad->id,
            'folio' => 'COT-00099', 'estado' => 'aprobado', 'fecha_emision' => now(),
        ]);

        $response = $this->cambiarEtapa(['etapa' => 'cerrado_ganado']);

        $response->assertOk();
    }
}
