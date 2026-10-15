<?php
// sentinel-back/tests/Feature/ActivosFijos/BandejaAsignacionesMovimientosTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\Area;
use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class BandejaAsignacionesMovimientosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private const BASE = '/api/splendidfarms/administration/activos-fijos/asignaciones/movimientos';
    private const BASE_GE = '/api/grupoesplendido/administration/activos-fijos/asignaciones/movimientos';

    private FixedAssetAssignment $a;
    private FixedAssetAssignment $l;
    private FixedAssetAssignment $s;
    private Area $recepcion;
    private AssetCategory $transporte;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 10, 0, 0, 'America/Mazatlan'));
        $this->setUpAssetFixtures();
        $this->otorgarActivos($this->actingUser, $this->enterprise, 'asignaciones', self::PERMISOS_CRUD);
        $this->otorgarActivos($this->actingUser, $this->corporativo, 'asignaciones', self::PERMISOS_CRUD);
        Sanctum::actingAs($this->actingUser);

        $hp = Brand::create(['code' => 'MRC-002', 'name' => 'HP', 'is_active' => true]);
        $this->transporte = AssetCategory::create(['code' => 'TAC-003', 'name' => 'Transporte', 'icon' => 'Truck', 'is_active' => true]);
        $this->recepcion = Area::create(['code' => 'ARE-91', 'name' => 'Recepción', 'slug' => 'recepcion-91', 'is_active' => true]);

        // L (primera, id menor): entregada en 2023 y devuelta el 9 de octubre (reasignación).
        $this->l = $this->asignacion(['name' => 'Laptop Lenovo IdeaPad', 'category_id' => $this->transporte->id], [
            'assigned_at' => '2023-05-15', 'returned_at' => '2026-10-09', 'assignee_name' => 'Jorge Salas',
            'condition_in' => 'regular', 'return_reason' => 'reemplazo', 'signed_document_path' => 'l.pdf',
        ]);
        // A: entregada el 9 de octubre, activa y sin carta.
        $this->a = $this->asignacion(['name' => 'Laptop HP Victus 15'], [
            'assigned_at' => '2026-10-09', 'assignee_name' => 'Ana Ruiz',
            'assignee_position' => 'Analista de costos', 'assignee_department' => 'Contabilidad',
        ]);
        // S: entregada en diciembre de 2025, activa, firmada, en Recepción y de marca HP.
        $this->s = $this->asignacion(['name' => 'Servidor 2', 'brand_id' => $hp->id], [
            'assigned_at' => '2025-12-01', 'assignee_name' => 'Daniela Ochoa',
            'area_id' => $this->recepcion->id, 'signed_document_path' => 's.pdf',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asignacion(array|FixedAsset $activo, array $datos): FixedAssetAssignment
    {
        $activo = $activo instanceof FixedAsset ? $activo : $this->crearActivo($activo);

        return FixedAssetAssignment::create(array_merge([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'assignee_type' => 'user',
            'assignee_id' => $this->actingUser->id,
            'assigned_by' => $this->actingUser->id,
            'condition_out' => 'bueno',
            'asset_snapshot' => ['code' => $activo->code],
        ], $datos));
    }

    /** [tipo, id de la asignación] de cada movimiento de la respuesta. */
    private function pares(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data.data'))
            ->map(fn ($m) => [$m['tipo'], $m['asignacion']['id']])
            ->all();
    }

    public function test_una_asignacion_devuelta_da_dos_movimientos_y_en_una_reasignacion_la_entrega_nueva_va_sobre_la_devolucion(): void
    {
        // El 9 de octubre: la entrega de A (id mayor) queda sobre la devolución de L.
        $this->assertSame([
            ['entrega', $this->a->id],
            ['devolucion', $this->l->id],
            ['entrega', $this->s->id],
            ['entrega', $this->l->id],
        ], $this->pares(self::BASE));

        $this->getJson(self::BASE)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.data.0.fecha', '2026-10-09')
            ->assertJsonPath('data.data.1.fecha', '2026-10-09')
            ->assertJsonPath('data.data.3.fecha', '2023-05-15');
    }

    public function test_la_misma_asignacion_entregada_y_devuelta_el_mismo_dia_pone_la_devolucion_arriba(): void
    {
        $x = $this->asignacion(['name' => 'Proyector'], [
            'assigned_at' => '2026-10-12', 'returned_at' => '2026-10-12', 'assignee_name' => 'Rosa Mena',
            'condition_in' => 'bueno', 'return_reason' => 'otro',
        ]);

        $this->assertSame([
            ['devolucion', $x->id],
            ['entrega', $x->id],
            ['entrega', $this->a->id],
            ['devolucion', $this->l->id],
            ['entrega', $this->s->id],
            ['entrega', $this->l->id],
        ], $this->pares(self::BASE));
    }

    public function test_dos_reasignaciones_el_mismo_dia_se_leen_en_orden_inverso_de_lo_ocurrido(): void
    {
        $activo = $this->crearActivo(['name' => 'Camioneta']);
        $devuelta = ['assigned_at' => '2026-10-13', 'returned_at' => '2026-10-13', 'condition_in' => 'bueno', 'return_reason' => 'otro'];
        $primera = $this->asignacion($activo, $devuelta + ['assignee_name' => 'Primera Persona']);
        $segunda = $this->asignacion($activo, $devuelta + ['assignee_name' => 'Segunda Persona']);

        // Lo ocurrido: E1 -> D1 -> E2 -> D2; la bitácora, la más reciente primero.
        $this->assertSame([
            ['devolucion', $segunda->id],
            ['entrega', $segunda->id],
            ['devolucion', $primera->id],
            ['entrega', $primera->id],
        ], array_slice($this->pares(self::BASE), 0, 4));
    }

    public function test_cada_movimiento_trae_la_asignacion_con_sus_relaciones(): void
    {
        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('data.data.0.asignacion.asset.name', 'Laptop HP Victus 15')
            ->assertJsonPath('data.data.0.asignacion.asset.category.name', 'Equipos de cómputo')
            ->assertJsonPath('data.data.0.asignacion.enterprise.name', 'Splendid Farms')
            ->assertJsonPath('data.data.0.asignacion.activa', true)
            ->assertJsonPath('data.data.0.asignacion.tiene_carta_firmada', false)
            ->assertJsonPath('data.data.2.asignacion.area.name', 'Recepción')
            ->assertJsonPath('data.data.2.asignacion.asset.brand.name', 'HP')
            ->assertJsonMissingPath('data.data.1.asignacion.signed_document_path');
    }

    public function test_filtra_por_tipo(): void
    {
        $this->getJson(self::BASE.'?tipo=entrega')->assertOk()->assertJsonPath('data.total', 3);
        $this->assertSame([['devolucion', $this->l->id]], $this->pares(self::BASE.'?tipo=devolucion'));
        $this->getJson(self::BASE.'?tipo=otra')->assertOk()->assertJsonPath('data.total', 4);
    }

    public function test_filtra_por_tarjeta(): void
    {
        $this->assertSame([['entrega', $this->a->id], ['entrega', $this->s->id]], $this->pares(self::BASE.'?tarjeta=activas'));
        $this->assertSame([['entrega', $this->a->id]], $this->pares(self::BASE.'?tarjeta=cartas'));
        $this->assertSame([['entrega', $this->a->id]], $this->pares(self::BASE.'?tarjeta=entregas_mes'));
        $this->assertSame([['devolucion', $this->l->id]], $this->pares(self::BASE.'?tarjeta=devoluciones_mes'));

        $this->getJson(self::BASE.'?tarjeta=activas&tipo=devolucion')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonCount(0, 'data.data')
            ->assertJsonCount(0, 'data.meses');
    }

    public function test_busca_por_puesto_area_y_marca_con_escape(): void
    {
        $this->assertSame([['entrega', $this->a->id]], $this->pares(self::BASE.'?search=analista'));
        $this->assertSame([['entrega', $this->s->id]], $this->pares(self::BASE.'?search=recep'));
        // «hp» está en el nombre de A y en la marca de S.
        $this->assertSame([['entrega', $this->a->id], ['entrega', $this->s->id]], $this->pares(self::BASE.'?search=hp'));
        $this->getJson(self::BASE.'?search='.urlencode('%'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson(self::BASE.'?search=_')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_filtra_por_area_y_por_tipo_de_activo(): void
    {
        $this->assertSame([['entrega', $this->s->id]], $this->pares(self::BASE.'?area_id='.$this->recepcion->id));
        $this->assertSame(
            [['devolucion', $this->l->id], ['entrega', $this->l->id]],
            $this->pares(self::BASE.'?category_id='.$this->transporte->id),
        );
    }

    public function test_pagina_y_trae_los_totales_de_cada_mes_de_la_pagina(): void
    {
        $this->getJson(self::BASE.'?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.meses', [['mes' => '2026-10', 'entregas' => 1, 'devoluciones' => 1]]);

        $this->getJson(self::BASE.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.data.0.asignacion.id', $this->s->id)
            ->assertJsonPath('data.meses', [
                ['mes' => '2025-12', 'entregas' => 1, 'devoluciones' => 0],
                ['mes' => '2023-05', 'entregas' => 1, 'devoluciones' => 0],
            ]);

        // Los totales del mes cuentan con los filtros, no solo lo que trae la página.
        $this->getJson(self::BASE.'?per_page=1&tipo=entrega')
            ->assertOk()
            ->assertJsonPath('data.meses', [['mes' => '2026-10', 'entregas' => 1, 'devoluciones' => 0]]);
    }

    public function test_respeta_el_alcance_y_la_empresa_en_ge(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $this->asignacion(['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001', 'name' => 'Laptop SP'], ['assigned_at' => '2026-10-03', 'assignee_name' => 'Persona SP']);

        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson(self::BASE.'?enterprise_id='.$porvenir->id)->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson(self::BASE_GE)->assertOk()->assertJsonPath('data.total', 5);
        $this->getJson(self::BASE_GE.'?enterprise_id='.$porvenir->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.asignacion.enterprise.name', 'Splendid by Porvenir');
    }

    public function test_la_busqueda_no_alcanza_asignaciones_de_otra_empresa(): void
    {
        $porvenir = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        $this->asignacion(
            ['enterprise_id' => $porvenir->id, 'code' => 'SP-AF-000001', 'name' => 'Laptop SP'],
            ['assigned_at' => '2026-10-03', 'assignee_name' => 'Zacarías Porvenir'],
        );

        $this->getJson(self::BASE.'?search=zacarias')->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.data');
        $this->getJson(self::BASE.'?search='.urlencode('Zacarías'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson(self::BASE_GE.'?search='.urlencode('Zacarías'))->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_un_usuario_con_solo_asignaciones_view_ve_la_bandeja_completa(): void
    {
        $soloVer = User::factory()->create(['role' => 'user']);
        $this->otorgarActivos($soloVer, $this->enterprise, 'asignaciones', ['view']);
        Sanctum::actingAs($soloVer);

        $base = '/api/splendidfarms/administration/activos-fijos/asignaciones';
        $this->getJson($base.'/cartas-pendientes')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.total', 4);

        $opciones = $this->getJson($base.'/resumen')->assertOk()->json('data.opciones');
        $this->assertNotEmpty($opciones['areas']);
        $this->assertNotEmpty($opciones['tipos']);
    }

    public function test_requiere_view(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_los_parametros_no_escalares_o_fuera_de_lista_se_ignoran(): void
    {
        $this->getJson(self::BASE.'?tipo[]=entrega&tarjeta[]=activas&search[]=x&area_id[]=1&category_id=abc&page[]=2&per_page=0')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.per_page', 25);
        $this->getJson(self::BASE.'?per_page=500')->assertOk()->assertJsonPath('data.per_page', 100);
    }
}
