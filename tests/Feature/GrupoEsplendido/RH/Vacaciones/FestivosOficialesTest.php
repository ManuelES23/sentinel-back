<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Holiday;
use App\Services\RH\FestivosOficiales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FestivosOficialesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fechas_de_2026_con_lunes_moviles(): void
    {
        $fechas = array_column(app(FestivosOficiales::class)->paraAnio(2026), 'date');

        $this->assertSame(['2026-01-01', '2026-02-02', '2026-03-16', '2026-05-01', '2026-09-16', '2026-11-16', '2026-12-25'], $fechas);
    }

    public function test_2027_y_el_cambio_de_poder_ejecutivo_en_2030(): void
    {
        $festivos = app(FestivosOficiales::class);

        $this->assertSame(['2027-01-01', '2027-02-01', '2027-03-15', '2027-05-01', '2027-09-16', '2027-11-15', '2027-12-25'], array_column($festivos->paraAnio(2027), 'date'));
        $this->assertContains('2030-10-01', array_column($festivos->paraAnio(2030), 'date'));
        $this->assertNotContains('2029-10-01', array_column($festivos->paraAnio(2029), 'date'));
    }

    public function test_el_comando_carga_los_oficiales_sin_duplicar(): void
    {
        $this->artisan('vacaciones:festivos', ['anio' => 2026])->assertSuccessful();
        $this->artisan('vacaciones:festivos', ['anio' => 2026])->assertSuccessful();

        $this->assertSame(7, Holiday::count());
        $this->assertTrue(Holiday::where('date', '2026-09-16')->whereNull('enterprise_id')->where('is_official', true)->exists());
    }

    public function test_el_comando_rechaza_un_anio_invalido(): void
    {
        $this->artisan('vacaciones:festivos', ['anio' => 1990])->assertFailed();
    }
}
