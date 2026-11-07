<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Convenios en curso con fechas pasadas (spec 6.8): se dan de alta, se activan y reciben recepciones
 * retroactivas por la API; los indicadores y el tablero cuentan las semanas ya cerradas. Todo con
 * fechas absolutas y «hoy» fijo, para que la prueba no dependa del día en que se corra.
 */
class ConvenioEnCursoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private int $temporadaId;
    private int $productorId;
    private int $variedadId;
    private int $tipoCargaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $this->variedadId = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail()->id;
        $this->tipoCargaId = TipoCarga::where('enterprise_id', $this->sbp->id)->orderBy('id')->firstOrFail()->id;
        $this->productorId = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Productor en curso', 'tipo' => 'productor', 'is_active' => true])->id;
        $this->temporadaId = Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $cultivo->id, 'nombre' => 'Temporada en curso',
            'fecha_inicio' => '2025-08-01', 'fecha_fin' => '2026-12-31', 'estado' => 'activa',
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function hoy(string $fechaHora): void
    {
        $hoy = CarbonImmutable::parse($fechaHora, 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);
    }

    private function url(string $ruta): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    /** Alta por la API (borrador) y activación con PATCH estado; devuelve el id del convenio. */
    private function darDeAltaYActivar(array $over): int
    {
        $id = $this->postJson($this->url('convenios'), $over + [
            'temporada_id' => $this->temporadaId,
            'productor_id' => $this->productorId,
            'variedad_id' => $this->variedadId,
            'modalidad' => 'compra_directa',
            'unidad' => 'caja',
            'moneda' => 'MXN',
            'porcentaje_rezaga' => 0,
            'precios' => [[
                'tipo_carga_id' => $this->tipoCargaId, 'precio' => 100,
                'vigencia_inicio' => $over['fecha_inicio'], 'es_principal' => true,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'borrador')->json('data.id');

        $this->patchJson($this->url("convenios/{$id}/estado"), ['status' => 'activo'])
            ->assertOk()->assertJsonPath('data.status', 'activo');

        return $id;
    }

    private function recibir(int $convenioId, string $fecha, int $cantidad)
    {
        return $this->postJson($this->url('recepciones'), [
            'convenio_id' => $convenioId, 'fecha' => $fecha,
            'tipo_carga_id' => $this->tipoCargaId, 'cantidad' => $cantidad,
        ]);
    }

    /** Metas semanales iguales: [lunes => cantidad] en el formato del cuerpo. */
    private function metas(string $primerLunes, int $semanas, int $cantidad): array
    {
        $metas = [];
        for ($i = 0; $i < $semanas; $i++) {
            $metas[] = ['semana' => CarbonImmutable::parse($primerLunes)->addWeeks($i)->toDateString(), 'cantidad' => $cantidad];
        }

        return $metas;
    }

    private function semanasDe(array $indicadores): array
    {
        return array_column($indicadores['semanas'], null, 'lunes');
    }

    public function test_convenio_a_medias_con_metas_semanales_y_recepciones_de_hace_semanas(): void
    {
        // Hoy: miércoles 14 de octubre de 2026 (semana 42). El convenio va del 31 de agosto al 22 de noviembre (12 semanas, 6 cerradas).
        $this->hoy('2026-10-14 10:00');
        $id = $this->darDeAltaYActivar([
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-11-22',
            'modo_volumen' => 'semanal', 'metas' => $this->metas('2026-08-31', 12, 100),
        ]);

        // Recepciones retroactivas (la primera, de hace seis semanas) y una de esta misma semana.
        foreach ([['2026-09-02', 100], ['2026-09-09', 100], ['2026-09-23', 100], ['2026-10-07', 100], ['2026-10-13', 30]] as [$fecha, $cantidad]) {
            $this->recibir($id, $fecha, $cantidad)->assertCreated();
        }

        $i = $this->getJson($this->url("convenios/{$id}"))->assertOk()->assertJsonPath('data.status', 'activo')->json('data.indicadores');

        $this->assertEquals(1200.0, $i['pactado']);
        $this->assertEquals(430.0, $i['recibido']);
        // Las semanas del 31 de agosto al 5 de octubre ya cerraron; la del 12 de octubre sigue abierta.
        $this->assertEquals(600.0, $i['meta_a_la_fecha']);
        $this->assertEquals(400.0, $i['recibido_a_la_fecha']);
        $this->assertEquals(0.6667, $i['cumplimiento']);
        $this->assertSame('atrasado', $i['estado']);
        $this->assertEquals(800.0, $i['proyeccion']);
        $this->assertEquals(['lunes' => '2026-10-12', 'numero' => 42, 'meta' => 100.0, 'recibido' => 30.0], $i['semana_actual']);

        $semanas = $this->semanasDe($i);
        $this->assertCount(12, $semanas);
        $this->assertCount(6, array_filter($semanas, fn ($s) => $s['cerrada']));
        $this->assertTrue($semanas['2026-09-28']['cerrada']);
        $this->assertFalse($semanas['2026-10-12']['cerrada']);
        $this->assertEquals(100.0, $semanas['2026-08-31']['recibido']); // la de hace seis semanas
        $this->assertEquals(0.0, $semanas['2026-09-14']['recibido']);

        // El listado trae los mismos indicadores y el tablero lo muestra atrasado, con su aviso.
        $this->assertSame('atrasado', $this->getJson($this->url("convenios?temporada_id={$this->temporadaId}"))
            ->assertOk()->json('data.0.indicadores.estado'));

        $tablero = $this->getJson($this->url("tablero?temporada_id={$this->temporadaId}"))->assertOk()->json('data');
        $this->assertEquals(['lunes' => '2026-10-12', 'numero' => 42, 'domingo' => '2026-10-18'], $tablero['semana']);
        $this->assertSame($id, $tablero['convenios'][0]['id']);
        $this->assertSame('atrasado', $tablero['convenios'][0]['indicadores']['estado']);
        $this->assertEquals(0.6667, $tablero['kpis']['cumplimiento']);
        $this->assertEquals(1, $tablero['kpis']['abajo_de_meta']);
        $this->assertEquals(1, $tablero['kpis']['con_avance']);
        $this->assertSame('atrasado', $tablero['avisos'][0]['tipo']);
        $this->assertStringContainsString('Faltan 200 cajas', $tablero['avisos'][0]['texto']);
        $this->assertEquals(100.0, collect($tablero['grafica']['caja'])->firstWhere('lunes', '2026-08-31')['recibido']);
    }

    public function test_convenio_a_medias_con_volumen_total(): void
    {
        $this->hoy('2026-10-14 10:00');
        $id = $this->darDeAltaYActivar([
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-11-22',
            'modo_volumen' => 'total', 'volumen_total' => 1200,
        ]);

        $this->recibir($id, '2026-09-02', 300)->assertCreated();
        $this->recibir($id, '2026-10-06', 300)->assertCreated();

        $i = $this->getJson($this->url("convenios/{$id}"))->assertOk()->json('data.indicadores');

        $this->assertEquals(1200.0, $i['pactado']);
        $this->assertEquals(600.0, $i['meta_a_la_fecha']);
        $this->assertEquals(600.0, $i['recibido_a_la_fecha']);
        $this->assertEquals(1.0, $i['cumplimiento']);
        $this->assertSame('en_meta', $i['estado']);
        $this->assertCount(6, array_filter($i['semanas'], fn ($s) => $s['cerrada']));
    }

    public function test_convenio_que_cruza_el_anio_de_la_semana_52_a_la_1(): void
    {
        // Hoy: miércoles 7 de enero de 2026 (semana 2). El convenio va del 8 de diciembre de 2025 al 1 de febrero de 2026:
        // semanas 50, 51, 52, 1, 2, 3, 4 y 5; las cuatro primeras (hasta el 4 de enero) ya cerraron.
        $this->hoy('2026-01-07 10:00');
        $id = $this->darDeAltaYActivar([
            'fecha_inicio' => '2025-12-08', 'fecha_fin' => '2026-02-01',
            'modo_volumen' => 'semanal', 'metas' => $this->metas('2025-12-08', 8, 200),
        ]);

        foreach ([['2025-12-10', 150], ['2025-12-17', 200], ['2025-12-24', 250], ['2025-12-31', 300], ['2026-01-05', 80]] as [$fecha, $cantidad]) {
            $this->recibir($id, $fecha, $cantidad)->assertCreated();
        }

        $i = $this->getJson($this->url("convenios/{$id}"))->assertOk()->json('data.indicadores');

        $this->assertEquals(1600.0, $i['pactado']);
        $this->assertEquals(800.0, $i['meta_a_la_fecha']);
        $this->assertEquals(900.0, $i['recibido_a_la_fecha']);
        $this->assertEquals(1.125, $i['cumplimiento']);
        $this->assertSame('adelantado', $i['estado']);
        $this->assertEquals(['lunes' => '2026-01-05', 'numero' => 2, 'meta' => 200.0, 'recibido' => 80.0], $i['semana_actual']);

        $this->assertSame([50, 51, 52, 1, 2, 3, 4, 5], array_column($i['semanas'], 'numero'));
        $this->assertSame(
            ['2025-12-08', '2025-12-15', '2025-12-22', '2025-12-29', '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'],
            array_column($i['semanas'], 'lunes'),
        );
        $semanas = $this->semanasDe($i);
        $this->assertEquals(300.0, $semanas['2025-12-29']['recibido']); // semana 1 de 2026
        $this->assertTrue($semanas['2025-12-29']['cerrada']);
        $this->assertFalse($semanas['2026-01-05']['cerrada']);

        $tablero = $this->getJson($this->url("tablero?temporada_id={$this->temporadaId}"))->assertOk()->json('data');
        $this->assertSame('adelantado', $tablero['convenios'][0]['indicadores']['estado']);
        $this->assertEquals(1.125, $tablero['kpis']['cumplimiento']);
        $this->assertSame(
            [50, 51, 52, 1, 2, 3, 4, 5],
            array_column($tablero['grafica']['caja'], 'numero'),
        );
    }

    public function test_lo_futuro_y_lo_ajeno_al_convenio_siguen_rechazandose(): void
    {
        $this->hoy('2026-10-14 10:00');
        $id = $this->darDeAltaYActivar([
            'fecha_inicio' => '2026-08-31', 'fecha_fin' => '2026-11-22',
            'modo_volumen' => 'total', 'volumen_total' => 1200,
        ]);

        $this->recibir($id, '2026-10-15', 10)->assertStatus(422)->assertJsonValidationErrors(['fecha']);
        $this->recibir($id, '2026-08-30', 10)->assertStatus(422)->assertJsonValidationErrors(['fecha']);
        $this->recibir($id, '2026-10-14', 10)->assertCreated();
    }
}
