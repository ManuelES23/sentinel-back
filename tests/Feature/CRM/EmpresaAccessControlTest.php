<?php
// sentinel-back/tests/Feature/CRM/EmpresaAccessControlTest.php

namespace Tests\Feature\CRM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Regresión de seguridad: FiltraPorEmpresa::getEmpresaId() confiaba en los
 * headers X-Enterprise-Id / X-Enterprise-Slug sin verificar que el usuario
 * autenticado tuviera acceso real a esa empresa. Cualquier usuario podía
 * mandar el ID de OTRA empresa y leer/escribir sus datos de CRM
 * (Prospectos, Clientes, Oportunidades, Cotizaciones, etc.).
 *
 * getEmpresaId() ahora exige un UserEnterpriseAccess activo para la empresa
 * del header antes de confiar en él; si no existe, se rechaza con 403 en
 * vez de caer silenciosamente a otra empresa del usuario.
 */
class EmpresaAccessControlTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_usuario_sin_acceso_a_la_empresa_del_header_numerico_es_rechazado(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();

        $response = $this->withHeaders(['X-Enterprise-Id' => $otraEmpresa->id])
            ->getJson('/api/crm/prospectos');

        $response->assertStatus(403);
    }

    public function test_usuario_sin_acceso_a_la_empresa_del_header_slug_es_rechazado(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();

        $response = $this->withHeaders(['X-Enterprise-Slug' => $otraEmpresa->slug])
            ->getJson('/api/crm/prospectos');

        $response->assertStatus(403);
    }

    /**
     * También cubre el intento de escritura, no solo lectura: crear un
     * prospecto mandando el header de una empresa ajena debe rechazarse,
     * no crear el registro bajo la empresa del atacante.
     */
    public function test_usuario_sin_acceso_no_puede_crear_datos_bajo_una_empresa_ajena(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();

        $response = $this->withHeaders(['X-Enterprise-Id' => $otraEmpresa->id])
            ->postJson('/api/crm/prospectos', [
                'nombre' => 'Prospecto inyectado por atacante',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('crm_prospectos', ['nombre' => 'Prospecto inyectado por atacante']);
    }

    /**
     * El rechazo debe ser explícito (403), no una caída silenciosa a la
     * propia empresa del usuario: si eso pasara, este listado regresaría
     * los prospectos de $this->enterprise en vez de un 403.
     */
    public function test_el_rechazo_no_cae_silenciosamente_a_la_empresa_propia_del_usuario(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();

        \App\Models\CRM\CrmProspecto::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Prospecto de mi propia empresa',
        ]);

        $response = $this->withHeaders(['X-Enterprise-Id' => $otraEmpresa->id])
            ->getJson('/api/crm/prospectos');

        $response->assertStatus(403);
    }

    public function test_usuario_con_acceso_a_la_empresa_del_header_numerico_puede_operar(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);

        $response = $this->withHeaders(['X-Enterprise-Id' => $otraEmpresa->id])
            ->getJson('/api/crm/prospectos');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_usuario_con_acceso_a_la_empresa_del_header_slug_puede_operar(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otraEmpresa);

        $response = $this->withHeaders(['X-Enterprise-Slug' => $otraEmpresa->slug])
            ->getJson('/api/crm/prospectos');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_usuario_sin_ningun_acceso_activo_es_rechazado_incluso_sin_header(): void
    {
        $usuarioSinAcceso = \App\Models\User::factory()->create();
        Sanctum::actingAs($usuarioSinAcceso);

        $response = $this->getJson('/api/crm/prospectos');

        $response->assertStatus(403);
    }

    /**
     * Un UserEnterpriseAccess con is_active=false no debe contar como acceso.
     */
    public function test_acceso_inactivo_a_la_empresa_del_header_es_rechazado(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        \App\Models\UserEnterpriseAccess::create([
            'user_id' => $this->actingUser->id,
            'enterprise_id' => $otraEmpresa->id,
            'is_active' => false,
            'granted_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Enterprise-Id' => $otraEmpresa->id])
            ->getJson('/api/crm/prospectos');

        $response->assertStatus(403);
    }
}
