<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Productor;
use App\Models\User;
use App\Services\Abastecimiento\FlujoLiquidacion;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Orden de bloqueo de las escrituras de dinero (arreglo final C1): dentro de la transacción, la fila del productor
 * se lee (con `lockForUpdate` en MySQL) ANTES que liquidaciones, pasos, abonos y aplicaciones. SQLite compila
 * `lockForUpdate` a nada, así que se comprueba el orden de las tablas en el SQL ejecutado, no el bloqueo real.
 */
class OrdenBloqueoDineroTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    /** @var array<int, string> SQL ejecutado; `#BEGIN` marca el inicio de cada transacción */
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function grabar(): void
    {
        $this->sql = [];
        DB::listen(function ($q) {
            $this->sql[] = $q->sql;
        });
        Event::listen(TransactionBeginning::class, function () {
            $this->sql[] = '#BEGIN';
        });
    }

    /**
     * Desde la primera transacción de la petición: el primer `select *` de `abs_productores` va antes de cualquier
     * consulta de las tablas de dinero (la única excepción es leer el `productor_id` de la liquidación o del abono).
     */
    private function assertProductorPrimero(): void
    {
        $inicio = array_search('#BEGIN', $this->sql, true);
        $this->assertNotFalse($inicio, 'La escritura debe abrir una transacción.');
        $tx = array_slice($this->sql, $inicio + 1);

        $productor = null;
        $dinero = null;
        foreach ($tx as $i => $s) {
            if ($productor === null && str_starts_with($s, 'select * from "abs_productores"')) {
                $productor = $i;
            }
            $soloProductorId = str_starts_with($s, 'select "productor_id" from');
            if ($dinero === null && ! $soloProductorId
                && preg_match('/"(abs_liquidaciones|abs_liquidacion_aprobaciones|abs_abonos|abs_abono_aplicaciones)"/', $s)) {
                $dinero = $i;
            }
        }

        $this->assertNotNull($productor, 'La transacción debe leer (bloquear) la fila del productor.');
        $this->assertNotNull($dinero);
        $this->assertLessThan($dinero, $productor, "El productor debe bloquearse antes que el dinero. Primera de dinero: {$tx[$dinero]}");
    }

    private function usuario(string $submodulo, array $permisos): User
    {
        $u = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($u, $this->sbp, $submodulo, $permisos);

        return $u;
    }

    public function test_alta_de_abono_bloquea_al_productor_antes_de_leer_el_saldo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['status' => 'aprobada', 'monto_calculado' => 1000, 'monto_final' => 1000, 'aprobada_at' => now()]);
        Sanctum::actingAs($this->usuario('abonos', ['view', 'create']));

        $this->grabar();
        $this->postJson('/api/splendidbyporvenir/administration/abastecimiento/abonos', [
            'productor_id' => $convenio->productor_id, 'fecha' => '2026-06-25', 'monto' => 600, 'moneda' => 'MXN',
            'metodo_pago' => 'transferencia', 'aplicaciones' => [['liquidacion_id' => $liq->id, 'monto' => 600]],
        ])->assertCreated();

        $this->assertProductorPrimero();
    }

    public function test_cancelar_abono_bloquea_al_productor_antes_que_al_abono(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $abono = $this->crearAbono(Productor::findOrFail($convenio->productor_id));
        Sanctum::actingAs($this->usuario('abonos', ['view', 'cancelar']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/abonos/{$abono->id}/cancelar", ['motivo' => 'Duplicado'])
            ->assertOk();

        $this->assertProductorPrimero();
    }

    public function test_aprobar_bloquea_al_productor_antes_de_leer_el_paso_y_los_anticipos(): void
    {
        $creador = $this->usuario('liquidaciones', ['view', 'create', 'edit']);
        $aprobador = $this->usuario('liquidaciones', ['view', 'aprobar']);
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearAbono(Productor::findOrFail($convenio->productor_id), ['monto' => 300]);
        $liq = $this->enRevision($convenio, $creador);

        Sanctum::actingAs($aprobador);
        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/{$liq->id}/aprobar", ['paso' => 1])
            ->assertOk()->assertJsonPath('data.status', 'aprobada');

        $this->assertProductorPrimero();
    }

    public function test_cancelar_liquidacion_bloquea_al_productor_antes_de_revisar_las_aplicaciones(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['status' => 'aprobada', 'monto_calculado' => 1000, 'monto_final' => 1000, 'aprobada_at' => now()]);
        Sanctum::actingAs($this->usuario('liquidaciones', ['view', 'delete']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/{$liq->id}/cancelar", ['motivo' => 'Error'])
            ->assertOk();

        $this->assertProductorPrimero();
    }

    /** Borrador con una recepción de 10 cajas enviado a revisión (sin cadena: decide quien tenga `aprobar`). */
    private function enRevision(Convenio $convenio, User $creador): Liquidacion
    {
        $liq = $this->crearLiquidacion($convenio, ['created_by' => $creador->id]);
        $r = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02', 'liquidacion_id' => $liq->id]);
        LiquidacionDetalle::create([
            'liquidacion_id' => $liq->id, 'recepcion_id' => $r->id, 'cantidad' => 10, 'kilos' => 0, 'rezaga_kg' => 0,
            'precio' => 100, 'porcentaje_productor' => 100, 'subtotal' => 1000, 'descuento' => 0, 'a_pagar' => 1000,
        ]);
        $liq->update(['cantidad' => 10, 'bruto' => 1000, 'monto_calculado' => 1000, 'monto_final' => 1000]);

        return DB::transaction(fn () => FlujoLiquidacion::enviar($liq->fresh(), $creador));
    }
}
