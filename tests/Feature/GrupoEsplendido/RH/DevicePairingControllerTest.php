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

    /**
     * Prueba de aislamiento real del throttle de /checador/pair (5/min por
     * IP). Antes de la corrección, este límite y el throttle:30,1 del grupo
     * compartían la misma clave del RateLimiter (sin un tercer segmento de
     * prefijo), así que el cupo real efectivo era mucho menor y se
     * compartía con cualquier otra ruta de /checador/* — ver hallazgo
     * Bloqueante de la revisión final del branch.
     */
    public function test_pair_throttle_is_isolated_from_other_checador_routes(): void
    {
        // Agota el cupo aislado de 5/min de /checador/pair.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/checador/pair', [])->assertStatus(422);
        }
        $this->postJson('/api/checador/pair', [])->assertStatus(429);

        // Otra ruta de /checador/* (server-time, pública, sin token) NO
        // debe verse afectada por el cupo agotado de pair — prueba que
        // son limitadores independientes, no el mismo contador compartido.
        $this->getJson('/api/checador/server-time')->assertStatus(200);
    }

    public function test_hitting_other_checador_routes_does_not_drain_pairs_isolated_budget(): void
    {
        // Golpea otra ruta de /checador/* varias veces (bien por debajo del
        // techo de 30/min del grupo) antes de tocar /checador/pair.
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/checador/server-time')->assertStatus(200);
        }

        // El cupo propio de pair sigue intacto: las primeras 5 pasan, la
        // sexta es la que dispara su propio límite aislado.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/checador/pair', [])->assertStatus(422);
        }
        $this->postJson('/api/checador/pair', [])->assertStatus(429);
    }
}
