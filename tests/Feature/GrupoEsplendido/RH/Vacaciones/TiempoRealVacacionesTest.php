<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Broadcasting\ModuleChannel;
use App\Events\VacationRequestUpdated;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class TiempoRealVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    public function test_el_evento_va_al_modulo_de_gestion_de_rh_y_al_canal_del_empleado(): void
    {
        $evento = new VacationRequestUpdated('created', ['employee_id' => 7], 'grupoesplendido', 'rh', 'gestion');

        $this->assertSame(
            ['private-module.grupoesplendido.rh.gestion', 'private-employee.7.vacations'],
            array_map(fn ($canal) => $canal->name, $evento->broadcastOn()),
        );
    }

    public function test_cada_aviso_se_emite_al_modulo_corporativo_aunque_el_empleado_sea_de_otra_empresa(): void
    {
        Event::fake([VacationRequestUpdated::class]);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        app(VacationRequestService::class)->crear(
            $this->empleado($this->empresa), $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL,
        );

        Event::assertDispatched(VacationRequestUpdated::class, fn (VacationRequestUpdated $e) => $e->action === 'created'
            && $e->enterprise === 'grupoesplendido'
            && $e->application === 'rh'
            && $e->module === 'gestion');
    }

    public function test_un_usuario_de_rh_con_acceso_puede_unirse_al_canal_y_el_canal_anterior_no_existe(): void
    {
        $modulo = $this->submoduloVacaciones()->module;
        $usuario = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $usuario->id, 'enterprise_id' => $this->corporativo->id, 'is_active' => true]);
        UserApplicationAccess::create(['user_id' => $usuario->id, 'application_id' => $modulo->application_id, 'is_active' => true]);
        UserModuleAccess::create(['user_id' => $usuario->id, 'module_id' => $modulo->id, 'is_active' => true]);

        $canal = new ModuleChannel();

        $this->assertTrue($canal->join($usuario, 'grupoesplendido', 'rh', 'gestion'));
        $this->assertFalse($canal->join($usuario, 'grupoesplendido', 'administration', 'rh'));
        $this->assertFalse($canal->join(User::factory()->create(), 'grupoesplendido', 'rh', 'gestion'));
    }
}
