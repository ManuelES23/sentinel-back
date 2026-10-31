<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Spec 2026-10-30 §5.4: las fichas de cliente y de prospecto muestran también
 * las actividades de sus oportunidades (historial y resumen del cliente). La
 * visibilidad de las de oportunidad sigue VisibilidadOportunidades.
 */
class FichasActividadesOportunidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmProspecto $prospecto;

    private CrmOportunidad $opPropia;

    private CrmOportunidad $opAjena;

    private int $minuto = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario',
            'activo' => true,
        ]);

        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo']);
        $this->prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto', 'estatus' => 'nuevo']);

        $this->opPropia = $this->oportunidad('Propia', $this->propio->id, ['cliente_id' => $this->cliente->id]);
        $this->opAjena = $this->oportunidad('Ajena', $this->vendedor->id, ['cliente_id' => $this->cliente->id]);
    }

    private function oportunidad(string $nombre, int $vendedorId, array $dueno): CrmOportunidad
    {
        return CrmOportunidad::create($dueno + [
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $vendedorId,
            'nombre' => $nombre,
            'etapa' => 'calificado',
        ]);
    }

    /** Actividad sobre una entidad; cada una un minuto más reciente que la anterior. */
    private function actividad(string $descripcion, string $entidadType, int $entidadId, ?int $empresaId = null): CrmActividad
    {
        return CrmActividad::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id,
            'entidad_type' => $entidadType,
            'entidad_id' => $entidadId,
            'tipo' => 'llamada',
            'descripcion' => $descripcion,
            'fecha_actividad' => now()->subDay()->addMinutes(++$this->minuto),
            'fuente' => 'manual',
        ]);
    }

    private function historial(string $tipo, int $id, string $query = ''): TestResponse
    {
        return $this->withHeaders($this->crmHeaders())->getJson("/api/crm/{$tipo}/{$id}/historial{$query}");
    }

    private function resumen(): TestResponse
    {
        return $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/clientes/{$this->cliente->id}/resumen")
            ->assertOk();
    }

    private function descripciones(TestResponse $r): array
    {
        return collect($r->assertOk()->json('data'))->pluck('descripcion')->all();
    }

    public function test_historial_del_cliente_incluye_sus_oportunidades(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('de la oportunidad', CrmOportunidad::class, $this->opPropia->id);

        $r = $this->historial('cliente', $this->cliente->id);

        $this->assertSame(['de la oportunidad', 'del cliente'], $this->descripciones($r));
        $r->assertJsonPath('data.0.entidad_tipo', 'oportunidad')
            ->assertJsonPath('data.0.oportunidad.id', $this->opPropia->id)
            ->assertJsonPath('data.0.oportunidad.nombre', 'Propia')
            ->assertJsonPath('data.1.entidad_tipo', 'cliente')
            ->assertJsonPath('data.1.oportunidad', null);
    }

    public function test_no_mezcla_oportunidades_de_otro_cliente(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'estatus' => 'activo']);
        $opOtro = $this->oportunidad('De otro cliente', $this->propio->id, ['cliente_id' => $otroCliente->id]);
        $this->actividad('de otro cliente', CrmOportunidad::class, $opOtro->id);
        $this->actividad('de la mia', CrmOportunidad::class, $this->opPropia->id);

        $this->assertSame(['de la mia'], $this->descripciones($this->historial('cliente', $this->cliente->id)));
    }

    public function test_oculta_oportunidades_ajenas_sin_equipo(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);
        $this->actividad('ajena', CrmOportunidad::class, $this->opAjena->id);
        $eliminada = $this->oportunidad('Eliminada', $this->propio->id, ['cliente_id' => $this->cliente->id]);
        $this->actividad('de eliminada', CrmOportunidad::class, $eliminada->id);
        $eliminada->delete();

        $this->assertSame(['propia', 'del cliente'], $this->descripciones($this->historial('cliente', $this->cliente->id)));
    }

    public function test_con_equipo_ve_las_de_todas_las_oportunidades_del_cliente(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);
        $this->actividad('ajena', CrmOportunidad::class, $this->opAjena->id);

        $this->assertSame(['ajena', 'propia'], $this->descripciones($this->historial('cliente', $this->cliente->id)));
    }

    public function test_sin_oportunidades_ver_solo_salen_las_del_cliente(): void
    {
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);

        $this->assertSame(['del cliente'], $this->descripciones($this->historial('cliente', $this->cliente->id)));
    }

    public function test_historial_del_prospecto_incluye_sus_oportunidades_visibles(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $propia = $this->oportunidad('Prospecto propia', $this->propio->id, ['prospecto_id' => $this->prospecto->id]);
        $ajena = $this->oportunidad('Prospecto ajena', $this->vendedor->id, ['prospecto_id' => $this->prospecto->id]);
        $this->actividad('del prospecto', CrmProspecto::class, $this->prospecto->id);
        $this->actividad('de la propia', CrmOportunidad::class, $propia->id);
        $this->actividad('de la ajena', CrmOportunidad::class, $ajena->id);
        // Una actividad de un cliente con el mismo id numerico no se cuela en el prospecto.
        $this->actividad('del cliente', CrmCliente::class, $this->prospecto->id);

        $r = $this->historial('prospecto', $this->prospecto->id);

        $this->assertSame(['de la propia', 'del prospecto'], $this->descripciones($r));
        $r->assertJsonPath('data.0.oportunidad.nombre', 'Prospecto propia')
            ->assertJsonPath('data.1.oportunidad', null);
    }

    public function test_historial_de_oportunidad_no_cambia(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);
        $this->actividad('ajena', CrmOportunidad::class, $this->opAjena->id);

        $this->assertSame(['propia'], $this->descripciones($this->historial('oportunidad', $this->opPropia->id)));
        $this->assertSame([], $this->descripciones($this->historial('oportunidad', $this->opAjena->id)));
    }

    public function test_con_equipo_la_ficha_excluye_las_de_oportunidades_eliminadas(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);
        $eliminada = $this->oportunidad('Eliminada', $this->vendedor->id, ['cliente_id' => $this->cliente->id]);
        $this->actividad('de eliminada', CrmOportunidad::class, $eliminada->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);
        $eliminada->delete();

        $this->assertSame(['propia'], $this->descripciones($this->historial('cliente', $this->cliente->id)));
        $this->assertSame(1, $this->resumen()->json('data.metricas.actividades'));
    }

    public function test_el_historial_de_oportunidad_y_de_empresa_externa_no_traen_la_clave_oportunidad(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Externa SA']);
        $this->actividad('de la oportunidad', CrmOportunidad::class, $this->opPropia->id);
        $this->actividad('de la externa', CrmEmpresaExterna::class, $externa->id);

        $deOportunidad = $this->historial('oportunidad', $this->opPropia->id)->assertOk();
        $deExterna = $this->historial('empresa_externa', $externa->id)->assertOk();

        $this->assertSame(['de la oportunidad'], collect($deOportunidad->json('data'))->pluck('descripcion')->all());
        $this->assertSame(['de la externa'], collect($deExterna->json('data'))->pluck('descripcion')->all());
        $this->assertArrayNotHasKey('oportunidad', $deOportunidad->json('data.0'));
        $this->assertArrayNotHasKey('oportunidad', $deExterna->json('data.0'));
    }

    public function test_el_listado_general_no_cambia(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);

        $r = $this->withHeaders($this->crmHeaders())
            ->getJson("/api/crm/actividades?entidad_tipo=cliente&entidad_id={$this->cliente->id}")
            ->assertOk();

        $this->assertSame(['del cliente'], collect($r->json('data'))->pluck('descripcion')->all());
        $this->assertArrayNotHasKey('oportunidad', $r->json('data.0'));
    }

    public function test_conteo_y_paginacion_correctos(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        // 10 del cliente y 10 de su oportunidad, intercaladas; 5 de la ajena que no cuentan.
        for ($i = 1; $i <= 10; $i++) {
            $this->actividad("cliente {$i}", CrmCliente::class, $this->cliente->id);
            $this->actividad("oportunidad {$i}", CrmOportunidad::class, $this->opPropia->id);
            if ($i <= 5) {
                $this->actividad("ajena {$i}", CrmOportunidad::class, $this->opAjena->id);
            }
        }

        $p1 = $this->historial('cliente', $this->cliente->id)->assertOk();
        $p2 = $this->historial('cliente', $this->cliente->id, '?page=2')->assertOk();

        $p1->assertJsonPath('meta.total', 20)->assertJsonPath('meta.last_page', 2);
        $this->assertCount(15, $p1->json('data'));
        $this->assertCount(5, $p2->json('data'));
        // Mas reciente primero, sin repetidos entre paginas.
        $todas = array_merge($p1->json('data'), $p2->json('data'));
        $this->assertCount(20, array_unique(array_column($todas, 'id')));
        $this->assertSame('oportunidad 10', $todas[0]['descripcion']);
        $this->assertSame('cliente 1', $todas[19]['descripcion']);
        $this->assertNotContains('ajena 1', array_column($todas, 'descripcion'));
    }

    public function test_el_conteo_de_consultas_no_crece_con_mas_filas(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $contar = function (int $filas): int {
            for ($i = 0; $i < $filas; $i++) {
                $op = $this->oportunidad("Op {$i}", $this->propio->id, ['cliente_id' => $this->cliente->id]);
                $this->actividad("a{$i}", CrmOportunidad::class, $op->id);
                $this->actividad("c{$i}", CrmCliente::class, $this->cliente->id);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->historial('cliente', $this->cliente->id)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con2 = $contar(2);
        $con6 = $contar(4); // acumulado: 6 oportunidades, 12 filas

        $this->assertSame($con2, $con6, 'El historial no debe consultar el nombre de la oportunidad fila por fila.');
    }

    public function test_resumen_incluye_oportunidades(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);
        $this->actividad('ajena', CrmOportunidad::class, $this->opAjena->id);

        $r = $this->resumen();

        $this->assertSame(2, $r->json('data.metricas.actividades'));
        $this->assertSame(1, $r->json('data.metricas.oportunidades'));
        $filas = collect($r->json('data.actividades'))->keyBy('descripcion');
        $this->assertCount(2, $filas);
        $this->assertSame(['id' => $this->opPropia->id, 'nombre' => 'Propia'], $filas['propia']['oportunidad']);
        $this->assertNull($filas['del cliente']['oportunidad']);
        $this->assertArrayNotHasKey('ajena', $filas->all());
    }

    public function test_resumen_con_mas_de_diez_trae_las_diez_mas_recientes_y_cuenta_todas(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'equipo']);
        for ($i = 1; $i <= 8; $i++) {
            $this->actividad("cliente {$i}", CrmCliente::class, $this->cliente->id);
            $this->actividad("oportunidad {$i}", CrmOportunidad::class, $this->opAjena->id);
        }

        $r = $this->resumen();

        $this->assertSame(16, $r->json('data.metricas.actividades'));
        $this->assertCount(10, $r->json('data.actividades'));
    }

    public function test_resumen_sin_oportunidades_ver_deja_solo_las_del_cliente(): void
    {
        $this->actividad('del cliente', CrmCliente::class, $this->cliente->id);
        $this->actividad('propia', CrmOportunidad::class, $this->opPropia->id);

        $r = $this->resumen();

        $this->assertSame(1, $r->json('data.metricas.actividades'));
        $this->assertSame(['del cliente'], collect($r->json('data.actividades'))->pluck('descripcion')->all());
    }
}
