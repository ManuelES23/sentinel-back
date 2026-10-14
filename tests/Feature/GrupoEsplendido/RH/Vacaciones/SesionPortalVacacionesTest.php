<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Employee;
use App\Models\VacationPortalSession;
use App\Services\RH\SesionPortalVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class SesionPortalVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private SesionPortalVacaciones $sesiones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->sesiones = app(SesionPortalVacaciones::class);
    }

    /** @return array{token: string, sesion: VacationPortalSession} */
    private function iniciar(?Employee $empleado = null): array
    {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_USER_AGENT' => 'TelefonoDePrueba']);

        return $this->sesiones->iniciar($empleado ?? $this->empleado($this->empresa), $request);
    }

    public function test_iniciar_guarda_solo_el_hash_y_caduca_en_dos_horas(): void
    {
        ['token' => $token, 'sesion' => $sesion] = $this->iniciar();

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $sesion->fresh()->token_hash);
        $this->assertDatabaseMissing('vacation_portal_sessions', ['token_hash' => $token]);
        $this->assertSame('10.0.0.7', $sesion->ip);
        $this->assertSame('TelefonoDePrueba', $sesion->user_agent);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $sesion->fresh()->expires_at->timestamp, 5);
        $this->assertArrayNotHasKey('token_hash', $sesion->toArray());
    }

    public function test_validar_devuelve_la_sesion_con_su_empleado_y_toca_la_actividad(): void
    {
        $empleado = $this->empleado($this->empresa);
        ['token' => $token, 'sesion' => $sesion] = $this->iniciar($empleado);
        $this->travel(5)->minutes();

        $valida = $this->sesiones->validar($token);

        $this->assertNotNull($valida);
        $this->assertSame($sesion->id, $valida->id);
        $this->assertSame($empleado->id, $valida->employee->id);
        $this->assertEqualsWithDelta(now()->timestamp, $sesion->fresh()->last_activity_at->timestamp, 2);
    }

    public function test_token_vacio_desconocido_o_de_otra_longitud_no_vale(): void
    {
        $this->iniciar();

        $this->assertNull($this->sesiones->validar(null));
        $this->assertNull($this->sesiones->validar(''));
        $this->assertNull($this->sesiones->validar('corto'));
        $this->assertNull($this->sesiones->validar(Str::random(64)));
    }

    public function test_caduca_tras_quince_minutos_sin_uso(): void
    {
        ['token' => $token] = $this->iniciar();
        $this->travel(16)->minutes();

        $this->assertNull($this->sesiones->validar($token));
    }

    public function test_el_uso_la_mantiene_viva_pero_no_pasa_de_dos_horas(): void
    {
        ['token' => $token] = $this->iniciar();

        for ($i = 0; $i < 11; $i++) {
            $this->travel(10)->minutes();
            $this->assertNotNull($this->sesiones->validar($token), "Debía seguir viva a los ".(($i + 1) * 10).' min');
        }

        $this->travel(11)->minutes(); // 121 min desde el login
        $this->assertNull($this->sesiones->validar($token));
    }

    public function test_revocada_no_vale(): void
    {
        ['token' => $token, 'sesion' => $sesion] = $this->iniciar();
        $this->sesiones->revocar($sesion);

        $this->assertNull($this->sesiones->validar($token));
        $this->assertNotNull($sesion->fresh()->revoked_at);
    }

    public function test_empleado_dado_de_baja_o_borrado_ya_no_vale(): void
    {
        $baja = $this->empleado($this->empresa);
        ['token' => $tokenBaja] = $this->iniciar($baja);
        $baja->update(['status' => Employee::STATUS_TERMINATED]);

        $borrado = $this->empleado($this->empresa);
        ['token' => $tokenBorrado] = $this->iniciar($borrado);
        $borrado->delete();

        $this->assertNull($this->sesiones->validar($tokenBaja));
        $this->assertNull($this->sesiones->validar($tokenBorrado));
    }

    public function test_revocar_todas_solo_toca_al_empleado_indicado(): void
    {
        $empleado = $this->empleado($this->empresa);
        $otro = $this->empleado($this->empresa);
        ['token' => $t1] = $this->iniciar($empleado);
        ['token' => $t2] = $this->iniciar($empleado);
        ['token' => $tOtro] = $this->iniciar($otro);

        $this->assertSame(2, $this->sesiones->revocarTodas($empleado->id));

        $this->assertNull($this->sesiones->validar($t1));
        $this->assertNull($this->sesiones->validar($t2));
        $this->assertNotNull($this->sesiones->validar($tOtro));
    }

    public function test_la_limpieza_borra_solo_lo_vencido_o_revocado_hace_mas_de_siete_dias(): void
    {
        $empleado = $this->empleado($this->empresa);
        $crear = fn (array $fechas) => VacationPortalSession::create(array_merge([
            'employee_id' => $empleado->id,
            'token_hash' => hash('sha256', Str::random(64)),
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
        ], $fechas));

        $vencidaVieja = $crear(['expires_at' => now()->subDays(8), 'last_activity_at' => now()->subDays(8)]);
        $revocadaVieja = $crear(['revoked_at' => now()->subDays(8)]);
        $vencidaReciente = $crear(['expires_at' => now()->subDay(), 'last_activity_at' => now()->subDay()]);
        $revocadaReciente = $crear(['revoked_at' => now()->subDays(2)]);
        $activa = $crear([]);

        $this->artisan('vacaciones:limpiar-sesiones-portal')->assertSuccessful();

        $this->assertModelMissing($vencidaVieja);
        $this->assertModelMissing($revocadaVieja);
        $this->assertModelExists($vencidaReciente);
        $this->assertModelExists($revocadaReciente);
        $this->assertModelExists($activa);
    }
}
