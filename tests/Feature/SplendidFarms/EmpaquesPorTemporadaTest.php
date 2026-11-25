<?php

namespace Tests\Feature\SplendidFarms;

use App\Models\Cultivo;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\RecepcionEmpaque;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * «Empaques que operan en cada temporada»: `empaque_ids` en el alta/edición
 * de temporadas, su salida en Operación Agrícola y el endpoint de opciones
 * del formulario admin.
 */
class EmpaquesPorTemporadaTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/agricola/temporadas';
    private const URL_OA = '/api/splendidfarms/operacion-agricola/temporadas';

    private EntityType $tipoEmpaque;
    private Entity $empaqueA;
    private Entity $empaqueB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->tipoEmpaque = EntityType::create([
            'code' => 'EMPAQUE',
            'name' => 'Empaque',
            'slug' => 'empaque',
            'is_active' => true,
        ]);

        $this->empaqueA = $this->crearEmpaque('EMP-A', 'Empaque Alfa');
        $this->empaqueB = $this->crearEmpaque('EMP-B', 'Empaque Beta');
    }

    private function crearEmpaque(string $code, string $name, array $extra = []): Entity
    {
        return Entity::create(array_merge([
            'branch_id' => $this->entity->branch_id,
            'entity_type_id' => $this->tipoEmpaque->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ], $extra));
    }

    private function cuerpo(array $extra = []): array
    {
        return array_merge([
            'cultivo_id' => $this->cultivoElote->id,
            'locacion' => 'Guasave',
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-06-30',
        ], $extra);
    }

    private function marcar(Temporada $temporada, Entity ...$empaques): void
    {
        $temporada->empaques()->sync(collect($empaques)->pluck('id')->all());
    }

    private function recepcion(Temporada $temporada, Entity $empaque, string $folio): void
    {
        RecepcionEmpaque::create([
            'temporada_id' => $temporada->id,
            'entity_id' => $empaque->id,
            'folio_recepcion' => $folio,
            'fecha_recepcion' => '2026-02-01',
            'productor_id' => $this->productorPrincipal->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $this->variedadElote->id,
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_recibida' => 1,
            'peso_recibido_kg' => 400,
            'peso_bascula' => 400,
            'status' => 'recibida',
        ]);
    }

    // ---------------------------------------------------------------- alta / edición

    public function test_store_con_empaque_ids_guarda_y_devuelve(): void
    {
        $ids = [$this->empaqueB->id, $this->empaqueA->id];

        $resp = $this->postJson(self::URL, $this->cuerpo(['empaque_ids' => $ids]))->assertCreated();

        $esperado = [$this->empaqueA->id, $this->empaqueB->id];
        $resp->assertJsonPath('temporada.empaque_ids', $esperado);

        $temporada = Temporada::where('locacion', 'Guasave')->firstOrFail();
        $this->assertDatabaseHas('temporada_empaques', ['temporada_id' => $temporada->id, 'entity_id' => $this->empaqueA->id]);
        $this->assertDatabaseHas('temporada_empaques', ['temporada_id' => $temporada->id, 'entity_id' => $this->empaqueB->id]);
    }

    public function test_store_sin_empaque_ids_no_marca_ninguno(): void
    {
        $this->postJson(self::URL, $this->cuerpo())
            ->assertCreated()
            ->assertJsonPath('temporada.empaque_ids', []);

        $this->assertDatabaseCount('temporada_empaques', 0);
    }

    public function test_update_reemplaza_los_empaques(): void
    {
        $this->marcar($this->temporadaBins, $this->empaqueA);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['empaque_ids' => [$this->empaqueB->id]]))
            ->assertOk()
            ->assertJsonPath('temporada.empaque_ids', [$this->empaqueB->id]);

        $this->assertDatabaseMissing('temporada_empaques', ['temporada_id' => $this->temporadaBins->id, 'entity_id' => $this->empaqueA->id]);
        $this->assertDatabaseHas('temporada_empaques', ['temporada_id' => $this->temporadaBins->id, 'entity_id' => $this->empaqueB->id]);
    }

    public function test_update_con_lista_vacia_limpia_todos(): void
    {
        $this->marcar($this->temporadaBins, $this->empaqueA, $this->empaqueB);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['empaque_ids' => []]))
            ->assertOk()
            ->assertJsonPath('temporada.empaque_ids', []);

        $this->assertDatabaseCount('temporada_empaques', 0);
    }

    public function test_update_sin_empaque_ids_no_los_toca(): void
    {
        $this->marcar($this->temporadaBins, $this->empaqueA, $this->empaqueB);

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo())
            ->assertOk()
            ->assertJsonPath('temporada.empaque_ids', [$this->empaqueA->id, $this->empaqueB->id]);

        $this->assertDatabaseCount('temporada_empaques', 2);
    }

    public function test_rechaza_una_entidad_que_no_es_empaque(): void
    {
        // $this->entity es de tipo PLANTA
        $this->postJson(self::URL, $this->cuerpo(['empaque_ids' => [$this->empaqueA->id, $this->entity->id]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.empaque_ids.0', 'Solo se pueden elegir entidades de tipo Empaque.');

        $this->putJson(self::URL.'/'.$this->temporadaBins->id, $this->cuerpo(['empaque_ids' => [$this->entity->id]]))
            ->assertStatus(422)
            ->assertJsonPath('errors.empaque_ids.0', 'Solo se pueden elegir entidades de tipo Empaque.');

        $this->assertDatabaseCount('temporada_empaques', 0);
        $this->assertDatabaseMissing('temporadas', ['locacion' => 'Guasave']);
    }

    public function test_rechaza_ids_inexistentes_o_repetidos(): void
    {
        $this->postJson(self::URL, $this->cuerpo(['empaque_ids' => [999999]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('empaque_ids.0');

        $this->postJson(self::URL, $this->cuerpo(['empaque_ids' => [$this->empaqueA->id, $this->empaqueA->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('empaque_ids.0');
    }

    public function test_listado_y_detalle_admin_traen_empaque_ids(): void
    {
        $this->marcar($this->temporadaBins, $this->empaqueB, $this->empaqueA);

        $lista = $this->getJson(self::URL)->assertOk()->json();
        $bins = collect($lista)->firstWhere('id', $this->temporadaBins->id);
        $normal = collect($lista)->firstWhere('id', $this->temporada->id);

        $this->assertSame([$this->empaqueA->id, $this->empaqueB->id], $bins['empaque_ids']);
        $this->assertSame([], $normal['empaque_ids']);
        $this->assertArrayNotHasKey('empaques', $bins);

        $this->getJson(self::URL.'/'.$this->temporadaBins->id)
            ->assertOk()
            ->assertJsonPath('empaque_ids', [$this->empaqueA->id, $this->empaqueB->id]);
    }

    // ---------------------------------------------------------------- Operación Agrícola

    public function test_operacion_agricola_index_y_show_traen_empaque_ids(): void
    {
        $this->marcar($this->temporadaBins, $this->empaqueB, $this->empaqueA);

        $lista = $this->getJson(self::URL_OA)->assertOk()->json('data');
        $bins = collect($lista)->firstWhere('id', $this->temporadaBins->id);
        $normal = collect($lista)->firstWhere('id', $this->temporada->id);

        $this->assertSame([$this->empaqueA->id, $this->empaqueB->id], $bins['empaque_ids']);
        $this->assertSame([], $normal['empaque_ids']);

        $this->getJson(self::URL_OA.'/'.$this->temporadaBins->id)
            ->assertOk()
            ->assertJsonPath('data.empaque_ids', [$this->empaqueA->id, $this->empaqueB->id]);

        $this->getJson(self::URL_OA.'/'.$this->temporada->id)
            ->assertOk()
            ->assertJsonPath('data.empaque_ids', []);
    }

    // ---------------------------------------------------------------- empaques-opciones

    public function test_opciones_exige_cultivo_id(): void
    {
        $this->getJson(self::URL.'/empaques-opciones')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cultivo_id');

        $this->getJson(self::URL.'/empaques-opciones?cultivo_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cultivo_id');
    }

    public function test_opciones_filtra_por_cultivo_y_excluye_inactivos(): void
    {
        $otroCultivo = Cultivo::create(['nombre' => 'Berries']);
        $inactivo = $this->crearEmpaque('EMP-I', 'Empaque Inactivo', ['is_active' => false]);
        $deOtroCultivo = $this->crearEmpaque('EMP-O', 'Empaque Otro cultivo');
        $sinCultivo = $this->crearEmpaque('EMP-S', 'Empaque Sin cultivo');
        $planta = $this->entity; // PLANTA, aunque tenga el cultivo no es EMPAQUE

        $this->empaqueA->cultivos()->attach($this->cultivoElote->id);
        $this->empaqueB->cultivos()->attach($this->cultivoElote->id);
        $inactivo->cultivos()->attach($this->cultivoElote->id);
        $deOtroCultivo->cultivos()->attach($otroCultivo->id);
        $planta->cultivos()->attach($this->cultivoElote->id);

        $data = $this->getJson(self::URL.'/empaques-opciones?cultivo_id='.$this->cultivoElote->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertSame([$this->empaqueA->id, $this->empaqueB->id], array_column($data, 'id'));
        $this->assertSame(
            ['id', 'name', 'code', 'is_active', 'recepciones'],
            array_keys($data[0]),
        );
        $this->assertSame('Empaque Alfa', $data[0]['name']);
        $this->assertSame('EMP-A', $data[0]['code']);
        $this->assertTrue($data[0]['is_active']);
        $this->assertSame(0, $data[0]['recepciones']);
        $this->assertNotContains($sinCultivo->id, array_column($data, 'id'));
    }

    public function test_opciones_incluye_marcados_aunque_esten_inactivos_o_sin_el_cultivo(): void
    {
        $inactivo = $this->crearEmpaque('EMP-I', 'Empaque Inactivo', ['is_active' => false]);
        $sinCultivo = $this->crearEmpaque('EMP-S', 'Empaque Sin cultivo');
        $this->empaqueA->cultivos()->attach($this->cultivoElote->id);
        $inactivo->cultivos()->attach($this->cultivoElote->id);

        $this->marcar($this->temporadaBins, $inactivo, $sinCultivo);

        $url = self::URL.'/empaques-opciones?cultivo_id='.$this->cultivoElote->id;

        // Sin temporada_id solo salen los activos con el cultivo.
        $this->assertSame([$this->empaqueA->id], array_column($this->getJson($url)->json('data'), 'id'));

        $data = $this->getJson($url.'&temporada_id='.$this->temporadaBins->id)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [$this->empaqueA->id, $inactivo->id, $sinCultivo->id],
            array_column($data, 'id'),
        );

        $filaInactivo = collect($data)->firstWhere('id', $inactivo->id);
        $this->assertFalse($filaInactivo['is_active']);
    }

    public function test_opciones_cuenta_recepciones_del_cultivo_y_ordena(): void
    {
        $this->empaqueA->cultivos()->attach($this->cultivoElote->id);
        $this->empaqueB->cultivos()->attach($this->cultivoElote->id);

        // B: 2 recepciones de Elote (una por cada temporada del cultivo) + 1 de Mango que no cuenta.
        $otraTemporadaElote = Temporada::create([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => 'Elote 2025',
            'locacion' => 'Mochis',
            'folio_temporada' => $this->cultivoElote->id.'-002',
            'año_inicio' => 2025,
            'año_fin' => 2025,
            'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2025-06-30',
            'user_id' => $this->actingUser->id,
        ]);
        $this->recepcion($this->temporadaBins, $this->empaqueB, 'REC-OPC-1');
        $this->recepcion($otraTemporadaElote, $this->empaqueB, 'REC-OPC-2');
        $this->recepcion($this->temporada, $this->empaqueB, 'REC-OPC-3'); // Mango
        $this->recepcion($this->temporada, $this->empaqueA, 'REC-OPC-4'); // Mango

        $data = $this->getJson(self::URL.'/empaques-opciones?cultivo_id='.$this->cultivoElote->id)
            ->assertOk()
            ->json('data');

        // B (2 recepciones) va antes que A (0) aunque A sea alfabéticamente primero.
        $this->assertSame([$this->empaqueB->id, $this->empaqueA->id], array_column($data, 'id'));
        $this->assertSame([2, 0], array_column($data, 'recepciones'));
    }
}
