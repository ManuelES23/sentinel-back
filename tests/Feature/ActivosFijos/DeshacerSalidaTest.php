<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use App\Services\ActivosFijos\AsignadorActivos;
use App\Services\ActivosFijos\CierreDepreciacion;
use App\Services\ActivosFijos\RegistradorSalidaActivo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class DeshacerSalidaTest extends TestCase
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

    private function datos(array $overrides = []): array
    {
        return array_merge([
            'type' => 'desecho',
            'disposal_date' => '2026-05-20',
            'reason' => 'Obsoleto',
            'authorized_by_name' => 'Dirección General',
        ], $overrides);
    }

    private function mes(string $mes): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $mes);
    }

    private function assertNoSePuedeDeshacer(FixedAssetDisposal $salida, string $clave): void
    {
        try {
            $this->registrador->deshacer($salida, 'Corrección', $this->usuario);
            $this->fail('Deshacer debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors());
        }
        $this->assertNull($salida->fresh()->reversed_at);
    }

    public function test_deshacer_restaura_el_estado_y_conserva_el_historial(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario);

        $deshecha = $this->registrador->deshacer($salida, '  Se registró por error  ', $this->usuario);

        $this->assertNotNull($deshecha->reversed_at);
        $this->assertSame($this->usuario->id, $deshecha->reversed_by);
        $this->assertSame('Se registró por error', $deshecha->reversal_reason);
        $this->assertFalse($deshecha->vigente);
        $this->assertSame('en_uso', $activo->fresh()->status);
        $this->assertNull($activo->fresh()->salidaVigente);
        $this->assertDatabaseHas('activity_logs', ['action' => 'reverse', 'model' => 'FixedAssetDisposal', 'model_id' => $salida->id]);

        // Se puede volver a dar de baja y el historial conserva las dos filas.
        $otra = $this->registrador->registrar($activo, $this->datos(['type' => 'venta', 'sale_price' => '12000.00', 'buyer_name' => 'Comprador']), $this->usuario);
        $this->assertSame(2, $activo->fresh()->salidas()->count());
        $this->assertSame($otra->id, $activo->fresh()->salidaVigente->id);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario);

        try {
            $this->registrador->deshacer($salida, "  \n ", $this->usuario);
            $this->fail('El motivo vacío debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $this->assertSame('baja', $activo->fresh()->status);
        $this->assertNull($salida->fresh()->reversed_at);
    }

    public function test_una_salida_ya_deshecha_no_se_deshace_dos_veces(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario);
        $this->registrador->deshacer($salida, 'Error', $this->usuario);

        try {
            $this->registrador->deshacer($salida, 'Otra vez', $this->usuario);
            $this->fail('Debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('salida', $e->errors());
        }
    }

    public function test_un_baja_antiguo_vuelve_a_disponible(): void
    {
        $activo = $this->crearActivoDepreciable(['status' => 'baja']);
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario);

        $this->registrador->deshacer($salida, 'Error', $this->usuario);

        $this->assertSame('disponible', $activo->fresh()->status);
    }

    public function test_no_se_vuelve_a_crear_la_asignacion_devuelta(): void
    {
        $activo = $this->crearActivoDepreciable();
        $empleado = $this->crearEmpleado($this->enterprise);
        app(AsignadorActivos::class)->asignar($activo, $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-03-01']), $this->usuario);
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario);

        $this->registrador->deshacer($salida, 'Error', $this->usuario);

        $this->assertNull($activo->fresh()->asignacionActiva);
        $this->assertSame('en_uso', $activo->fresh()->status);
    }

    public function test_un_cierre_posterior_al_mes_de_la_salida_lo_impide_y_el_del_mismo_mes_no(): void
    {
        $activo = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($activo, $this->datos(), $this->usuario); // 20 de mayo
        $cierres = app(CierreDepreciacion::class);

        $mayo = $cierres->cerrar($this->enterprise, $this->mes('2026-05'), $this->usuario);
        $junio = $cierres->cerrar($this->enterprise, $this->mes('2026-06'), $this->usuario);

        $this->assertNoSePuedeDeshacer($salida, 'salida');

        $cierres->reabrir($junio, 'Prueba', $this->usuario);
        $deshecha = $this->registrador->deshacer($salida, 'Corrección', $this->usuario);

        $this->assertNotNull($deshecha->reversed_at);
        $this->assertNotNull($mayo->fresh()); // el cierre del mismo mes se conserva
    }

    public function test_deshacer_un_traspaso_retira_el_activo_recibido_si_no_se_uso(): void
    {
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        $origen = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($origen, $this->datos([
            'type' => 'traspaso',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id,
            'destination_entity_id' => $entidad->id,
        ]), $this->usuario, [$this->corporativo->id]);
        $destinoId = $salida->destination_asset_id;

        $this->registrador->deshacer($salida, 'Error', $this->usuario);

        $this->assertSoftDeleted('fixed_assets', ['id' => $destinoId]);
        $this->assertSame('en_uso', $origen->fresh()->status);
    }

    public function test_deshacer_un_traspaso_se_rechaza_si_el_activo_recibido_ya_se_uso(): void
    {
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        $origen = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($origen, $this->datos([
            'type' => 'traspaso',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id,
            'destination_entity_id' => $entidad->id,
        ]), $this->usuario, [$this->corporativo->id]);

        // El activo recibido ya se dio de baja (tiene su propia salida).
        $destino = FixedAsset::findOrFail($salida->destination_asset_id);
        $destino->update(['status' => 'baja']);
        $this->crearSalidaDirecta($destino, ['disposal_date' => '2026-06-10']);

        $this->assertNoSePuedeDeshacer($salida, 'salida');
        $this->assertNull(FixedAsset::findOrFail($destino->id)->deleted_at);
        $this->assertSame('baja', $origen->fresh()->status);
    }

    public function test_deshacer_un_traspaso_se_rechaza_si_el_activo_recibido_ya_tiene_asignaciones(): void
    {
        [$sucursal, $entidad] = $this->crearUbicacion($this->corporativo, 'GE');
        $origen = $this->crearActivoDepreciable();
        $salida = $this->registrador->registrar($origen, $this->datos([
            'type' => 'traspaso',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $sucursal->id,
            'destination_entity_id' => $entidad->id,
        ]), $this->usuario, [$this->corporativo->id]);

        $empleado = $this->crearEmpleado($this->corporativo);
        app(AsignadorActivos::class)->asignar(
            FixedAsset::findOrFail($salida->destination_asset_id),
            $this->datosAsignacion('employee', $empleado->id, ['assigned_at' => '2026-06-01']),
            $this->usuario,
        );

        $this->assertNoSePuedeDeshacer($salida, 'salida');
    }
}
