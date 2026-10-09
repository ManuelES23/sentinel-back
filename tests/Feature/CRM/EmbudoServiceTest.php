<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\EmbudoService;
use App\Support\CRM\RangoDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class EmbudoServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private EmbudoService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        $this->servicio = new EmbudoService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $fecha): void
    {
        Carbon::setTestNow($fecha);
        CarbonImmutable::setTestNow($fecha);
    }

    /** @param array<string, string> $pasos fecha => etapa nueva */
    private function oportunidad(string $creada, array $pasos = [], array $extra = []): CrmOportunidad
    {
        $this->ahora($creada);
        $op = CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op',
            'monto_esperado' => 1000,
            'probabilidad' => 50,
        ], $extra));

        foreach ($pasos as $fecha => $etapa) {
            $this->ahora($fecha);
            $op->etapa = $etapa;
            if (in_array($etapa, CrmOportunidad::ETAPAS_TERMINALES, true)) {
                $op->fecha_cierre_real = now();
            }
            $op->save();
        }

        return $op;
    }

    private function octubre(): RangoDashboard
    {
        return RangoDashboard::desdePeriodo('personalizado', '2026-10-01', '2026-10-31');
    }

    private function porEtapa(array $resultado): array
    {
        return collect($resultado['etapas'])->keyBy('etapa')->all();
    }

    public function test_pipeline_cuenta_las_abiertas_hoy_con_y_sin_fecha_de_cierre(): void
    {
        $this->oportunidad('2026-10-01', [], ['monto_esperado' => 1000, 'probabilidad' => 20]);
        $this->oportunidad('2026-10-01', [], ['monto_esperado' => 3000, 'probabilidad' => 40, 'fecha_cierre_esperada' => '2026-12-01']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'propuesta'], ['monto_esperado' => 2000, 'probabilidad' => 50]);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'cerrado_ganado'], ['monto_esperado' => 9999]);

        $pipeline = collect($this->servicio->pipeline($this->enterprise->id, null))->keyBy('etapa');

        $this->assertSame(['prospecto', 'calificado', 'propuesta', 'negociacion'], $pipeline->keys()->all());
        $this->assertSame(2, $pipeline['prospecto']['cantidad']);
        $this->assertSame(4000.0, $pipeline['prospecto']['monto']);
        $this->assertSame(1400.0, $pipeline['prospecto']['ponderado']);
        $this->assertSame(30.0, $pipeline['prospecto']['probabilidadPromedio']);
        $this->assertSame(1, $pipeline['propuesta']['cantidad']);
        $this->assertSame(1000.0, $pipeline['propuesta']['ponderado']);
        $this->assertSame(0, $pipeline['calificado']['cantidad']);
        $this->assertSame(0.0, $pipeline['negociacion']['monto']);
    }

    public function test_pipeline_filtra_por_vendedor_y_empresa(): void
    {
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        $this->oportunidad('2026-10-01');
        $this->oportunidad('2026-10-01', [], ['vendedor_id' => $otro->id]);
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        $this->oportunidad('2026-10-01', [], ['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id]);

        $todos = collect($this->servicio->pipeline($this->enterprise->id, null))->keyBy('etapa');
        $propio = collect($this->servicio->pipeline($this->enterprise->id, $this->vendedor->id))->keyBy('etapa');

        $this->assertSame(2, $todos['prospecto']['cantidad']);
        $this->assertSame(1, $propio['prospecto']['cantidad']);
    }

    public function test_conversion_por_cohorte_cuenta_llegadas_fugas_y_dias(): void
    {
        // A: recorre todo y se gana.
        $this->oportunidad('2026-10-01', ['2026-10-03' => 'calificado', '2026-10-07' => 'propuesta', '2026-10-10' => 'negociacion', '2026-10-12' => 'cerrado_ganado'], ['monto_esperado' => 1000]);
        // B: se pierde en calificado.
        $this->oportunidad('2026-10-02', ['2026-10-04' => 'calificado', '2026-10-06' => 'cerrado_perdido'], ['motivo_perdida' => 'Precio']);
        // C: sigue en prospecto.
        $this->oportunidad('2026-10-02');
        // D: se salta calificado y sigue en propuesta.
        $this->oportunidad('2026-10-05', ['2026-10-08' => 'propuesta']);
        // E: creada fuera de la cohorte.
        $this->oportunidad('2026-09-20', ['2026-10-02' => 'calificado']);
        $this->ahora('2026-10-20');

        $r = $this->servicio->conversion($this->enterprise->id, null, $this->octubre());
        $e = $this->porEtapa($r);

        $this->assertSame(4, $r['total']);
        $this->assertSame(1, $r['ganadas']);
        $this->assertSame(1000.0, $r['montoGanado']);
        $this->assertSame(0, $r['perdidasSinRegistro']);

        $this->assertSame([4, 3, 2, 1], [$e['prospecto']['llegaron'], $e['calificado']['llegaron'], $e['propuesta']['llegaron'], $e['negociacion']['llegaron']]);
        $this->assertSame([1, 0, 1, 0], [$e['prospecto']['siguenAbiertas'], $e['calificado']['siguenAbiertas'], $e['propuesta']['siguenAbiertas'], $e['negociacion']['siguenAbiertas']]);
        $this->assertSame([0, 1, 0, 0], [$e['prospecto']['perdidas'], $e['calificado']['perdidas'], $e['propuesta']['perdidas'], $e['negociacion']['perdidas']]);
        $this->assertSame([75.0, 66.7, 50.0, 100.0], [$e['prospecto']['pasa'], $e['calificado']['pasa'], $e['propuesta']['pasa'], $e['negociacion']['pasa']]);
        // Prospecto: A 2, B 2, D 3 → 2.3. Calificado: A 4, B 2 → 3. Propuesta: A 3. Negociación: A 2.
        $this->assertSame([2.3, 3.0, 3.0, 2.0], [$e['prospecto']['diasPromedio'], $e['calificado']['diasPromedio'], $e['propuesta']['diasPromedio'], $e['negociacion']['diasPromedio']]);
        $this->assertNull($r['mayorFuga']);
    }

    public function test_conversion_cumple_la_identidad_entre_etapas(): void
    {
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'calificado', '2026-10-03' => 'cerrado_perdido']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'propuesta']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'negociacion', '2026-10-03' => 'cerrado_ganado']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'cerrado_perdido']);
        $this->ahora('2026-10-20');

        $r = $this->servicio->conversion($this->enterprise->id, null, $this->octubre());
        $etapas = $r['etapas'];

        foreach ($etapas as $i => $etapa) {
            $siguiente = $i < 3 ? $etapas[$i + 1]['llegaron'] : $r['ganadas'];
            $extra = $i === 0 ? $r['perdidasSinRegistro'] : 0;
            $this->assertSame($etapa['llegaron'], $etapa['siguenAbiertas'] + $etapa['perdidas'] + $siguiente + $extra, $etapa['etapa']);
        }
    }

    public function test_las_filas_inferidas_no_cuentan_para_los_dias_y_las_perdidas_sin_registro_se_separan(): void
    {
        $perdida = $this->oportunidad('2026-10-01', ['2026-10-04' => 'calificado', '2026-10-06' => 'cerrado_perdido']);
        CrmOportunidadEtapa::where('oportunidad_id', $perdida->id)->update(['inferido' => true]);
        CrmOportunidadEtapa::where('oportunidad_id', $perdida->id)->where('etapa_hasta', 'cerrado_perdido')->update(['etapa_desde' => null]);
        $this->ahora('2026-10-20');

        $r = $this->servicio->conversion($this->enterprise->id, null, $this->octubre());
        $e = $this->porEtapa($r);

        $this->assertSame(1, $r['perdidasSinRegistro']);
        $this->assertSame(0, $e['calificado']['perdidas']);
        $this->assertNull($e['prospecto']['diasPromedio']);
        $this->assertNull($e['calificado']['diasPromedio']);
    }

    public function test_mayor_fuga_exige_cinco_y_desempata_por_la_etapa_mas_temprana(): void
    {
        // 20 entran; 10 pasan a calificado (50 %); 5 a propuesta (50 %); las 5 llegan a negociación y se ganan (100 %).
        for ($i = 0; $i < 20; $i++) {
            $pasos = [];
            if ($i < 10) {
                $pasos['2026-10-02'] = 'calificado';
            }
            if ($i < 5) {
                $pasos += ['2026-10-03' => 'propuesta', '2026-10-04' => 'negociacion', '2026-10-05' => 'cerrado_ganado'];
            }
            $this->oportunidad('2026-10-01', $pasos);
        }
        $this->ahora('2026-10-20');

        $r = $this->servicio->conversion($this->enterprise->id, null, $this->octubre());

        $this->assertSame('prospecto', $r['mayorFuga']);
    }

    public function test_conversion_vacia(): void
    {
        $this->ahora('2026-10-20');
        $r = $this->servicio->conversion($this->enterprise->id, null, $this->octubre());

        $this->assertSame(0, $r['total']);
        $this->assertNull($r['etapas'][0]['pasa']);
        $this->assertNull($r['mayorFuga']);
    }

    public function test_conversion_filtra_por_vendedor_e_ignora_otra_empresa_y_borradas(): void
    {
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro']);
        $this->oportunidad('2026-10-01');
        $this->oportunidad('2026-10-01', [], ['vendedor_id' => $otro->id]);
        $this->oportunidad('2026-10-01')->delete();
        $otraEmpresa = $this->crearOtraEmpresa();
        $ajeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno']);
        $this->oportunidad('2026-10-01', [], ['empresa_id' => $otraEmpresa->id, 'vendedor_id' => $ajeno->id]);
        $this->ahora('2026-10-20');

        $this->assertSame(2, $this->servicio->conversion($this->enterprise->id, null, $this->octubre())['total']);
        $this->assertSame(1, $this->servicio->conversion($this->enterprise->id, $this->vendedor->id, $this->octubre())['total']);
    }

    private function detalle(string $tipo, ?string $etapa): array
    {
        return $this->servicio->detalle($this->enterprise->id, null, $this->octubre(), $tipo, $etapa);
    }

    public function test_detalle_abiertas_ignora_el_periodo_y_ordena_por_monto(): void
    {
        $this->oportunidad('2026-08-01', [], ['nombre' => 'Vieja', 'monto_esperado' => 500]);
        $this->oportunidad('2026-10-02', [], ['nombre' => 'Grande', 'monto_esperado' => 9000]);
        $this->oportunidad('2026-10-02', ['2026-10-03' => 'calificado'], ['nombre' => 'Otra etapa']);
        $this->ahora('2026-10-20');

        $r = $this->detalle('abiertas', 'prospecto');

        $this->assertSame(2, $r['total']);
        $this->assertSame(['Grande', 'Vieja'], array_column($r['items'], 'nombre'));
        $this->assertSame(9000.0, $r['items'][0]['monto']);
        $this->assertSame(['id' => $this->vendedor->id, 'nombre' => 'Juan Pérez'], $r['items'][0]['vendedor']);
    }

    public function test_detalle_llegaron_incluye_las_que_pasaron_la_etapa(): void
    {
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'calificado'], ['nombre' => 'Calificada']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'propuesta'], ['nombre' => 'Saltó']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'calificado', '2026-10-03' => 'cerrado_perdido'], ['nombre' => 'Perdida en calificado']);
        $this->oportunidad('2026-10-01', [], ['nombre' => 'Se quedó']);
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'cerrado_ganado'], ['nombre' => 'Ganada']);
        $this->ahora('2026-10-20');

        $nombres = array_column($this->detalle('llegaron', 'calificado')['items'], 'nombre');
        sort($nombres);

        $this->assertSame(['Calificada', 'Ganada', 'Perdida en calificado', 'Saltó'], $nombres);
        $this->assertSame(5, $this->detalle('llegaron', 'prospecto')['total']);
    }

    public function test_detalle_perdidas_por_etapa_y_sin_registro(): void
    {
        $this->oportunidad('2026-10-01', ['2026-10-02' => 'calificado', '2026-10-03' => 'cerrado_perdido'], ['nombre' => 'En calificado', 'motivo_perdida' => 'Precio']);
        $sin = $this->oportunidad('2026-10-01', ['2026-10-02' => 'cerrado_perdido'], ['nombre' => 'Sin registro']);
        CrmOportunidadEtapa::where('oportunidad_id', $sin->id)->where('etapa_hasta', 'cerrado_perdido')->update(['etapa_desde' => null]);
        $this->ahora('2026-10-20');

        $calificado = $this->detalle('perdidas', 'calificado');
        $this->assertSame(['En calificado'], array_column($calificado['items'], 'nombre'));
        $this->assertSame('Precio', $calificado['items'][0]['motivoPerdida']);
        $this->assertSame(['Sin registro'], array_column($this->detalle('perdidas', 'sin_registro')['items'], 'nombre'));
    }

    public function test_detalle_ganadas_de_la_cohorte(): void
    {
        $this->oportunidad('2026-10-01', ['2026-10-05' => 'cerrado_ganado'], ['nombre' => 'De octubre']);
        $this->oportunidad('2026-09-01', ['2026-10-05' => 'cerrado_ganado'], ['nombre' => 'De septiembre']);
        $this->ahora('2026-10-20');

        $r = $this->detalle('ganadas', null);

        $this->assertSame(['De octubre'], array_column($r['items'], 'nombre'));
        $this->assertSame('2026-10-05', substr($r['items'][0]['fechaCierreReal'], 0, 10));
    }

    public function test_detalle_limita_a_50_con_el_total_real(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->oportunidad('2026-10-01', [], ['monto_esperado' => $i]);
        }
        $this->ahora('2026-10-20');

        $r = $this->detalle('abiertas', 'prospecto');

        $this->assertSame(55, $r['total']);
        $this->assertCount(50, $r['items']);
        $this->assertSame(55.0, $r['items'][0]['monto']);
    }
}
