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

    public function test_no_notifica_por_entidades_staladas_o_no_pertenecientes(): void
    {
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['dias_alerta_caducidad' => 30]);

        // Stock con vencimiento en almacénA (válido)
        $this->darStock($this->almacenA, $this->insumo, 2, 'VENC', now()->subDay()->toDateString());

        // Crear almacén en otra sucursal (no perteneciente a la empresa del usuario)
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa',
            'description' => 'Empresa para test de staladas',
            'is_active' => true,
        ]);
        $otraSucursal = Branch::create([
            'enterprise_id' => $otraEmpresa->id,
            'code' => 'SUC-X',
            'name' => 'Sucursal X',
            'slug' => 'sucursal-x',
        ]);
        $almacenAjeno = Entity::create([
            'branch_id' => $otraSucursal->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-X',
            'name' => 'Almacén Ajeno',
        ]);

        // Stock con vencimiento en almacénAjeno
        $this->darStock($almacenAjeno, $this->insumo, 2, 'VENC-X', now()->subDay()->toDateString());

        // Usuario con acceso en la empresa correcta
        $usuario = $this->crearUsuarioDeCampo([$this->almacenA]);

        // Asignar usuario al almacén ajeno (stale grant)
        UserEntityAccess::create([
            'user_id' => $usuario->id,
            'entity_id' => $almacenAjeno->id,
            'enterprise_id' => $this->empresa->id,  // Empresa incorrecta
        ]);

        // Ejecutar comando
        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();

        // Debe haber exactamente 1 notificación (solo para almacénA)
        $avisos = SystemNotification::where('title', EnviarAlertasCaducidad::TITULO)
            ->where('user_id', $usuario->id)
            ->get();

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Almacén Campo A', $avisos->first()->message);
        $this->assertStringNotContainsString('Almacén Ajeno', $avisos->first()->message);
    }

    public function test_miembro_sin_almacenes_asignados_no_hace_fallar_el_comando(): void
    {
        $this->setUpAlmacenFixtures();
        $this->insumo->update(['dias_alerta_caducidad' => 30]);
        $this->darStock($this->almacenA, $this->insumo, 2, 'VENC', now()->subDay()->toDateString());

        // Miembro de la empresa (user_enterprise_access activo) pero SIN ninguna
        // fila en user_entity_access y sin permiso ver_todos_almacenes: antes
        // esto hacía fallar el comando completo con un fatal error.
        $sinGrants = $this->crearUsuarioDeCampo();
        $conGrants = $this->crearUsuarioDeCampo([$this->almacenA]);

        $this->artisan('inventario:alertas-caducidad')->assertSuccessful();

        $this->assertSame(0, SystemNotification::where('user_id', $sinGrants->id)
            ->where('title', EnviarAlertasCaducidad::TITULO)
            ->count());

        $avisos = SystemNotification::where('title', EnviarAlertasCaducidad::TITULO)
            ->where('user_id', $conGrants->id)
            ->get();
        $this->assertCount(1, $avisos);
    }
}
