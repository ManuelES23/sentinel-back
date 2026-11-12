<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hoja de necesidades de ventas de la semana (guardado en lote de renglones
 * nuevos, cambios parciales y borrados) y copia de la semana anterior.
 */
class HojaNecesidadesTest extends TestCase
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

    private function comoVendedor(): void
    {
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view', 'capturar']]));
    }

    private function urlHoja(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/necesidades/hoja';
    }

    private function urlCopiar(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/necesidades/copiar-anterior';
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    /** Renglón nuevo válido: pedido de Walmart con variedades Amarillo + Bicolor. */
    private function nueva(string $clave = 'n1', array $cambios = []): array
    {
        return array_merge([
            'clave' => $clave,
            'bodega_id' => $this->bodega('Nogales')->id,
            'cliente_id' => null,
            'cliente_nombre' => 'Walmart',
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            'cajas' => [0, 850, 0, 8500, 1520, 0],
        ], $cambios);
    }

    private function empresaSbp(): Enterprise
    {
        return Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            ['name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true],
        );
    }

    private function guardar(array $cuerpo, ?ProgramaSemanal $p = null)
    {
        return $this->putJson($this->urlHoja($p), $cuerpo);
    }

    // ── Guardado en lote ────────────────────────────────

    public function test_crea_cambia_y_borra_en_una_peticion(): void
    {
        $this->comoVendedor();
        $cambiada = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [100, 200, 300, 400, 500, 600]);
        $borrada = $this->necesidad($this->programa, 'Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1000, 0, 0, 0, 0, 0]);
        Event::fake([ProgramaSemanalUpdated::class]);

        $respuesta = $this->guardar([
            'nuevas' => [
                $this->nueva('a'),
                $this->nueva('b', ['cliente_nombre' => 'El Super', 'cajas' => [960, 0, 0, 0, 0, 0]]),
            ],
            'cambios' => [['id' => $cambiada->id, 'cajas' => ['2' => 5584]]],
            'borradas' => [$borrada->id],
        ])->assertOk()->assertJsonPath('success', true);

        // Dos renglones nuevos, uno cambiado, uno borrado.
        $this->assertSame(3, ProgramaNecesidad::count());
        $this->assertNull(ProgramaNecesidad::find($borrada->id));
        $this->assertSame([100, 200, 5584, 400, 500, 600], $cambiada->fresh()->cajasPorDia());

        $claves = $respuesta->json('claves');
        $this->assertSame(['a', 'b'], array_keys($claves));
        $a = ProgramaNecesidad::findOrFail($claves['a']);
        $b = ProgramaNecesidad::findOrFail($claves['b']);
        $this->assertSame('Walmart', $a->cliente_nombre);
        $this->assertSame([0, 850, 0, 8500, 1520, 0], $a->cajasPorDia());
        $this->assertSame($this->programa->id, $a->programa_id);
        $this->assertSame($this->cultivo->id, $a->cultivo_id);
        $this->assertSame('El Super', $b->cliente_nombre);
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $a->variedades()->pluck('variedades.id')->all(),
        );

        // La respuesta trae la semana, como el show del programa.
        $this->assertCount(3, $respuesta->json('data.necesidades'));
        $grupoAb = (string) $this->grupo('Amarillo / Bicolor')->id;
        // Suma las dos nuevas y la que ya estaba (100…600 con el miércoles en 5584).
        $this->assertSame([1060, 1050, 5584, 8900, 2020, 600], $respuesta->json("data.balance.$grupoAb.necesidad"));

        // Un solo aviso por la petición completa.
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);
    }

    public function test_cambio_parcial_no_pisa_otros_dias(): void
    {
        $this->comoVendedor();
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1480, 0, 0, 0, 0, 0]);

        $this->guardar(['cambios' => [['id' => $n->id, 'cajas' => ['2' => 500]]]])->assertOk();

        $this->assertSame([1480, 0, 500, 0, 0, 0], $n->fresh()->cajasPorDia());
    }

    public function test_renglon_sin_empaque_se_guarda_y_queda_sin_clasificar(): void
    {
        $this->comoVendedor();

        $respuesta = $this->guardar(['nuevas' => [$this->nueva('a', ['tipo_empaque_id' => null])]])->assertOk();

        $n = ProgramaNecesidad::findOrFail($respuesta->json('claves.a'));
        $this->assertNull($n->tipo_empaque_id);
        $renglon = $respuesta->json('data.necesidades.0');
        $this->assertNull($renglon['tipo_empaque_id']);

        // También se puede quitar el empaque de un renglón que ya lo tenía.
        $otro = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Tray', ['Amarillo'], 'RPC', [10, 0, 0, 0, 0, 0]);
        $this->guardar(['cambios' => [['id' => $otro->id, 'tipo_empaque_id' => null]]])->assertOk();
        $this->assertNull($otro->fresh()->tipo_empaque_id);
    }

    public function test_semana_sin_temporada_abierta_del_cultivo_es_422_y_no_guarda_nada(): void
    {
        $this->comoVendedor();
        // WK47: la temporada OI empieza el 23 nov.
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);

        $this->guardar(['nuevas' => [$this->nueva('a')]], $wk47)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nuevas.0.presentacion_id' => self::MENSAJE_TEMPORADA]);
        $this->assertSame(0, ProgramaNecesidad::count());

        // Subir cajas de un renglón que ya estaba ahí tampoco se acepta.
        $viejo = $this->necesidad($wk47, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);
        $this->guardar(['cambios' => [['id' => $viejo->id, 'cajas' => ['0' => 5]]]], $wk47)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cambios.0.cajas' => self::MENSAJE_TEMPORADA]);
        $this->assertSame(1, $viejo->fresh()->cajas_0);

        // Un lote con un renglón bueno y otro malo no guarda ninguno.
        $this->guardar([
            'nuevas' => [$this->nueva('a')],
            'cambios' => [['id' => $viejo->id, 'cajas' => ['1' => 9]]],
        ], $wk47)->assertUnprocessable();
        $this->assertSame(1, ProgramaNecesidad::count());
        $this->assertSame([1, 0, 0, 0, 0, 0], $viejo->fresh()->cajasPorDia());
    }

    public function test_borrar_y_bajar_cajas_se_puede_aunque_ya_no_haya_temporada_abierta(): void
    {
        $this->comoVendedor();
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $baja = $this->necesidad($wk47, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [10, 20, 0, 0, 0, 0]);
        $cero = $this->necesidad($wk47, 'Texas', 'Walmart', 'Tray', ['Amarillo'], 'RPC', [5, 0, 0, 0, 0, 0]);
        $borrada = $this->necesidad($wk47, 'Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1000, 0, 0, 0, 0, 0]);
        // Un catálogo desactivado después tampoco estorba al borrar ni al bajar.
        $this->bodega('Texas')->update(['is_active' => false]);

        $this->guardar([
            'cambios' => [
                ['id' => $baja->id, 'cajas' => ['0' => 4, '1' => 20]],
                ['id' => $cero->id, 'cajas' => ['0' => 0]],
            ],
            'borradas' => [$borrada->id],
        ], $wk47)->assertOk();

        $this->assertSame([4, 20, 0, 0, 0, 0], $baja->fresh()->cajasPorDia());
        $this->assertSame([0, 0, 0, 0, 0, 0], $cero->fresh()->cajasPorDia());
        $this->assertNull(ProgramaNecesidad::find($borrada->id));
    }

    public function test_cliente_del_crm_por_nombre_exacto_se_liga(): void
    {
        $this->comoVendedor();
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart de México']);
        $otro = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        CrmCliente::create(['empresa_id' => $otro->id, 'nombre' => 'Caruso']);

        $respuesta = $this->guardar(['nuevas' => [
            $this->nueva('a', ['cliente_nombre' => 'walmart de méxico']),
            $this->nueva('b', ['cliente_nombre' => '  WALMART DE MEXICO  ']),
            $this->nueva('c', ['cliente_nombre' => 'Walmart']),
            $this->nueva('d', ['cliente_nombre' => 'Caruso']),
        ]])->assertOk();

        $a = ProgramaNecesidad::findOrFail($respuesta->json('claves.a'));
        $this->assertSame($cliente->id, $a->cliente_id);
        $this->assertSame('Walmart de México', $a->cliente_nombre);
        $this->assertSame($cliente->id, ProgramaNecesidad::findOrFail($respuesta->json('claves.b'))->cliente_id);
        // Nombre parcial o de otra empresa: texto libre.
        $this->assertNull(ProgramaNecesidad::findOrFail($respuesta->json('claves.c'))->cliente_id);
        $this->assertNull(ProgramaNecesidad::findOrFail($respuesta->json('claves.d'))->cliente_id);

        $renglones = collect($respuesta->json('data.necesidades'))->keyBy('id');
        $this->assertTrue($renglones[$a->id]['en_crm']);
    }

    public function test_cliente_por_id_toma_el_nombre_del_crm_y_otra_empresa_es_422(): void
    {
        $this->comoVendedor();
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart de México']);

        $respuesta = $this->guardar(['nuevas' => [
            $this->nueva('a', ['cliente_id' => $cliente->id, 'cliente_nombre' => 'Otro nombre']),
        ]])->assertOk();
        $n = ProgramaNecesidad::findOrFail($respuesta->json('claves.a'));
        $this->assertSame($cliente->id, $n->cliente_id);
        $this->assertSame('Walmart de México', $n->cliente_nombre);

        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        $ajeno = CrmCliente::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno']);
        $this->guardar(['nuevas' => [$this->nueva('b', ['cliente_id' => $ajeno->id, 'cliente_nombre' => null])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cliente_id');
        $this->assertSame(1, ProgramaNecesidad::count());
    }

    public function test_cliente_texto_libre_queda_fuera_del_crm(): void
    {
        $this->comoVendedor();

        $respuesta = $this->guardar(['nuevas' => [$this->nueva('a', ['cliente_nombre' => '  Cliente Nuevo  '])]])->assertOk();

        $n = ProgramaNecesidad::findOrFail($respuesta->json('claves.a'));
        $this->assertNull($n->cliente_id);
        $this->assertSame('Cliente Nuevo', $n->cliente_nombre);
        $this->assertFalse($respuesta->json('data.necesidades.0.en_crm'));
    }

    public function test_cambia_cliente_variedades_y_presentacion(): void
    {
        $this->comoVendedor();
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Vallarta']);
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Blanco'], 'RPC', [1, 2, 3, 4, 5, 6]);

        $this->guardar(['cambios' => [[
            'id' => $n->id,
            'cliente_nombre' => 'vallarta',
            'bodega_id' => $this->bodega('Nogales')->id,
            'presentacion_id' => $this->presentacion('Tray')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
        ]]])->assertOk();

        $fresca = $n->fresh();
        $this->assertSame($cliente->id, $fresca->cliente_id);
        $this->assertSame('Vallarta', $fresca->cliente_nombre);
        $this->assertSame($this->bodega('Nogales')->id, $fresca->bodega_id);
        $this->assertSame($this->presentacion('Tray')->id, $fresca->presentacion_id);
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $fresca->variedades()->pluck('variedades.id')->all(),
        );
        // Las cajas no se tocaron.
        $this->assertSame([1, 2, 3, 4, 5, 6], $fresca->cajasPorDia());
    }

    // ── Color: variedades o grupo de balance ────────────

    public function test_el_color_puede_ser_un_grupo_de_balance(): void
    {
        $this->comoVendedor();
        $grupo = $this->grupo('Amarillo / Bicolor');

        $respuesta = $this->guardar(['nuevas' => [
            $this->nueva('a', ['variedad_ids' => null, 'grupo_id' => $grupo->id]),
            // Una variedad sin grupo explícito es un grupo implícito «v{id}».
            $this->nueva('b', ['variedad_ids' => null, 'grupo_id' => 'v'.$this->variedades['Blanco']->id]),
        ]])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            ProgramaNecesidad::findOrFail($respuesta->json('claves.a'))->variedades()->pluck('variedades.id')->all(),
        );
        $this->assertSame(
            [$this->variedades['Blanco']->id],
            ProgramaNecesidad::findOrFail($respuesta->json('claves.b'))->variedades()->pluck('variedades.id')->all(),
        );
    }

    public function test_grupo_de_otro_cultivo_o_inexistente_es_422(): void
    {
        $this->comoVendedor();
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajeno = GrupoBalance::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'orden' => 1]);

        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => null, 'grupo_id' => $ajeno->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.grupo_id');
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => null, 'grupo_id' => 999999])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.grupo_id');
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => null, 'grupo_id' => 'v999999'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.grupo_id');
        // Ni variedades ni grupo, o las dos cosas.
        $sin = $this->nueva('a', ['variedad_ids' => null]);
        $this->guardar(['nuevas' => [$sin]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');
        $this->guardar(['nuevas' => [$this->nueva('a', ['grupo_id' => $this->grupo('Amarillo / Bicolor')->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_variedades_de_grupos_distintos_es_422(): void
    {
        $this->comoVendedor();

        $this->guardar(['nuevas' => [$this->nueva('a', [
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Blanco']->id],
        ])]])->assertUnprocessable()
            ->assertJsonValidationErrors(['nuevas.0.variedad_ids' => 'Las variedades deben ser del mismo grupo de balance']);

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    // ── Todo o nada y validaciones ──────────────────────

    public function test_error_en_un_renglon_no_guarda_ninguno(): void
    {
        $this->comoVendedor();

        $this->guardar(['nuevas' => [
            $this->nueva('a'),
            $this->nueva('b', ['presentacion_id' => 999999]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.1.presentacion_id');

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_error_de_negocio_en_el_segundo_renglon_no_guarda_el_primero(): void
    {
        $this->comoVendedor();
        $existente = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        $this->guardar([
            'nuevas' => [
                $this->nueva('a'),
                $this->nueva('b', ['variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Blanco']->id]]),
            ],
            'cambios' => [['id' => $existente->id, 'cajas' => ['0' => 9]]],
            'borradas' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('nuevas.1.variedad_ids');

        $this->assertSame(1, ProgramaNecesidad::count());
        $this->assertSame(1, $existente->fresh()->cajas_0);
    }

    public function test_validaciones_de_campos(): void
    {
        $this->comoVendedor();

        // Sin cliente_id el nombre es obligatorio.
        $sinNombre = $this->nueva('a', ['cliente_nombre' => null]);
        $this->guardar(['nuevas' => [$sinNombre]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cliente_nombre');
        $this->guardar(['nuevas' => [$this->nueva('a', ['cliente_nombre' => str_repeat('a', 151)])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cliente_nombre');

        // Exactamente seis enteros no negativos.
        foreach ([[1, 2, 3], [1, 2, 3, 4, 5, 6, 7]] as $cajas) {
            $this->guardar(['nuevas' => [$this->nueva('a', ['cajas' => $cajas])]])
                ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cajas');
        }
        foreach ([[1, 2, 3, 4, 5, -1], [1, 2, 3, 4, 5, 1.5], [1, 2, 3, 4, 5, 1000000]] as $cajas) {
            $this->guardar(['nuevas' => [$this->nueva('a', ['cajas' => $cajas])]])
                ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cajas.5');
        }

        // Variedades: al menos una, sin repetir.
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => []])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');
        $amarillo = $this->variedades['Amarillo']->id;
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => [$amarillo, $amarillo]])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');

        // Variedad de otro cultivo.
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);
        $this->guardar(['nuevas' => [$this->nueva('a', ['variedad_ids' => [$ajena->id]])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.variedad_ids');

        // Claves repetidas o ausentes.
        $this->guardar(['nuevas' => [$this->nueva('a'), $this->nueva('a')]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.clave');
        $sinClave = $this->nueva('a');
        unset($sinClave['clave']);
        $this->guardar(['nuevas' => [$sinClave]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.clave');

        // Catálogos inactivos.
        $bodega = $this->bodega('Nogales');
        $bodega->update(['is_active' => false]);
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.bodega_id');
        $bodega->update(['is_active' => true]);

        $tipo = $this->tipoEmpaque('RPC');
        $tipo->update(['is_active' => false]);
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.tipo_empaque_id');
        $tipo->update(['is_active' => true]);

        $presentacion = $this->presentacion('Bulk');
        $presentacion->update(['is_active' => false]);
        $this->guardar(['nuevas' => [$this->nueva('a')]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.presentacion_id');

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_validaciones_de_cambios_y_borradas(): void
    {
        $this->comoVendedor();
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 2, 3, 4, 5, 6]);

        // Día fuera de rango, cajas inválidas.
        foreach ([['6' => 1], ['x' => 1], ['1' => -3], ['1' => 1.5], ['1' => 1000000]] as $cajas) {
            $this->guardar(['cambios' => [['id' => $n->id, 'cajas' => $cajas]]])->assertUnprocessable();
        }
        // Id repetido en cambios, o a la vez cambiado y borrado.
        $this->guardar(['cambios' => [['id' => $n->id, 'cajas' => ['0' => 1]], ['id' => $n->id, 'cajas' => ['1' => 1]]]])
            ->assertUnprocessable();
        $this->guardar(['cambios' => [['id' => $n->id, 'cajas' => ['0' => 1]]], 'borradas' => [$n->id]])->assertUnprocessable();

        // Un cambio de cliente sin nombre ni id tampoco puede dejar el renglón sin cliente.
        $this->guardar(['cambios' => [['id' => $n->id, 'cliente_nombre' => '  ']]])->assertUnprocessable();

        // Un renglón de otra semana o que ya no existe no se cambia; uno que ya no existe sí se puede «borrar».
        $otra = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $ajeno = $this->necesidad($otra, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);
        $this->guardar(['cambios' => [['id' => $ajeno->id, 'cajas' => ['0' => 1]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('cambios.0.id');
        $this->guardar(['cambios' => [['id' => 999999, 'cajas' => ['0' => 1]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('cambios.0.id');
        $this->guardar(['borradas' => [$ajeno->id]])->assertUnprocessable()->assertJsonValidationErrors('borradas.0');
        $this->guardar(['borradas' => [999999]])->assertOk();

        $this->assertSame([1, 2, 3, 4, 5, 6], $n->fresh()->cajasPorDia());
        $this->assertNotNull(ProgramaNecesidad::find($ajeno->id));
    }

    public function test_cambiar_la_presentacion_a_otro_cultivo_revisa_las_variedades(): void
    {
        $this->comoVendedor();
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $presentacionAjena = \App\Models\Planeacion\Presentacion::create(['cultivo_id' => $otro->id, 'nombre' => 'Caja 25', 'is_active' => true]);
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        // Las variedades del renglón son de elote: la presentación nueva ya no cuadra.
        $this->guardar(['cambios' => [['id' => $n->id, 'presentacion_id' => $presentacionAjena->id]]])
            ->assertUnprocessable();
        $this->assertSame($this->presentacion('Bulk')->id, $n->fresh()->presentacion_id);
    }

    public function test_cambio_sin_diferencia_real_no_avisa(): void
    {
        $this->comoVendedor();
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 2, 3, 4, 5, 6]);
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->guardar(['cambios' => [[
            'id' => $n->id,
            'cajas' => ['0' => 1],
            'cliente_nombre' => 'Walmart',
            'variedad_ids' => [$this->variedades['Amarillo']->id],
        ]]])->assertOk();
        $this->guardar([])->assertOk();

        Event::assertNotDispatched(ProgramaSemanalUpdated::class);
    }

    public function test_sin_permiso_capturar_ventas_es_403(): void
    {
        // Ver las necesidades y capturar cortes no basta para capturar necesidades.
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view'], 'plan-corte' => ['view', 'capturar']]));
        $necesidad = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        $this->guardar(['nuevas' => [$this->nueva('a')]])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para esta acción de Planeación.');
        $this->guardar(['borradas' => [$necesidad->id]])->assertForbidden();
        $this->postJson($this->urlCopiar())->assertForbidden();

        $this->assertSame(1, ProgramaNecesidad::count());
        $this->assertSame(1, ProgramaNecesidad::first()->cajas_0);
    }

    public function test_las_rutas_sueltas_de_necesidades_ya_no_existen(): void
    {
        $this->comoVendedor();
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        $this->postJson(self::BASE.'/programa/'.$this->programa->id.'/necesidades', $this->nueva())->assertNotFound();
        $this->putJson(self::BASE.'/necesidades/'.$n->id, ['dia' => 0, 'cajas' => 5])->assertNotFound();
        $this->deleteJson(self::BASE.'/necesidades/'.$n->id)->assertNotFound();
        $this->assertSame(1, ProgramaNecesidad::count());
    }

    // ── Copiar la semana anterior ───────────────────────

    public function test_copiar_semana_anterior(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->cargarWk48($anterior); // 12 renglones
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Cliente CRM']);
        ProgramaNecesidad::where('programa_id', $anterior->id)->first()->update(['cliente_id' => $cliente->id, 'cliente_nombre' => 'Cliente CRM']);
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $anterior->id)->count());

        $this->postJson($this->urlCopiar())
            ->assertOk()
            ->assertJsonPath('data.copiadas', 12);

        $copias = ProgramaNecesidad::with('variedades')->where('programa_id', $this->programa->id)->orderBy('id')->get();
        $origen = ProgramaNecesidad::with('variedades')->where('programa_id', $anterior->id)->orderBy('id')->get();
        $this->assertCount(12, $copias);
        foreach ($origen as $i => $o) {
            $c = $copias[$i];
            $this->assertSame($o->bodega_id, $c->bodega_id);
            $this->assertSame($o->cliente_id, $c->cliente_id);
            $this->assertSame($o->cliente_nombre, $c->cliente_nombre);
            $this->assertSame($o->cultivo_id, $c->cultivo_id);
            $this->assertSame($o->presentacion_id, $c->presentacion_id);
            $this->assertSame($o->tipo_empaque_id, $c->tipo_empaque_id);
            $this->assertSame($o->cajasPorDia(), $c->cajasPorDia());
            $this->assertEqualsCanonicalizing($o->variedades->pluck('id')->all(), $c->variedades->pluck('id')->all());
        }

        // La semana anterior no se toca y la segunda vez ya no copia.
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $anterior->id)->count());
        $this->postJson($this->urlCopiar())
            ->assertUnprocessable()
            ->assertJsonPath('errors.semana.0', 'Esta semana ya tiene necesidades');
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
    }

    public function test_copiar_sin_semana_anterior_o_vacia_es_422(): void
    {
        $this->comoVendedor();

        $this->postJson($this->urlCopiar())
            ->assertUnprocessable()
            ->assertJsonPath('errors.semana.0', 'La semana anterior no tiene necesidades');

        // Existe pero sin renglones; y una semana de hace 14 días no cuenta.
        ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->postJson($this->urlCopiar())->assertUnprocessable();

        $lejana = ProgramaSemanal::create(['lunes' => '2026-11-02']);
        $this->cargarWk48($lejana);
        $this->postJson($this->urlCopiar())->assertUnprocessable();
        $this->assertSame(0, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
    }

    public function test_copiar_a_semana_sin_temporada_abierta_es_422(): void
    {
        $this->comoVendedor();
        $wk47 = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $wk46 = ProgramaSemanal::create(['lunes' => '2026-11-09']);
        $this->necesidad($wk46, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        $this->postJson($this->urlCopiar($wk47))
            ->assertUnprocessable()
            ->assertJsonPath('errors.semana.0', self::MENSAJE_TEMPORADA);
        $this->assertSame(0, ProgramaNecesidad::where('programa_id', $wk47->id)->count());
    }

    public function test_copiar_semana_anterior_emite_un_solo_evento(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->cargarWk48($anterior); // 12 renglones
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->postJson($this->urlCopiar())->assertOk()->assertJsonPath('data.copiadas', 12);

        // Un aviso por programa y petición, no uno por renglón.
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);

        // La siguiente escritura vuelve a avisar.
        $id = ProgramaNecesidad::where('programa_id', $this->programa->id)->value('id');
        $this->guardar(['cambios' => [['id' => $id, 'cajas' => ['1' => 9]]]])->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 2);
    }
}
