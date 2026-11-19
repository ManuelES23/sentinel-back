<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Productor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class EsquemaCuentaConvenioTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private const MIGRACION = 'database/migrations/2026_11_19_100000_create_abastecimiento_cuenta_convenio.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function migracion()
    {
        return require base_path(self::MIGRACION);
    }

    public function test_crea_las_columnas_y_la_tabla_nuevas(): void
    {
        $this->assertTrue(Schema::hasColumns('abs_abonos', ['tipo', 'liquidacion_id']));
        $this->assertTrue(Schema::hasColumns('abs_liquidaciones', ['saldo_inicial', 'liquidada_at', 'liquidada_by']));
        $this->assertTrue(Schema::hasTable('abs_abono_recepciones'));
        $this->assertTrue(Schema::hasColumns('abs_abono_recepciones', [
            'id', 'abono_id', 'recepcion_id', 'monto', 'created_at', 'updated_at',
        ]));

        // Los periodos ya admiten nulo y el estado nuevo `liquidada` es válido.
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['periodo_inicio' => null, 'periodo_fin' => null, 'status' => 'liquidada']);
        $this->assertSame('liquidada', $liq->fresh()->status);
        $this->assertNull($liq->fresh()->periodo_inicio);
        $this->assertSame('0.00', $liq->fresh()->saldo_inicial);

        // El tipo del abono por omisión es `abono`.
        $productor = Productor::findOrFail($convenio->productor_id);
        $this->assertSame('abono', $this->crearAbono($productor)->fresh()->tipo);
    }

    public function test_un_pago_se_reparte_entre_recepciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r1 = $this->crearRecepcion($convenio);
        $r2 = $this->crearRecepcion($convenio);
        $productor = Productor::findOrFail($convenio->productor_id);
        $abono = $this->crearAbono($productor, ['tipo' => 'recepcion', 'convenio_id' => $convenio->id, 'monto' => 300]);

        AbonoRecepcion::create(['abono_id' => $abono->id, 'recepcion_id' => $r1->id, 'monto' => 200]);
        AbonoRecepcion::create(['abono_id' => $abono->id, 'recepcion_id' => $r2->id, 'monto' => 100]);

        $this->assertCount(2, $abono->recepciones);
        $this->assertCount(1, $r1->pagos);
        $this->assertSame('200.00', $r1->pagos->first()->monto);
        $this->assertSame($abono->id, $r1->pagos->first()->abono->id);
        $this->assertSame($r1->id, $abono->recepciones->first()->recepcion->id);
        $this->assertSame([$abono->id], $convenio->pagos()->pluck('id')->all());
        $this->assertNull($abono->liquidacion);
    }

    public function test_la_misma_recepcion_no_se_repite_en_un_pago(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r1 = $this->crearRecepcion($convenio);
        $productor = Productor::findOrFail($convenio->productor_id);
        $abono = $this->crearAbono($productor, ['tipo' => 'recepcion', 'convenio_id' => $convenio->id]);

        AbonoRecepcion::create(['abono_id' => $abono->id, 'recepcion_id' => $r1->id, 'monto' => 50]);

        $this->expectException(QueryException::class);
        AbonoRecepcion::create(['abono_id' => $abono->id, 'recepcion_id' => $r1->id, 'monto' => 50]);
    }

    public function test_la_migracion_se_detiene_con_datos_del_flujo_viejo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearLiquidacion($convenio, ['status' => 'aprobada']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hay liquidaciones o abonos del flujo anterior (borrador, en revisión, aprobada, pagada o con aplicaciones): límpialos antes de migrar.');
        $this->migracion()->up();
    }

    public function test_la_migracion_corre_dos_veces_sin_error(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertTrue(Schema::hasTable('abs_abono_recepciones'));
        $this->assertTrue(Schema::hasColumns('abs_abonos', ['tipo', 'liquidacion_id']));
    }

    public function test_down_se_detiene_con_datos_y_revierte_sin_ellos(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = Productor::findOrFail($convenio->productor_id);
        $abono = $this->crearAbono($productor, ['tipo' => 'adelanto', 'convenio_id' => $convenio->id]);

        try {
            $this->migracion()->down();
            $this->fail('Debió negarse a revertir con datos de la cuenta por convenio.');
        } catch (RuntimeException $e) {
            $this->assertSame('No se puede revertir: ya hay cuentas por convenio con datos.', $e->getMessage());
        }

        $abono->forceDelete();
        $this->migracion()->down();

        $this->assertFalse(Schema::hasTable('abs_abono_recepciones'));
        $this->assertFalse(Schema::hasColumn('abs_abonos', 'tipo'));
        $this->assertFalse(Schema::hasColumn('abs_abonos', 'liquidacion_id'));
        $this->assertFalse(Schema::hasColumn('abs_liquidaciones', 'saldo_inicial'));
        $this->assertFalse(Schema::hasColumn('abs_liquidaciones', 'liquidada_at'));

        // Y vuelve a subir limpio.
        $this->migracion()->up();
        $this->assertTrue(Schema::hasTable('abs_abono_recepciones'));
    }

    /** Deja el esquema como antes de la migración (sin datos), para probar `up()` desde cero. */
    private function revertirLimpio(): void
    {
        $this->migracion()->down();
        $this->assertFalse(Schema::hasColumn('abs_abonos', 'tipo'));
    }

    public function test_up_se_detiene_con_un_abono_del_flujo_viejo_sin_aplicaciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->revertirLimpio();

        // Un anticipo del plan 2 sin aplicaciones: sigue «aplicado» y, con `tipo` por omisión `abono`, contaría en `pagado`.
        DB::table('abs_abonos')->insert([
            'enterprise_id' => $this->sbp->id, 'folio' => 'AB-0001', 'productor_id' => $convenio->productor_id,
            'convenio_id' => $convenio->id, 'fecha' => '2026-06-10', 'monto' => 500, 'moneda' => 'MXN',
            'status' => 'aplicado', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->migracion()->up();
            $this->fail('Debió negarse a migrar con abonos del flujo anterior.');
        } catch (RuntimeException $e) {
            $this->assertSame('Hay abonos del flujo anterior: límpialos antes de migrar.', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('abs_abonos', 'tipo'));

        // Un abono ya borrado no estorba.
        DB::table('abs_abonos')->update(['deleted_at' => now()]);
        $this->migracion()->up();
        $this->assertTrue(Schema::hasColumn('abs_abonos', 'tipo'));
    }

    public function test_up_con_las_columnas_ya_puestas_no_revisa_los_abonos(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearPago($convenio, ['tipo' => 'recepcion']);

        // Ya migrada: volver a correr `up()` con abonos nuevos es inocuo.
        $this->migracion()->up();
        $this->assertSame(1, DB::table('abs_abonos')->count());
    }

    public function test_down_se_detiene_con_cualquier_abono_vivo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $abono = $this->crearAbono(Productor::findOrFail($convenio->productor_id), ['tipo' => 'abono', 'convenio_id' => $convenio->id]);

        try {
            $this->migracion()->down();
            $this->fail('Debió negarse a revertir con un abono.');
        } catch (RuntimeException $e) {
            $this->assertSame('No se puede revertir: ya hay cuentas por convenio con datos.', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('abs_abonos', 'tipo'));

        // Borrado (softDelete) ya no estorba.
        $abono->delete();
        $this->migracion()->down();
        $this->assertFalse(Schema::hasColumn('abs_abonos', 'tipo'));
    }

    public function test_down_se_detiene_con_una_liquidacion_cancelada_de_periodos_nulos(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['status' => 'cancelada', 'periodo_inicio' => null, 'periodo_fin' => null]);

        try {
            $this->migracion()->down();
            $this->fail('Debió negarse a revertir con una liquidación sin periodos.');
        } catch (RuntimeException $e) {
            $this->assertSame('No se puede revertir: ya hay cuentas por convenio con datos.', $e->getMessage());
        }
        // Sin media reversión: el esquema sigue completo.
        $this->assertTrue(Schema::hasTable('abs_abono_recepciones'));
        $this->assertTrue(Schema::hasColumns('abs_abonos', ['tipo', 'liquidacion_id']));
        $this->assertTrue(Schema::hasColumns('abs_liquidaciones', ['saldo_inicial', 'liquidada_at']));

        $liq->forceDelete();
        $this->migracion()->down();
        $this->assertFalse(Schema::hasColumn('abs_liquidaciones', 'liquidada_at'));
    }

    public function test_sube_baja_y_sube_limpio(): void
    {
        $this->migracion()->down();
        $this->migracion()->up();
        $this->migracion()->down();
        $this->migracion()->up();

        $this->assertTrue(Schema::hasTable('abs_abono_recepciones'));
        $this->assertTrue(Schema::hasColumns('abs_abonos', ['tipo', 'liquidacion_id']));
        $this->assertTrue(Schema::hasColumns('abs_liquidaciones', ['saldo_inicial', 'liquidada_at', 'liquidada_by']));
    }
}
