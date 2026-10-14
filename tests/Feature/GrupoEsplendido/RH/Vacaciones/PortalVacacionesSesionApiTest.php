<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\DevicePairing;
use App\Models\Employee;
use App\Models\User;
use App\Services\RH\VerificadorPinEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class PortalVacacionesSesionApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/portal-vacaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function entrar(Employee $empleado, string $pin = '123456'): string
    {
        return $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => $pin])
            ->assertOk()
            ->json('data.token');
    }

    /** Encabezado por petición (no withHeaders, que se queda pegado para las siguientes). */
    private function portal(string $token): array
    {
        return ['X-Portal-Token' => $token];
    }

    public function test_login_correcto_devuelve_token_y_solo_datos_minimos(): void
    {
        $empleado = $this->empleado($this->empresa);

        $respuesta = $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.empleado.numero', $empleado->employee_number)
            ->assertJsonPath('data.empleado.empresa', 'Splendid Farms');

        $token = $respuesta->json('data.token');
        $this->assertSame(64, strlen($token));
        $this->assertNotNull($respuesta->json('data.expires_at'));
        $this->assertSame(['nombre', 'numero', 'empresa', 'puesto', 'departamento', 'fecha_ingreso'], array_keys($respuesta->json('data.empleado')));
        $this->assertDatabaseHas('vacation_portal_sessions', ['employee_id' => $empleado->id, 'token_hash' => hash('sha256', $token)]);
    }

    public function test_login_acepta_el_numero_en_minusculas_y_con_espacios(): void
    {
        $empleado = $this->empleado($this->empresa);

        $this->postJson(self::BASE.'/login', ['employee_number' => '  '.strtolower($empleado->employee_number).' ', 'pin' => '123456'])
            ->assertOk();
    }

    public function test_toda_falla_de_identidad_responde_igual_y_no_crea_sesion(): void
    {
        $empleado = $this->empleado($this->empresa);
        $inactivo = $this->empleado($this->empresa, ['status' => Employee::STATUS_INACTIVE]);

        foreach ([
            [$empleado->employee_number, '000000'],
            ['NO-EXISTE-99', '123456'],
            [$inactivo->employee_number, '123456'],
        ] as [$numero, $pin]) {
            $this->postJson(self::BASE.'/login', ['employee_number' => $numero, 'pin' => $pin])
                ->assertStatus(422)
                ->assertJsonPath('message', VerificadorPinEmpleado::MENSAJE_ERROR);
        }

        $this->assertDatabaseCount('vacation_portal_sessions', 0);
    }

    public function test_cinco_errores_bloquean_aunque_luego_el_pin_sea_correcto(): void
    {
        $empleado = $this->empleado($this->empresa);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '999999'])->assertStatus(422);
        }

        $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', VerificadorPinEmpleado::MENSAJE_ERROR);
    }

    public function test_yo_devuelve_la_lista_blanca_y_el_saldo_derivado(): void
    {
        $empleado = $this->empleado($this->empresa, [
            'curp' => 'CURP800101HDFXXX01', 'rfc' => 'RFCX800101AB1', 'nss' => '12345678901',
            'salary' => 98765.43, 'email' => 'privado@ejemplo.com', 'mobile' => '6671234567',
            'address_street' => 'Calle Secreta', 'emergency_contact' => 'Contacto Privado',
        ]);
        $this->solicitud($empleado, 'pending', 3);
        $this->solicitud($empleado, 'approved', 2);
        $token = $this->entrar($empleado);

        $respuesta = $this->getJson(self::BASE.'/yo', $this->portal($token))
            ->assertOk()
            ->assertJsonPath('data.saldo.acumulados', 42)
            ->assertJsonPath('data.saldo.en_revision', 3)
            ->assertJsonPath('data.saldo.usados', 2)
            ->assertJsonPath('data.saldo.disponibles', 37)
            ->assertJsonPath('data.saldo.sin_fecha_ingreso', false);

        $this->assertSame(['nombre', 'numero', 'empresa', 'puesto', 'departamento', 'fecha_ingreso'], array_keys($respuesta->json('data.empleado')));
        foreach (['98765', 'CURP800101', 'RFCX800101', '12345678901', 'privado@ejemplo.com', '6671234567', 'Calle Secreta', 'Contacto Privado'] as $privado) {
            $this->assertStringNotContainsString($privado, $respuesta->getContent());
        }
    }

    public function test_sin_token_o_con_token_invalido_responde_401(): void
    {
        $this->getJson(self::BASE.'/yo')->assertStatus(401)->assertJsonPath('message', 'Tu sesión terminó, vuelve a entrar.');
        $this->getJson(self::BASE.'/yo', $this->portal(str_repeat('x', 64)))->assertStatus(401);
    }

    public function test_logout_revoca_la_sesion(): void
    {
        $token = $this->entrar($this->empleado($this->empresa));

        $this->postJson(self::BASE.'/logout', [], $this->portal($token))->assertOk();

        $this->getJson(self::BASE.'/yo', $this->portal($token))->assertStatus(401);
    }

    public function test_caduca_por_inactividad(): void
    {
        $token = $this->entrar($this->empleado($this->empresa));
        $this->travel(16)->minutes();

        $this->getJson(self::BASE.'/yo', $this->portal($token))->assertStatus(401);
    }

    public function test_un_token_de_sanctum_no_abre_el_portal(): void
    {
        $tokenSanctum = User::factory()->create()->createToken('prueba')->plainTextToken;

        $this->getJson(self::BASE.'/yo', ['Authorization' => 'Bearer '.$tokenSanctum])->assertStatus(401);
    }

    public function test_un_token_del_checador_no_abre_el_portal(): void
    {
        $empleado = $this->empleado($this->empresa);
        $crudo = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($crudo),
            'mode' => DevicePairing::MODE_SELF,
            'paired_by_employee_id' => $empleado->id,
        ]);

        $this->getJson(self::BASE.'/yo', ['X-Device-Token' => $crudo])->assertStatus(401);
        $this->getJson(self::BASE.'/yo', $this->portal($crudo))->assertStatus(401);
    }

    public function test_un_token_del_portal_no_abre_rutas_internas(): void
    {
        $token = $this->entrar($this->empleado($this->empresa));

        $this->getJson('/api/auth/user', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
        $this->getJson('/api/auth/user', $this->portal($token))->assertStatus(401);
        $this->getJson('/api/grupoesplendido/rh/vacaciones', $this->portal($token))->assertStatus(401);
    }

    public function test_el_limite_es_por_sesion_y_no_por_ip(): void
    {
        $tokenA = $this->entrar($this->empleado($this->empresa));
        $tokenB = $this->entrar($this->empleado($this->empresa));

        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::BASE.'/yo', $this->portal($tokenA))->assertOk();
        }

        $this->getJson(self::BASE.'/yo', $this->portal($tokenA))->assertStatus(429);
        $this->getJson(self::BASE.'/yo', $this->portal($tokenB))->assertOk();
    }

    public function test_el_login_tiene_su_propio_limite_por_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::BASE.'/login', ['employee_number' => 'NADIE-'.$i, 'pin' => '123456'])->assertStatus(422);
        }

        $this->postJson(self::BASE.'/login', ['employee_number' => 'NADIE-10', 'pin' => '123456'])->assertStatus(429);
    }
}
