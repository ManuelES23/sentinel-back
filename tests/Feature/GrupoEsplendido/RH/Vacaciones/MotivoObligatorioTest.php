<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** El motivo es obligatorio cuando el empleado solicita (portal y Mi Perfil); RH al capturar lo deja opcional. */
class MotivoObligatorioTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const MENSAJE = 'Escribe para qué son estos días. Con una frase basta (mínimo 10 letras).';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function fechas(int $semanas = 0): array
    {
        $inicio = today()->next(Carbon::MONDAY)->addWeeks(2 + $semanas);

        return ['start_date' => $inicio->toDateString(), 'end_date' => $inicio->copy()->addDays(4)->toDateString()];
    }

    /** Hace la petición como el empleado, por el camino indicado: portal o perfil. */
    private function solicitar(string $via, array $datos)
    {
        if ($via === 'portal') {
            $empleado = $this->empleado($this->empresa);
            $token = $this->postJson('/api/portal-vacaciones/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
                ->assertOk()->json('data.token');

            return $this->postJson('/api/portal-vacaciones/solicitudes', $datos, ['X-Portal-Token' => $token]);
        }

        $empleado = $this->empleadoConUsuario($this->empresa);
        Sanctum::actingAs($empleado->user);

        return $this->postJson('/api/profile/vacation-request', $datos);
    }

    public static function vias(): array
    {
        return [['portal'], ['perfil']];
    }

    #[DataProvider('vias')]
    public function test_sin_motivo_responde_422(string $via): void
    {
        $this->solicitar($via, $this->fechas())->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    #[DataProvider('vias')]
    public function test_solo_espacios_responde_422(string $via): void
    {
        $this->solicitar($via, $this->fechas() + ['reason' => '         '])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    #[DataProvider('vias')]
    public function test_se_recorta_antes_de_validar_y_el_mensaje_es_el_acordado(string $via): void
    {
        $this->solicitar($via, $this->fechas() + ['reason' => '  Viaje cor  '])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', self::MENSAJE);
    }

    #[DataProvider('vias')]
    public function test_sin_motivo_el_mensaje_tambien_es_el_acordado(string $via): void
    {
        $this->solicitar($via, $this->fechas())->assertStatus(422)->assertJsonPath('errors.reason.0', self::MENSAJE);
    }

    #[DataProvider('vias')]
    public function test_un_motivo_valido_crea_la_solicitud_recortada(string $via): void
    {
        $respuesta = $this->solicitar($via, $this->fechas() + ['reason' => '  Viaje a Mazatlán  ']);

        if ($via === 'portal') {
            $respuesta->assertCreated();
        } else {
            $respuesta->assertOk()->assertJsonPath('status', 'success');
        }
        $this->assertDatabaseHas('vacation_requests', ['reason' => 'Viaje a Mazatlán']);
    }

    #[DataProvider('vias')]
    public function test_mas_de_500_caracteres_responde_422(string $via): void
    {
        $this->solicitar($via, $this->fechas() + ['reason' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    #[DataProvider('vias')]
    public function test_500_caracteres_exactos_pasan(string $via): void
    {
        $this->solicitar($via, $this->fechas() + ['reason' => str_repeat('a', 500)])->assertSuccessful();
    }

    // ===== RH sigue igual: el motivo es opcional =====

    public function test_rh_captura_sin_motivo_y_con_ya_autorizada(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create();
        Sanctum::actingAs($rh);
        $this->concederVacaciones($rh, 'create', 'aprobar');
        $this->regla(1, 'user', ['user_id' => User::factory()->create()->id]);
        $base = '/api/grupoesplendido/rh/vacaciones';

        $this->postJson($base, ['employee_id' => $this->empleado($this->empresa)->id] + $this->fechas())
            ->assertCreated();

        $this->postJson($base, [
            'employee_id' => $this->empleado($this->empresa)->id,
            'ya_autorizada' => true,
            'nota_autorizacion' => 'Autorizada por el director el 10 oct',
        ] + $this->fechas(1))->assertCreated();
    }
}
