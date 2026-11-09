<?php

namespace Tests\Feature\SplendidFarms\Administration\ChecadorCampo;

use App\Models\DevicePairing;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\SplendidFarms\ChecadorCampo\AutorizacionChecadorCampo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class AutorizacionChecadorCampoTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private function servicio(): AutorizacionChecadorCampo
    {
        return new AutorizacionChecadorCampo();
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

    private function usuarioConAcceso(Enterprise $empresa, array $acceso = []): User
    {
        $user = User::factory()->create(['role' => 'user']);
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
        $this->darAccesoChecadorCampo($user, $empresa, $acceso);

        return $user;
    }

    private function emparejamiento(?User $user, Enterprise $empresa, array $overrides = []): DevicePairing
    {
        return DevicePairing::create(array_merge([
            'device_token_hash' => DevicePairing::hashToken(DevicePairing::generateToken()),
            'mode' => DevicePairing::MODE_SF_FIELD,
            'paired_by_user_id' => $user?->id,
            'enterprise_id' => $empresa->id,
            'last_used_at' => now(),
        ], $overrides));
    }

    public function test_superadmin_autorizado_sin_concesiones(): void
    {
        $empresa = $this->empresaSf();
        $admin = User::factory()->create(['role' => 'superadmin']);

        $this->assertTrue($this->servicio()->usuarioAutorizado($admin, $empresa));
        $this->assertSame($empresa->id, $this->servicio()->empresa()->id);
    }

    public function test_usuario_con_acceso_a_empresa_y_submodulo_autorizado(): void
    {
        $empresa = $this->empresaSf();
        $user = $this->usuarioConAcceso($empresa);

        $this->assertTrue($this->servicio()->usuarioAutorizado($user, $empresa));
    }

    public function test_sin_acceso_a_la_empresa_no_autorizado(): void
    {
        $empresa = $this->empresaSf();
        $user = User::factory()->create(['role' => 'user']);
        $this->darAccesoChecadorCampo($user, $empresa);

        $this->assertFalse($this->servicio()->usuarioAutorizado($user, $empresa));

        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'is_active' => false,
            'granted_at' => now(),
        ]);
        $this->assertFalse($this->servicio()->usuarioAutorizado($user, $empresa));
    }

    public function test_acceso_de_submodulo_inactivo_o_vencido_no_autorizado(): void
    {
        $empresa = $this->empresaSf();
        $inactivo = $this->usuarioConAcceso($empresa, ['is_active' => false]);
        $vencido = $this->usuarioConAcceso($empresa, ['expires_at' => now()->subDay()]);
        $vigente = $this->usuarioConAcceso($empresa, ['expires_at' => now()->addDay()]);

        $this->assertFalse($this->servicio()->usuarioAutorizado($inactivo, $empresa));
        $this->assertFalse($this->servicio()->usuarioAutorizado($vencido, $empresa));
        $this->assertTrue($this->servicio()->usuarioAutorizado($vigente, $empresa));
    }

    public function test_estado_revocado_gana_a_caducado(): void
    {
        $empresa = $this->empresaSf();
        $user = $this->usuarioConAcceso($empresa);
        $p = $this->emparejamiento($user, $empresa, [
            'revoked_at' => now()->subDays(40),
            'last_used_at' => now()->subDays(40),
        ]);

        $this->assertSame('dispositivo_revocado', $this->servicio()->estado($p));
        $this->assertTrue($p->isRevoked());
        $this->assertFalse($p->estaVigente());
    }

    public function test_estado_caducado_a_los_30_dias(): void
    {
        $empresa = $this->empresaSf();
        $user = $this->usuarioConAcceso($empresa);

        $viejo = $this->emparejamiento($user, $empresa, ['last_used_at' => now()->subDays(31)]);
        $this->assertSame('dispositivo_caducado', $this->servicio()->estado($viejo));
        $this->assertTrue($viejo->isExpired());

        $reciente = $this->emparejamiento($user, $empresa, ['last_used_at' => now()->subDays(29)]);
        $this->assertSame('ok', $this->servicio()->estado($reciente));
        $this->assertTrue($reciente->estaVigente());
    }

    public function test_estado_sin_permiso_si_el_usuario_se_borro_o_perdio_acceso(): void
    {
        $empresa = $this->empresaSf();
        $user = $this->usuarioConAcceso($empresa);
        $p = $this->emparejamiento($user, $empresa);
        $this->assertSame('ok', $this->servicio()->estado($p));

        $sinUsuario = $this->emparejamiento(null, $empresa);
        $this->assertSame('sin_permiso', $this->servicio()->estado($sinUsuario));

        UserEnterpriseAccess::where('user_id', $user->id)->update(['is_active' => false]);
        $this->assertSame('sin_permiso', $this->servicio()->estado($p->fresh()));

        $borrado = $this->usuarioConAcceso($empresa);
        $p2 = $this->emparejamiento($borrado, $empresa);
        $borrado->delete();
        $this->assertSame('sin_permiso', $this->servicio()->estado($p2->fresh()));
    }

    public function test_find_by_token_filtra_por_modo(): void
    {
        $empresa = $this->empresaSf();
        $raw = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SF_FIELD,
            'enterprise_id' => $empresa->id,
            'revoked_at' => now(),
        ]);

        $this->assertNull(DevicePairing::findByToken($raw, [DevicePairing::MODE_SELF, DevicePairing::MODE_KIOSK]));
        $encontrado = DevicePairing::findByToken($raw, [DevicePairing::MODE_SF_FIELD]);
        $this->assertNotNull($encontrado);
        $this->assertTrue($encontrado->isRevoked());
    }
}
