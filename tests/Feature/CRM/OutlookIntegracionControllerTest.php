<?php
// tests/Feature/CRM/OutlookIntegracionControllerTest.php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmOutlookConexion;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class OutlookIntegracionControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    /** Crea el árbol Application/Module/Submodule/PermissionType y otorga 'ver' al actingUser. */
    private function otorgarPermisoOutlook(): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'crm'],
            ['name' => 'CRM Comercial', 'description' => 'CRM Comercial', 'path' => '/'.$this->enterprise->slug.'/crm', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(
            ['application_id' => $app->id, 'slug' => 'integraciones'],
            ['name' => 'Integraciones', 'order' => 1, 'is_active' => true],
        );
        $submodulo = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'outlook'],
            ['name' => 'Outlook', 'order' => 1, 'is_active' => true],
        );
        $tipo = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $submodulo->id, 'slug' => 'ver'],
            ['name' => 'Ver', 'order' => 1, 'is_active' => true],
        );

        UserSubmodulePermission::create([
            'user_id' => $this->actingUser->id,
            'submodule_id' => $submodulo->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    private function crearVendedorPropio(): \App\Models\CRM\CrmVendedor
    {
        return \App\Models\CRM\CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
        ]);
    }

    public function test_estado_sin_permiso_responde_403(): void
    {
        $response = $this->getJson('/api/crm/integraciones/outlook/estado', $this->crmHeaders());
        $response->assertStatus(403);
    }

    public function test_estado_sin_conexion_reporta_no_conectado(): void
    {
        $this->otorgarPermisoOutlook();
        $this->crearVendedorPropio();

        $response = $this->getJson('/api/crm/integraciones/outlook/estado', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('data.conectado', false);
    }

    public function test_conectar_sin_vendedor_propio_responde_422(): void
    {
        $this->otorgarPermisoOutlook();

        $response = $this->getJson('/api/crm/integraciones/outlook/conectar', $this->crmHeaders());

        $response->assertStatus(422);
    }

    public function test_conectar_devuelve_la_url_de_consentimiento_y_guarda_el_nonce(): void
    {
        $this->otorgarPermisoOutlook();
        $this->crearVendedorPropio();

        $providerMock = \Mockery::mock(AbstractProvider::class);
        $providerMock->shouldReceive('stateless')->once()->andReturnSelf();
        $providerMock->shouldReceive('scopes')->once()->andReturnSelf();
        $providerMock->shouldReceive('with')->once()->andReturnSelf();
        $providerMock->shouldReceive('redirect')->once()->andReturn(
            new RedirectResponse('https://login.microsoftonline.com/fake-consent-url')
        );
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($providerMock);

        $response = $this->getJson('/api/crm/integraciones/outlook/conectar', $this->crmHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', 'https://login.microsoftonline.com/fake-consent-url');
    }

    public function test_callback_con_nonce_valido_crea_la_conexion_y_redirige_a_ok(): void
    {
        $vendedor = $this->crearVendedorPropio();

        $nonce = 'nonce-de-prueba';
        Cache::put('outlook_connect_nonce:'.$nonce, [
            'user_id' => $this->actingUser->id,
            'empresa_id' => $this->enterprise->id,
        ], now()->addMinutes(10));

        $socialiteUser = new SocialiteUser();
        $socialiteUser->token = 'access-token-fake';
        $socialiteUser->refreshToken = 'refresh-token-fake';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->email = 'vendedor@outlook.com';

        $providerMock = \Mockery::mock(AbstractProvider::class);
        $providerMock->shouldReceive('stateless')->once()->andReturnSelf();
        $providerMock->shouldReceive('user')->once()->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($providerMock);

        // Ruta pública -- request sin Sanctum::actingAs (Auth::user() no se usa aquí).
        $response = $this->get('/api/crm/integraciones/outlook/callback?state='.$nonce);

        $response->assertRedirect();
        $this->assertStringContainsString('outlook=ok', $response->headers->get('Location'));
        $this->assertDatabaseHas('crm_outlook_conexiones', [
            'crm_vendedor_id' => $vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
        ]);

        // El nonce se consume: una segunda llamada con el mismo state ya no encuentra contexto.
        $this->assertNull(Cache::get('outlook_connect_nonce:'.$nonce));
    }

    public function test_callback_con_nonce_invalido_redirige_a_error_sin_crear_nada(): void
    {
        $response = $this->get('/api/crm/integraciones/outlook/callback?state=nonce-que-no-existe');

        $response->assertRedirect();
        $this->assertStringContainsString('outlook=error', $response->headers->get('Location'));
        $this->assertDatabaseCount('crm_outlook_conexiones', 0);
    }

    public function test_desconectar_borra_la_conexion_del_usuario_actual(): void
    {
        $this->otorgarPermisoOutlook();
        $vendedor = $this->crearVendedorPropio();

        CrmOutlookConexion::create([
            'empresa_id' => $this->enterprise->id,
            'crm_vendedor_id' => $vendedor->id,
            'email_outlook' => 'vendedor@outlook.com',
            'access_token' => 'a',
            'refresh_token' => 'b',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->deleteJson('/api/crm/integraciones/outlook/desconectar', [], $this->crmHeaders());

        $response->assertStatus(200);
        $this->assertDatabaseCount('crm_outlook_conexiones', 0);
    }
}
