<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\Enterprise;
use App\Models\InventoryStock;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * Encargado del almacén (Organización → Entidades) y filtro «solo faltantes» del reporte de existencias.
 */
class EncargadoYFaltantesTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const ORG = '/api/splendidfarms/administration/organizacion';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        // Admin por rol: entra a la empresa y ve todos los almacenes sin fila de acceso propia.
        $this->actingUser->update(['role' => 'admin']);
        Sanctum::actingAs($this->actingUser);
        $this->withHeaders(['X-Enterprise-Slug' => $this->enterprise->slug]);
    }

    private function otraEmpresa(): Enterprise
    {
        return Enterprise::create(['name' => 'Otra', 'slug' => 'otra', 'description' => 'x', 'is_active' => true]);
    }

    private function conAcceso(string $nombre, ?Enterprise $empresa = null, bool $activo = true): User
    {
        $usuario = User::factory()->create(['name' => $nombre, 'role' => 'user']);
        UserEnterpriseAccess::create([
            'user_id' => $usuario->id,
            'enterprise_id' => ($empresa ?? $this->enterprise)->id,
            'is_active' => $activo,
        ]);

        return $usuario;
    }

    public function test_asigna_encargado_con_acceso(): void
    {
        $encargado = $this->conAcceso('Ana Almacén');

        $this->putJson(self::ORG."/entidades/{$this->entity->id}", ['encargado_user_id' => $encargado->id])
            ->assertOk()
            ->assertJsonPath('data.encargado_user_id', $encargado->id)
            ->assertJsonPath('data.encargado.id', $encargado->id)
            ->assertJsonPath('data.encargado.name', 'Ana Almacén');

        $this->assertSame($encargado->id, (int) $this->entity->fresh()->encargado_user_id);

        $this->getJson(self::ORG.'/entidades')->assertOk()
            ->assertJsonPath('data.0.encargado.id', $encargado->id)
            ->assertJsonPath('data.0.encargado.name', 'Ana Almacén');
        $this->getJson(self::ORG."/entidades/{$this->entity->id}")->assertOk()
            ->assertJsonPath('data.encargado.name', 'Ana Almacén');

        // Quitarlo con null.
        $this->putJson(self::ORG."/entidades/{$this->entity->id}", ['encargado_user_id' => null])->assertOk()
            ->assertJsonPath('data.encargado', null);
        $this->assertNull($this->entity->fresh()->encargado_user_id);
        $this->getJson(self::ORG.'/entidades')->assertJsonPath('data.0.encargado', null);
    }

    public function test_encargado_sin_acceso_da_422(): void
    {
        $sinFila = User::factory()->create(['role' => 'user']);
        $inactivo = $this->conAcceso('Inactivo', null, false);
        $deOtraEmpresa = $this->conAcceso('Ajeno', $this->otraEmpresa());

        foreach ([$sinFila, $inactivo, $deOtraEmpresa] as $usuario) {
            $this->putJson(self::ORG."/entidades/{$this->entity->id}", ['encargado_user_id' => $usuario->id])
                ->assertStatus(422)
                ->assertJsonPath('message', 'El encargado debe tener acceso a la empresa.');
        }
        $this->assertNull($this->entity->fresh()->encargado_user_id);

        // Usuario inexistente: error de validación del campo.
        $this->putJson(self::ORG."/entidades/{$this->entity->id}", ['encargado_user_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['encargado_user_id']);

        // Al crear también se valida.
        $this->postJson(self::ORG.'/entidades', [
            'branch_id' => $this->entity->branch_id,
            'entity_type_id' => $this->entity->entity_type_id,
            'name' => 'Almacén nuevo',
            'encargado_user_id' => $sinFila->id,
        ])->assertStatus(422)->assertJsonPath('message', 'El encargado debe tener acceso a la empresa.');
    }

    public function test_lista_encargados_disponibles(): void
    {
        $this->conAcceso('Zulema');
        $this->conAcceso('Beto');
        $this->conAcceso('Inactiva', null, false);
        $this->conAcceso('Ajeno', $this->otraEmpresa());
        User::factory()->create(['name' => 'Sin acceso', 'role' => 'user']);

        $respuesta = $this->getJson(self::ORG.'/entidades/encargados-disponibles')
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(['Beto', 'Zulema'], collect($respuesta->json('data'))->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['id', 'name', 'email'], array_keys($respuesta->json('data.0')));
    }

    public function test_stock_solo_negativos_con_fecha(): void
    {
        $e = $this->entity->id;

        // Faltante de hoy: cruza a negativo con el servicio (negativo_desde = ahora).
        InventoryStock::updateStock($this->tarima->id, $e, null, 5, 2);
        InventoryStock::updateStock($this->tarima->id, $e, null, -8, 2, null, null, null, true);
        // Existencia sana.
        InventoryStock::updateStock($this->esquineros->id, $e, null, 10, 2);
        // Faltante anterior a la migración: sin negativo_desde, solo last_movement_at.
        InventoryStock::updateStock($this->fleje->id, $e, null, 1, 2);
        InventoryStock::updateStock($this->fleje->id, $e, null, -4, 2, null, null, null, true);
        DB::table('inventory_stock')->where('product_id', $this->fleje->id)
            ->update(['negativo_desde' => null, 'last_movement_at' => '2026-10-01 08:00:00']);

        $url = '/api/splendidfarms/inventario/reportes/stock';

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data.stock');

        $plano = $this->getJson($url.'?solo_negativos=1')->assertOk();
        $filas = collect($plano->json('data.stock'))->keyBy('product_id');
        $this->assertEqualsCanonicalizing([$this->tarima->id, $this->fleje->id], $filas->keys()->all());
        $this->assertNotNull($filas[$this->tarima->id]['negativo_desde']);
        $this->assertStringStartsWith(now()->utc()->format('Y-m-d'), $filas[$this->tarima->id]['negativo_desde']);
        $this->assertStringStartsWith('2026-10-01', $filas[$this->fleje->id]['negativo_desde']);
        $this->assertSame(2, $plano->json('data.totals.total_items'));

        // Agrupado por producto: la fecha viaja en cada ubicación.
        $agrupado = $this->getJson($url.'?solo_negativos=1&grouped_by_product=1')->assertOk();
        $this->assertCount(2, $agrupado->json('data.stock'));
        foreach ($agrupado->json('data.stock') as $grupo) {
            $this->assertNotNull($grupo['locations'][0]['negativo_desde']);
        }

        // Con el filtro de almacén que usa el aviso (entity_id) sigue funcionando.
        $this->getJson($url.'?solo_negativos=1&entity_id='.$e)->assertOk()->assertJsonCount(2, 'data.stock');

        // Sin el filtro, las filas sanas traen negativo_desde null.
        $todas = collect($this->getJson($url)->json('data.stock'))->keyBy('product_id');
        $this->assertNull($todas[$this->esquineros->id]['negativo_desde']);
    }
}
