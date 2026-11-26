<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Empaque;

use App\Models\DestinoEmbarque;
use App\Models\EmbarqueEmpaque;
use App\Models\Planeacion\BodegaDestino;
use App\Models\ProduccionEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Catálogo de destinos de los embarques de Empaque (alta desde el modal del
 * embarque, liga opcional con una bodega de Planeación), destino obligatorio en
 * el alta del embarque, y las acciones «Destino» y «Estado» de la lista.
 */
class DestinosEmbarqueTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const DESTINOS = '/api/splendidfarms/operacion-agricola/empaque/destinos-embarque';

    private const EMBARQUES = '/api/splendidfarms/operacion-agricola/empaque/embarques';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function bodegaPlaneacion(string $nombre, bool $activa = true): BodegaDestino
    {
        // La migración de Planeación ya siembra Texas, Nogales, Mexicali y Nacional.
        return BodegaDestino::updateOrCreate(['nombre' => $nombre], ['orden' => 1, 'is_active' => $activa]);
    }

    private function crearDestino(array $datos): TestResponse
    {
        return $this->postJson(self::DESTINOS, array_merge(['enterprise_id' => $this->enterprise->id], $datos));
    }

    private function destino(string $nombre, bool $activo = true): DestinoEmbarque
    {
        return DestinoEmbarque::create(['enterprise_id' => $this->enterprise->id, 'nombre' => $nombre, 'is_active' => $activo]);
    }

    private function embarcar(array $extra): TestResponse
    {
        $pallet = $this->crearProduccionBins(['en_cuarto_frio' => true, 'peso_neto_kg' => 500]);

        return $this->postJson(self::EMBARQUES, array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'tipo_venta' => 'exportacion',
            'fecha_embarque' => '2026-02-10',
            'pallets' => [['id' => $pallet->id, 'posicion_carga' => 1]],
        ], $extra));
    }

    private function embarqueViejo(): EmbarqueEmpaque
    {
        return EmbarqueEmpaque::create([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'folio_embarque' => 'EMB-VIEJO-1',
            'tipo_venta' => 'exportacion',
            'fecha_embarque' => '2026-02-10',
            'status' => 'programado',
        ]);
    }

    public function test_crea_y_lista_destinos_con_liga_opcional_a_planeacion(): void
    {
        $texas = $this->bodegaPlaneacion('Texas');

        $this->crearDestino(['nombre' => '  McAllen   TX ', 'plan_bodega_destino_id' => $texas->id])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'McAllen TX')
            ->assertJsonPath('data.plan_bodega_destino_id', $texas->id)
            ->assertJsonPath('data.bodega_planeacion', 'Texas');
        $this->crearDestino(['nombre' => 'Reynosa'])
            ->assertCreated()
            ->assertJsonPath('data.plan_bodega_destino_id', null)
            ->assertJsonPath('data.bodega_planeacion', null);

        $lista = $this->getJson(self::DESTINOS.'?enterprise_id='.$this->enterprise->id)->assertOk()->json('data');

        $this->assertSame(['McAllen TX', 'Reynosa'], array_column($lista, 'nombre'));
        $this->assertSame('Texas', $lista[0]['bodega_planeacion']);
    }

    public function test_el_nombre_es_obligatorio_y_no_se_repite_sin_importar_mayusculas_ni_acentos(): void
    {
        $this->crearDestino(['nombre' => 'Mexicali'])->assertCreated();

        $this->crearDestino(['nombre' => '  MEXICÁLI '])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'Ya existe un destino con ese nombre.');
        $this->crearDestino(['nombre' => '   '])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->crearDestino(['nombre' => 'Tijuana', 'plan_bodega_destino_id' => 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_bodega_destino_id');

        $this->assertSame(1, DestinoEmbarque::count());
    }

    public function test_edita_la_liga_el_nombre_y_desactiva(): void
    {
        $texas = $this->bodegaPlaneacion('Texas');
        $destino = $this->destino('McAllen');
        $this->destino('Reynosa');

        $this->putJson(self::DESTINOS.'/'.$destino->id, ['plan_bodega_destino_id' => $texas->id])
            ->assertOk()->assertJsonPath('data.bodega_planeacion', 'Texas');
        $this->putJson(self::DESTINOS.'/'.$destino->id, ['nombre' => 'reynosa'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un destino con ese nombre.');
        $this->putJson(self::DESTINOS.'/'.$destino->id, ['nombre' => 'McAllen', 'plan_bodega_destino_id' => null])
            ->assertOk()->assertJsonPath('data.plan_bodega_destino_id', null);
        $this->putJson(self::DESTINOS.'/'.$destino->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $activos = $this->getJson(self::DESTINOS.'?enterprise_id='.$this->enterprise->id)->json('data');
        $todos = $this->getJson(self::DESTINOS.'?enterprise_id='.$this->enterprise->id.'&incluir_inactivos=1')->json('data');
        $this->assertSame(['Reynosa'], array_column($activos, 'nombre'));
        $this->assertSame(['McAllen', 'Reynosa'], array_column($todos, 'nombre'));
    }

    public function test_bodegas_de_planeacion_para_ligar_son_solo_las_activas(): void
    {
        BodegaDestino::query()->update(['is_active' => false]);
        $this->bodegaPlaneacion('Texas');
        $this->bodegaPlaneacion('Mexicali', false);

        $bodegas = $this->getJson(self::DESTINOS.'/bodegas-planeacion')->assertOk()->json('data');

        $this->assertSame(['Texas'], array_column($bodegas, 'nombre'));
    }

    public function test_el_alta_del_embarque_exige_un_destino_activo_y_copia_su_nombre(): void
    {
        $inactivo = $this->destino('Viejo', false);
        $reynosa = $this->destino('Reynosa');

        $this->embarcar([])
            ->assertStatus(422)
            ->assertJsonPath('errors.destino_embarque_id.0', 'Elige el destino del embarque.');
        $this->embarcar(['destino_embarque_id' => $inactivo->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.destino_embarque_id.0', 'Ese destino está desactivado.');
        $this->embarcar(['destino_embarque_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destino_embarque_id');
        $this->assertSame(0, EmbarqueEmpaque::count());

        $id = $this->embarcar(['destino_embarque_id' => $reynosa->id, 'destino' => 'otro texto'])
            ->assertCreated()
            ->assertJsonPath('data.destino_embarque_id', $reynosa->id)
            ->json('data.id');

        $embarque = EmbarqueEmpaque::findOrFail($id);
        $this->assertSame('Reynosa', $embarque->destino);
        $this->assertSame('embarcado', ProduccionEmpaque::findOrFail($embarque->detalles()->value('produccion_id'))->status);
    }

    public function test_asigna_o_corrige_el_destino_de_un_embarque_ya_capturado(): void
    {
        $embarque = $this->embarqueViejo();
        $mcallen = $this->destino('McAllen');

        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/destino', [])
            ->assertStatus(422)->assertJsonValidationErrors('destino_embarque_id');

        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/destino', ['destino_embarque_id' => $mcallen->id])
            ->assertOk()
            ->assertJsonPath('data.destino', 'McAllen')
            ->assertJsonPath('data.destino_embarque_id', $mcallen->id);

        $embarque->refresh();
        $this->assertSame($mcallen->id, $embarque->destino_embarque_id);
        $this->assertSame('McAllen', $embarque->destino);
    }

    public function test_cambia_el_estado_pero_no_cancela(): void
    {
        $embarque = $this->embarqueViejo();

        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/estado', ['status' => 'en_transito'])
            ->assertOk()->assertJsonPath('data.status', 'en_transito');
        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/estado', ['status' => 'programado'])
            ->assertOk()->assertJsonPath('data.status', 'programado');

        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/estado', ['status' => 'cancelado'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Ese estado no se puede elegir. Para cancelar un embarque, elimínalo.');
        $this->patchJson(self::EMBARQUES.'/'.$embarque->id.'/estado', [])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('programado', $embarque->fresh()->status);
    }
}
