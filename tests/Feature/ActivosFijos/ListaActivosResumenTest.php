<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Entity;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class ListaActivosResumenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const URL = '/api/splendidfarms/administration/activos-fijos/activos/resumen';
    private const URL_GE = '/api/grupoesplendido/administration/activos-fijos/activos/resumen';

    private AssetCategory $otraCategoria;
    private Entity $otraEntidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->otraCategoria = AssetCategory::create([
            'code' => 'TAC-010', 'name' => 'Maquinaria y equipos', 'icon' => 'Truck', 'is_active' => true,
        ]);
        $this->otraEntidad = Entity::create([
            'branch_id' => $this->branch->id, 'entity_type_id' => $this->entity->entity_type_id,
            'code' => 'OFC-X', 'name' => 'Empaque Charay', 'slug' => 'empaque-charay', 'is_active' => true,
        ]);

        // A: en uso, cómputo, oficinas, con responsable y valor, 2019
        $a = $this->crearActivo(['name' => 'A', 'status' => 'en_uso', 'purchase_value' => 1000, 'purchase_date' => '2019-03-01']);
        FixedAssetAssignment::create([
            'enterprise_id' => $a->enterprise_id, 'fixed_asset_id' => $a->id, 'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id, 'assignee_name' => 'Ana Ruiz', 'assigned_at' => '2026-01-10',
            'condition_out' => 'bueno', 'asset_snapshot' => [],
        ]);
        // B: en uso, maquinaria, empaque, sin responsable ni valor, sin fecha
        $this->crearActivo(['name' => 'B', 'status' => 'en_uso', 'category_id' => $this->otraCategoria->id, 'entity_id' => $this->otraEntidad->id]);
        // C: disponible, cómputo, oficinas, 2022
        $this->crearActivo(['name' => 'C', 'status' => 'disponible', 'purchase_date' => '2022-07-01']);
        // D: baja, cómputo, oficinas, 2025
        $this->crearActivo(['name' => 'D', 'status' => 'baja', 'purchase_date' => '2025-02-01']);
    }

    private function resumen(string $query = '', string $url = self::URL): array
    {
        return $this->getJson($url.$query)->assertOk()->json('data');
    }

    /** value => count de un grupo. */
    private function conteos(array $grupo): array
    {
        return collect($grupo)->pluck('count', 'value')->all();
    }

    public function test_devuelve_el_total_y_los_conteos_de_cada_grupo(): void
    {
        $r = $this->resumen();

        // assertEquals en los mapas: no importa el orden de las claves, solo los conteos.
        $this->assertSame(4, $r['total']);
        $this->assertEquals(
            ['en_uso' => 2, 'en_mantenimiento' => 0, 'disponible' => 1, 'resguardo' => 0, 'fuera_de_servicio' => 0, 'baja' => 1],
            $this->conteos($r['status']),
        );
        $this->assertSame(array_keys(FixedAsset::STATUSES), array_column($r['status'], 'value'));
        $this->assertEquals(
            [(string) $this->assetCategory->id => 3, (string) $this->otraCategoria->id => 1],
            $this->conteos($r['category']),
        );
        // ordenados por etiqueta
        $this->assertSame(['Equipos de cómputo', 'Maquinaria y equipos'], array_column($r['category'], 'label'));
        $this->assertEquals(
            [(string) $this->entity->id => 3, (string) $this->otraEntidad->id => 1],
            $this->conteos($r['entity']),
        );
        $this->assertSame(['Empaque Charay', 'Oficinas SF'], array_column($r['entity'], 'label'));
        $this->assertEquals(['si' => 1, 'no' => 3], $this->conteos($r['assigned']));
        $this->assertEquals(['si' => 1, 'no' => 3], $this->conteos($r['has_value']));
        $this->assertEquals(['a' => 1, 'b' => 1, 'c' => 1], $this->conteos($r['year']));
        $this->assertSame(1, $r['without_date']);
    }

    public function test_cada_grupo_ignora_su_propio_filtro_pero_respeta_los_demas(): void
    {
        $r = $this->resumen('?status=en_uso');

        // total y demás grupos solo cuentan los «en uso» (A y B)
        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $this->conteos($r['category'])[(string) $this->assetCategory->id]);
        $this->assertSame(1, $this->conteos($r['category'])[(string) $this->otraCategoria->id]);
        // el grupo Estado sigue mostrando todos los estados: no se pone en cero «Disponible»
        $this->assertEquals(
            ['en_uso' => 2, 'en_mantenimiento' => 0, 'disponible' => 1, 'resguardo' => 0, 'fuera_de_servicio' => 0, 'baja' => 1],
            $this->conteos($r['status']),
        );
    }

    public function test_el_grupo_tipo_ignora_el_filtro_de_tipo_y_los_otros_lo_aplican(): void
    {
        $r = $this->resumen('?category_id='.$this->otraCategoria->id);

        $this->assertSame(1, $r['total']);
        // ambos tipos siguen visibles en su propio grupo
        $this->assertEquals(
            [(string) $this->assetCategory->id => 3, (string) $this->otraCategoria->id => 1],
            $this->conteos($r['category']),
        );
        // pero los demás grupos solo cuentan lo de maquinaria (B)
        $this->assertSame(1, $this->conteos($r['status'])['en_uso']);
        $this->assertSame(0, $this->conteos($r['status'])['disponible']);
        $this->assertSame([(string) $this->otraEntidad->id => 1], $this->conteos($r['entity']));
    }

    public function test_incluye_con_cero_los_ids_seleccionados_que_no_tienen_activos(): void
    {
        // «disponible» solo existe en cómputo (C); maquinaria seleccionada queda sin activos
        $r = $this->resumen('?status=disponible&category_id='.$this->otraCategoria->id);

        $this->assertSame(0, $r['total']);
        $conteos = $this->conteos($r['category']);
        $this->assertSame(1, $conteos[(string) $this->assetCategory->id]);
        $this->assertSame(0, $conteos[(string) $this->otraCategoria->id]);
        $fila = collect($r['category'])->firstWhere('value', (string) $this->otraCategoria->id);
        $this->assertSame('Maquinaria y equipos', $fila['label']);
    }

    public function test_los_conteos_respetan_la_busqueda(): void
    {
        $r = $this->resumen('?search=ana');

        $this->assertSame(1, $r['total']);
        $this->assertSame(1, $this->conteos($r['status'])['en_uso']);
    }

    public function test_no_cuenta_activos_de_otra_empresa_y_ge_ve_todo(): void
    {
        $this->crearActivo(['name' => 'GE-1', 'status' => 'en_uso', 'enterprise_id' => $this->corporativo->id]);

        $this->assertSame(4, $this->resumen()['total']);
        $this->assertSame(5, $this->resumen('', self::URL_GE)['total']);
        $this->assertSame(1, $this->resumen('?enterprise_id='.$this->corporativo->id, self::URL_GE)['total']);
    }

    public function test_sin_permiso_de_ver_activos_responde_403(): void
    {
        $otro = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($otro, $this->enterprise, 'asignaciones', ['view']);
        Sanctum::actingAs($otro);

        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_un_usuario_sin_acceso_a_la_empresa_no_obtiene_conteos(): void
    {
        $ajeno = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($ajeno);

        // Igual que AlcanceYPermisosTest: sin acceso a la empresa el middleware responde 403.
        $this->getJson(self::URL)->assertForbidden();
    }
}
