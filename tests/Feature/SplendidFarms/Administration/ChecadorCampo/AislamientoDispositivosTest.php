<?php

namespace Tests\Feature\SplendidFarms\Administration\ChecadorCampo;

use App\Models\DevicePairing;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesRhFixtures;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

/**
 * Aislamiento de modos: un token sf_field no sirve en las rutas de Grupo
 * Espléndido (device.token) y un token self/kiosk no sirve en las de campo
 * (sf.field.device).
 */
class AislamientoDispositivosTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures, CreatesSfPersonalFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta de prueba hasta que existan los endpoints reales de campo.
        Route::middleware('sf.field.device')->get('/api/_prueba-campo', function (Request $request) {
            $pairing = $request->attributes->get('devicePairing');

            return response()->json([
                'ok' => true,
                'id' => $pairing->id,
                'user_cargado' => $pairing->relationLoaded('pairedByUser'),
                'empresa_cargada' => $pairing->relationLoaded('enterprise'),
            ]);
        });
    }

    private function empresaSf(): Enterprise
    {
        return Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => config('biometrics.sf_field_enterprise_slug'),
            'description' => 'SF',
            'is_active' => true,
        ]);
    }

    /** @return array{0: string, 1: DevicePairing, 2: User, 3: Enterprise} */
    private function dispositivoCampo(array $overrides = [], bool $conPermiso = true): array
    {
        $empresa = Enterprise::where('slug', config('biometrics.sf_field_enterprise_slug'))->first()
            ?? $this->empresaSf();
        $user = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
        if ($conPermiso) {
            $this->darAccesoChecadorCampo($user, $empresa);
        }

        $raw = DevicePairing::generateToken();
        $pairing = DevicePairing::create(array_merge([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SF_FIELD,
            'paired_by_user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'last_used_at' => now()->subHour(),
        ], $overrides));

        return [$raw, $pairing, $user, $empresa];
    }

    private function pairingGe(string $modo): string
    {
        $raw = DevicePairing::generateToken();
        $datos = [
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => $modo,
        ];
        if ($modo === DevicePairing::MODE_SELF) {
            [, $enterprise] = $this->createAuthenticatedRhUser();
            $datos['paired_by_employee_id'] = $this->createEmployee($enterprise->id)->id;
        } else {
            $datos['paired_by_user_id'] = User::factory()->create()->id;
        }
        DevicePairing::create($datos);

        return $raw;
    }

    public function test_token_sf_field_rechazado_en_roster_package_de_ge(): void
    {
        [$raw] = $this->dispositivoCampo();

        $this->withHeaders(['X-Device-Token' => $raw])
            ->getJson('/api/checador/roster-package')
            ->assertStatus(401);
    }

    public function test_token_sf_field_rechazado_en_sync_de_ge(): void
    {
        [$raw] = $this->dispositivoCampo();

        $this->withHeaders(['X-Device-Token' => $raw])
            ->postJson('/api/checador/sync', ['checks' => []])
            ->assertStatus(401);
    }

    public function test_tokens_self_y_kiosk_siguen_aceptados_en_ge(): void
    {
        foreach ([DevicePairing::MODE_SELF, DevicePairing::MODE_KIOSK] as $modo) {
            $raw = $this->pairingGe($modo);

            $this->withHeaders(['X-Device-Token' => $raw])
                ->getJson('/api/checador/roster-package')
                ->assertOk();
        }
    }

    public function test_token_self_rechazado_en_rutas_de_campo(): void
    {
        foreach ([DevicePairing::MODE_SELF, DevicePairing::MODE_KIOSK] as $modo) {
            $raw = $this->pairingGe($modo);

            $this->withHeaders(['X-Device-Token' => $raw])
                ->getJson('/api/_prueba-campo')
                ->assertStatus(401)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('code', 'dispositivo_no_emparejado');
        }
    }

    public function test_middleware_campo_sin_header_o_token_desconocido(): void
    {
        $this->getJson('/api/_prueba-campo')
            ->assertStatus(401)
            ->assertJsonPath('code', 'dispositivo_no_emparejado');

        $this->withHeaders(['X-Device-Token' => 'desconocido'])
            ->getJson('/api/_prueba-campo')
            ->assertStatus(401)
            ->assertJsonPath('code', 'dispositivo_no_emparejado');
    }

    public function test_middleware_campo_acepta_dispositivo_vigente(): void
    {
        [$raw, $pairing] = $this->dispositivoCampo();

        $this->withHeaders(['X-Device-Token' => $raw])
            ->getJson('/api/_prueba-campo')
            ->assertOk()
            ->assertJsonPath('id', $pairing->id)
            ->assertJsonPath('user_cargado', true)
            ->assertJsonPath('empresa_cargada', true);

        $this->assertTrue($pairing->fresh()->last_used_at->gt(now()->subMinute()));
    }

    public function test_admin_de_ge_no_revoca_un_sf_field(): void
    {
        $this->createAuthenticatedRhUser();
        [, $pairing] = $this->dispositivoCampo();

        $this->postJson("/api/grupoesplendido/rh/asistencia/dispositivos/{$pairing->id}/revocar")
            ->assertStatus(404);

        $this->assertNull($pairing->fresh()->revoked_at);
    }

    public function test_middleware_campo_devuelve_codigo_por_estado(): void
    {
        [$rawRevocado] = $this->dispositivoCampo(['revoked_at' => now()]);
        $this->withHeaders(['X-Device-Token' => $rawRevocado])
            ->getJson('/api/_prueba-campo')
            ->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'dispositivo_revocado',
                'message' => 'Este celular ya no está autorizado. Conéctate y vuelve a iniciar sesión.',
            ]);

        [$rawCaducado] = $this->dispositivoCampo(['last_used_at' => now()->subDays(31)]);
        $this->withHeaders(['X-Device-Token' => $rawCaducado])
            ->getJson('/api/_prueba-campo')
            ->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'dispositivo_caducado',
                'message' => 'Este celular pasó más de 30 días sin conectarse. Conéctate y vuelve a iniciar sesión.',
            ]);

        [$rawSinPermiso] = $this->dispositivoCampo([], false);
        $this->withHeaders(['X-Device-Token' => $rawSinPermiso])
            ->getJson('/api/_prueba-campo')
            ->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'sin_permiso',
                'message' => 'Ya no tienes permiso para usar el checador de campo. Pide acceso a tu administrador.',
            ]);
    }

    public function test_middleware_campo_no_actualiza_last_used_si_falla(): void
    {
        $antes = now()->subDays(2)->startOfSecond();
        [$raw, $pairing] = $this->dispositivoCampo(['last_used_at' => $antes], false);

        $this->withHeaders(['X-Device-Token' => $raw])
            ->getJson('/api/_prueba-campo')
            ->assertStatus(401);

        $this->assertTrue($pairing->fresh()->last_used_at->equalTo($antes));
    }
}
