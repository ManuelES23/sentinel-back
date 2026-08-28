<?php
// sentinel-back/tests/Feature/GrupoEsplendido/RH/DevicePairingControllerTest.php
namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\DevicePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class DevicePairingControllerTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    public function test_pair_with_valid_number_and_pin_creates_self_pairing_and_returns_token(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id, ['pin' => '135790']);

        $response = $this->postJson('/api/checador/pair', [
            'employee_number' => $employee->employee_number,
            'pin' => '135790',
        ]);

        $response->assertStatus(201);
        $token = $response->json('data.device_token');
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));

        $this->assertDatabaseHas('device_pairings', [
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $employee->id,
        ]);

        $pairing = DevicePairing::first();
        $this->assertNotSame($token, $pairing->device_token_hash); // nunca se guarda en texto plano
    }

    public function test_pair_with_wrong_pin_is_rejected(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id, ['pin' => '135790']);

        $this->postJson('/api/checador/pair', [
            'employee_number' => $employee->employee_number,
            'pin' => '000000',
        ])->assertStatus(422);

        $this->assertDatabaseCount('device_pairings', 0);
    }

    public function test_pair_with_inactive_employee_is_rejected(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id, ['pin' => '135790', 'status' => 'inactive']);

        $this->postJson('/api/checador/pair', [
            'employee_number' => $employee->employee_number,
            'pin' => '135790',
        ])->assertStatus(422);
    }

    public function test_pair_requires_employee_number_and_pin(): void
    {
        $this->postJson('/api/checador/pair', [])->assertStatus(422);
    }

    public function test_pair_kiosk_requires_authentication(): void
    {
        $this->postJson('/api/grupoesplendido/rh/checador-fijo/pair')
            ->assertStatus(401);
    }

    public function test_pair_kiosk_creates_kiosk_pairing_linked_to_authenticated_user(): void
    {
        [$user] = $this->createAuthenticatedRhUser();

        $response = $this->postJson('/api/grupoesplendido/rh/checador-fijo/pair', [
            'label' => 'Entrada oficina principal',
        ]);

        $response->assertStatus(201);
        $token = $response->json('data.device_token');
        $this->assertNotEmpty($token);

        $this->assertDatabaseHas('device_pairings', [
            'mode' => DevicePairing::MODE_KIOSK,
            'paired_by_user_id' => $user->id,
            'label' => 'Entrada oficina principal',
        ]);
    }

    public function test_pair_kiosk_label_is_optional(): void
    {
        $this->createAuthenticatedRhUser();

        $this->postJson('/api/grupoesplendido/rh/checador-fijo/pair')
            ->assertStatus(201);
    }
}
