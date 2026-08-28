<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/DevicePairingModelTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\DevicePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class DevicePairingModelTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    public function test_generate_token_produces_unique_high_entropy_strings(): void
    {
        $a = DevicePairing::generateToken();
        $b = DevicePairing::generateToken();

        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, $b);
    }

    public function test_hash_token_is_deterministic_and_not_reversible_looking(): void
    {
        $raw = 'un-token-de-prueba-fijo';

        $hash1 = DevicePairing::hashToken($raw);
        $hash2 = DevicePairing::hashToken($raw);

        $this->assertSame($hash1, $hash2);
        $this->assertNotSame($raw, $hash1);
        $this->assertSame(64, strlen($hash1)); // sha256 hex = 64 chars
    }

    public function test_find_active_by_token_returns_matching_non_revoked_pairing(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $raw = DevicePairing::generateToken();

        $pairing = DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
        ]);

        $found = DevicePairing::findActiveByToken($raw);

        $this->assertNotNull($found);
        $this->assertSame($pairing->id, $found->id);
    }

    public function test_find_active_by_token_ignores_revoked_pairing(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $raw = DevicePairing::generateToken();

        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
            'revoked_at' => now(),
        ]);

        $this->assertNull(DevicePairing::findActiveByToken($raw));
    }

    public function test_find_active_by_token_returns_null_for_unknown_token(): void
    {
        $this->assertNull(DevicePairing::findActiveByToken('token-que-no-existe'));
    }
}
