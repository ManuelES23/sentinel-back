<?php

namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Services\RH\VerificadorPinEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class VerificadorPinEmpleadoTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function verificador(): VerificadorPinEmpleado
    {
        return app(VerificadorPinEmpleado::class);
    }

    public function test_el_pin_se_guarda_cifrado(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);

        $crudo = DB::table('employees')->where('id', $empleado->id)->value('pin');

        $this->assertNotSame('135790', $crudo);
        $this->assertTrue(Hash::check('135790', $crudo));
    }

    public function test_el_pin_no_queda_en_la_bitacora(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);
        $empleado->update(['pin' => '246802']);

        $logs = ActivityLog::where('model', 'Employee')->where('model_id', $empleado->id)->get();

        $this->assertGreaterThanOrEqual(2, $logs->count());
        $this->assertFalse(
            $logs->contains(fn ($log) => str_contains(json_encode($log->toArray()), '"pin"'))
        );
    }

    public function test_numero_y_pin_correctos_devuelven_al_empleado(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);

        $this->assertSame($empleado->id, $this->verificador()->verificar($empleado->employee_number, '135790')?->id);
    }

    public function test_normaliza_minusculas_y_espacios_y_respeta_ceros_a_la_izquierda(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['employee_number' => 'AB123456', 'pin' => '000123']);

        $this->assertSame($empleado->id, $this->verificador()->verificar(' ab 123456 ', '000123')?->id);
        $this->assertNull($this->verificador()->verificar('AB123456', '123'));
    }

    public function test_pin_incorrecto_numero_inexistente_e_inactivo_dan_null(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $activo = $this->createEmployee($empresa->id, ['pin' => '135790']);
        $inactivo = $this->createEmployee($empresa->id, ['pin' => '135790', 'status' => 'inactive']);

        $this->assertNull($this->verificador()->verificar($activo->employee_number, '000000'));
        $this->assertNull($this->verificador()->verificar('NO-EXISTE', '135790'));
        $this->assertNull($this->verificador()->verificar($inactivo->employee_number, '135790'));
    }

    public function test_cinco_errores_bloquean_quince_minutos(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);

        for ($i = 0; $i < VerificadorPinEmpleado::MAX_INTENTOS; $i++) {
            $this->assertNull($this->verificador()->verificar($empleado->employee_number, '000000'));
        }

        $empleado->refresh();
        $this->assertTrue($empleado->pin_locked_until->isFuture());
        $this->assertNull($this->verificador()->verificar($empleado->employee_number, '135790'));

        $this->travel(VerificadorPinEmpleado::MINUTOS_BLOQUEO + 1)->minutes();

        $this->assertSame($empleado->id, $this->verificador()->verificar($empleado->employee_number, '135790')?->id);
        $this->assertNull($empleado->fresh()->pin_locked_until);
    }

    public function test_un_acierto_reinicia_el_contador(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);

        foreach (range(1, 3) as $_) {
            $this->verificador()->verificar($empleado->employee_number, '000000');
        }
        $this->assertSame(3, $empleado->fresh()->pin_failed_attempts);

        $this->verificador()->verificar($empleado->employee_number, '135790');
        $this->assertSame(0, $empleado->fresh()->pin_failed_attempts);
    }

    public function test_desbloquear_libera_al_empleado(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);
        foreach (range(1, VerificadorPinEmpleado::MAX_INTENTOS) as $_) {
            $this->verificador()->verificar($empleado->employee_number, '000000');
        }

        $this->verificador()->desbloquear($empleado->fresh());

        $this->assertSame($empleado->id, $this->verificador()->verificar($empleado->employee_number, '135790')?->id);
    }

    public function test_la_migracion_cifra_pines_en_claro_y_es_reejecutable(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id);
        DB::table('employees')->where('id', $empleado->id)->update(['pin' => '246802']);

        $migracion = require database_path('migrations/2026_10_15_100000_hash_employee_pins_and_add_pin_lockout.php');
        $migracion->cifrarPines();
        $primero = DB::table('employees')->where('id', $empleado->id)->value('pin');

        $this->assertTrue(Hash::check('246802', $primero));

        $migracion->cifrarPines();
        $this->assertSame($primero, DB::table('employees')->where('id', $empleado->id)->value('pin'));
    }

    public function test_regenerar_pin_devuelve_el_pin_en_claro_y_desbloquea(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['pin' => '135790']);
        foreach (range(1, VerificadorPinEmpleado::MAX_INTENTOS) as $_) {
            $this->verificador()->verificar($empleado->employee_number, '000000');
        }

        $pin = $this->postJson("/api/grupoesplendido/rh/empleados/{$empleado->id}/regenerate-pin")
            ->assertOk()
            ->json('data.pin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertSame($empleado->id, $this->verificador()->verificar($empleado->employee_number, $pin)?->id);
    }

    public function test_alta_de_empleado_devuelve_su_pin_una_vez(): void
    {
        [, $empresa] = $this->createAuthenticatedRhUser();

        $respuesta = $this->postJson('/api/grupoesplendido/rh/empleados', [
            'enterprise_id' => $empresa->id,
            'first_name' => 'Ana',
            'last_name' => 'López',
            'hire_date' => '2024-01-15',
        ])->assertCreated();

        $pin = $respuesta->json('pin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertArrayNotHasKey('pin', $respuesta->json('data'));
        $this->assertTrue(Hash::check($pin, Employee::find($respuesta->json('data.id'))->pin));
    }
}
