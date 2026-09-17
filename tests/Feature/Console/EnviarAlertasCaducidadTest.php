<?php

namespace Tests\Feature\Console;

use App\Console\Commands\EnviarAlertasCaducidad;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\SystemNotification;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
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

    public function test_usuario_con_acceso_en_dos_empresas_recibe_dos_notificaciones(): void
    {
        // Empresa 1: Splendid Farms (from fixture)
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['dias_alerta_caducidad' => 30]);
        $this->darStock($this->almacenA, $this->insumo, 2, 'VENC1', now()->subDay()->toDateString());

        // Empresa 2: Nueva empresa con su almacén
        $empresa2 = Enterprise::create([
            'name' => 'Canes Agro',
            'slug' => 'canesagro',
            'description' => 'Otra empresa agrícola',
            'is_active' => true,
        ]);

        $sucursal2 = Branch::create([
            'enterprise_id' => $empresa2->id,
            'code' => 'SUC-02',
            'name' => 'Sucursal Canes',
            'slug' => 'sucursal-canes',
        ]);

        $almacen2 = Entity::create([
            'branch_id' => $sucursal2->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-C',
            'name' => 'Almacén Canes',
        ]);

        $insumo2 = app(\App\Models\Product::class)->create([
            'code' => 'PROD-00002',
            'name' => 'Insecticida XYZ',
            'unit_id' => $this->unidad->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
            'dias_alerta_caducidad' => 30,
        ]);
        $insumo2->enterprises()->attach($empresa2->id);

        $this->darStock($almacen2, $insumo2, 2, 'VENC2', now()->subDay()->toDateString());

        // Usuario con acceso activo en ambas empresas
        $usuario = $this->crearUsuarioDeCampo([$this->almacenA]);
        UserEnterpriseAccess::create([
            'user_id' => $usuario->id,
            'enterprise_id' => $empresa2->id,
            'is_active' => true,
        ]);
        UserEntityAccess::create([
            'user_id' => $usuario->id,
            'entity_id' => $almacen2->id,
            'enterprise_id' => $empresa2->id,
        ]);

        // Ejecutar comando una sola vez
        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();

        // Debe haber exactamente 2 notificaciones: una por empresa
        $avisos = SystemNotification::where('title', EnviarAlertasCaducidad::TITULO)
            ->where('user_id', $usuario->id)
            ->get();

        $this->assertCount(2, $avisos);

        // Una para Splendid Farms (verifica el nombre del almacén en el mensaje)
        $avisoSplendid = $avisos->first(fn ($a) => str_contains($a->message, 'Almacén Campo A'));
        $this->assertNotNull($avisoSplendid);
        $this->assertEquals($this->empresa->id, $avisoSplendid->data['enterprise_id']);

        // Una para Canes Agro (verifica el nombre del almacén en el mensaje)
        $avisoCanes = $avisos->first(fn ($a) => str_contains($a->message, 'Almacén Canes'));
        $this->assertNotNull($avisoCanes);
        $this->assertEquals($empresa2->id, $avisoCanes->data['enterprise_id']);

        // Ejecutar nuevamente, no debe haber nuevas notificaciones
        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();
        $this->assertCount(2, SystemNotification::where('title', EnviarAlertasCaducidad::TITULO)
            ->where('user_id', $usuario->id)
            ->get());
    }
}
