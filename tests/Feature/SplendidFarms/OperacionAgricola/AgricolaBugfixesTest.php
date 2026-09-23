<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\Branch;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\RequisicionCampo;
use App\Models\Temporada;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Models\ZonaCultivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cubre los bugs encontrados en la auditoría del módulo agrícola (2026-09-17):
 * tope de hectáreas con lotes dibujados en mapa, zonas de temporada,
 * borrados que dejaban huérfanos, filtros vacíos y límites de caracteres.
 */
class AgricolaBugfixesTest extends TestCase
{
    use RefreshDatabase;

    private const OA = '/api/splendidfarms/operacion-agricola/agricola';
    private const ADMIN = '/api/splendidfarms/administration/agricola';

    private User $user;
    private Temporada $temporada;
    private Productor $productor;
    private ZonaCultivo $zona;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $cultivo = Cultivo::create(['nombre' => 'Mango']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $this->user->id,
        ]);
        $this->productor = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);
        $this->zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);
    }

    private function loteConMapa(): Lote
    {
        // Lote dibujado en el mapa: superficie manual vacía
        $lote = Lote::create([
            'productor_id' => $this->productor->id,
            'zona_cultivo_id' => $this->zona->id,
            'nombre' => 'Keitt A',
            'superficie' => null,
            'superficie_calculada' => 12.5,
            'coordenadas' => [[25.79, -108.99], [25.79, -108.98], [25.80, -108.98]],
            'is_active' => true,
        ]);

        // Las etapas exigen que el lote esté en la temporada
        $this->temporada->asignarLote($lote->id, $this->temporada->cultivo_id);

        return $lote;
    }

    public function test_etapa_se_puede_crear_en_lote_dibujado_en_mapa(): void
    {
        $lote = $this->loteConMapa();

        $r = $this->postJson(self::OA.'/etapas', [
            'lote_id' => $lote->id,
            'nombre' => 'Etapa 1',
            'superficie' => 5,
            'temporada_id' => $this->temporada->id,
        ]);
        $r->assertCreated();

        $this->assertEquals(5, (float) Etapa::where('lote_id', $lote->id)->sum('superficie'));
    }

    public function test_superficie_disponible_contempla_la_superficie_del_mapa(): void
    {
        $lote = $this->loteConMapa();

        $this->getJson(self::OA.'/etapas/superficie-disponible?lote_id='.$lote->id)
            ->assertOk()
            ->assertJsonPath('data.superficie_total', 12.5)
            ->assertJsonPath('data.superficie_disponible', 12.5);
    }

    public function test_mover_etapa_a_un_lote_mas_chico_se_rechaza(): void
    {
        $grande = $this->loteConMapa();
        $chico = Lote::create([
            'productor_id' => $this->productor->id,
            'nombre' => 'Chico',
            'superficie' => 1,
            'is_active' => true,
        ]);

        $etapa = Etapa::create([
            'lote_id' => $grande->id,
            'nombre' => 'Etapa 1',
            'superficie' => 10,
            'orden' => 1,
            'is_active' => true,
        ]);

        // Sin tocar la superficie: antes pasaba y el lote quedaba excedido
        $this->putJson(self::OA.'/etapas/'.$etapa->id, ['lote_id' => $chico->id])
            ->assertStatus(422);

        $this->assertEquals($grande->id, $etapa->fresh()->lote_id);
    }

    public function test_actualizar_zona_devuelve_sus_lotes(): void
    {
        $this->loteConMapa();

        $this->putJson(self::OA.'/zonas-cultivo/'.$this->zona->id, ['nombre' => 'Zona Norte 2'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Zona Norte 2')
            ->assertJsonCount(1, 'data.lotes');
    }

    public function test_no_se_puede_borrar_un_productor_con_lotes(): void
    {
        $this->loteConMapa();

        $this->deleteJson(self::OA.'/productores/'.$this->productor->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted($this->productor);
    }

    public function test_no_se_puede_borrar_una_zona_con_lotes(): void
    {
        $this->loteConMapa();

        $this->deleteJson(self::OA.'/zonas-cultivo/'.$this->zona->id)
            ->assertStatus(422);

        $this->assertNotSoftDeleted($this->zona);
    }

    public function test_lote_de_administracion_copia_la_superficie_del_mapa(): void
    {
        $response = $this->postJson(self::ADMIN.'/lotes', [
            'productor_id' => $this->productor->id,
            'nombre' => 'Dibujado',
            'coordenadas' => [[25.79, -108.99], [25.79, -108.98], [25.80, -108.98]],
            'superficie_calculada' => 8.25,
        ])->assertCreated();

        $lote = Lote::findOrFail($response->json('data.id'));
        $this->assertEquals(8.25, (float) $lote->superficie);

        // Y por lo tanto admite etapas
        $this->temporada->asignarLote($lote->id, $this->temporada->cultivo_id);
        $this->postJson(self::OA.'/etapas', [
            'lote_id' => $lote->id,
            'nombre' => 'Etapa 1',
            'superficie' => 8,
            'temporada_id' => $this->temporada->id,
        ])->assertCreated();
    }

    public function test_no_se_puede_bajar_la_superficie_del_lote_debajo_de_sus_etapas(): void
    {
        // Lote capturado a mano (sin mapa): la superficie manual es la efectiva
        $lote = Lote::create([
            'productor_id' => $this->productor->id,
            'nombre' => 'Manual',
            'superficie' => 12,
            'is_active' => true,
        ]);
        $this->temporada->asignarLote($lote->id, $this->temporada->cultivo_id);

        Etapa::create([
            'lote_id' => $lote->id,
            'nombre' => 'Etapa 1',
            'superficie' => 10,
            'orden' => 1,
            'is_active' => true,
        ]);

        $this->putJson(self::ADMIN.'/lotes/'.$lote->id, ['superficie' => 2])
            ->assertStatus(422);

        $this->putJson(self::OA.'/lotes/'.$lote->id, [
            'productor_id' => $this->productor->id,
            'superficie' => 2,
            'coordenadas' => [],
        ])->assertStatus(422);

        $this->assertEquals(12, (float) $lote->fresh()->superficie_efectiva);
    }

    public function test_no_se_puede_bajar_la_superficie_calculada_debajo_de_las_etapas(): void
    {
        $lote = $this->loteConMapa(); // 12.5 ha calculadas
        Etapa::create([
            'lote_id' => $lote->id,
            'nombre' => 'Etapa 1',
            'superficie' => 10,
            'orden' => 1,
            'is_active' => true,
        ]);

        // La superficie efectiva la manda la calculada: bajarla a 1 ha
        // dejaría 10 ha de etapas en un lote de 1 ha.
        $this->putJson(self::ADMIN.'/lotes/'.$lote->id, [
            'superficie' => 100,
            'superficie_calculada' => 1,
        ])->assertStatus(422);

        $this->assertEquals(12.5, (float) $lote->fresh()->superficie_efectiva);
    }

    public function test_subir_la_superficie_manual_con_mapa_vigente_no_se_rechaza(): void
    {
        $lote = $this->loteConMapa();
        Etapa::create([
            'lote_id' => $lote->id,
            'nombre' => 'Etapa 1',
            'superficie' => 10,
            'orden' => 1,
            'is_active' => true,
        ]);

        // Baja la manual pero la calculada (12.5) sigue cubriendo las etapas
        $this->putJson(self::ADMIN.'/lotes/'.$lote->id, ['superficie' => 5])
            ->assertOk();
    }

    public function test_zonas_de_la_temporada_se_listan_y_se_asignan(): void
    {
        $this->postJson(self::ADMIN."/temporadas/{$this->temporada->id}/zonas-cultivo", [
            'zona_cultivo_id' => $this->zona->id,
            'superficie_asignada' => 10,
        ])->assertCreated();

        $this->getJson(self::ADMIN."/temporadas/{$this->temporada->id}/zonas-cultivo")
            ->assertOk()
            ->assertJsonPath('0.id', $this->zona->id)
            ->assertJsonPath('0.nombre', 'Zona Norte');
    }

    public function test_productores_de_la_temporada_devuelve_la_direccion(): void
    {
        $this->productor->update(['direccion' => 'Ejido El Carrizo']);
        $this->temporada->productores()->attach($this->productor->id, ['is_active' => true]);

        $this->getJson(self::ADMIN."/temporadas/{$this->temporada->id}/productores")
            ->assertOk()
            ->assertJsonPath('0.ubicacion', 'Ejido El Carrizo');
    }

    public function test_direccion_mayor_a_255_se_rechaza_con_422(): void
    {
        $this->postJson(self::ADMIN.'/productores', [
            'tipo' => 'externo',
            'nombre' => 'Largo',
            'direccion' => str_repeat('a', 300),
        ])->assertStatus(422);
    }

    public function test_filtros_vacios_no_vacian_el_listado_de_costeo(): void
    {
        $this->getJson(self::OA."/costeo?temporada_id={$this->temporada->id}&lote_id=&categoria=")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_filtro_de_status_vacio_no_vacia_las_requisiciones(): void
    {
        // Desde el Task 6, index() exige empresa (X-Enterprise-Slug) y filtra
        // por almacenes visibles, así que la requisición necesita
        // enterprise_id/almacen_id vinculados al usuario para seguir siendo
        // visible en la prueba.
        $empresa = Enterprise::create(['name' => 'Agro Bugfixes', 'slug' => 'agro-bugfixes', 'description' => 'Empresa de prueba', 'is_active' => true]);
        UserEnterpriseAccess::create(['user_id' => $this->user->id, 'enterprise_id' => $empresa->id, 'is_active' => true]);
        $sucursal = Branch::create(['enterprise_id' => $empresa->id, 'code' => 'SUC-BF', 'name' => 'Sucursal Bugfixes', 'slug' => 'sucursal-bugfixes']);
        $tipo = EntityType::create(['code' => 'CAMPO-BF', 'name' => 'Campo', 'slug' => 'campo-bf']);
        $almacen = Entity::create(['branch_id' => $sucursal->id, 'entity_type_id' => $tipo->id, 'code' => 'ALM-BF', 'name' => 'Almacén Bugfixes']);
        UserEntityAccess::create(['user_id' => $this->user->id, 'entity_id' => $almacen->id, 'enterprise_id' => $empresa->id]);

        RequisicionCampo::create([
            'numero_requisicion' => 'RC-2026-00001',
            'enterprise_id' => $empresa->id,
            'almacen_id' => $almacen->id,
            'temporada_id' => $this->temporada->id,
            'solicitante_user_id' => $this->user->id,
            'fecha_solicitud' => now()->toDateString(),
            'prioridad' => 'media',
            'status' => RequisicionCampo::STATUS_BORRADOR,
        ]);

        $this->getJson(self::OA."/requisiciones?temporada_id={$this->temporada->id}&status=", ['X-Enterprise-Slug' => 'agro-bugfixes'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }
}
