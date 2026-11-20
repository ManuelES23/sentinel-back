<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hoja de sobrantes del Programa de empaque: guardado en lote (nuevas,
 * cambios parciales y borradas) con las mismas reglas de renglón que
 * Necesidades, sin cliente y con tipo de empaque obligatorio.
 */
class HojaSobrantesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private const MENSAJE_TEMPORADA = 'El cultivo no tiene una temporada abierta en esta semana.';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function comoEmpacador(): void
    {
        Sanctum::actingAs($this->usuarioCon(['programa-empaque' => ['view', 'capturar']]));
    }

    private function guardar(array $cuerpo, ?ProgramaSemanal $p = null)
    {
        return $this->putJson(self::BASE.'/programa/'.($p ?? $this->programa)->id.'/sobrantes/hoja', $cuerpo);
    }

    /** Renglón nuevo válido: Bulk Amarillo + Bicolor en RPC para Nogales. */
    private function nueva(string $clave = 'n1', array $cambios = []): array
    {
        return array_merge([
            'clave' => $clave,
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            'cajas' => [0, 850, 0, 500, 120, 0, 40],
        ], $cambios);
    }

    /** @param  int[]  $cajas */
    private function sobrante(string $bodega, string $presentacion, array $variedades, string $empaque, array $cajas, ?ProgramaSemanal $p = null): ProgramaSobrante
    {
        $attrs = [
            'bodega_id' => $this->bodega($bodega)->id,
            'presentacion_id' => $this->presentacion($presentacion)->id,
            'tipo_empaque_id' => $this->tipoEmpaque($empaque)->id,
        ];
        foreach (array_values($cajas) as $dia => $valor) {
            $attrs["cajas_{$dia}"] = $valor;
        }

        return $this->crearSobrante($p ?? $this->programa, $attrs, array_map(fn ($n) => $this->variedades[$n]->id, $variedades));
    }

    public function test_crea_cambia_y_borra_en_una_peticion(): void
    {
        $this->comoEmpacador();
        $cambiado = $this->sobrante('Texas', 'Bulk', ['Amarillo'], 'RPC', [100, 200, 300, 400, 500, 600, 0]);
        $borrado = $this->sobrante('Texas', 'Tray', ['Blanco'], 'RPC', [1000, 0, 0, 0, 0, 0, 0]);
        Event::fake([ProgramaSemanalUpdated::class]);

        $respuesta = $this->guardar([
            'nuevas' => [
                $this->nueva('a'),
                $this->nueva('b', ['grupo_id' => 'v'.$this->variedades['Blanco']->id, 'variedad_ids' => null, 'cajas' => [0, 0, 0, 0, 0, 0, 777]]),
            ],
            'cambios' => [['id' => $cambiado->id, 'cajas' => ['2' => 5584, '6' => 9]]],
            'borradas' => [$borrado->id],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(3, ProgramaSobrante::count());
        $this->assertNull(ProgramaSobrante::find($borrado->id));
        $this->assertSame([100, 200, 5584, 400, 500, 600, 9], $cambiado->fresh()->cajasPorDia());

        $claves = $respuesta->json('claves');
        $this->assertSame(['a', 'b'], array_keys($claves));
        $a = ProgramaSobrante::findOrFail($claves['a']);
        $b = ProgramaSobrante::findOrFail($claves['b']);
        $this->assertSame([0, 850, 0, 500, 120, 0, 40], $a->cajasPorDia());
        $this->assertSame(40, $a->cajas_6);
        $this->assertSame($this->programa->id, $a->programa_id);
        $this->assertSame($this->cultivo->id, $a->cultivo_id);
        $this->assertSame($this->tipoEmpaque('RPC')->id, $a->tipo_empaque_id);
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $a->variedades()->pluck('variedades.id')->all(),
        );
        $this->assertSame([$this->variedades['Blanco']->id], $b->variedades()->pluck('variedades.id')->all());
        $this->assertSame(777, $b->cajas_6);

        // La respuesta trae la semana con los sobrantes y lo asignado en el balance.
        $this->assertCount(3, $respuesta->json('data.sobrantes'));
        $grupoAb = (string) $this->grupo('Amarillo / Bicolor')->id;
        $this->assertSame([100, 1050, 5584, 900, 620, 600, 49], $respuesta->json("data.balance.$grupoAb.sobrante_asignado"));

        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
    }

    public function test_exige_permiso_capturar_de_programa_empaque(): void
    {
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view', 'capturar']]));
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertForbidden();

        Sanctum::actingAs($this->usuarioCon(['programa-empaque' => ['view']]));
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertForbidden();
        $this->assertSame(0, ProgramaSobrante::count());

        Sanctum::actingAs($this->usuarioCon(['programa-empaque' => ['capturar']]));
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertOk();
        $this->assertSame(1, ProgramaSobrante::count());
    }

    public function test_asignar_de_mas_se_guarda(): void
    {
        $this->comoEmpacador();

        $respuesta = $this->guardar(['nuevas' => [$this->nueva('a', ['cajas' => [0, 0, 20000, 0, 0, 0, 0]])]])->assertOk();

        $grupoAb = (string) $this->grupo('Amarillo / Bicolor')->id;
        $this->assertSame('falta', $respuesta->json("data.balance.$grupoAb.estado"));
        $this->assertSame(20000, ProgramaSobrante::firstOrFail()->cajas_2);
    }

    public function test_tipo_de_empaque_es_obligatorio(): void
    {
        $this->comoEmpacador();

        $this->guardar(['nuevas' => [$this->nueva('a', ['tipo_empaque_id' => null])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.tipo_empaque_id');
        $sinClave = $this->nueva('a');
        unset($sinClave['tipo_empaque_id']);
        $this->guardar(['nuevas' => [$sinClave]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.tipo_empaque_id');

        // Desactivado tampoco.
        $this->tipoEmpaque('Cartón')->update(['is_active' => false]);
        $this->guardar(['nuevas' => [$this->nueva('a', ['tipo_empaque_id' => $this->tipoEmpaque('Cartón')->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.tipo_empaque_id');

        // Un cambio no puede quitarlo.
        $s = $this->sobrante('Texas', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $this->guardar(['cambios' => [['id' => $s->id, 'tipo_empaque_id' => null]]])
            ->assertUnprocessable()->assertJsonValidationErrors('cambios.0.tipo_empaque_id');
        $this->assertSame($this->tipoEmpaque('RPC')->id, $s->fresh()->tipo_empaque_id);

        $this->assertSame(1, ProgramaSobrante::count());
    }

    public function test_validaciones_de_renglon(): void
    {
        $this->comoEmpacador();

        // Presentación de otro cultivo.
        $tomate = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Presentacion::create(['cultivo_id' => $tomate->id, 'nombre' => 'Caja 25 lb', 'orden' => 1, 'is_active' => true]);
        $this->guardar(['nuevas' => [$this->nueva('a', ['presentacion_id' => $ajena->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.presentacion_id');

        // Variedades de grupos distintos.
        $this->guardar(['nuevas' => [$this->nueva('a', [
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Blanco']->id],
        ])]])->assertUnprocessable()
            ->assertJsonValidationErrors(['nuevas.0.variedad_ids' => 'Las variedades deben ser del mismo grupo de balance']);

        // Sin color no llega al balance: es obligatorio.
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => null])]])
            ->assertUnprocessable()->assertJsonValidationErrors(['nuevas.0.variedad_ids', 'nuevas.0.grupo_id']);
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => []])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');

        // Exactamente siete días.
        foreach ([[1, 2, 3, 4, 5, 6], [1, 2, 3, 4, 5, 6, 7, 8]] as $cajas) {
            $this->guardar(['nuevas' => [$this->nueva('a', ['cajas' => $cajas])]])
                ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cajas');
        }
        $this->guardar(['nuevas' => [$this->nueva('a', ['cajas' => [0, 0, 0, 0, 0, 0, 1000000]])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cajas.6');

        // Un día fuera de 0..6 en un cambio.
        $s = $this->sobrante('Texas', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $this->guardar(['cambios' => [['id' => $s->id, 'cajas' => ['7' => 5]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('cambios.0.cajas');

        $this->assertSame(1, ProgramaSobrante::count());
        $this->assertSame([1, 0, 0, 0, 0, 0, 0], $s->fresh()->cajasPorDia());
    }

    public function test_sin_temporada_no_sube_pero_si_baja(): void
    {
        $this->comoEmpacador();
        // WK47: la temporada OI empieza el 23 nov.
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);

        $this->guardar(['nuevas' => [$this->nueva('a')]], $wk47)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nuevas.0.presentacion_id' => self::MENSAJE_TEMPORADA]);

        $baja = $this->sobrante('Texas', 'Bulk', ['Amarillo'], 'RPC', [10, 20, 0, 0, 0, 0, 0], $wk47);
        $borrado = $this->sobrante('Texas', 'Tray', ['Amarillo'], 'RPC', [5, 0, 0, 0, 0, 0, 0], $wk47);

        $this->guardar(['cambios' => [['id' => $baja->id, 'cajas' => ['0' => 11]]]], $wk47)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cambios.0.cajas' => self::MENSAJE_TEMPORADA]);
        $this->assertSame(10, $baja->fresh()->cajas_0);

        $this->guardar([
            'cambios' => [['id' => $baja->id, 'cajas' => ['0' => 0]]],
            'borradas' => [$borrado->id],
        ], $wk47)->assertOk();

        $this->assertSame([0, 20, 0, 0, 0, 0, 0], $baja->fresh()->cajasPorDia());
        $this->assertNull(ProgramaSobrante::find($borrado->id));
    }

    public function test_un_cambio_de_otro_programa_es_422(): void
    {
        $this->comoEmpacador();
        $otraSemana = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $ajeno = $this->sobrante('Texas', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0], $otraSemana);

        $this->guardar(['cambios' => [['id' => $ajeno->id, 'cajas' => ['0' => 0]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('cambios.0.id');
        $this->guardar(['borradas' => [$ajeno->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('borradas.0');

        $this->assertSame([1, 0, 0, 0, 0, 0, 0], $ajeno->fresh()->cajasPorDia());
    }
}
