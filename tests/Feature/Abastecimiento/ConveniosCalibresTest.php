<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\Abastecimiento\Recepcion;
use App\Models\User;
use App\Services\Abastecimiento\ReglasRecepcion;
use App\Services\Abastecimiento\ValidadorImportacionRecepciones;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** Convenio por calibre en la API: alta, edición, estatus, presentación e indicadores. */
class ConveniosCalibresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private User $admin;

    private Temporada $temporada;

    private Productor $productor;

    private Variedad $variedad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $this->temporada = Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $cultivo->id, 'nombre' => 'Temp Mango',
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-09-30', 'estado' => 'activa',
        ]);
        $this->productor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => 'Rancho Uno', 'is_active' => true]);
        $this->variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
    }

    private function url(string $ruta, string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function tipo(): TipoCarga
    {
        return TipoCarga::where('enterprise_id', $this->sbp->id)->orderBy('id')->firstOrFail();
    }

    private function calibre(string $nombre, int $orden = 0, bool $activo = true, ?Variedad $variedad = null): VariedadCalibre
    {
        $calibre = $this->crearCalibre($variedad ?? $this->variedad, $nombre, $activo);
        $calibre->update(['orden' => $orden]);

        return $calibre;
    }

    private function cuerpo(array $over = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporada->id,
            'productor_id' => $this->productor->id,
            'variedad_id' => $this->variedad->id,
            'modalidad' => 'compra_directa',
            'unidad' => 'caja',
            'moneda' => 'MXN',
            'porcentaje_rezaga' => 5,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-28',
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 100], ['semana' => '2026-06-08', 'cantidad' => 120]],
            'precios' => [['tipo_carga_id' => $this->tipo()->id, 'precio' => 185, 'vigencia_inicio' => '2026-06-01']],
        ], $over);
    }

    /** Cuerpo de un convenio por calibre en volumen total: un precio del tipo principal por calibre. */
    private function cuerpoCalibres(array $calibres, array $over = []): array
    {
        $tipo = $this->tipo()->id;

        return $this->cuerpo(array_merge([
            'modo_volumen' => 'total',
            'usa_calibres' => true,
            'volumen_total' => 999,
            'metas' => [],
            'calibres' => array_map(fn ($c) => ['calibre_id' => $c[0]->id, 'volumen_total' => $c[1]], $calibres),
            'precios' => array_map(fn ($c) => [
                'tipo_carga_id' => $tipo, 'calibre_id' => $c[0]->id, 'precio' => $c[2] ?? 100, 'vigencia_inicio' => '2026-06-01',
            ], $calibres),
        ], $over));
    }

    private function crear(array $body): Convenio
    {
        $id = $this->postJson($this->url('convenios'), $body)->assertCreated()->json('data.id');

        return Convenio::findOrFail($id);
    }

    // ---- Alta ----

    public function test_alta_por_calibre_en_modo_total(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);

        $res = $this->postJson($this->url('convenios'), $this->cuerpoCalibres([[$jumbo, 100, 200], [$grande, 101, 150]]))
            ->assertCreated();

        $res->assertJsonPath('data.usa_calibres', true)
            ->assertJsonPath('data.volumen_total', null)
            ->assertJsonPath('data.precio_principal', null)
            ->assertJsonPath('data.calibres.0.calibre_id', $jumbo->id)
            ->assertJsonPath('data.calibres.0.nombre', 'Jumbo')
            ->assertJsonPath('data.calibres.0.orden', 1)
            ->assertJsonPath('data.calibres.0.pactado', 100)
            ->assertJsonPath('data.calibres.1.calibre_id', $grande->id)
            ->assertJsonPath('data.calibres.1.pactado', 101)
            ->assertJsonPath('data.indicadores.pactado', 201)
            ->assertJsonCount(2, 'data.precios')
            ->assertJsonCount(0, 'data.metas');
        $this->assertSame($jumbo->id, collect($res->json('data.precios'))->firstWhere('precio', '200.0000')['calibre_id']);
        $this->assertSame('Jumbo', collect($res->json('data.precios'))->firstWhere('calibre_id', $jumbo->id)['calibre']['nombre']);
        $this->assertArrayNotHasKey('calibre_clave', $res->json('data.precios.0'));
        $this->assertSame(2, ConvenioCalibre::count());
    }

    public function test_alta_por_calibre_en_modo_semanal(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $body = $this->cuerpoCalibres([[$jumbo, null], [$grande, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 40, 'calibre_id' => $jumbo->id],
                ['semana' => '2026-06-01', 'cantidad' => 30, 'calibre_id' => $grande->id],
                ['semana' => '2026-06-08', 'cantidad' => 60, 'calibre_id' => $jumbo->id],
            ],
        ]);

        $res = $this->postJson($this->url('convenios'), $body)->assertCreated();

        $res->assertJsonPath('data.calibres.0.pactado', 100)
            ->assertJsonPath('data.calibres.1.pactado', 30)
            ->assertJsonPath('data.indicadores.pactado', 130)
            ->assertJsonCount(3, 'data.metas');
        $this->assertSame([$jumbo->id, $grande->id, $jumbo->id], array_column($res->json('data.metas'), 'calibre_id'));
        $this->assertSame(3, ConvenioMeta::whereNotNull('calibre_id')->count());
    }

    public function test_calibre_de_otra_variedad_da_422(): void
    {
        $otraVariedad = Variedad::where('cultivo_id', $this->variedad->cultivo_id)->where('id', '!=', $this->variedad->id)->orderBy('id')->firstOrFail();
        $ajeno = $this->calibre('Jumbo', 1, true, $otraVariedad);

        $this->postJson($this->url('convenios'), $this->cuerpoCalibres([[$ajeno, 100]]))
            ->assertStatus(422)->assertJsonValidationErrors(['calibres.0.calibre_id']);
        $this->assertSame(0, Convenio::count());
    }

    public function test_calibre_inactivo_no_se_agrega_pero_se_conserva_si_ya_estaba(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $chico = $this->calibre('Chico', 3, false);

        $this->postJson($this->url('convenios'), $this->cuerpoCalibres([[$jumbo, 100], [$chico, 50]]))
            ->assertStatus(422)->assertJsonValidationErrors(['calibres.1.calibre_id']);

        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, 100]]));
        $jumbo->update(['is_active' => false]);

        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, 120]]))
            ->assertOk()->assertJsonPath('data.calibres.0.pactado', 120);
    }

    public function test_calibre_repetido_da_422(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);

        $this->postJson($this->url('convenios'), $this->cuerpoCalibres([[$jumbo, 100], [$jumbo, 50]]))
            ->assertStatus(422)->assertJsonValidationErrors(['calibres.1.calibre_id']);
        $this->assertSame(0, Convenio::count());
    }

    public function test_precio_sin_calibre_en_convenio_por_calibre_da_422(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $body = $this->cuerpoCalibres([[$jumbo, 100]]);
        $body['precios'][] = ['tipo_carga_id' => $this->tipo()->id, 'precio' => 90, 'vigencia_inicio' => '2026-06-15'];

        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.1.calibre_id']);
    }

    public function test_convenio_clasico_con_calibre_en_el_cuerpo_da_422(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);

        $this->postJson($this->url('convenios'), $this->cuerpo(['calibres' => [['calibre_id' => $jumbo->id, 'volumen_total' => 10]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['calibres']);

        $body = $this->cuerpo();
        $body['precios'][0]['calibre_id'] = $jumbo->id;
        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.0.calibre_id']);

        $body = $this->cuerpo();
        $body['metas'][1]['calibre_id'] = $jumbo->id;
        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['metas.1.calibre_id']);

        $this->assertSame(0, Convenio::count());
        // Un clásico sigue igual: `calibres` vacío no estorba y la respuesta trae la forma nueva.
        $this->postJson($this->url('convenios'), $this->cuerpo(['calibres' => []]))->assertCreated()
            ->assertJsonPath('data.usa_calibres', false)
            ->assertJsonPath('data.calibres', [])
            ->assertJsonPath('data.precios.0.calibre_id', null)
            ->assertJsonPath('data.precios.0.calibre', null)
            ->assertJsonPath('data.metas.0.calibre_id', null)
            ->assertJsonPath('data.indicadores.calibres', []);
    }

    public function test_mismo_tipo_y_vigencia_en_calibres_distintos_se_guarda(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);

        $this->postJson($this->url('convenios'), $this->cuerpoCalibres([[$jumbo, 100, 200], [$grande, 100, 150]]))
            ->assertCreated();

        $this->assertSame(2, ConvenioPrecio::where('tipo_carga_id', $this->tipo()->id)->whereDate('vigencia_inicio', '2026-06-01')->count());
    }

    // ---- Edición ----

    public function test_editar_borrador_cambia_calibres_y_no_deja_huerfanos(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $otraVariedad = Variedad::where('cultivo_id', $this->variedad->cultivo_id)->where('id', '!=', $this->variedad->id)->orderBy('id')->firstOrFail();
        $mediano = $this->calibre('Mediano', 1, true, $otraVariedad);

        $semanal = fn (array $cals) => [
            'modo_volumen' => 'semanal',
            'metas' => array_map(fn ($c) => ['semana' => '2026-06-01', 'cantidad' => 10, 'calibre_id' => $c->id], $cals),
        ];
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null], [$grande, null]], $semanal([$jumbo, $grande])));

        // Cambia de variedad: los calibres, precios y metas de la anterior se van.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$mediano, null]], ['variedad_id' => $otraVariedad->id] + $semanal([$mediano])))
            ->assertOk()->assertJsonCount(1, 'data.calibres')->assertJsonPath('data.calibres.0.calibre_id', $mediano->id);

        $this->assertSame([$mediano->id], ConvenioCalibre::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
        $this->assertSame([$mediano->id], ConvenioPrecio::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
        $this->assertSame([$mediano->id], ConvenioMeta::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());

        // Y regresa: los precios borrados del mismo (tipo, calibre, vigencia) se restauran sin chocar.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, null], [$grande, null]], $semanal([$jumbo, $grande])))
            ->assertOk()->assertJsonCount(2, 'data.calibres');
        $this->assertSame(2, ConvenioPrecio::where('convenio_id', $convenio->id)->count());
        $this->assertSame(3, ConvenioPrecio::withTrashed()->where('convenio_id', $convenio->id)->count());
    }

    public function test_editar_sin_metas_conserva_las_metas_por_calibre(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 10, 'calibre_id' => $jumbo->id]],
        ]));

        $body = $this->cuerpoCalibres([[$jumbo, null]], ['modo_volumen' => 'semanal', 'notas' => 'sin metas']);
        unset($body['metas']);

        $this->putJson($this->url("convenios/{$convenio->id}"), $body)
            ->assertOk()->assertJsonPath('data.calibres.0.pactado', 10)->assertJsonPath('data.notas', 'sin metas');
    }

    public function test_quitar_un_calibre_sin_enviar_metas_borra_sus_metas(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null], [$grande, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 40, 'calibre_id' => $jumbo->id],
                ['semana' => '2026-06-01', 'cantidad' => 30, 'calibre_id' => $grande->id],
            ],
        ]));

        $body = $this->cuerpoCalibres([[$jumbo, null]], ['modo_volumen' => 'semanal']);
        unset($body['metas']);

        $res = $this->putJson($this->url("convenios/{$convenio->id}"), $body)->assertOk();
        $res->assertJsonCount(1, 'data.calibres')
            ->assertJsonPath('data.calibres.0.pactado', 40)
            ->assertJsonPath('data.indicadores.pactado', 40)
            ->assertJsonCount(1, 'data.metas')
            ->assertJsonPath('data.metas.0.calibre_id', $jumbo->id);
        $this->assertSame([$jumbo->id], ConvenioMeta::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
    }

    public function test_apagar_calibres_en_borrador_limpia_filas(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 10, 'calibre_id' => $jumbo->id]],
        ]));

        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpo(['usa_calibres' => false]))
            ->assertOk()
            ->assertJsonPath('data.usa_calibres', false)
            ->assertJsonPath('data.calibres', [])
            ->assertJsonPath('data.indicadores.pactado', 220);

        $this->assertSame(0, ConvenioCalibre::where('convenio_id', $convenio->id)->count());
        $this->assertSame(0, ConvenioPrecio::where('convenio_id', $convenio->id)->whereNotNull('calibre_id')->count());
        $this->assertSame(0, ConvenioMeta::where('convenio_id', $convenio->id)->whereNotNull('calibre_id')->count());
        $this->assertSame(2, ConvenioMeta::where('convenio_id', $convenio->id)->whereNull('calibre_id')->count());
    }

    public function test_no_se_cambia_usa_calibres_con_recepciones(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $convenio = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id, 'variedad_id' => $this->variedad->id,
        ]);
        $this->crearRecepcion($convenio);

        $body = $this->cuerpoCalibres([[$jumbo, 100]], ['porcentaje_rezaga' => 0]);
        $this->putJson($this->url("convenios/{$convenio->id}"), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usa_calibres'])
            ->assertJsonPath('errors.usa_calibres.0', 'No se puede cambiar: el convenio ya tiene recepciones.');
        $this->assertFalse($convenio->refresh()->usa_calibres);

        // Con liquidaciones (sin recepciones) también.
        $otro = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id, 'variedad_id' => $this->variedad->id,
        ]);
        $this->crearLiquidacion($otro);
        $this->putJson($this->url("convenios/{$otro->id}"), $body)
            ->assertStatus(422)
            ->assertJsonPath('errors.usa_calibres.0', 'No se puede cambiar: el convenio ya tiene liquidaciones.');
    }

    // ---- Estatus ----

    public function test_activar_exige_precio_principal_en_cada_calibre(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, 100], [$grande, 100]]));
        ConvenioPrecio::where('convenio_id', $convenio->id)->where('calibre_id', $grande->id)->delete();

        $this->patchJson($this->url("convenios/{$convenio->id}/estado"), ['status' => 'activo'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('borrador', $convenio->refresh()->status);
    }

    public function test_activar_exige_volumen_en_cada_calibre(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, 100], [$grande, 100]]));
        ConvenioCalibre::where('convenio_id', $convenio->id)->where('calibre_id', $grande->id)->update(['volumen_total' => 0]);

        $this->patchJson($this->url("convenios/{$convenio->id}/estado"), ['status' => 'activo'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Define el volumen pactado de cada calibre antes de activar el convenio.');

        ConvenioCalibre::where('convenio_id', $convenio->id)->where('calibre_id', $grande->id)->update(['volumen_total' => 50]);
        $this->patchJson($this->url("convenios/{$convenio->id}/estado"), ['status' => 'activo'])
            ->assertOk()->assertJsonPath('data.status', 'activo')->assertJsonPath('data.calibres.1.pactado', 50);
    }

    // ---- Indicadores y presentación ----

    public function test_comprometido_suma_por_calibre(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $tipo = $this->tipo()->id;
        $body = $this->cuerpoCalibres([[$jumbo, 100], [$grande, 50]], [
            'modalidad' => 'consignacion',
            'precios' => [
                ['tipo_carga_id' => $tipo, 'calibre_id' => $jumbo->id, 'precio' => 10, 'porcentaje_productor' => 80, 'vigencia_inicio' => '2026-06-01'],
                // Una vigencia posterior no cuenta: el comprometido usa la vigente al inicio.
                ['tipo_carga_id' => $tipo, 'calibre_id' => $jumbo->id, 'precio' => 99, 'porcentaje_productor' => 80, 'vigencia_inicio' => '2026-06-15'],
                ['tipo_carga_id' => $tipo, 'calibre_id' => $grande->id, 'precio' => 20, 'porcentaje_productor' => 50, 'vigencia_inicio' => '2026-06-01'],
            ],
        ]);

        $res = $this->postJson($this->url('convenios'), $body)->assertCreated();

        // 100 × 10 × 80 % + 50 × 20 × 50 % = 800 + 500
        $this->assertEquals(1300, $res->json('data.indicadores.comprometido'));
        $this->assertEquals(150, $res->json('data.indicadores.pactado'));
        $porCalibre = collect($res->json('data.indicadores.calibres'))->keyBy('calibre_id');
        // Plan 2 (B4): el renglón suma recibido, avance y valor recibido (sin recepciones: 0).
        $this->assertEquals([
            'calibre_id' => $jumbo->id, 'nombre' => 'Jumbo', 'pactado' => 100, 'comprometido' => 800,
            'recibido' => 0, 'avance' => 0, 'valor_recibido' => 0,
        ], $porCalibre[$jumbo->id]);
        $this->assertEquals(500, $porCalibre[$grande->id]['comprometido']);
    }

    public function test_show_e_index_traen_calibres(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null], [$grande, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 40, 'calibre_id' => $jumbo->id],
                ['semana' => '2026-06-01', 'cantidad' => 30, 'calibre_id' => $grande->id],
            ],
        ]));
        // Un calibre sin renglones de metas sigue saliendo (pactado 0).
        ConvenioMeta::where('convenio_id', $convenio->id)->where('calibre_id', $grande->id)->delete();

        $show = $this->getJson($this->url("convenios/{$convenio->id}"))->assertOk();
        $show->assertJsonPath('data.usa_calibres', true)
            ->assertJsonPath('data.precio_principal', null)
            ->assertJsonCount(2, 'data.calibres')
            ->assertJsonPath('data.calibres.0.pactado', 40)
            ->assertJsonPath('data.calibres.1.calibre_id', $grande->id)
            ->assertJsonPath('data.calibres.1.pactado', 0)
            ->assertJsonCount(2, 'data.indicadores.calibres')
            ->assertJsonPath('data.precios.0.calibre.nombre', 'Jumbo');

        $index = $this->getJson($this->url("convenios?temporada_id={$this->temporada->id}"))->assertOk();
        $index->assertJsonPath('data.0.usa_calibres', true)
            ->assertJsonPath('data.0.precio_principal', null)
            ->assertJsonCount(2, 'data.0.calibres')
            ->assertJsonCount(2, 'data.0.indicadores.calibres');

        $this->getJson("/api/splendidbyporvenir/administration/abastecimiento/tablero?temporada_id={$this->temporada->id}")->assertOk();
        $convenio->update(['status' => 'activo']);
        $tablero = $this->getJson("/api/splendidbyporvenir/administration/abastecimiento/tablero?temporada_id={$this->temporada->id}")
            ->assertOk()->json('data');
        // Σ por calibre: Jumbo 40 × 100 + Grande 0 × 100.
        $fila = collect($tablero['convenios'])->firstWhere('id', $convenio->id);
        $this->assertEquals(4000, $fila['indicadores']['comprometido']);
        $this->get("/api/splendidbyporvenir/administration/abastecimiento/tablero/exportar?temporada_id={$this->temporada->id}")->assertOk();
    }

    public function test_put_sin_usa_calibres_conserva_el_convenio_por_calibre(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $convenio = $this->crear($this->cuerpoCalibres([[$jumbo, null]], [
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 10, 'calibre_id' => $jumbo->id]],
        ]));

        // Un front viejo (en caché) no manda `usa_calibres` ni calibres: falla cerrado, sin tocar nada.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpo())
            ->assertStatus(422)
            ->assertJsonPath('errors.calibres.0', 'Elige al menos un calibre.');

        $this->assertTrue($convenio->refresh()->usa_calibres);
        $this->assertSame([$jumbo->id], ConvenioCalibre::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
        $this->assertSame([$jumbo->id], ConvenioPrecio::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
        $this->assertSame(1, ConvenioPrecio::withTrashed()->where('convenio_id', $convenio->id)->count());
        $this->assertSame([$jumbo->id], ConvenioMeta::where('convenio_id', $convenio->id)->pluck('calibre_id')->all());
    }

    public function test_precio_clasico_borrado_choca_en_la_base_y_se_restaura(): void
    {
        $tipo = $this->tipo()->id;
        $precios = [
            ['tipo_carga_id' => $tipo, 'precio' => 185, 'vigencia_inicio' => '2026-06-01'],
            ['tipo_carga_id' => $tipo, 'precio' => 190, 'vigencia_inicio' => '2026-06-15'],
        ];
        $convenio = $this->crear($this->cuerpo(['precios' => $precios]));
        $segundo = ConvenioPrecio::where('convenio_id', $convenio->id)->whereDate('vigencia_inicio', '2026-06-15')->firstOrFail();

        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpo(['precios' => [$precios[0]]]))->assertOk();
        $this->assertSoftDeleted($segundo);

        // El índice único cuenta el renglón borrado: dos clásicos (calibre nulo) iguales chocan.
        try {
            ConvenioPrecio::create(['convenio_id' => $convenio->id, 'tipo_carga_id' => $tipo, 'precio' => 1, 'vigencia_inicio' => '2026-06-15']);
            $this->fail('Se esperaba el choque con el índice único.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpo(['precios' => $precios]))->assertOk();
        $this->assertNotSoftDeleted($segundo);
        $this->assertSame(2, ConvenioPrecio::withTrashed()->where('convenio_id', $convenio->id)->count());
    }

    // ---- Recepciones: reglas por tipo de convenio ----

    private function convenioPorCalibre(): Convenio
    {
        return $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id,
            'variedad_id' => $this->variedad->id, 'usa_calibres' => true,
        ]);
    }

    public function test_recepcion_clasica_con_renglones_da_422(): void
    {
        $clasico = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id, 'variedad_id' => $this->variedad->id,
        ]);

        try {
            ReglasRecepcion::validar($clasico, [
                'fecha' => '2026-06-10', 'tipo_carga_id' => $this->tipo()->id, 'cantidad' => 10,
                'renglones' => [['calibre_id' => $this->calibre('A')->id, 'cantidad' => 10]],
            ]);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('Este convenio no es por calibre.', $e->errors()['renglones'][0]);
        }
    }

    public function test_reglas_aceptan_renglones_y_el_importe_suma_cada_calibre(): void
    {
        $a = $this->calibre('A');
        $b = $this->calibre('B');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 100, 'precio' => 20.0],
            ['calibre' => $b, 'volumen' => 100, 'precio' => 35.5],
        ]);
        $renglones = [
            ['calibre_id' => $a->id, 'cantidad' => 10],
            ['calibre_id' => $b->id, 'cantidad' => 4],
        ];

        ReglasRecepcion::validar($convenio, [
            'fecha' => '2026-06-10', 'tipo_carga_id' => $this->tipo()->id, 'renglones' => $renglones,
        ]);

        $recepcion = $this->crearRecepcionConRenglones($convenio, $renglones);
        $this->assertSame(342.0, ReglasRecepcion::importeRenglones($convenio, $recepcion));

        // Un renglón sin precio a la fecha de la recepción deja el importe en nulo.
        $recepcion->update(['fecha' => '2026-05-15']);
        $this->assertNull(ReglasRecepcion::importeRenglones($convenio, $recepcion->refresh()->load('renglones')));
    }

    public function test_recepcion_por_calibre_con_cantidad_y_sin_renglones_da_422(): void
    {
        $convenio = $this->convenioPorCalibre();

        $this->postJson($this->url('recepciones'), [
            'convenio_id' => $convenio->id, 'fecha' => '2026-06-10',
            'tipo_carga_id' => $this->tipo()->id, 'cantidad' => 10,
        ])->assertStatus(422)->assertJsonPath('errors.renglones.0', 'Captura la recepción por calibre.');
        $this->assertSame(0, Recepcion::count());
    }

    public function test_importacion_rechaza_convenio_por_calibre(): void
    {
        $convenio = $this->convenioPorCalibre();

        $r = app(ValidadorImportacionRecepciones::class)->validar($this->sbp, [[
            'fila' => 2, 'convenio_folio' => $convenio->folio, 'fecha' => '2026-06-10',
            'tipo_carga' => $this->tipo()->nombre, 'cantidad' => '10', 'kilos' => '', 'rezaga_kg' => '', 'ticket' => 'T-1',
        ]]);

        $this->assertSame('error', $r['filas'][0]['estado']);
        $this->assertSame(
            ['Este convenio es por calibre: la importación por calibre llegará con el formato de Excel.'],
            $r['filas'][0]['errores'],
        );
        $this->assertSame(1, $r['resumen']['con_error']);
    }

    public function test_recepciones_clasicas_siguen_igual(): void
    {
        $clasico = $this->crearConvenio($this->sbp, [
            'temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id, 'variedad_id' => $this->variedad->id,
        ]);
        $cuerpo = [
            'convenio_id' => $clasico->id, 'fecha' => '2026-06-10',
            'tipo_carga_id' => $this->tipo()->id, 'cantidad' => 10,
        ];

        $id = $this->postJson($this->url('recepciones'), $cuerpo)->assertCreated()->json('data.id');
        $this->putJson($this->url('recepciones/'.$id), $cuerpo + ['notas' => 'ok'])->assertOk();
        $this->assertSame(1, Recepcion::count());
    }

    // ---- Edición de un convenio por calibre con recepciones (C7, C14) ----

    /**
     * Convenio activo por calibre (Jumbo y Grande, precio desde el 1 de junio) con una recepción
     * el 10 de junio que solo trae renglón de Jumbo.
     *
     * @return array{0: Convenio, 1: VariedadCalibre, 2: VariedadCalibre}
     */
    private function porCalibreConRecepcion(): array
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $grande = $this->calibre('Grande', 2);
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $jumbo, 'volumen' => 100],
            ['calibre' => $grande, 'volumen' => 100],
        ], ['temporada_id' => $this->temporada->id, 'productor_id' => $this->productor->id, 'variedad_id' => $this->variedad->id]);
        $this->crearRecepcionConRenglones($convenio, [['calibre_id' => $jumbo->id, 'cantidad' => 10]], ['fecha' => '2026-06-10']);

        return [$convenio, $jumbo, $grande];
    }

    public function test_no_se_quita_un_calibre_con_recepciones(): void
    {
        [$convenio, $jumbo, $grande] = $this->porCalibreConRecepcion();

        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$grande, 100]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calibres'])
            ->assertJsonPath('errors.calibres.0', 'No se puede quitar un calibre que ya tiene recepciones.');
        $this->assertEqualsCanonicalizing(
            [$jumbo->id, $grande->id],
            ConvenioCalibre::where('convenio_id', $convenio->id)->pluck('calibre_id')->all(),
        );

        // Grande no tiene renglones: ese sí se quita.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, 100]]))
            ->assertOk()->assertJsonCount(1, 'data.calibres')->assertJsonPath('data.calibres.0.calibre_id', $jumbo->id);
    }

    public function test_se_agrega_un_calibre_con_recepciones(): void
    {
        [$convenio, $jumbo, $grande] = $this->porCalibreConRecepcion();
        $chico = $this->calibre('Chico', 3);

        // Agregar un calibre y cambiar volúmenes se permite (C14).
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, 150], [$grande, 80], [$chico, 40]]))
            ->assertOk()
            ->assertJsonCount(3, 'data.calibres')
            ->assertJsonPath('data.calibres.0.calibre_id', $jumbo->id)
            ->assertJsonPath('data.calibres.0.pactado', 150);
        $this->assertSame(3, ConvenioCalibre::where('convenio_id', $convenio->id)->count());
    }

    public function test_cobertura_por_calibre_bloquea_un_precio_que_deja_renglones_sin_precio(): void
    {
        [$convenio, $jumbo, $grande] = $this->porCalibreConRecepcion();
        $tipo = $this->tipo()->id;
        $precios = fn (string $desdeJumbo, string $desdeGrande) => [
            ['tipo_carga_id' => $tipo, 'calibre_id' => $jumbo->id, 'precio' => 100, 'vigencia_inicio' => $desdeJumbo],
            ['tipo_carga_id' => $tipo, 'calibre_id' => $grande->id, 'precio' => 100, 'vigencia_inicio' => $desdeGrande],
        ];

        // El precio de Jumbo empezaría el 15: el renglón de Jumbo del 10 se quedaría sin precio.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, 100], [$grande, 100]], [
            'precios' => $precios('2026-06-15', '2026-06-01'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precios'])
            ->assertJsonPath('errors.precios.0', 'No se puede: el precio dejaría recepciones ya capturadas sin precio vigente.');

        // Grande no tiene renglones: su precio sí puede empezar después.
        $this->putJson($this->url("convenios/{$convenio->id}"), $this->cuerpoCalibres([[$jumbo, 100], [$grande, 100]], [
            'precios' => $precios('2026-06-01', '2026-06-15'),
        ]))->assertOk();
    }

    public function test_nueva_vigencia_de_precio_por_calibre_con_recepciones_se_acepta(): void
    {
        [$convenio, $jumbo, $grande] = $this->porCalibreConRecepcion();
        $body = $this->cuerpoCalibres([[$jumbo, 100], [$grande, 100]]);
        $body['precios'][] = ['tipo_carga_id' => $this->tipo()->id, 'calibre_id' => $jumbo->id, 'precio' => 130, 'vigencia_inicio' => '2026-06-20'];

        $this->putJson($this->url("convenios/{$convenio->id}"), $body)->assertOk()->assertJsonCount(3, 'data.precios');
        $this->assertSame(3, ConvenioPrecio::where('convenio_id', $convenio->id)->count());
    }
}
