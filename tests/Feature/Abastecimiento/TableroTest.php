<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Tablero de Abastecimiento con los cinco convenios del prototipo aprobado
 * (MXN) y uno en USD. «Hoy» es el martes 14 de julio de 2026 (semana ISO 29).
 */
class TableroTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    /**
     * Tope documentado de consultas de GET tablero, sea cual sea el número de convenios.
     * Medido con un administrador: empresa + módulo + temporada + convenios + productor + variedad + precios +
     * tipo de carga + metas + recepciones agrupadas + recepciones sin liquidar = 11 (igual con 2 o con 10 convenios).
     * Si crece, revisar que no sea por convenio.
     */
    private const TOPE_CONSULTAS_TABLERO = 11;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(string $query = '', string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/tablero".$query;
    }

    /** Lunes de la semana ISO $n de 2026 (la semana 26 empieza el 22 de junio). */
    private function lunes(int $n): string
    {
        return CarbonImmutable::parse('2026-06-22')->addWeeks($n - 26)->toDateString();
    }

    private function productor(string $nombre, string $tipo = 'productor'): int
    {
        return Productor::create([
            'enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'tipo' => $tipo, 'is_active' => true,
        ])->id;
    }

    /** Metas semanales desde la semana ISO $desde: [lunes => cantidad]. */
    private function metas(int $desde, array $cantidades): array
    {
        $metas = [];
        foreach ($cantidades as $i => $cantidad) {
            $metas[$this->lunes($desde + $i)] = $cantidad;
        }

        return $metas;
    }

    /** Recepciones de un martes por semana ISO: [semana => cantidad]. */
    private function recibir(Convenio $c, array $porSemana, bool $kilos = false): void
    {
        foreach ($porSemana as $semana => $cantidad) {
            $this->crearRecepcion($c, [
                'fecha' => CarbonImmutable::parse($this->lunes($semana))->addDay()->toDateString(),
                'cantidad' => $cantidad,
                'kilos' => $kilos ? $cantidad : null,
            ]);
        }
    }

    /**
     * Los cinco convenios del prototipo (folios CV-0001 a CV-0005), todos en MXN:
     * 1 en meta, 2 en meta (kg, consignación 70 %), 3 atrasado, 4 por iniciar
     * (empieza la semana 30), 5 adelantado (consignación 65 %).
     *
     * @return array<int, Convenio> indexados por número de convenio
     */
    private function prototipo(): array
    {
        $cv1 = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Empaque Huerta Real', 'empaque'),
            'precio' => 185, 'fecha_inicio' => $this->lunes(26), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(26, [2000, 2500, 2500, 3000, 3500, 3500, 3000, 2500, 1500]),
        ]);
        $this->recibir($cv1, [26 => 1980, 27 => 2210, 28 => 2650, 29 => 1300]);

        $cv2 = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Agrícola Los Laureles'),
            'modalidad' => 'consignacion', 'unidad' => 'kg', 'precio' => 9.5,
            'fecha_inicio' => $this->lunes(18), 'fecha_fin' => '2026-07-26',
            'modo_volumen' => 'total', 'volumen_total' => 180000, 'metas' => [],
        ]);
        $cv2->precios->first()->update(['porcentaje_productor' => 70]);
        $this->recibir($cv2, [
            18 => 9800, 19 => 12400, 20 => 14100, 21 => 15200, 22 => 16050, 23 => 15800,
            24 => 14900, 25 => 14300, 26 => 13950, 27 => 13100, 28 => 12800, 29 => 5200,
        ], kilos: true);

        $cv3 = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Mangos del Pacífico'),
            'precio' => 178, 'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-30',
            'metas' => $this->metas(27, [1500, 1500, 2000, 2000, 2000, 2000, 2000, 1500, 500]),
        ]);
        $this->recibir($cv3, [27 => 1180, 28 => 920, 29 => 400]);

        $cv4 = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Santa Rita', 'empaque'),
            'precio' => 192, 'fecha_inicio' => $this->lunes(30), 'fecha_fin' => '2026-09-06',
            'metas' => $this->metas(30, [1000, 1500, 2000, 2000, 1500, 1200, 800]),
        ]);

        $cv5 = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Rancho El Palmar'),
            'modalidad' => 'consignacion', 'precio' => 150,
            'fecha_inicio' => $this->lunes(22), 'fecha_fin' => '2026-07-26',
            'modo_volumen' => 'total', 'volumen_total' => 8000, 'metas' => [],
        ]);
        $cv5->precios->first()->update(['porcentaje_productor' => 65]);
        $this->recibir($cv5, [22 => 700, 23 => 950, 24 => 1020, 25 => 1100, 26 => 980, 27 => 1050, 28 => 900, 29 => 300]);

        return [1 => $cv1, 2 => $cv2, 3 => $cv3, 4 => $cv4, 5 => $cv5];
    }

    /** Convenio en USD, atrasado, que cambiaría los números si se mezclara. */
    private function convenioUsd(int $temporadaId): Convenio
    {
        $usd = $this->crearConvenio($this->sbp, [
            'temporada_id' => $temporadaId, 'moneda' => 'USD', 'precio' => 12,
            'productor_id' => $this->productor('Exportadora del Valle'),
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-02',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($usd, [27 => 100, 28 => 100]);

        return $usd;
    }

    private function tablero(int $temporadaId, string $extra = ''): array
    {
        return $this->getJson($this->url("?temporada_id={$temporadaId}{$extra}"))->assertOk()->json('data');
    }

    // ---- Monedas ----

    public function test_monedas_presentes_y_moneda_por_omision(): void
    {
        $c = $this->prototipo();
        $this->convenioUsd($c[1]->temporada_id);

        $res = $this->getJson($this->url("?temporada_id={$c[1]->temporada_id}"))->assertOk();

        $res->assertJsonPath('success', true);
        $this->assertIsString($res->json('message'));
        $this->assertSame(['MXN', 'USD'], $res->json('data.monedas'));
        $this->assertSame('MXN', $res->json('data.moneda'));
    }

    public function test_pedir_usd_muestra_solo_los_convenios_en_usd(): void
    {
        $c = $this->prototipo();
        $usd = $this->convenioUsd($c[1]->temporada_id);

        $data = $this->tablero($c[1]->temporada_id, '&moneda=USD');

        $this->assertSame('USD', $data['moneda']);
        $this->assertSame([$usd->id], array_column($data['convenios'], 'id'));
        $this->assertEqualsWithDelta(4000 * 12, $data['kpis']['comprometido'], 0.01);
        $this->assertEqualsWithDelta(200 * 12, $data['kpis']['valor_recibido'], 0.01);
        $this->assertSame(1, $data['kpis']['abajo_de_meta']);
        $this->assertSame(1, $data['kpis']['con_avance']);
        // La gráfica de cajas solo trae el convenio en USD.
        $this->assertEqualsWithDelta(1000, $data['grafica']['caja'][0]['meta'], 0.001);
        $this->assertSame([], $data['grafica']['kg']);
    }

    public function test_moneda_pedida_sin_convenios_cae_a_la_primera(): void
    {
        $c = $this->prototipo();

        $data = $this->tablero($c[1]->temporada_id, '&moneda=USD');

        $this->assertSame(['MXN'], $data['monedas']);
        $this->assertSame('MXN', $data['moneda']);
        $this->assertCount(5, $data['convenios']);
    }

    // ---- Indicadores ----

    public function test_kpis_en_mxn_no_incluyen_los_de_usd(): void
    {
        $c = $this->prototipo();
        $this->convenioUsd($c[1]->temporada_id);

        $kpis = $this->tablero($c[1]->temporada_id, '&moneda=MXN')['kpis'];

        $this->assertSame(1, $kpis['abajo_de_meta']);
        $this->assertSame(4, $kpis['con_avance']); // el CV-0004 aún no empieza
        $this->assertEqualsWithDelta(4440000 + 1197000 + 2670000 + 1920000 + 780000, $kpis['comprometido'], 0.01);
        $this->assertEqualsWithDelta(1505900 + 1048040 + 445000 + 682500, $kpis['valor_recibido'], 0.01);
        // Ponderado por valor: Σ recibido a la fecha × peso / Σ meta a la fecha × peso.
        $this->assertEqualsWithDelta(0.9588, $kpis['cumplimiento'], 0.001);
        // Nada está liquidado: todo lo recibido está sin liquidar.
        $this->assertEqualsWithDelta($kpis['valor_recibido'], $kpis['sin_liquidar'], 0.01);
    }

    public function test_semana_de_hoy(): void
    {
        $c = $this->prototipo();

        $this->assertSame(
            ['lunes' => '2026-07-13', 'numero' => 29, 'domingo' => '2026-07-19'],
            $this->tablero($c[1]->temporada_id)['semana'],
        );
    }

    public function test_convenios_ordenados_por_estado_con_sus_datos(): void
    {
        $c = $this->prototipo();
        $this->convenioUsd($c[1]->temporada_id);

        $convenios = $this->tablero($c[1]->temporada_id)['convenios'];

        // atrasado, riesgo, en_meta, adelantado, por_iniciar (y dentro del estado, por folio).
        $this->assertSame(
            [$c[3]->id, $c[1]->id, $c[2]->id, $c[5]->id, $c[4]->id],
            array_column($convenios, 'id'),
        );
        $this->assertSame(
            ['atrasado', 'en_meta', 'en_meta', 'adelantado', 'por_iniciar'],
            array_column(array_column($convenios, 'indicadores'), 'estado'),
        );

        $cv3 = $convenios[0];
        $this->assertSame('CV-0003', $cv3['folio']);
        $this->assertSame('Mangos del Pacífico', $cv3['productor']['nombre']);
        $this->assertArrayHasKey('nombre', $cv3['variedad']);
        $this->assertSame('compra_directa', $cv3['modalidad']);
        $this->assertSame('caja', $cv3['unidad']);
        $this->assertEqualsWithDelta(178, $cv3['precio_principal']['precio'], 0.001);
        $this->assertArrayNotHasKey('semanas', $cv3['indicadores']);
        $this->assertEqualsWithDelta(0.70, $cv3['indicadores']['cumplimiento'], 0.001);
        $this->assertSame('consignacion', $convenios[2]['modalidad']);
        $this->assertSame('kg', $convenios[2]['unidad']);
    }

    public function test_orden_con_riesgo_y_dentro_del_estado_por_folio(): void
    {
        // Dos convenios en riesgo (A y C) con el atrasado B en medio: el atrasado va primero y,
        // entre los dos en riesgo, manda el folio.
        $a = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($a, [27 => 900, 28 => 900]); // 90 %: en riesgo
        $b = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($b, [27 => 1000, 28 => 500]); // 75 %: atrasado
        $c = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($c, [27 => 950, 28 => 950]); // 95 %: en riesgo

        $convenios = $this->tablero($a->temporada_id)['convenios'];

        $this->assertSame([$b->id, $a->id, $c->id], array_column($convenios, 'id'));
        $this->assertSame(['atrasado', 'riesgo', 'riesgo'], array_column(array_column($convenios, 'indicadores'), 'estado'));
    }

    // ---- Gráfica ----

    public function test_grafica_suma_por_semana_los_convenios_de_cada_unidad(): void
    {
        $c = $this->prototipo();
        $this->convenioUsd($c[1]->temporada_id);

        $grafica = $this->tablero($c[1]->temporada_id)['grafica'];

        $cajas = collect($grafica['caja'])->keyBy('lunes');
        // Semana 29: metas CV-0001 3000 + CV-0003 2000 + CV-0005 888 (el CV-0004 aún no empieza).
        $this->assertSame(29, $cajas['2026-07-13']['numero']);
        $this->assertEqualsWithDelta(3000 + 2000 + 888, $cajas['2026-07-13']['meta'], 0.001);
        $this->assertEqualsWithDelta(1300 + 400 + 300, $cajas['2026-07-13']['recibido'], 0.001);
        // Semana 26: CV-0001 y CV-0005 (el CV-0003 empieza en la 27).
        $this->assertEqualsWithDelta(2000 + 888, $cajas['2026-06-22']['meta'], 0.001);
        $this->assertEqualsWithDelta(1980 + 980, $cajas['2026-06-22']['recibido'], 0.001);
        // Semana 30: CV-0001 3500 + CV-0003 2000 + CV-0004 1000 + CV-0005 896 (la última absorbe el resto).
        $this->assertEqualsWithDelta(3500 + 2000 + 1000 + 896, $cajas['2026-07-20']['meta'], 0.001);
        $this->assertEqualsWithDelta(0, $cajas['2026-07-20']['recibido'], 0.001);
        // Ordenada por semana y sin el convenio en USD.
        $lunes = array_column($grafica['caja'], 'lunes');
        $sorted = $lunes;
        sort($sorted);
        $this->assertSame($sorted, $lunes);

        $kg = collect($grafica['kg'])->keyBy('lunes');
        $this->assertEqualsWithDelta(5200, $kg['2026-07-13']['recibido'], 0.001);
        $this->assertEqualsWithDelta(13846.15, $kg['2026-07-13']['meta'], 0.001);
        $this->assertSame(['lunes', 'numero', 'meta', 'recibido'], array_keys($grafica['kg'][0]));
    }

    // ---- Avisos ----

    public function test_avisos_de_atrasados_y_de_inicio_de_la_semana_siguiente(): void
    {
        $c = $this->prototipo();

        $avisos = $this->tablero($c[1]->temporada_id)['avisos'];

        $this->assertSame([
            [
                'tipo' => 'atrasado',
                'convenio_id' => $c[3]->id,
                'texto' => 'CV-0003 · Mangos del Pacífico recibió 70 % de su meta en las semanas cerradas. Faltan 900 cajas.',
            ],
            [
                'tipo' => 'inicia',
                'convenio_id' => $c[4]->id,
                'texto' => 'CV-0004 · Santa Rita empieza la semana 30 con 1,000 cajas.',
            ],
        ], $avisos);
    }

    public function test_aviso_de_atrasado_en_kilos_con_miles(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'unidad' => 'kg', 'productor_id' => $this->productor('Huerta Uno'),
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($c, [27 => 500], kilos: true);

        $avisos = $this->tablero($c->temporada_id)['avisos'];

        $this->assertCount(1, $avisos);
        $this->assertSame('CV-0001 · Huerta Uno recibió 25 % de su meta en las semanas cerradas. Faltan 1,500 kg.', $avisos[0]['texto']);
    }

    public function test_aviso_en_singular_con_una_caja_y_un_kilo(): void
    {
        // Atrasados sin recibir nada de una meta de 1 en la semana cerrada: «Faltan 1 caja» / «Faltan 1 kg».
        $caja = $this->crearConvenio($this->sbp, [
            'productor_id' => $this->productor('Huerta Caja'),
            'fecha_inicio' => $this->lunes(28), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(28, [1, 1, 1]),
        ]);
        $kg = $this->crearConvenio($this->sbp, [
            'temporada_id' => $caja->temporada_id, 'unidad' => 'kg',
            'productor_id' => $this->productor('Huerta Kilo'),
            'fecha_inicio' => $this->lunes(28), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(28, [1, 1, 1]),
        ]);
        // Empieza la semana siguiente con 1 caja.
        $inicia = $this->crearConvenio($this->sbp, [
            'temporada_id' => $caja->temporada_id,
            'productor_id' => $this->productor('Huerta Inicia'),
            'fecha_inicio' => $this->lunes(30), 'fecha_fin' => '2026-08-02',
            'metas' => $this->metas(30, [1]),
        ]);

        $textos = array_column($this->tablero($caja->temporada_id)['avisos'], 'texto', 'convenio_id');

        $this->assertSame('CV-0001 · Huerta Caja recibió 0 % de su meta en las semanas cerradas. Faltan 1 caja.', $textos[$caja->id]);
        $this->assertSame('CV-0002 · Huerta Kilo recibió 0 % de su meta en las semanas cerradas. Faltan 1 kg.', $textos[$kg->id]);
        $this->assertSame('CV-0003 · Huerta Inicia empieza la semana 30 con 1 caja.', $textos[$inicia->id]);
    }

    public function test_un_borrador_con_inicio_pasado_no_cuenta_como_atrasado(): void
    {
        $c = $this->prototipo();
        $c[3]->update(['status' => 'borrador']); // el CV-0003 estaba atrasado

        $data = $this->tablero($c[1]->temporada_id);

        $this->assertSame(0, $data['kpis']['abajo_de_meta']);
        $this->assertSame(3, $data['kpis']['con_avance']); // CV-0001, 0002 y 0005
        $this->assertSame([], array_filter($data['avisos'], fn ($a) => $a['tipo'] === 'atrasado'));
        $fila = collect($data['convenios'])->firstWhere('id', $c[3]->id);
        $this->assertSame('por_iniciar', $fila['indicadores']['estado']);
    }

    public function test_un_borrador_sin_recepciones_no_entra_al_cumplimiento_ni_a_la_grafica_pero_si_al_aviso_de_inicio(): void
    {
        $activo = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($activo, [27 => 1000, 28 => 1000]);
        $temporada = $activo->temporada_id;

        $antes = $this->tablero($temporada);
        $this->assertEqualsWithDelta(1.0, $antes['kpis']['cumplimiento'], 0.0001);

        // Borrador sin recepciones con inicio pasado y semanas cerradas con meta (sería 0 % si contara).
        $borrador = $this->crearConvenio($this->sbp, [
            'temporada_id' => $temporada, 'status' => 'borrador',
            'productor_id' => $this->productor('Huerta Borrador'),
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(27, [5000, 5000, 5000, 5000]),
        ]);
        // Borrador que empieza la semana siguiente: su aviso de inicio se conserva como recordatorio.
        $proximo = $this->crearConvenio($this->sbp, [
            'temporada_id' => $temporada, 'status' => 'borrador',
            'productor_id' => $this->productor('Huerta Proxima'),
            'fecha_inicio' => $this->lunes(30), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(30, [700]),
        ]);

        $data = $this->tablero($temporada);

        $this->assertEqualsWithDelta(1.0, $data['kpis']['cumplimiento'], 0.0001);
        $this->assertEqualsWithDelta($antes['kpis']['comprometido'], $data['kpis']['comprometido'], 0.001);
        $this->assertSame($antes['kpis']['abajo_de_meta'], $data['kpis']['abajo_de_meta']);
        $fila = collect($data['convenios'])->firstWhere('id', $borrador->id);
        $this->assertNull($fila['indicadores']['cumplimiento']);
        $this->assertSame('por_iniciar', $fila['indicadores']['estado']);
        $this->assertSame($antes['grafica'], $data['grafica']);

        $avisos = array_column($data['avisos'], 'tipo', 'convenio_id');
        $this->assertSame('inicia', $avisos[$proximo->id]);
        $this->assertArrayNotHasKey($borrador->id, $avisos);
    }

    public function test_sin_avisos_si_nadie_esta_atrasado_ni_empieza_la_semana_siguiente(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [1000, 1000, 1000, 1000]),
        ]);
        $this->recibir($c, [27 => 1000, 28 => 1000]);
        // Empieza en dos semanas: todavía no es aviso.
        $this->crearConvenio($this->sbp, [
            'temporada_id' => $c->temporada_id,
            'fecha_inicio' => $this->lunes(31), 'fecha_fin' => '2026-08-23',
            'metas' => $this->metas(31, [100, 100, 100]),
        ]);

        $this->assertSame([], $this->tablero($c->temporada_id)['avisos']);
    }

    // ---- Cancelados, vacío y sin liquidar ----

    public function test_los_cancelados_se_excluyen_de_todo_el_tablero(): void
    {
        $c = $this->prototipo();
        $usd = $this->convenioUsd($c[1]->temporada_id);
        $usd->update(['status' => 'cancelado']);
        $c[3]->update(['status' => 'cancelado']);

        $data = $this->tablero($c[1]->temporada_id);

        $this->assertSame(['MXN'], $data['monedas']);
        $this->assertNotContains($c[3]->id, array_column($data['convenios'], 'id'));
        $this->assertCount(4, $data['convenios']);
        $this->assertSame(0, $data['kpis']['abajo_de_meta']);
        $this->assertSame([], array_filter($data['avisos'], fn ($a) => $a['tipo'] === 'atrasado'));
        $this->assertEqualsWithDelta(1505900 + 1048040 + 682500, $data['kpis']['valor_recibido'], 0.01);
    }

    public function test_temporada_sin_convenios(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $c->delete();

        $data = $this->tablero($c->temporada_id);

        $this->assertSame([], $data['monedas']);
        $this->assertNull($data['moneda']);
        $this->assertSame([], $data['convenios']);
        $this->assertSame([], $data['avisos']);
        $this->assertSame(['caja' => [], 'kg' => []], $data['grafica']);
        $this->assertSame(
            ['cumplimiento' => null, 'comprometido' => 0, 'valor_recibido' => 0, 'sin_liquidar' => 0, 'abajo_de_meta' => 0, 'con_avance' => 0],
            array_map(fn ($v) => is_float($v) ? (int) $v : $v, $data['kpis']),
        );
        $this->assertSame(29, $data['semana']['numero']);
    }

    public function test_sin_liquidar_excluye_liquidadas_y_borradas_y_usa_el_precio_de_cada_fecha(): void
    {
        $c = $this->crearConvenio($this->sbp, [
            'modalidad' => 'consignacion', 'precio' => 100,
            'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-07-26',
            'metas' => $this->metas(27, [100, 100, 100, 100]),
        ]);
        $precio = $c->precios->first();
        $precio->update(['porcentaje_productor' => 50]);
        // A partir del lunes de la semana 28 el precio sube a 120.
        $c->precios()->create([
            'tipo_carga_id' => $precio->tipo_carga_id, 'precio' => 120, 'porcentaje_productor' => 50,
            'vigencia_inicio' => $this->lunes(28), 'es_principal' => true,
        ]);

        $this->crearRecepcion($c, ['fecha' => $this->lunes(27), 'cantidad' => 10]);              // 10 × 100 × 50 % = 500
        $this->crearRecepcion($c, ['fecha' => $this->lunes(28), 'cantidad' => 20]);              // 20 × 120 × 50 % = 1,200
        $this->crearRecepcion($c, ['fecha' => $this->lunes(28), 'cantidad' => 7, 'liquidacion_id' => 1]); // liquidada
        $borrada = $this->crearRecepcion($c, ['fecha' => $this->lunes(28), 'cantidad' => 5]);
        $borrada->delete();

        $kpis = $this->tablero($c->temporada_id)['kpis'];

        $this->assertEqualsWithDelta(1700, $kpis['sin_liquidar'], 0.01);
        // Lo recibido sí incluye la liquidada: 500 + 1,200 + 7 × 120 × 50 % = 2,120.
        $this->assertEqualsWithDelta(2120, $kpis['valor_recibido'], 0.01);
    }

    public function test_sin_liquidar_no_mezcla_monedas(): void
    {
        $c = $this->prototipo();
        $usd = $this->convenioUsd($c[1]->temporada_id);

        $mxn = $this->tablero($c[1]->temporada_id)['kpis']['sin_liquidar'];
        $enUsd = $this->tablero($c[1]->temporada_id, '&moneda=USD')['kpis']['sin_liquidar'];

        $this->assertEqualsWithDelta(200 * 12, $enUsd, 0.01);
        $this->assertEqualsWithDelta(1505900 + 1048040 + 445000 + 682500, $mxn, 0.01);
        $this->assertSame(2, Recepcion::where('convenio_id', $usd->id)->count());
    }

    // ---- Validación, permiso y empresa ----

    public function test_exige_temporada_de_la_empresa_y_moneda_valida(): void
    {
        $propio = $this->crearConvenio($this->sbp);
        $ajeno = $this->crearConvenio($this->otra);

        $this->getJson($this->url())->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url("?temporada_id={$ajeno->temporada_id}"))->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url('?temporada_id=999999'))->assertStatus(422)->assertJsonValidationErrors('temporada_id');
        $this->getJson($this->url("?temporada_id={$propio->temporada_id}&moneda=EUR"))->assertStatus(422)->assertJsonValidationErrors('moneda');
        $this->getJson($this->url("?temporada_id={$propio->temporada_id}&moneda=MXN"))->assertOk();
        // La temporada de SBP tampoco vale desde la URL de otra empresa.
        $this->getJson($this->url("?temporada_id={$propio->temporada_id}", 'otraempresa'))->assertStatus(422);
    }

    public function test_exige_permiso_tablero_view(): void
    {
        $c = $this->crearConvenio($this->sbp);
        Sanctum::actingAs($this->captor);

        $this->getJson($this->url("?temporada_id={$c->temporada_id}"))->assertForbidden();

        // Otro submódulo no basta.
        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view']);
        $this->getJson($this->url("?temporada_id={$c->temporada_id}"))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'tablero', ['view']);
        $this->getJson($this->url("?temporada_id={$c->temporada_id}"))->assertOk();
    }

    // ---- Consultas ----

    public function test_el_tablero_no_hace_consultas_por_convenio(): void
    {
        $c = $this->prototipo();
        $temporada = $c[1]->temporada_id;

        $contar = function () use ($temporada): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->url("?temporada_id={$temporada}"))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con5 = $contar();

        for ($i = 0; $i < 8; $i++) {
            $extra = $this->crearConvenio($this->sbp, [
                'temporada_id' => $temporada, 'fecha_inicio' => $this->lunes(27), 'fecha_fin' => '2026-08-02',
                'productor_id' => $this->productor("Productor extra {$i}"),
                'metas' => $this->metas(27, [100, 100, 100, 100]),
            ]);
            $this->recibir($extra, [27 => 80, 28 => 120]);
            $this->crearRecepcion($extra, ['fecha' => $this->lunes(29), 'cantidad' => 10, 'liquidacion_id' => 7]);
        }
        $this->assertSame(13, Convenio::count());

        $con13 = $contar();

        $this->assertSame($con5, $con13, 'El número de consultas no debe crecer con los convenios.');
        $this->assertLessThanOrEqual(self::TOPE_CONSULTAS_TABLERO, $con13);
    }
}
