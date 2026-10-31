<?php

namespace Tests\Feature\CRM;

use App\Models\Application;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\Enterprise;
use App\Support\CRM\EmpresasCrm;
use App\Support\CRM\EtiquetasActividadPredeterminadas;
use Database\Seeders\CrmEtiquetasActividadSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Esquema nuevo de «la oportunidad es el eje del CRM» (spec 2026-10-30 §6):
 * etiquetas de actividad, tipo de producto y estado de cobro en la
 * oportunidad, tipo `whatsapp` en la agenda y la bandera `oportunidad_eje`.
 */
class OportunidadEjeEsquemaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION_PRODUCTOS = 'migrations/2026_10_30_100300_apagar_productos_en_oportunidad.php';

    private const NOMBRES = ['Primer contacto', 'Seguimiento', 'Cotización', 'Negociación', 'Cobranza', 'Rechazo'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function oportunidad(array $extra = []): CrmOportunidad
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);

        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad', 'etapa' => 'negociacion',
        ], $extra));
    }

    private function agenda(array $extra = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->vendedor->id,
            'tipo' => 'llamada',
            'titulo' => 'Evento',
            'fecha_inicio' => now()->addDay(),
            'fecha_fin' => now()->addDay()->addHour(),
        ], $extra));
    }

    public function test_siembra_las_seis_etiquetas_en_orden(): void
    {
        EtiquetasActividadPredeterminadas::sembrar($this->enterprise->id);

        $etiquetas = CrmEtiquetaActividad::where('empresa_id', $this->enterprise->id)->orderBy('orden')->get();

        $this->assertSame(self::NOMBRES, $etiquetas->pluck('nombre')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], $etiquetas->pluck('orden')->all());
        $this->assertTrue($etiquetas->every(fn ($e) => $e->activo === true));
        $this->assertSame(self::NOMBRES, array_column(EtiquetasActividadPredeterminadas::LISTA, 'nombre'));
    }

    public function test_sembrar_es_idempotente(): void
    {
        EtiquetasActividadPredeterminadas::sembrar($this->enterprise->id);
        EtiquetasActividadPredeterminadas::sembrar($this->enterprise->id);
        $this->assertSame(6, CrmEtiquetaActividad::where('empresa_id', $this->enterprise->id)->count());

        // Una empresa con un catálogo propio (ya editado) no se toca.
        $otra = $this->crearOtraEmpresa();
        CrmEtiquetaActividad::create(['empresa_id' => $otra->id, 'nombre' => 'Mía', 'orden' => 1]);
        EtiquetasActividadPredeterminadas::sembrar($otra->id);
        $this->assertSame(['Mía'], CrmEtiquetaActividad::where('empresa_id', $otra->id)->pluck('nombre')->all());
    }

    public function test_el_seeder_siembra_solo_las_empresas_con_crm(): void
    {
        $sinCrm = $this->crearOtraEmpresa();
        Application::create([
            'enterprise_id' => $this->enterprise->id, 'slug' => 'crm', 'name' => 'CRM Comercial',
            'description' => 'CRM', 'path' => '/x/crm', 'is_active' => true,
        ]);

        $this->seed(CrmEtiquetasActividadSeeder::class);

        $this->assertSame(6, CrmEtiquetaActividad::where('empresa_id', $this->enterprise->id)->count());
        $this->assertSame(0, CrmEtiquetaActividad::where('empresa_id', $sinCrm->id)->count());
    }

    public function test_agenda_acepta_tipo_whatsapp(): void
    {
        $agenda = $this->agenda(['tipo' => 'whatsapp', 'titulo' => 'Escribir por WhatsApp']);

        $this->assertSame('whatsapp', $agenda->fresh()->tipo);
        $this->assertDatabaseHas('crm_agenda', ['id' => $agenda->id, 'tipo' => 'whatsapp']);
    }

    public function test_la_agenda_conserva_los_tipos_anteriores_y_rechaza_los_desconocidos(): void
    {
        foreach (['llamada', 'visita', 'reunion', 'tarea', 'correo'] as $tipo) {
            $this->agenda(['tipo' => $tipo, 'titulo' => $tipo]);
        }
        $this->assertSame(5, CrmAgenda::count());

        $this->expectException(QueryException::class);
        $this->agenda(['tipo' => 'telepatia']);
    }

    public function test_etiqueta_id_se_guarda_en_agenda_y_actividades(): void
    {
        $etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Seguimiento', 'orden' => 1]);

        $agenda = $this->agenda(['etiqueta_id' => $etiqueta->id]);
        $actividad = CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'tipo' => 'llamada',
            'entidad_type' => 'oportunidad',
            'entidad_id' => $this->oportunidad()->id,
            'vendedor_id' => $this->vendedor->id,
            'descripcion' => 'Llamé',
            'fecha_actividad' => now(),
            'etiqueta_id' => $etiqueta->id,
        ]);

        $this->assertTrue($agenda->fresh()->etiqueta->is($etiqueta));
        $this->assertTrue($actividad->fresh()->etiqueta->is($etiqueta));
    }

    public function test_ganar_pone_por_cobrar(): void
    {
        Sanctum::actingAs($this->actingUser);
        $oportunidad = $this->oportunidad();
        $this->assertNull($oportunidad->fresh()->estado_cobro);

        $oportunidad->update(['etapa' => 'cerrado_ganado']);

        $oportunidad = $oportunidad->fresh();
        $this->assertSame('por_cobrar', $oportunidad->estado_cobro);
        $this->assertNotNull($oportunidad->cobro_actualizado_at);
        $this->assertSame($this->actingUser->id, (int) $oportunidad->cobro_actualizado_por);
    }

    public function test_ganada_con_estado_no_se_sobrescribe(): void
    {
        $oportunidad = $this->oportunidad(['estado_cobro' => 'cobrada']);

        $oportunidad->update(['etapa' => 'cerrado_ganado']);

        $this->assertSame('cobrada', $oportunidad->fresh()->estado_cobro);
    }

    public function test_otras_etapas_no_ponen_estado_de_cobro(): void
    {
        $oportunidad = $this->oportunidad(['etapa' => 'calificado']);

        $oportunidad->update(['etapa' => 'propuesta']);

        $this->assertNull($oportunidad->fresh()->estado_cobro);
    }

    public function test_estados_de_cobro_y_tipo_de_producto(): void
    {
        $this->assertSame(['por_cobrar', 'cobrada', 'rechazada'], CrmOportunidad::ESTADOS_COBRO);

        $oportunidad = $this->oportunidad(['tipo_producto' => 'Fresa']);
        $this->assertSame('Fresa', $oportunidad->fresh()->tipo_producto);
    }

    public function test_migracion_apaga_productos_en_oportunidad(): void
    {
        $sbp = Enterprise::create([
            'name' => 'Splendid by Porvenir', 'slug' => EmpresasCrm::SLUG_SBP,
            'description' => 'SBP de prueba', 'is_active' => true,
        ]);
        foreach ([$this->enterprise, $sbp] as $empresa) {
            CrmConfiguracionComercial::paraEmpresa($empresa->id);
            DB::table('crm_configuraciones_comerciales')->where('empresa_id', $empresa->id)
                ->update(['productos_en_oportunidad' => true]);
        }

        (require database_path(self::MIGRACION_PRODUCTOS))->up();

        $this->assertFalse(CrmConfiguracionComercial::productosEnOportunidad($this->enterprise->id));
        $this->assertFalse(CrmConfiguracionComercial::productosEnOportunidad($sbp->id));

        (require database_path(self::MIGRACION_PRODUCTOS))->down();

        $this->assertFalse(CrmConfiguracionComercial::productosEnOportunidad($this->enterprise->id));
        $this->assertTrue(CrmConfiguracionComercial::productosEnOportunidad($sbp->id));
    }

    public function test_configuracion_trae_oportunidad_eje(): void
    {
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $this->withHeaders($this->crmHeaders())
            ->getJson('/api/crm/configuracion-comercial')
            ->assertOk()
            ->assertJsonPath('data.oportunidad_eje', true);
    }
}
