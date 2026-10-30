<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Enterprise;
use App\Models\Holiday;
use App\Models\User;
use App\Services\RH\EmpresaDeFestivos;
use App\Services\RH\FestivosOficiales;
use App\Services\RH\WorkingDaysCalculator;
use App\Support\RH\FestivosGeneralesAEmpresa;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Festivos por empresa: cada empresa los suyos o los de otra, y una excepción por empleado. */
class FestivosPorEmpresaTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Enterprise $sbp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->sbp = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Comercio', 'is_active' => true]);
        // Un martes con festivo en cada empresa: 2026-11-17 (SF) y 2026-11-24 (SBP).
        Holiday::create(['date' => '2026-11-17', 'name' => 'Festivo SF', 'enterprise_id' => $this->empresa->id]);
        Holiday::create(['date' => '2026-11-24', 'name' => 'Festivo SBP', 'enterprise_id' => $this->sbp->id]);
    }

    /** Días descontables del 16 al 28 de noviembre de 2026 (lunes a sábado, dos semanas = 12). */
    private function descontables($empleado): array
    {
        return app(WorkingDaysCalculator::class)->calcular($empleado->fresh(), Carbon::parse('2026-11-16'), Carbon::parse('2026-11-28'))['descontables'];
    }

    // ===== Cálculo de días =====

    public function test_cada_empleado_descuenta_sin_los_festivos_de_su_empresa(): void
    {
        $deSf = $this->descontables($this->empleado($this->empresa));
        $deSbp = $this->descontables($this->empleado($this->sbp));

        $this->assertNotContains('2026-11-17', $deSf);
        $this->assertContains('2026-11-24', $deSf);
        $this->assertContains('2026-11-17', $deSbp);
        $this->assertNotContains('2026-11-24', $deSbp);
    }

    public function test_una_empresa_que_sigue_a_otra_usa_sus_festivos(): void
    {
        $this->corporativo->update(['holiday_source_enterprise_id' => $this->empresa->id]);

        $dias = $this->descontables($this->empleado($this->corporativo));

        $this->assertNotContains('2026-11-17', $dias);
        $this->assertContains('2026-11-24', $dias);
    }

    public function test_la_excepcion_del_empleado_gana_sobre_la_de_su_empresa(): void
    {
        $this->corporativo->update(['holiday_source_enterprise_id' => $this->empresa->id]);
        $empleado = $this->empleado($this->corporativo, ['holiday_source_enterprise_id' => $this->sbp->id]);

        $dias = $this->descontables($empleado);

        $this->assertContains('2026-11-17', $dias);
        $this->assertNotContains('2026-11-24', $dias);
        $this->assertSame($this->sbp->id, app(EmpresaDeFestivos::class)->deEmpleado($empleado->fresh()));
    }

    public function test_un_festivo_viejo_sin_empresa_sigue_aplicando_a_todas(): void
    {
        Holiday::create(['date' => '2026-11-19', 'name' => 'General viejo']);

        $this->assertNotContains('2026-11-19', $this->descontables($this->empleado($this->sbp)));
    }

    // ===== Festivos oficiales =====

    public function test_los_6_de_estados_unidos_con_el_dia_observado(): void
    {
        $oficiales = app(FestivosOficiales::class);

        $this->assertSame(
            ['2026-01-01', '2026-05-25', '2026-07-03', '2026-09-07', '2026-11-26', '2026-12-25'],
            array_column($oficiales->paraAnio(2026, FestivosOficiales::ESTADOS_UNIDOS), 'date'),
        );
        // 2027: el 4 de julio es domingo (→ lunes 5) y Navidad sábado (→ viernes 24).
        $this->assertSame(
            ['2027-01-01', '2027-05-31', '2027-07-05', '2027-09-06', '2027-11-25', '2027-12-24'],
            array_column($oficiales->paraAnio(2027, FestivosOficiales::ESTADOS_UNIDOS), 'date'),
        );
        $this->assertSame('Independence Day (observado)', $oficiales->paraAnio(2026, FestivosOficiales::ESTADOS_UNIDOS)[2]['name']);
    }

    public function test_el_comando_carga_los_de_una_empresa_y_pais(): void
    {
        $this->artisan('vacaciones:festivos', ['anio' => 2026, '--empresa' => 'splendidbyporvenir', '--pais' => 'us'])->assertSuccessful();
        $this->artisan('vacaciones:festivos', ['anio' => 2026, '--empresa' => 'splendidbyporvenir', '--pais' => 'us'])->assertSuccessful();

        $this->assertSame(6, Holiday::where('enterprise_id', $this->sbp->id)->where('is_official', true)->count());
        $this->artisan('vacaciones:festivos', ['anio' => 2026, '--empresa' => 'no-existe'])->assertFailed();
        $this->artisan('vacaciones:festivos', ['anio' => 2026, '--pais' => 'fr'])->assertFailed();
    }

    public function test_el_comando_sin_empresa_carga_los_mexicanos_en_splendid_farms(): void
    {
        $this->artisan('vacaciones:festivos', ['anio' => 2027])->assertSuccessful();

        $this->assertSame(7, Holiday::where('enterprise_id', $this->empresa->id)->where('is_official', true)->count());
        $this->assertSame(0, Holiday::whereNull('enterprise_id')->count());
    }

    // ===== API de Festivos =====

    private function rhConFestivos(string ...$permisos): User
    {
        $this->submoduloFestivos();
        $rh = User::factory()->create();
        $this->concederFestivos($rh, ...$permisos);
        Sanctum::actingAs($rh);

        return $rh;
    }

    public function test_cargar_oficiales_desde_la_pantalla_por_empresa_y_pais(): void
    {
        $this->rhConFestivos('create');
        $url = '/api/grupoesplendido/rh/festivos/cargar-oficiales';

        $this->postJson($url, ['year' => 2026, 'enterprise_id' => $this->sbp->id, 'pais' => 'us'])
            ->assertOk()->assertJsonPath('data.creados', 6)->assertJsonPath('data.existentes', 0);
        $this->postJson($url, ['year' => 2026, 'enterprise_id' => $this->sbp->id, 'pais' => 'us'])
            ->assertOk()->assertJsonPath('data.creados', 0);
        $this->postJson($url, ['year' => 2026])->assertStatus(422)->assertJsonValidationErrors('enterprise_id');
        $this->postJson($url, ['year' => 2026, 'enterprise_id' => $this->sbp->id, 'pais' => 'fr'])->assertStatus(422)->assertJsonValidationErrors('pais');
    }

    public function test_un_festivo_nuevo_exige_empresa(): void
    {
        $this->rhConFestivos('create');

        $this->postJson('/api/grupoesplendido/rh/festivos', ['date' => '2026-12-12', 'name' => 'Guadalupe'])
            ->assertStatus(422)->assertJsonValidationErrors('enterprise_id');
    }

    public function test_lista_y_cambia_que_festivos_sigue_cada_empresa(): void
    {
        $this->rhConFestivos('view');
        $url = '/api/grupoesplendido/rh/festivos/empresas';

        $lista = $this->getJson($url.'?year=2026')->assertOk()->json('data');
        $sf = collect($lista)->firstWhere('id', $this->empresa->id);
        $this->assertSame(1, $sf['festivos_propios']);
        $this->assertNull($sf['holiday_source_enterprise_id']);

        $this->putJson($url."/{$this->corporativo->id}", ['holiday_source_enterprise_id' => $this->empresa->id])->assertForbidden();

        $this->concederFestivos(auth()->user(), 'edit');
        $this->putJson($url."/{$this->corporativo->id}", ['holiday_source_enterprise_id' => $this->empresa->id])
            ->assertOk()->assertJsonPath('data.holiday_source_enterprise_id', $this->empresa->id);
        $this->putJson($url."/{$this->corporativo->id}", ['holiday_source_enterprise_id' => null])
            ->assertOk()->assertJsonPath('data.holiday_source_enterprise_id', null);
    }

    public function test_no_se_encadenan_empresas_ni_una_sigue_a_si_misma(): void
    {
        $this->rhConFestivos('view', 'edit');
        $url = '/api/grupoesplendido/rh/festivos/empresas';
        $this->corporativo->update(['holiday_source_enterprise_id' => $this->empresa->id]);

        $this->putJson($url."/{$this->sbp->id}", ['holiday_source_enterprise_id' => $this->sbp->id])
            ->assertStatus(422)->assertJsonValidationErrors('holiday_source_enterprise_id');
        // SBP no puede seguir a GE, que ya sigue a SF.
        $this->putJson($url."/{$this->sbp->id}", ['holiday_source_enterprise_id' => $this->corporativo->id])
            ->assertStatus(422)->assertJsonValidationErrors('holiday_source_enterprise_id');
        // SF no puede seguir a otra mientras GE la sigue.
        $this->putJson($url."/{$this->empresa->id}", ['holiday_source_enterprise_id' => $this->sbp->id])
            ->assertStatus(422)->assertJsonValidationErrors('holiday_source_enterprise_id');
    }

    public function test_festivos_del_calendario_del_equipo_de_una_empresa_que_sigue_a_otra(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'view');
        Sanctum::actingAs($rh);
        $this->corporativo->update(['holiday_source_enterprise_id' => $this->empresa->id]);
        $url = '/api/grupoesplendido/rh/vacaciones/festivos-rango?desde=2026-11-01&hasta=2026-11-30';

        $this->assertSame(['Festivo SF'], array_column($this->getJson($url."&enterprise_id={$this->corporativo->id}")->assertOk()->json('data'), 'name'));
        $this->assertSame(['Festivo SBP'], array_column($this->getJson($url."&enterprise_id={$this->sbp->id}")->json('data'), 'name'));
        $this->assertCount(2, $this->getJson($url)->json('data'));
    }

    // ===== Empleado =====

    public function test_el_empleado_guarda_su_excepcion_y_la_de_su_propia_empresa_se_vuelve_nula(): void
    {
        [$rh] = [User::factory()->create()];
        Sanctum::actingAs($rh);
        $empleado = $this->empleado($this->corporativo);
        $url = "/api/grupoesplendido/rh/empleados/{$empleado->id}";

        $this->putJson($url, ['holiday_source_enterprise_id' => $this->sbp->id])->assertOk()
            ->assertJsonPath('data.holiday_source_enterprise_id', $this->sbp->id);
        $this->putJson($url, ['holiday_source_enterprise_id' => $this->corporativo->id])->assertOk()
            ->assertJsonPath('data.holiday_source_enterprise_id', null);
        $this->putJson($url, ['holiday_source_enterprise_id' => 99999])->assertStatus(422);
    }

    // ===== Migración de los festivos generales =====

    public function test_los_generales_pasan_a_splendid_farms_y_las_demas_la_siguen_menos_sbp(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad']);
        Holiday::create(['date' => '2026-11-17', 'name' => 'Repetido con el de SF']);

        FestivosGeneralesAEmpresa::aplicar();

        $this->assertSame(0, Holiday::whereNull('enterprise_id')->count());
        $this->assertTrue(Holiday::where('enterprise_id', $this->empresa->id)->where('date', '2026-12-25')->exists());
        $this->assertSame(1, Holiday::where('enterprise_id', $this->empresa->id)->where('date', '2026-11-17')->count());
        $this->assertSame($this->empresa->id, $this->corporativo->fresh()->holiday_source_enterprise_id);
        $this->assertNull($this->sbp->fresh()->holiday_source_enterprise_id);
        $this->assertNull($this->empresa->fresh()->holiday_source_enterprise_id);
    }
}
