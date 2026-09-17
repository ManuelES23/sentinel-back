<?php

namespace Tests\Feature\Console;

use App\Console\Commands\EnviarAlertasCaducidad;
use App\Models\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class EnviarAlertasCaducidadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    public function test_notifica_una_vez_por_dia_solo_a_quien_ve_el_almacen(): void
    {
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['dias_alerta_caducidad' => 30]);
        $this->darStock($this->almacenA, $this->insumo, 2, 'VENC', now()->subDay()->toDateString());
        $this->darStock($this->almacenA, $this->insumo, 2, 'PRONTO', now()->addDays(10)->toDateString());
        $this->darStock($this->almacenB, $this->insumo, 2, 'LEJOS', now()->addDays(90)->toDateString());

        $encargadoA = $this->crearUsuarioDeCampo([$this->almacenA]);
        $encargadoB = $this->crearUsuarioDeCampo([$this->almacenB]);

        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();
        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();

        $avisos = SystemNotification::where('title', EnviarAlertasCaducidad::TITULO)->get();
        $this->assertCount(1, $avisos);
        $this->assertSame($encargadoA->id, $avisos->first()->user_id);
        $this->assertStringContainsString('1 lote(s) vencido(s)', $avisos->first()->message);
        $this->assertStringContainsString('1 por caducar', $avisos->first()->message);
        $this->assertSame(0, SystemNotification::where('user_id', $encargadoB->id)->count());
    }
}
