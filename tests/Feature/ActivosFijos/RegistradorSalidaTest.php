<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use App\Services\ActivosFijos\AsignadorActivos;
use App\Services\ActivosFijos\CierreDepreciacion;
use App\Services\ActivosFijos\RegistradorSalidaActivo;
use App\Services\ActivosFijos\ResponsablesActivo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class RegistradorSalidaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    private RegistradorSalidaActivo $registrador;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        $this->registrador = app(RegistradorSalidaActivo::class);
        $this->usuario = User::factory()->create(['name' => 'Marta Gómez', 'role' => 'user']);
    }

    /** Datos de una salida el 20 de mayo de 2026 (libro de un activo depreciable: 11,100.00). */
    private function datos(string $tipo, array $overrides = []): array
    {
        $propios = $tipo === 'venta' ? [
            'sale_price' => '15000.00',
            'buyer_name' => 'Comercial Rivera S.A.',
            'buyer_address' => 'Calle 5 #123, Culiacán',
            'buyer_identification' => 'INE 1234',
            'payment_method' => 'Transferencia',
            'place' => 'Culiacán, Sin.',
        ] : [];

        return array_merge([
            'type' => $tipo,
            'disposal_date' => '2026-05-20',
            'reason' => $tipo === 'venta' ? null : 'Motivo de la salida',
            'authorized_by_name' => 'Dirección General',
        ], $propios, $overrides);
    }

    private function assertRechazada(FixedAsset $activo, array $datos, string $clave): void
    {
        try {
            $this->registrador->registrar($activo, $datos, $this->usuario);
            $this->fail('La salida debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors());
        }

        $this->assertSame(0, FixedAssetDisposal::count());
        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_la_venta_congela_las_cifras_y_da_de_baja_el_activo(): void
    {
        $activo = $this->crearActivoDepreciable();

        $salida = $this->registrador->registrar($activo, $this->datos('venta'), $this->usuario);

        $this->assertSame('venta', $salida->type);
        $this->assertSame('2026-05-20', $salida->disposal_date->toDateString());
        $this->assertSame('12000.00', $salida->purchase_value);
        $this->assertSame('900.00', $salida->accumulated_depreciation);
        $this->assertSame('11100.00', $salida->book_value);
        $this->assertSame('15000.00', $salida->sale_price);
        $this->assertSame('3900.00', $salida->result_amount);
        $this->assertSame('en_uso', $salida->previous_status);
        $this->assertSame('Comercial Rivera S.A.', $salida->buyer_name);
        $this->assertSame('Culiacán, Sin.', $salida->place);
        $this->assertSame('Marta Gómez', $salida->created_by_name);
        $this->assertSame($this->usuario->id, $salida->created_by);
        $this->assertSame($activo->code, $salida->asset_snapshot['code']);
        $this->assertSame('Equipos de cómputo', $salida->asset_snapshot['category']);
        $this->assertSame('baja', $activo->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'dispose', 'model' => 'FixedAssetDisposal', 'model_id' => $salida->id]);
    }

    public function test_desecho_siniestro_y_donacion_son_perdida_igual_al_libro(): void
    {
        foreach (['desecho', 'siniestro', 'donacion'] as $tipo) {
            $activo = $this->crearActivoDepreciable();

            $salida = $this->registrador->registrar($activo, $this->datos($tipo, [
                'claim_reference' => 'FOLIO-77',
                'buyer_name' => 'No debe guardarse',
                'sale_price' => '999.00',
            ]), $this->usuario);

            $this->assertSame('-11100.00', $salida->result_amount, $tipo);
            $this->assertNull($salida->sale_price, $tipo);
            $this->assertNull($salida->buyer_name, $tipo);
            $this->assertSame($tipo === 'siniestro' ? 'FOLIO-77' : null, $salida->claim_reference, $tipo);
            $this->assertSame('baja', $activo->fresh()->status, $tipo);
        }
    }

    public function test_la_venta_exige_precio(): void
    {
        $activo = $this->crearActivoDepreciable();
        $datos = $this->datos('venta');
        unset($datos['sale_price']);

        $this->assertRechazada($activo, $datos, 'sale_price');
    }

    public function test_un_tipo_desconocido_se_rechaza(): void
    {
        $activo = $this->crearActivoDepreciable();

        $this->assertRechazada($activo, $this->datos('desecho', ['type' => 'regalo']), 'type');
    }

    public function test_la_fecha_no_puede_ser_futura_ni_anterior_a_la_compra(): void
    {
        $activo = $this->crearActivoDepreciable(); // comprado el 15 de enero de 2026

        $this->assertRechazada($activo, $this->datos('desecho', ['disposal_date' => '2026-06-16']), 'disposal_date');
        $this->assertRechazada($activo, $this->datos('desecho', ['disposal_date' => '2026-01-10']), 'disposal_date');
    }

    public function test_la_fecha_de_hoy_es_valida(): void
    {
        $activo = $this->crearActivoDepreciable();

        $salida = $this->registrador->registrar($activo, $this->datos('desecho', ['disposal_date' => '2026-06-15']), $this->usuario);

        $this->assertSame('2026-06-15', $salida->disposal_date->toDateString());
    }

    public function test_la_fecha_en_un_mes_ya_cerrado_se_rechaza(): void
    {
        $activo = $this->crearActivoDepreciable();
        app(CierreDepreciacion::class)->cerrar($this->enterprise, CarbonImmutable::createFromFormat('!Y-m', '2026-04'), $this->usuario);

        $this->assertRechazada($activo, $this->datos('desecho', ['disposal_date' => '2026-04-20']), 'disposal_date');
        $this->assertRechazada($activo, $this->datos('desecho', ['disposal_date' => '2026-03-02']), 'disposal_date');

        $salida = $this->registrador->registrar($activo, $this->datos('desecho', ['disposal_date' => '2026-05-02']), $this->usuario);
        $this->assertSame('2026-05-02', $salida->disposal_date->toDateString());
    }

    public function test_solo_puede_haber_una_salida_vigente_por_activo(): void
    {
        $activo = $this->crearActivoDepreciable();
        $primera = $this->registrador->registrar($activo, $this->datos('desecho'), $this->usuario);

        try {
            $this->registrador->registrar($activo, $this->datos('venta'), $this->usuario);
            $this->fail('La segunda salida debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset', $e->errors());
        }

        $this->assertSame(1, FixedAssetDisposal::count());
        $this->assertSame('baja', $activo->fresh()->status);
        $this->assertSame('en_uso', $primera->fresh()->previous_status);
        $this->assertSame(1, ActivityLog::where('action', 'dispose')->count());
    }

    public function test_la_asignacion_activa_se_devuelve_en_la_misma_operacion(): void
    {
        $activo = $this->crearActivoDepreciable();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = app(AsignadorActivos::class)->asignar(
            $activo,
            $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-03-01', 'condition_out' => 'regular']),
            $this->usuario,
        );

        $salida = $this->registrador->registrar($activo, $this->datos('desecho'), $this->usuario);

        $asignacion->refresh();
        $this->assertSame('2026-05-20', $asignacion->returned_at->toDateString());
        $this->assertSame('otro', $asignacion->return_reason);
        $this->assertSame('regular', $asignacion->condition_in);
        $this->assertStringContainsString('baja del activo', $asignacion->return_notes);
        $this->assertSame('en_uso', $salida->previous_status);
        $this->assertSame('baja', $activo->fresh()->status);
    }

    public function test_la_fecha_no_puede_ser_anterior_a_la_entrega_vigente(): void
    {
        $activo = $this->crearActivoDepreciable();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = app(AsignadorActivos::class)->asignar(
            $activo,
            $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-05-25']),
            $this->usuario,
        );

        $this->assertRechazada($activo, $this->datos('desecho', ['disposal_date' => '2026-05-20']), 'disposal_date');
        $this->assertNull($asignacion->fresh()->returned_at);
    }

    public function test_un_baja_antiguo_sin_salida_guarda_disponible_como_estado_previo(): void
    {
        $activo = $this->crearActivoDepreciable(['status' => 'baja']);

        $salida = $this->registrador->registrar($activo, $this->datos('desecho'), $this->usuario);

        $this->assertSame('disponible', $salida->previous_status);
        $this->assertSame('baja', $activo->fresh()->status);
    }

    public function test_la_vista_previa_calcula_sin_escribir(): void
    {
        $activo = $this->crearActivoDepreciable();
        $fecha = CarbonImmutable::parse('2026-05-20');

        $sinPrecio = $this->registrador->previa($activo, 'venta', $fecha, null);
        $conPrecio = $this->registrador->previa($activo, 'venta', $fecha, '15000.00');
        $desecho = $this->registrador->previa($activo, 'desecho', $fecha);

        $this->assertSame('11100.00', $sinPrecio['book_value']);
        $this->assertNull($sinPrecio['result_amount']);
        $this->assertSame('3900.00', $conPrecio['result_amount']);
        $this->assertSame('-11100.00', $desecho['result_amount']);
        $this->assertFalse($desecho['has_active_assignment']);
        $this->assertSame(0, FixedAssetDisposal::count());
        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_la_vista_previa_respeta_las_mismas_reglas_de_fecha(): void
    {
        $activo = $this->crearActivoDepreciable();

        $this->expectException(ValidationException::class);
        $this->registrador->previa($activo, 'desecho', CarbonImmutable::parse('2026-06-16'));
    }

    public function test_la_vista_previa_avisa_si_la_fecha_es_anterior_a_la_entrega_vigente(): void
    {
        $activo = $this->crearActivoDepreciable();
        $empleado = $this->crearEmpleado($this->enterprise);
        app(AsignadorActivos::class)->asignar(
            $activo,
            $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-05-25']),
            $this->usuario,
        );

        try {
            $this->registrador->previa($activo, 'desecho', CarbonImmutable::parse('2026-05-20'));
            $this->fail('La vista previa debió rechazar la fecha.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('disposal_date', $e->errors());
        }
    }

    public function test_si_falla_la_devolucion_de_la_asignacion_no_queda_salida_ni_cambios(): void
    {
        $activo = $this->crearActivoDepreciable();
        $empleado = $this->crearEmpleado($this->enterprise);
        $asignacion = app(AsignadorActivos::class)->asignar(
            $activo,
            $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-03-01']),
            $this->usuario,
        );

        // La salida ya insertó su fila cuando se devuelve la asignación: aquí se prueba el rollback.
        $this->app->instance(AsignadorActivos::class, new class(app(ResponsablesActivo::class)) extends AsignadorActivos {
            public function devolver(FixedAssetAssignment $asignacion, array $datos, User $por): FixedAssetAssignment
            {
                throw ValidationException::withMessages(['returned_at' => 'Falla simulada de la devolución.']);
            }
        });
        $registrador = app(RegistradorSalidaActivo::class);

        try {
            $registrador->registrar($activo, $this->datos('desecho'), $this->usuario);
            $this->fail('La salida debió fallar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('returned_at', $e->errors());
        }

        $this->assertSame(0, FixedAssetDisposal::count());
        $this->assertSame('en_uso', $activo->fresh()->status);
        $this->assertNull($asignacion->fresh()->returned_at);
        $this->assertSame(0, ActivityLog::where('action', 'dispose')->count());
    }
}
