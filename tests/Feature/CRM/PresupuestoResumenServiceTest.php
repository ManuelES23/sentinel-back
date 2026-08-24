<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmPresupuesto;
use App\Services\CRM\PresupuestoResumenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class PresupuestoResumenServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected PresupuestoResumenService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        $this->servicio = new PresupuestoResumenService();
    }

    public function test_resumen_mensual_suma_monto_esperado_de_oportunidades_ganadas_ese_mes(): void
    {
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada en agosto', 'etapa' => 'cerrado_ganado',
            'monto_esperado' => 1000, 'fecha_cierre_real' => '2026-08-15',
        ]);
        CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Ganada en septiembre', 'etapa' => 'cerrado_ganado',
            'monto_esperado' => 500, 'fecha_cierre_real' => '2026-09-01',
        ]);

        $resumen = $this->servicio->resumenMensual($this->enterprise->id, $this->vendedor->id, 8, 2026);

        $this->assertEquals(1000.0, $resumen['montoEsperado']);
    }

    public function test_resumen_mensual_suma_total_de_cotizaciones_aprobadas_ese_mes(): void
    {
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad', 'monto_esperado' => 0,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'folio' => 'COT-00001', 'estado' => 'aprobado', 'fecha_emision' => '2026-08-10',
            'subtotal' => 800, 'total' => 800,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'folio' => 'COT-00002', 'estado' => 'enviado', 'fecha_emision' => '2026-08-11',
            'subtotal' => 300, 'total' => 300,
        ]);

        $resumen = $this->servicio->resumenMensual($this->enterprise->id, $this->vendedor->id, 8, 2026);

        // Solo la aprobada cuenta -- la 'enviado' no.
        $this->assertEquals(800.0, $resumen['montoCotizado']);
    }

    public function test_resumen_mensual_cuenta_clientes_y_actividades_reales_del_mes(): void
    {
        $cliente = new CrmCliente([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cliente de agosto', 'estatus' => 'activo',
        ]);
        $cliente->forceFill(['created_at' => '2026-08-05'])->save();

        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada', 'entidad_type' => 'CrmCliente', 'entidad_id' => 1,
            'descripcion' => 'Llamada de seguimiento', 'fecha_actividad' => '2026-08-20', 'fuente' => 'manual',
        ]);

        $resumen = $this->servicio->resumenMensual($this->enterprise->id, $this->vendedor->id, 8, 2026);

        $this->assertEquals(1, $resumen['clientesReales']);
        $this->assertEquals(1, $resumen['actividadesReales']);
    }

    public function test_resumen_mensual_no_mezcla_datos_de_otro_mes(): void
    {
        $cliente = new CrmCliente([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Cliente de julio', 'estatus' => 'activo',
        ]);
        $cliente->forceFill(['created_at' => '2026-07-31'])->save();

        $resumen = $this->servicio->resumenMensual($this->enterprise->id, $this->vendedor->id, 8, 2026);

        $this->assertEquals(0, $resumen['clientesReales']);
    }

    public function test_resumen_mensual_incluye_cotizaciones_del_primer_dia_del_mes(): void
    {
        // Regression test for SQLite date-string comparison bug where day-1
        // cotizations with fecha_emision='2026-08-01' (date-cast column) were
        // excluded due to lexicographic string comparison with '2026-08-01 00:00:00'.
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Oportunidad', 'monto_esperado' => 0,
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $oportunidad->id,
            'folio' => 'COT-00001', 'estado' => 'aprobado', 'fecha_emision' => '2026-08-01',
            'subtotal' => 500, 'total' => 500,
        ]);

        $resumen = $this->servicio->resumenMensual($this->enterprise->id, $this->vendedor->id, 8, 2026);

        $this->assertEquals(500.0, $resumen['montoCotizado']);
    }

    public function test_comparativo_anual_devuelve_12_meses_con_ceros_donde_no_hay_presupuesto(): void
    {
        CrmPresupuesto::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'mes' => 3, 'anio' => 2026, 'meta_monto' => 5000, 'meta_clientes' => 2, 'meta_actividades' => 10,
        ]);

        $comparativo = $this->servicio->comparativoAnual($this->enterprise->id, $this->vendedor->id, 2026);

        $this->assertCount(12, $comparativo);
        $marzo = collect($comparativo)->firstWhere('mes', 3);
        $this->assertEquals(5000.0, $marzo['metaMonto']);
        $enero = collect($comparativo)->firstWhere('mes', 1);
        $this->assertEquals(0.0, $enero['metaMonto']);
        $this->assertEquals(0, $enero['clientesReales']);
    }
}
