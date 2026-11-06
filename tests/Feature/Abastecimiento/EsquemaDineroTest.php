<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class EsquemaDineroTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    public function test_crea_las_cinco_tablas_con_sus_columnas(): void
    {
        $esperado = [
            'abs_liquidaciones' => [
                'id', 'enterprise_id', 'folio', 'convenio_id', 'productor_id', 'moneda', 'periodo_inicio', 'periodo_fin',
                'cantidad', 'kilos', 'bruto', 'rezaga_real_pct', 'rezaga_aceptada_pct', 'descuento', 'monto_calculado',
                'monto_ajustado', 'motivo_ajuste', 'monto_final', 'status', 'origen', 'paso_actual', 'ronda',
                'enviada_at', 'enviada_by', 'aprobada_at', 'aprobada_by', 'rechazo_motivo', 'rechazada_at', 'rechazada_by',
                'cancelada_at', 'cancelada_by', 'cancelada_motivo', 'notas', 'created_by',
                'created_at', 'updated_at', 'deleted_at',
            ],
            'abs_liquidacion_detalles' => [
                'id', 'liquidacion_id', 'recepcion_id', 'cantidad', 'kilos', 'rezaga_kg', 'precio',
                'porcentaje_productor', 'subtotal', 'descuento', 'a_pagar', 'created_at', 'updated_at',
            ],
            'abs_liquidacion_aprobaciones' => [
                'id', 'liquidacion_id', 'ronda', 'step_order', 'description', 'rules', 'decision', 'decided_by',
                'comment', 'decided_at', 'created_at', 'updated_at',
            ],
            'abs_abonos' => [
                'id', 'enterprise_id', 'folio', 'productor_id', 'convenio_id', 'fecha', 'monto', 'moneda',
                'metodo_pago', 'referencia', 'notas', 'status', 'cancelado_motivo', 'cancelado_by', 'cancelado_at',
                'aplicaciones_canceladas', 'created_by', 'created_at', 'updated_at', 'deleted_at',
            ],
            'abs_abono_aplicaciones' => ['id', 'abono_id', 'liquidacion_id', 'monto', 'created_at', 'updated_at'],
        ];

        foreach ($esperado as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}");
            $this->assertTrue(Schema::hasColumns($tabla, $columnas), "Faltan columnas en {$tabla}");
        }
    }

    public function test_valores_por_omision_de_la_liquidacion(): void
    {
        $liq = $this->crearLiquidacion($this->crearConvenio($this->sbp));

        $fresca = Liquidacion::findOrFail($liq->id);
        $this->assertSame('borrador', $fresca->status);
        $this->assertSame('normal', $fresca->origen);
        $this->assertSame(0, $fresca->ronda);
        $this->assertNull($fresca->paso_actual);
        $this->assertNull($fresca->monto_ajustado);
    }

    public function test_folio_de_liquidacion_es_unico_por_empresa(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio);

        try {
            $this->crearLiquidacion($convenio, ['folio' => $liq->folio]);
            $this->fail('Debió rechazar el folio duplicado de la liquidación.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('abs_liquidaciones', $e->getMessage());
        }

        // El mismo folio en otra empresa sí se permite.
        $otroConvenio = $this->crearConvenio($this->otra);
        $otra = $this->crearLiquidacion($otroConvenio, ['folio' => $liq->folio]);
        $this->assertSame($liq->folio, $otra->folio);
    }

    public function test_folio_de_abono_es_unico_por_empresa(): void
    {
        $productor = Productor::firstOrCreate(
            ['enterprise_id' => $this->sbp->id, 'nombre' => 'Productor de prueba'],
            ['tipo' => 'productor', 'is_active' => true],
        );
        $abono = $this->crearAbono($productor);

        $this->expectException(QueryException::class);
        $this->crearAbono($productor, ['folio' => $abono->folio]);
    }

    public function test_recepcion_devuelve_su_liquidacion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio);
        $recepcion = $this->crearRecepcion($convenio, ['liquidacion_id' => $liq->id]);
        $libre = $this->crearRecepcion($convenio);

        $this->assertSame($liq->id, Recepcion::findOrFail($recepcion->id)->liquidacion->id);
        $this->assertNull(Recepcion::findOrFail($libre->id)->liquidacion);
        $this->assertSame([$recepcion->id], $liq->recepciones()->pluck('abs_recepciones.id')->all());
        $this->assertSame([$liq->id], $convenio->liquidaciones()->pluck('id')->all());
    }

    public function test_relaciones_y_casts_de_liquidacion_y_abono(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $recepcion = $this->crearRecepcion($convenio);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1000, 'bruto' => 1000]);

        LiquidacionDetalle::create([
            'liquidacion_id' => $liq->id, 'recepcion_id' => $recepcion->id, 'cantidad' => 10, 'kilos' => 120,
            'rezaga_kg' => 5, 'precio' => 100.5, 'porcentaje_productor' => 80, 'subtotal' => 1005,
            'descuento' => 0, 'a_pagar' => 1005,
        ]);
        LiquidacionAprobacion::create([
            'liquidacion_id' => $liq->id, 'ronda' => 1, 'step_order' => 1, 'description' => 'Gerencia',
            'rules' => [['type' => 'user', 'user_id' => 7]], 'decision' => 'waiting',
        ]);

        $liq = Liquidacion::with(['detalles', 'aprobaciones', 'convenio', 'productor'])->findOrFail($liq->id);
        $this->assertCount(1, $liq->detalles);
        $this->assertSame($recepcion->id, $liq->detalles->first()->recepcion->id);
        $this->assertSame('100.5000', $liq->detalles->first()->precio);
        $this->assertCount(1, $liq->aprobaciones);
        $this->assertSame([['type' => 'user', 'user_id' => 7]], $liq->aprobaciones->first()->rules);
        $this->assertSame($convenio->id, $liq->convenio->id);
        $this->assertSame($convenio->productor_id, $liq->productor->id);
        $this->assertSame('2026-06-01', $liq->periodo_inicio->format('Y-m-d'));
        $this->assertSame('1000.00', $liq->monto_final);

        $abono = $this->crearAbono($liq->productor, ['monto' => 500]);
        AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => 300]);

        $abono = Abono::with(['aplicaciones', 'productor'])->findOrFail($abono->id);
        $this->assertCount(1, $abono->aplicaciones);
        $this->assertSame($liq->id, $abono->aplicaciones->first()->liquidacion->id);
        $this->assertSame($liq->productor_id, $abono->productor->id);
        $this->assertSame('2026-06-10', $abono->fecha->format('Y-m-d'));
        $this->assertSame('500.00', $abono->monto);
        $this->assertSame('aplicado', $abono->status);

        $abono->update(['aplicaciones_canceladas' => [['liquidacion_id' => $liq->id, 'monto' => '300.00']]]);
        $this->assertIsArray($abono->fresh()->aplicaciones_canceladas);
    }

    public function test_scope_de_empresa_separa_liquidaciones_y_abonos(): void
    {
        $a = $this->crearLiquidacion($this->crearConvenio($this->sbp));
        $this->crearLiquidacion($this->crearConvenio($this->otra));

        $this->assertSame([$a->id], Liquidacion::deEmpresa($this->sbp->id)->pluck('id')->all());

        $productor = Productor::where('enterprise_id', $this->sbp->id)->firstOrFail();
        $abono = $this->crearAbono($productor);
        $otroProductor = Productor::where('enterprise_id', $this->otra->id)->firstOrFail();
        $this->crearAbono($otroProductor);

        $this->assertSame([$abono->id], Abono::deEmpresa($this->sbp->id)->pluck('id')->all());
    }

    public function test_la_migracion_corre_dos_veces_sin_error(): void
    {
        $migracion = require database_path('migrations/2026_11_06_100000_create_abastecimiento_dinero_schema.php');

        $migracion->up();
        $migracion->up();

        foreach (['abs_liquidaciones', 'abs_liquidacion_detalles', 'abs_liquidacion_aprobaciones', 'abs_abonos', 'abs_abono_aplicaciones'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla));
        }
    }
}
