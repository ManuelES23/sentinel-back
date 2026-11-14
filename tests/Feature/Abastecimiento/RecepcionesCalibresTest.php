<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\RecepcionCalibre;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\User;
use App\Services\Abastecimiento\ReglasRenglonesRecepcion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Recepciones de convenios por calibre en la API: renglones atómicos (alta, edición y baja),
 * cabecera como suma de los renglones y presentación con `renglones`. «Hoy» es el martes
 * 14 de julio de 2026; los convenios por calibre van del 1 al 28 de junio.
 */
class RecepcionesCalibresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private User $admin;

    private Variedad $variedad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);

        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $this->variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $ruta = 'recepciones'): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    private function tipo(): TipoCarga
    {
        return TipoCarga::where('enterprise_id', $this->sbp->id)->orderBy('id')->firstOrFail();
    }

    /** El calibre de la variedad con ese nombre (aun borrado); lo crea si no existe. */
    private function calibre(string $nombre, ?int $orden = null): VariedadCalibre
    {
        $calibre = VariedadCalibre::withTrashed()->where('variedad_id', $this->variedad->id)->where('nombre', $nombre)->first()
            ?? $this->crearCalibre($this->variedad, $nombre);
        if ($orden !== null) {
            $calibre->update(['orden' => $orden]);
        }

        return $calibre;
    }

    /** Convenio por calibre con A ($20) y B ($35.50) por caja. */
    private function convenioAB(array $over = []): Convenio
    {
        return $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->calibre('A', 1), 'volumen' => 100, 'precio' => 20.0],
            ['calibre' => $this->calibre('B', 2), 'volumen' => 100, 'precio' => 35.5],
        ], $over);
    }

    private function cuerpo(Convenio $c, array $renglones, array $over = []): array
    {
        return $over + [
            'convenio_id' => $c->id,
            'fecha' => '2026-06-10',
            'tipo_carga_id' => $this->tipo()->id,
            'renglones' => $renglones,
        ];
    }

    private function renglon(string $calibre, $cantidad, $kilos = null, $rezaga = null): array
    {
        return ['calibre_id' => $this->calibre($calibre)->id, 'cantidad' => $cantidad, 'kilos' => $kilos, 'rezaga_kg' => $rezaga];
    }

    // ---- Alta y edición ----

    public function test_alta_por_calibre_guarda_cabecera_y_renglones(): void
    {
        $c = $this->convenioAB();

        $r = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10), $this->renglon('B', 5)]));

        $r->assertStatus(201)->assertJsonPath('success', true);
        $recepcion = Recepcion::findOrFail($r->json('data.id'));
        $this->assertEqualsWithDelta(15, (float) $recepcion->cantidad, 0.001);
        $this->assertNull($recepcion->kilos);
        $this->assertNull($recepcion->rezaga_kg);

        $renglones = RecepcionCalibre::where('recepcion_id', $recepcion->id)->orderBy('id')->get();
        $this->assertCount(2, $renglones);
        $this->assertSame([$this->calibre('A')->id, $this->calibre('B')->id], $renglones->pluck('calibre_id')->all());
        $this->assertEqualsWithDelta(10, (float) $renglones[0]->cantidad, 0.001);
        $this->assertEqualsWithDelta(5, (float) $renglones[1]->cantidad, 0.001);
        $this->assertNull($renglones[0]->kilos);
    }

    public function test_cabecera_con_kilos_y_rezaga_es_la_suma(): void
    {
        $c = $this->convenioAB();

        $r = $this->postJson($this->url(), $this->cuerpo($c, [
            $this->renglon('A', 10, 100.5, 2),
            $this->renglon('B', 5, 50.25, null),
        ]))->assertStatus(201);

        $recepcion = Recepcion::findOrFail($r->json('data.id'));
        $this->assertEqualsWithDelta(15, (float) $recepcion->cantidad, 0.001);
        $this->assertEqualsWithDelta(150.75, (float) $recepcion->kilos, 0.001);
        $this->assertEqualsWithDelta(2, (float) $recepcion->rezaga_kg, 0.001);
        $this->assertEqualsWithDelta(150.75, $r->json('data.kilos'), 0.001);
        $this->assertEqualsWithDelta(2, $r->json('data.rezaga_kg'), 0.001);
    }

    public function test_editar_reemplaza_los_renglones_y_recalcula_la_cabecera(): void
    {
        $c = $this->convenioAB();
        $id = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10, 100), $this->renglon('B', 5, 40)]))
            ->assertStatus(201)->json('data.id');

        $this->putJson($this->url("recepciones/{$id}"), $this->cuerpo($c, [$this->renglon('B', 7)], ['fecha' => '2026-06-12']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $recepcion = Recepcion::findOrFail($id);
        $this->assertEqualsWithDelta(7, (float) $recepcion->cantidad, 0.001);
        $this->assertNull($recepcion->kilos);
        $this->assertSame('2026-06-12', $recepcion->fecha->format('Y-m-d'));
        $this->assertSame(1, RecepcionCalibre::count(), 'No deben quedar renglones huérfanos.');
        $renglon = RecepcionCalibre::firstOrFail();
        $this->assertSame($id, $renglon->recepcion_id);
        $this->assertSame($this->calibre('B')->id, $renglon->calibre_id);
        $this->assertEqualsWithDelta(7, (float) $renglon->cantidad, 0.001);
    }

    public function test_dos_envios_iguales_no_duplican_renglones(): void
    {
        $c = $this->convenioAB();
        $rec = $this->crearRecepcionConRenglones($c, [$this->renglon('A', 1)], ['fecha' => '2026-06-10']);
        $cuerpo = $this->cuerpo($c, [$this->renglon('A', 10), $this->renglon('B', 5)]);

        $this->putJson($this->url("recepciones/{$rec->id}"), $cuerpo)->assertOk();
        $this->putJson($this->url("recepciones/{$rec->id}"), $cuerpo)->assertOk();

        $this->assertSame(2, RecepcionCalibre::where('recepcion_id', $rec->id)->count());
        $this->assertSame(2, RecepcionCalibre::count());
        $this->assertEqualsWithDelta(15, (float) $rec->refresh()->cantidad, 0.001);
    }

    // ---- Reglas ----

    public function test_calibre_sin_precio_ajeno_o_repetido_da_422_en_su_renglon(): void
    {
        $c = $this->convenioAB();
        $sinPrecio = $this->calibre('C');
        ConvenioCalibre::create(['convenio_id' => $c->id, 'calibre_id' => $sinPrecio->id, 'volumen_total' => 10]);
        $ajeno = $this->calibre('D');

        $errores = $this->postJson($this->url(), $this->cuerpo($c, [
            $this->renglon('A', 10),
            ['calibre_id' => $sinPrecio->id, 'cantidad' => 1],
            ['calibre_id' => $ajeno->id, 'cantidad' => 1],
            $this->renglon('A', 2),
        ]))->assertStatus(422)->json('errors');

        $this->assertSame(ReglasRenglonesRecepcion::MENSAJE_PRECIO, $errores['renglones.1.calibre_id'][0]);
        $this->assertSame('El calibre no es de este convenio.', $errores['renglones.2.calibre_id'][0]);
        $this->assertSame('Este calibre ya está en la recepción.', $errores['renglones.3.calibre_id'][0]);
        $this->assertArrayNotHasKey('renglones.0.calibre_id', $errores);

        $this->assertSame(0, Recepcion::count());
        $this->assertSame(0, RecepcionCalibre::count());
    }

    public function test_kilos_a_medias_da_422(): void
    {
        $c = $this->convenioAB();

        $errores = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10, 100), $this->renglon('B', 5)]))
            ->assertStatus(422)->json('errors');

        $this->assertSame('Captura los kilos de todos los calibres o de ninguno.', $errores['renglones.1.kilos'][0]);

        $this->assertSame(0, Recepcion::count());
    }

    public function test_sin_renglones_da_422(): void
    {
        $c = $this->convenioAB();

        $this->postJson($this->url(), $this->cuerpo($c, []))
            ->assertStatus(422)
            ->assertJsonPath('errors.renglones.0', ReglasRenglonesRecepcion::MENSAJE_SIN_RENGLONES);
        $this->assertSame(0, Recepcion::count());
    }

    public function test_renglones_mal_formados_dan_422_y_nunca_500(): void
    {
        $c = $this->convenioAB();
        $a = $this->calibre('A')->id;

        $casos = [
            'texto' => ['abc', 'renglones'],
            'lista de escalares' => [[1, 2], 'renglones.0'],
            'calibre fraccionario' => [[['calibre_id' => 1.5, 'cantidad' => 1]], 'renglones.0.calibre_id'],
            'calibre de texto' => [[['calibre_id' => 'x', 'cantidad' => 1]], 'renglones.0.calibre_id'],
            'cantidad arreglo' => [[['calibre_id' => $a, 'cantidad' => [1]]], 'renglones.0.cantidad'],
            'kilos arreglo' => [[['calibre_id' => $a, 'cantidad' => 1, 'kilos' => ['x']]], 'renglones.0.kilos'],
        ];

        foreach ($casos as $nombre => [$renglones, $clave]) {
            $r = $this->postJson($this->url(), $this->cuerpo($c, [], ['renglones' => $renglones]));
            $this->assertSame(422, $r->status(), "Caso: {$nombre}");
            $this->assertArrayHasKey($clave, $r->json('errors'), "Caso: {$nombre}");
        }

        $this->assertSame(0, Recepcion::count());
    }

    public function test_clasica_con_renglones_da_422(): void
    {
        $clasico = $this->crearConvenio($this->sbp);

        $this->postJson($this->url(), [
            'convenio_id' => $clasico->id, 'fecha' => '2026-06-10', 'tipo_carga_id' => $this->tipo()->id,
            'cantidad' => 10, 'renglones' => [$this->renglon('A', 10)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.renglones.0', 'Este convenio no es por calibre.');

        $this->assertSame(0, Recepcion::count());
        $this->assertSame(0, RecepcionCalibre::count());
    }

    public function test_no_se_pasa_una_recepcion_entre_clasico_y_calibre(): void
    {
        $mensaje = 'No se puede pasar una recepción entre un convenio clásico y uno por calibre.';
        $clasico = $this->crearConvenio($this->sbp);
        $porCalibre = $this->convenioAB();
        $recClasica = $this->crearRecepcion($clasico, ['fecha' => '2026-06-10']);
        $recCalibre = $this->crearRecepcionConRenglones($porCalibre, [$this->renglon('A', 3)], ['fecha' => '2026-06-10']);

        // Clásica → por calibre.
        $this->putJson($this->url("recepciones/{$recClasica->id}"), $this->cuerpo($porCalibre, [$this->renglon('A', 10)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.convenio_id.0', $mensaje);

        // Por calibre → clásica.
        $this->putJson($this->url("recepciones/{$recCalibre->id}"), [
            'convenio_id' => $clasico->id, 'fecha' => '2026-06-10', 'tipo_carga_id' => $this->tipo()->id, 'cantidad' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.convenio_id.0', $mensaje);

        $this->assertSame($clasico->id, $recClasica->refresh()->convenio_id);
        $this->assertSame($porCalibre->id, $recCalibre->refresh()->convenio_id);
        $this->assertSame(0, RecepcionCalibre::where('recepcion_id', $recClasica->id)->count());
        $this->assertSame(1, RecepcionCalibre::where('recepcion_id', $recCalibre->id)->count());
    }

    public function test_recepcion_liquidada_no_se_edita(): void
    {
        $c = $this->convenioAB();
        $rec = $this->crearRecepcionConRenglones($c, [$this->renglon('A', 3)], ['fecha' => '2026-06-10', 'liquidacion_id' => 77]);

        $this->putJson($this->url("recepciones/{$rec->id}"), $this->cuerpo($c, [$this->renglon('B', 9)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', 'Ya está en una liquidación: no se puede cambiar.');
        $this->deleteJson($this->url("recepciones/{$rec->id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', 'Ya está en una liquidación: no se puede cambiar.');

        $this->assertEqualsWithDelta(3, (float) $rec->refresh()->cantidad, 0.001);
        $renglones = RecepcionCalibre::where('recepcion_id', $rec->id)->get();
        $this->assertCount(1, $renglones);
        $this->assertSame($this->calibre('A')->id, $renglones[0]->calibre_id);
    }

    // ---- Presentación ----

    public function test_importe_estimado_es_la_suma_por_calibre(): void
    {
        $c = $this->convenioAB();

        $r = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10), $this->renglon('B', 4)]))
            ->assertStatus(201)
            ->assertJsonPath('data.convenio.usa_calibres', true)
            ->assertJsonPath('data.precio', null)
            ->assertJsonPath('data.porcentaje_productor', null)
            ->assertJsonPath('data.renglones.0.calibre_id', $this->calibre('A')->id)
            ->assertJsonPath('data.renglones.0.calibre', ['id' => $this->calibre('A')->id, 'nombre' => 'A'])
            ->assertJsonPath('data.renglones.1.calibre.nombre', 'B')
            ->assertJsonPath('data.renglones.1.kilos', null)
            ->assertJsonPath('data.renglones.1.rezaga_kg', null)
            ->assertJsonPath('data.renglones.1.porcentaje_productor', null);

        $this->assertCount(2, $r->json('data.renglones'));
        $this->assertIsInt($r->json('data.renglones.0.id'));
        $this->assertEqualsWithDelta(10, $r->json('data.renglones.0.cantidad'), 0.001);
        $this->assertEqualsWithDelta(20, $r->json('data.renglones.0.precio'), 0.0001);
        $this->assertEqualsWithDelta(35.5, $r->json('data.renglones.1.precio'), 0.0001);
        $this->assertEqualsWithDelta(200, $r->json('data.renglones.0.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(142, $r->json('data.renglones.1.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(342, $r->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(14, $r->json('data.cantidad'), 0.001);

        // La edición responde igual, con los renglones nuevos.
        $put = $this->putJson($this->url("recepciones/{$r->json('data.id')}"), $this->cuerpo($c, [$this->renglon('B', 2)]))->assertOk();
        $this->assertCount(1, $put->json('data.renglones'));
        $this->assertEqualsWithDelta(71, $put->json('data.importe_estimado'), 0.001);
    }

    public function test_precio_de_renglon_usa_la_vigencia_de_la_fecha(): void
    {
        $c = $this->convenioAB(['modalidad' => 'consignacion']);
        ConvenioPrecio::where('convenio_id', $c->id)->update(['porcentaje_productor' => 50]);
        ConvenioPrecio::create([
            'convenio_id' => $c->id, 'tipo_carga_id' => $this->tipo()->id, 'calibre_id' => $this->calibre('A')->id,
            'precio' => 30, 'porcentaje_productor' => 80, 'vigencia_inicio' => '2026-06-15', 'es_principal' => true,
        ]);

        $antes = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10)], ['fecha' => '2026-06-10']))->assertStatus(201);
        $despues = $this->postJson($this->url(), $this->cuerpo($c, [$this->renglon('A', 10)], ['fecha' => '2026-06-20']))->assertStatus(201);

        $this->assertEqualsWithDelta(20, $antes->json('data.renglones.0.precio'), 0.0001);
        $this->assertEqualsWithDelta(50, $antes->json('data.renglones.0.porcentaje_productor'), 0.001);
        $this->assertEqualsWithDelta(100, $antes->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(30, $despues->json('data.renglones.0.precio'), 0.0001);
        $this->assertEqualsWithDelta(80, $despues->json('data.renglones.0.porcentaje_productor'), 0.001);
        $this->assertEqualsWithDelta(240, $despues->json('data.importe_estimado'), 0.001);
    }

    public function test_listado_y_semana_traen_renglones_y_calibres_con_consultas_constantes(): void
    {
        $z = $this->calibre('Z', 9);
        $c = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $this->calibre('A', 1), 'volumen' => 100, 'precio' => 20.0],
            ['calibre' => $this->calibre('B', 2), 'volumen' => 100, 'precio' => 35.5],
            ['calibre' => $z, 'volumen' => 100, 'precio' => 10.0],
        ]);
        $this->crearRecepcionConRenglones($c, [$this->renglon('A', 10), $this->renglon('Z', 3)], ['fecha' => '2026-06-09']);
        $this->calibre('B')->update(['is_active' => false]);
        $z->delete(); // borrado del catálogo: su nombre sigue saliendo
        $clasico = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-08']);

        $t = $c->temporada_id;
        $listado = $this->url("recepciones?temporada_id={$t}");
        $semana = $this->url("recepciones/semana?temporada_id={$t}&lunes=2026-06-08");

        \DB::enableQueryLog();
        $l1 = $this->getJson($listado)->assertOk();
        $qListado = count(\DB::getQueryLog());
        \DB::flushQueryLog();
        $s1 = $this->getJson($semana)->assertOk();
        $qSemana = count(\DB::getQueryLog());
        \DB::flushQueryLog();

        // Listado: renglones con nombre del calibre (aun borrado); la clásica, lista vacía.
        $porCalibre = collect($l1->json('data.data'))->firstWhere('convenio_id', $c->id);
        $deClasico = collect($l1->json('data.data'))->firstWhere('convenio_id', $clasico->id);
        $this->assertTrue($porCalibre['convenio']['usa_calibres']);
        $this->assertSame(['A', 'Z'], array_column(array_column($porCalibre['renglones'], 'calibre'), 'nombre'));
        $this->assertEqualsWithDelta(230, $porCalibre['importe_estimado'], 0.001);
        $this->assertNull($porCalibre['precio']);
        $this->assertSame([], $deClasico['renglones']);
        $this->assertFalse($deClasico['convenio']['usa_calibres']);
        $this->assertEqualsWithDelta(100, $deClasico['precio'], 0.0001);

        // Semana: fila por calibre con sus calibres (todos, por orden) y precios con calibre.
        $fila = collect($s1->json('data.convenios'))->firstWhere('id', $c->id);
        $filaClasica = collect($s1->json('data.convenios'))->firstWhere('id', $clasico->id);
        $this->assertTrue($fila['usa_calibres']);
        $this->assertSame([
            ['calibre_id' => $this->calibre('A')->id, 'nombre' => 'A', 'orden' => 1],
            ['calibre_id' => $this->calibre('B')->id, 'nombre' => 'B', 'orden' => 2],
            ['calibre_id' => $z->id, 'nombre' => 'Z', 'orden' => 9],
        ], $fila['calibres']);
        $this->assertEqualsCanonicalizing(['A', 'B', 'Z'], array_column(array_column($fila['precios'], 'calibre'), 'nombre'));
        $this->assertFalse($filaClasica['usa_calibres']);
        $this->assertSame([], $filaClasica['calibres']);
        $recepcion = collect($s1->json('data.recepciones'))->firstWhere('convenio_id', $c->id);
        $this->assertCount(2, $recepcion['renglones']);

        // Con 4 convenios por calibre (cada uno con su recepción) el número de consultas no cambia.
        for ($i = 0; $i < 3; $i++) {
            $otro = $this->convenioAB(['temporada_id' => $t]);
            $this->crearRecepcionConRenglones($otro, [$this->renglon('A', 2), $this->renglon('B', 1)], ['fecha' => '2026-06-10']);
        }
        \DB::flushQueryLog();
        $l4 = $this->getJson($listado)->assertOk();
        $qListado4 = count(\DB::getQueryLog());
        \DB::flushQueryLog();
        $s4 = $this->getJson($semana)->assertOk();
        $qSemana4 = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertCount(5, $l4->json('data.data'));
        $this->assertCount(5, $s4->json('data.convenios'));
        $this->assertSame($qListado, $qListado4, 'El listado no debe consultar por recepción ni por renglón.');
        $this->assertSame($qSemana, $qSemana4, 'La semana no debe consultar por convenio ni por renglón.');
    }

    public function test_ficha_del_convenio_trae_renglones_de_las_ultimas_recepciones(): void
    {
        $c = $this->convenioAB();
        $this->crearRecepcionConRenglones($c, [$this->renglon('A', 10, 100), $this->renglon('B', 4, 30)], ['fecha' => '2026-06-10']);
        $clasico = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($clasico, ['fecha' => '2026-06-10']);

        $r = $this->getJson($this->url("convenios/{$c->id}"))->assertOk();

        $renglones = $r->json('data.recepciones.0.renglones');
        $this->assertCount(2, $renglones);
        $this->assertSame(['A', 'B'], array_column(array_column($renglones, 'calibre'), 'nombre'));
        $this->assertEqualsWithDelta(100, $renglones[0]['kilos'], 0.001);
        $this->assertEqualsWithDelta(35.5, $renglones[1]['precio'], 0.0001);
        $this->assertEqualsWithDelta(142, $renglones[1]['importe_estimado'], 0.001);
        foreach (['id', 'calibre_id', 'calibre', 'cantidad', 'kilos', 'rezaga_kg', 'precio', 'porcentaje_productor', 'importe_estimado'] as $clave) {
            $this->assertArrayHasKey($clave, $renglones[0]);
        }

        $this->getJson($this->url("convenios/{$clasico->id}"))->assertOk()->assertJsonPath('data.recepciones.0.renglones', []);
    }

    public function test_por_kilo_en_consignacion_valora_cada_renglon_por_sus_kilos(): void
    {
        $c = $this->convenioAB(['unidad' => 'kg', 'modalidad' => 'consignacion']);
        ConvenioPrecio::where('convenio_id', $c->id)->update(['porcentaje_productor' => 37.5]);

        $r = $this->postJson($this->url(), $this->cuerpo($c, [
            $this->renglon('A', 10, 100.55),
            $this->renglon('B', 4, 40.25),
        ]))->assertStatus(201);

        $a = round(100.55 * 20 * 37.5 / 100, 2);   // 754.13
        $b = round(40.25 * 35.5 * 37.5 / 100, 2);  // 535.83
        $this->assertEqualsWithDelta($a, $r->json('data.renglones.0.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta($b, $r->json('data.renglones.1.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(37.5, $r->json('data.renglones.0.porcentaje_productor'), 0.001);
        $this->assertEqualsWithDelta($a + $b, $r->json('data.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta(140.8, $r->json('data.kilos'), 0.001);

        // Mismo valor en el listado, en la ficha del convenio y en sus indicadores.
        $fila = $this->getJson($this->url("recepciones?temporada_id={$c->temporada_id}"))->assertOk()->json('data.data.0');
        $this->assertEqualsWithDelta($a + $b, $fila['importe_estimado'], 0.001);
        $ficha = $this->getJson($this->url("convenios/{$c->id}"))->assertOk();
        $this->assertEqualsWithDelta($b, $ficha->json('data.recepciones.0.renglones.1.importe_estimado'), 0.001);
        $this->assertEqualsWithDelta($a + $b, $ficha->json('data.indicadores.valor_recibido'), 0.01);
    }

    public function test_cabecera_por_calibre_sin_renglones_no_se_valora_en_cero(): void
    {
        $c = $this->convenioAB();
        $this->crearRecepcion($c, ['fecha' => '2026-06-10', 'cantidad' => 10]); // cabecera sin renglones

        $fila = $this->getJson($this->url("recepciones?temporada_id={$c->temporada_id}"))->assertOk()->json('data.data.0');

        $this->assertSame([], $fila['renglones']);
        $this->assertNull($fila['importe_estimado']);
        $this->assertNull($fila['precio']);
    }

    public function test_suma_de_renglones_que_desborda_la_cabecera_da_422(): void
    {
        $c = $this->convenioAB();

        $this->postJson($this->url(), $this->cuerpo($c, [
            $this->renglon('A', 999999999999),
            $this->renglon('B', 999999999999),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.renglones.0', ReglasRenglonesRecepcion::MENSAJE_SUMA);

        $this->assertSame(0, Recepcion::count());
    }

    public function test_borrar_deja_los_renglones_con_la_cabecera_borrada(): void
    {
        $c = $this->convenioAB();
        $rec = $this->crearRecepcionConRenglones($c, [$this->renglon('A', 3), $this->renglon('B', 2)], ['fecha' => '2026-06-10']);

        $this->deleteJson($this->url("recepciones/{$rec->id}"))->assertOk();

        $this->assertSoftDeleted('abs_recepciones', ['id' => $rec->id]);
        $this->assertSame(2, RecepcionCalibre::where('recepcion_id', $rec->id)->count());
    }
}
