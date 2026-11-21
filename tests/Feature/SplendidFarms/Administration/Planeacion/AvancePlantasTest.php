<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Entity;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Recipe;
use App\Services\Planeacion\CatalogoPlaneacion;
use App\Services\Planeacion\PlantasAvance;
use App\Services\Planeacion\RealEmpacado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plantas que cuentan en el Avance de empaque: parámetro `avance_plantas` de
 * la configuración (lista de entidades), por omisión las propias
 * (`is_external = 0`). Hoy fijo: miércoles 2026-11-25.
 */
class AvancePlantasTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion';

    private const LUNES = '2026-11-23';

    private Entity $propia;

    private Entity $externa;

    private Recipe $receta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->crearElote();
        $this->propia = $this->planta('Empaque Charay');
        $this->externa = $this->planta('Agrigar', true);
        $this->receta = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($this->receta, 'Bulk', 'RPC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conPermisos(array $permisos): void
    {
        Sanctum::actingAs($this->usuarioCon($permisos));
    }

    private function palletEn(Entity $planta, int $cajas, string $fecha = '2026-11-23')
    {
        return $this->pallet($fecha, $this->receta, $cajas, 'Amarillo', ['entity_id' => $planta->id]);
    }

    private function avance(): array
    {
        return $this->getJson(self::URL.'/programa/avance?lunes='.self::LUNES)->assertOk()->json('data');
    }

    private function guardar(mixed $ids)
    {
        return $this->putJson(self::URL.'/configuracion/avance-plantas', ['entity_ids' => $ids]);
    }

    public function test_por_omision_solo_cuentan_las_plantas_propias(): void
    {
        $this->conPermisos(['avance-empaque' => ['view']]);
        $this->palletEn($this->propia, 100);
        $this->palletEn($this->externa, 40);

        $d = $this->avance();

        $this->assertSame(100, $d['totales']['real']);
        $this->assertSame([100, 0, 0, 0, 0, 0, 0], $d['sin_programa'][0]['real']);
        $this->assertSame([['id' => $this->propia->id, 'nombre' => 'Empaque Charay']], $d['plantas']);
    }

    public function test_marcada_la_externa_si_cuenta_y_los_totales_cuadran(): void
    {
        $this->conPermisos(['avance-empaque' => ['view'], 'configuracion' => ['view', 'editar']]);
        $p = ProgramaSemanal::create(['lunes' => self::LUNES]);
        $this->necesidad($p, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [200, 0, 0, 0, 0, 0, 0]);
        $this->palletEn($this->propia, 100);
        $this->palletEn($this->externa, 40);

        $this->guardar([$this->externa->id, $this->propia->id])->assertOk()
            ->assertJsonPath('message', 'Plantas del avance guardadas.')
            ->assertJsonPath('data.avance_plantas.seleccionadas', [$this->propia->id, $this->externa->id])
            ->assertJsonPath('data.avance_plantas.por_omision', false);

        $d = $this->avance();
        $this->assertSame([140, 0, 0, 0, 0, 0, 0], $d['renglones'][0]['real']);
        $this->assertSame(140, $d['totales']['real']);
        $this->assertSame(60, $d['renglones'][0]['atraso']);
        // En orden de nombre.
        $this->assertSame([
            ['id' => $this->externa->id, 'nombre' => 'Agrigar'],
            ['id' => $this->propia->id, 'nombre' => 'Empaque Charay'],
        ], $d['plantas']);

        // Solo la externa: la propia deja de contar.
        $this->guardar([$this->externa->id])->assertOk();
        $d = $this->avance();
        $this->assertSame(40, $d['totales']['real']);
        $this->assertSame([['id' => $this->externa->id, 'nombre' => 'Agrigar']], $d['plantas']);
    }

    public function test_lista_vacia_deja_el_real_en_ceros(): void
    {
        $this->conPermisos(['avance-empaque' => ['view'], 'configuracion' => ['view', 'editar']]);
        $this->palletEn($this->propia, 100);
        $this->palletEn($this->externa, 40);
        $this->pallet('2026-11-24', null, 15, 'Amarillo', ['entity_id' => $this->propia->id]);

        $this->guardar([])->assertOk()
            ->assertJsonPath('data.avance_plantas.seleccionadas', [])
            ->assertJsonPath('data.avance_plantas.por_omision', false);

        $d = $this->avance();
        $this->assertSame(0, $d['totales']['real']);
        $this->assertSame([], $d['renglones']);
        $this->assertSame([], $d['sin_programa']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $d['sin_clasificar']['cajas']);
        $this->assertSame(0, $d['sin_clasificar']['pallets']);
        $this->assertSame([], $d['plantas']);
    }

    public function test_get_configuracion_trae_las_plantas_y_sus_opciones(): void
    {
        $this->conPermisos(['configuracion' => ['view', 'editar']]);
        $sinPallets = $this->planta('Agrelin', true);
        $propiaSinPallets = $this->planta('Empaque Las Palmas');
        $this->palletEn($this->propia, 100);
        $this->palletEn($this->propia, 20, '2026-10-01');
        $this->palletEn($this->externa, 40);
        $borrado = $this->palletEn($this->externa, 30);
        $borrado->delete();

        $plantas = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data.avance_plantas');

        $this->assertTrue($plantas['por_omision']);
        // Por omisión, las propias con pallets (una oficina o bodega propia no tiene).
        $this->assertSame([$this->propia->id], $plantas['seleccionadas']);
        // Sin las que no tienen pallets; ordenadas por nombre; sin el pallet borrado.
        $this->assertSame([
            ['id' => $this->externa->id, 'nombre' => 'Agrigar', 'externa' => true, 'pallets' => 1],
            ['id' => $this->propia->id, 'nombre' => 'Empaque Charay', 'externa' => false, 'pallets' => 2],
        ], $plantas['opciones']);

        // Una planta propia nueva cuenta sola con su primer pallet.
        $this->palletEn($propiaSinPallets, 5);
        $plantas = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data.avance_plantas');
        $this->assertSame([$this->propia->id, $propiaSinPallets->id], $plantas['seleccionadas']);

        // Ya elegida aunque no tenga pallets: aparece en las opciones.
        $this->guardar([$sinPallets->id])->assertOk();
        $plantas = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data.avance_plantas');
        $this->assertFalse($plantas['por_omision']);
        $this->assertSame([$sinPallets->id], $plantas['seleccionadas']);
        $this->assertSame(
            ['Agrelin', 'Agrigar', 'Empaque Charay', 'Empaque Las Palmas'],
            array_column($plantas['opciones'], 'nombre'),
        );
    }

    public function test_opciones_en_consultas_fijas(): void
    {
        foreach (range(1, 5) as $i) {
            $this->palletEn($this->planta("Externa {$i}", true), 10);
        }

        DB::enableQueryLog();
        $opciones = app(PlantasAvance::class)->opciones();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Las 5 externas con pallets (Agrigar y la propia, sin pallets, no salen).
        $this->assertCount(5, $opciones);
        $this->assertLessThanOrEqual(3, $consultas);
    }

    public function test_put_valida_ids_existentes_y_normaliza_repetidos(): void
    {
        $this->conPermisos(['configuracion' => ['view', 'editar']]);

        $this->guardar([$this->propia->id, 999999])->assertStatus(422)
            ->assertJsonValidationErrors(['entity_ids' => 'Hay plantas que no existen.']);
        $this->putJson(self::URL.'/configuracion/avance-plantas', [])->assertStatus(422)
            ->assertJsonValidationErrors('entity_ids');
        $this->guardar('no')->assertStatus(422)->assertJsonValidationErrors('entity_ids');
        $this->assertNull(ConfiguracionPlaneacion::where('clave', 'avance_plantas')->first());

        $this->guardar([$this->externa->id, (string) $this->propia->id, $this->externa->id])->assertOk()
            ->assertJsonPath('data.avance_plantas.seleccionadas', [$this->propia->id, $this->externa->id]);
        $this->assertSame(
            json_encode([$this->propia->id, $this->externa->id]),
            ConfiguracionPlaneacion::where('clave', 'avance_plantas')->value('valor'),
        );
    }

    public function test_put_exige_configuracion_editar(): void
    {
        $this->conPermisos(['configuracion' => ['view']]);

        $this->guardar([$this->propia->id])->assertForbidden();
        $this->assertNull(ConfiguracionPlaneacion::where('clave', 'avance_plantas')->first());
    }

    public function test_real_empacado_sin_plantas_cuenta_todas_y_con_lista_filtra(): void
    {
        $this->palletEn($this->propia, 100);
        $this->palletEn($this->externa, 40);
        $real = new RealEmpacado(new CatalogoPlaneacion);
        $suma = fn (array $r) => array_sum(array_map(fn ($f) => array_sum($f['cajas']), $r['renglones']));

        $this->assertSame(140, $suma($real->deLaSemana(self::LUNES, null)));
        $this->assertSame(140, $suma($real->deLaSemana(self::LUNES, null, null)));
        $this->assertSame(40, $suma($real->deLaSemana(self::LUNES, null, [$this->externa->id])));
        $this->assertSame(0, $suma($real->deLaSemana(self::LUNES, null, [])));
    }

    public function test_ids_ignora_lo_guardado_que_no_es_una_lista_de_plantas(): void
    {
        $plantas = app(PlantasAvance::class);
        ConfiguracionPlaneacion::create(['clave' => 'avance_plantas', 'valor' => json_encode([$this->externa->id, 999999, 'x', $this->externa->id])]);

        $this->assertSame([$this->externa->id], $plantas->ids());
    }
}
