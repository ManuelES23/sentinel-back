<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Events\CRM\ProspectoUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use App\Services\CRM\SeguimientoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * POST crm/mi-dia/actividades (spec 2026-10-30 §5.3 y §8): «Nueva actividad»
 * crea, en una sola transacción, el prospecto (si hace falta), la oportunidad
 * (si hace falta) y el evento o la actividad.
 */
class MiDiaNuevaActividadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/mi-dia/actividades';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmEtiquetaActividad $etiqueta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        Carbon::setTestNow('2026-09-14 10:00:00');
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'La Huerta', 'estatus' => 'activo',
        ]);
        $this->etiqueta = $this->etiqueta();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Permisos de un vendedor con todo lo que necesita «Nueva actividad».
     * `$excepto` lista permisos que NO se otorgan, con la forma `modulo.permiso`.
     */
    private function permisosVendedor(array $excepto = []): void
    {
        $mapa = [
            'mi-dia' => ['ver'],
            'agenda' => ['ver', 'crear'],
            'actividades' => ['ver', 'crear'],
            'oportunidades' => ['ver', 'crear'],
            'prospectos' => ['ver', 'crear'],
        ];
        foreach ($mapa as $modulo => $permisos) {
            $permisos = array_values(array_filter($permisos, fn ($p) => ! in_array("{$modulo}.{$p}", $excepto, true)));
            if ($permisos) {
                $this->otorgarPermisosCrm($modulo, $modulo, $permisos);
            }
        }
    }

    private function etiqueta(array $datos = [], ?int $empresaId = null): CrmEtiquetaActividad
    {
        return CrmEtiquetaActividad::create(array_merge([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => 'Seguimiento '.uniqid(),
        ], $datos));
    }

    private function evento(array $datos = []): array
    {
        return array_merge([
            'tipo' => 'llamada', 'titulo' => 'Llamar para cotizar',
            'fecha_inicio' => '2026-09-15 09:00:00', 'etiqueta_id' => $this->etiqueta->id,
        ], $datos);
    }

    private function actividad(array $datos = []): array
    {
        return array_merge([
            'tipo' => 'llamada', 'descripcion' => 'Habló con el comprador',
            'resultado' => 'Pidió precio', 'etiqueta_id' => $this->etiqueta->id,
        ], $datos);
    }

    private function crear(array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::URL, $datos);
    }

    private function oportunidadExistente(array $datos = []): CrmOportunidad
    {
        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'La Huerta · Pepper', 'monto_esperado' => 1000,
        ], $datos));
    }

    private function sinNadaCreado(): void
    {
        $this->assertSame(0, CrmProspecto::count());
        $this->assertSame(0, CrmOportunidad::count());
        $this->assertSame(0, CrmAgenda::count());
        $this->assertSame(0, CrmActividad::count());
    }

    // ---- Caminos felices ----

    public function test_prospecto_nuevo_abre_oportunidad_y_evento(): void
    {
        $this->permisosVendedor();

        $respuesta = $this->crear([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo', 'telefono' => '6671234567', 'email' => 'rancho@example.com'],
            'tipo_producto' => 'Pepper',
            'monto_esperado' => 2500,
            'evento' => $this->evento(),
        ])->assertCreated();

        $respuesta->assertJsonPath('data.prospecto.nombre', 'Rancho Nuevo')
            ->assertJsonPath('data.prospecto.estatus', 'nuevo')
            ->assertJsonPath('data.prospecto.vendedor_id', $this->propio->id)
            ->assertJsonPath('data.oportunidad.nombre', 'Rancho Nuevo · Pepper')
            ->assertJsonPath('data.oportunidad.etapa', 'prospecto')
            ->assertJsonPath('data.oportunidad.vendedor_id', $this->propio->id)
            ->assertJsonPath('data.oportunidad.monto_origen.tipo', 'estimado')
            ->assertJsonPath('data.evento.titulo', 'Llamar para cotizar')
            ->assertJsonPath('data.evento.vendedor.id', $this->propio->id)
            ->assertJsonPath('data.evento.etiqueta.id', $this->etiqueta->id)
            ->assertJsonPath('data.actividad', null);

        $prospecto = CrmProspecto::firstOrFail();
        $oportunidad = CrmOportunidad::firstOrFail();
        $this->assertSame($prospecto->id, $oportunidad->prospecto_id);
        $this->assertNull($oportunidad->cliente_id);
        $this->assertDatabaseHas('crm_agenda', [
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $oportunidad->id,
            'etiqueta_id' => $this->etiqueta->id,
            'completado' => false,
        ]);
        $this->assertSame(0, CrmActividad::count());
    }

    public function test_cliente_sin_oportunidad_abre_una(): void
    {
        $this->permisosVendedor();

        $this->crear([
            'cliente_id' => $this->cliente->id,
            'tipo_producto' => 'Pepper',
            'evento' => $this->evento(),
        ])->assertCreated()
            ->assertJsonPath('data.prospecto', null)
            ->assertJsonPath('data.oportunidad.nombre', 'La Huerta · Pepper')
            ->assertJsonPath('data.oportunidad.cliente.nombre', 'La Huerta');

        $this->assertSame(1, CrmOportunidad::where('cliente_id', $this->cliente->id)->count());
        $this->assertSame(1, CrmAgenda::count());
        $this->assertSame(0, CrmProspecto::count());
    }

    public function test_cliente_con_oportunidad_abierta_abre_otra_nueva(): void
    {
        $this->permisosVendedor();
        $this->oportunidadExistente();

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])->assertCreated();

        $this->assertSame(2, CrmOportunidad::where('cliente_id', $this->cliente->id)->count());
    }

    public function test_prospecto_existente_abre_oportunidad(): void
    {
        $this->permisosVendedor();
        $prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Viejo', 'estatus' => 'nuevo',
        ]);

        $this->crear(['prospecto_id' => $prospecto->id, 'tipo_producto' => 'Pepper', 'evento' => $this->evento()])
            ->assertCreated()
            ->assertJsonPath('data.prospecto', null)
            ->assertJsonPath('data.oportunidad.prospecto_id', $prospecto->id);

        $this->assertSame(1, CrmProspecto::count());
    }

    public function test_oportunidad_existente_solo_agrega_el_evento(): void
    {
        $this->permisosVendedor();
        $oportunidad = $this->oportunidadExistente();

        $this->crear(['oportunidad_id' => $oportunidad->id, 'evento' => $this->evento()])
            ->assertCreated()
            ->assertJsonPath('data.oportunidad.id', $oportunidad->id)
            ->assertJsonPath('data.prospecto', null)
            ->assertJsonPath('data.evento.entidad_id', $oportunidad->id);

        $this->assertSame(1, CrmOportunidad::count());
        $this->assertSame(1, CrmAgenda::where('entidad_type', CrmOportunidad::class)->where('entidad_id', $oportunidad->id)->count());
    }

    public function test_oportunidad_ganada_acepta_actividad_sin_cambiar_de_etapa(): void
    {
        $this->permisosVendedor();
        $oportunidad = $this->oportunidadExistente(['etapa' => 'cerrado_ganado']);

        $this->crear(['oportunidad_id' => $oportunidad->id, 'actividad' => $this->actividad()])->assertCreated();

        $this->assertSame('cerrado_ganado', $oportunidad->fresh()->etapa);
    }

    public function test_ya_la_hice_crea_actividad(): void
    {
        $this->permisosVendedor();

        $this->crear([
            'cliente_id' => $this->cliente->id,
            'tipo_producto' => 'Pepper',
            'actividad' => $this->actividad(),
        ])->assertCreated()
            ->assertJsonPath('data.evento', null)
            ->assertJsonPath('data.actividad.descripcion', 'Habló con el comprador')
            ->assertJsonPath('data.actividad.resultado', 'Pidió precio')
            ->assertJsonPath('data.actividad.etiqueta.id', $this->etiqueta->id)
            ->assertJsonPath('data.actividad.vendedor.id', $this->propio->id);

        $oportunidad = CrmOportunidad::firstOrFail();
        $this->assertDatabaseHas('crm_actividades', [
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $oportunidad->id,
            'fuente' => 'manual',
            'etiqueta_id' => $this->etiqueta->id,
        ]);
        $this->assertSame(0, CrmAgenda::count());
        // Sin fecha_actividad el servidor guarda ahora.
        $this->assertTrue(CrmActividad::firstOrFail()->fecha_actividad->equalTo(now()));
    }

    public function test_actividad_con_fecha_pasada_la_respeta_y_futura_se_rechaza(): void
    {
        $this->permisosVendedor();

        $this->crear([
            'cliente_id' => $this->cliente->id,
            'actividad' => $this->actividad(['fecha_actividad' => '2026-09-14 08:00:00']),
        ])->assertCreated();
        $this->assertTrue(CrmActividad::firstOrFail()->fecha_actividad->equalTo(Carbon::parse('2026-09-14 08:00:00')));

        $this->crear([
            'cliente_id' => $this->cliente->id,
            'actividad' => $this->actividad(['fecha_actividad' => '2026-09-14 12:00:00']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['actividad.fecha_actividad']);
    }

    // ---- Validación ----

    public function test_error_al_final_no_deja_nada(): void
    {
        $this->permisosVendedor();
        $ajena = $this->etiqueta([], $this->crearOtraEmpresa()->id);

        $this->crear([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'evento' => $this->evento(['etiqueta_id' => $ajena->id]),
        ])->assertUnprocessable()->assertJsonValidationErrors(['evento.etiqueta_id']);

        $this->sinNadaCreado();
    }

    public function test_etiqueta_inactiva_422(): void
    {
        $this->permisosVendedor();
        $inactiva = $this->etiqueta(['activo' => false]);

        $this->crear(['cliente_id' => $this->cliente->id, 'actividad' => $this->actividad(['etiqueta_id' => $inactiva->id])])
            ->assertUnprocessable()->assertJsonValidationErrors(['actividad.etiqueta_id']);

        $this->sinNadaCreado();
    }

    public function test_la_etiqueta_es_obligatoria(): void
    {
        $this->permisosVendedor();
        $evento = $this->evento();
        unset($evento['etiqueta_id']);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $evento])
            ->assertUnprocessable()->assertJsonValidationErrors(['evento.etiqueta_id']);
    }

    public function test_el_evento_debe_ser_futuro(): void
    {
        $this->permisosVendedor();

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento(['fecha_inicio' => '2026-09-14 09:00:00'])])
            ->assertUnprocessable()->assertJsonValidationErrors(['evento.fecha_inicio']);
    }

    public function test_dos_origenes_422(): void
    {
        $this->permisosVendedor();

        $this->crear([
            'cliente_id' => $this->cliente->id,
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'evento' => $this->evento(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['origen']);

        $this->sinNadaCreado();
    }

    public function test_sin_origen_422(): void
    {
        $this->permisosVendedor();

        $this->crear(['evento' => $this->evento()])->assertUnprocessable()->assertJsonValidationErrors(['origen']);
    }

    public function test_sin_registro_422(): void
    {
        $this->permisosVendedor();

        $this->crear(['cliente_id' => $this->cliente->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['registro']);

        $this->sinNadaCreado();
    }

    public function test_dos_registros_422(): void
    {
        $this->permisosVendedor();

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento(), 'actividad' => $this->actividad()])
            ->assertUnprocessable()->assertJsonValidationErrors(['registro']);

        $this->sinNadaCreado();
    }

    public function test_bloque_evento_vacio_cuenta_como_ausente_y_no_salta_permisos(): void
    {
        // Sin agenda.crear: un `evento: {}` no puede colarse como «sin registro» con 201.
        $this->permisosVendedor(['agenda.crear']);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['registro']);
        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => new \stdClass])
            ->assertUnprocessable()->assertJsonValidationErrors(['registro']);

        $this->sinNadaCreado();
    }

    public function test_bloque_actividad_vacio_cuenta_como_ausente(): void
    {
        $this->permisosVendedor(['actividades.crear']);

        $this->crear(['cliente_id' => $this->cliente->id, 'actividad' => new \stdClass])
            ->assertUnprocessable()->assertJsonValidationErrors(['registro']);

        $this->sinNadaCreado();
    }

    public function test_bloque_prospecto_nuevo_vacio_cuenta_como_ausente(): void
    {
        $this->permisosVendedor();

        $this->crear(['prospecto_nuevo' => new \stdClass, 'evento' => $this->evento()])
            ->assertUnprocessable()->assertJsonValidationErrors(['origen']);
        $this->crear(['prospecto_nuevo' => new \stdClass, 'cliente_id' => $this->cliente->id, 'evento' => $this->evento()])
            ->assertCreated()->assertJsonPath('data.prospecto', null);

        $this->assertSame(0, CrmProspecto::count());
        $this->assertSame(1, CrmOportunidad::count());
    }

    public function test_bloques_con_campos_pero_sin_lo_obligatorio_422(): void
    {
        $this->permisosVendedor();

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => ['tipo' => 'llamada']])
            ->assertUnprocessable()->assertJsonValidationErrors(['evento.titulo', 'evento.fecha_inicio', 'evento.etiqueta_id']);
        $this->crear(['cliente_id' => $this->cliente->id, 'actividad' => ['resultado' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['actividad.tipo', 'actividad.descripcion', 'actividad.etiqueta_id']);

        $this->sinNadaCreado();
    }

    public function test_oportunidad_id_cero_422_no_500(): void
    {
        $this->permisosVendedor();

        foreach ([0, '0'] as $id) {
            $this->crear(['oportunidad_id' => $id, 'evento' => $this->evento()])
                ->assertUnprocessable()->assertJsonValidationErrors(['oportunidad_id']);
        }

        $this->sinNadaCreado();
    }

    public function test_oportunidad_perdida_422(): void
    {
        $this->permisosVendedor();
        $oportunidad = $this->oportunidadExistente(['etapa' => 'cerrado_perdido']);

        $this->crear(['oportunidad_id' => $oportunidad->id, 'evento' => $this->evento()])
            ->assertUnprocessable()->assertJsonValidationErrors(['oportunidad_id']);

        $this->assertSame(0, CrmAgenda::count());
    }

    public function test_oportunidad_de_otra_empresa_404(): void
    {
        $this->permisosVendedor();
        $otra = $this->crearOtraEmpresa();
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'De otra empresa', 'activo' => true]);
        $ajena = CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $vendedorAjeno->id, 'nombre' => 'Ajena', 'monto_esperado' => 1,
        ]);

        $this->crear(['oportunidad_id' => $ajena->id, 'evento' => $this->evento()])->assertNotFound();
        $this->crear(['oportunidad_id' => 99999, 'evento' => $this->evento()])->assertNotFound();
    }

    public function test_oportunidad_de_otro_vendedor_sin_equipo_404_igual_que_inexistente(): void
    {
        $this->permisosVendedor();
        $ajena = $this->oportunidadExistente(['vendedor_id' => $this->vendedor->id]);

        $this->crear(['oportunidad_id' => $ajena->id, 'evento' => $this->evento()])->assertNotFound();

        $this->assertSame(0, CrmAgenda::count());
    }

    public function test_correo_duplicado_422(): void
    {
        $this->permisosVendedor();
        CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Existente', 'estatus' => 'nuevo', 'email' => 'dup@example.com',
        ]);

        $respuesta = $this->crear([
            'prospecto_nuevo' => ['nombre' => 'Otro', 'email' => 'dup@example.com'],
            'evento' => $this->evento(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['prospecto_nuevo.email']);

        $this->assertSame('Ya existe un prospecto con este correo.', $respuesta->json('errors')['prospecto_nuevo.email'][0]);

        $this->assertSame(1, CrmProspecto::count());
        $this->assertSame(0, CrmOportunidad::count());
    }

    public function test_correo_de_un_prospecto_eliminado_no_cuenta_como_duplicado(): void
    {
        $this->permisosVendedor();
        $viejo = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Borrado', 'estatus' => 'nuevo', 'email' => 'dup@example.com',
        ]);
        $viejo->delete();

        $this->crear([
            'prospecto_nuevo' => ['nombre' => 'Otro', 'email' => 'dup@example.com'],
            'evento' => $this->evento(),
        ])->assertCreated();
    }

    public function test_el_nombre_del_prospecto_nuevo_es_obligatorio(): void
    {
        $this->permisosVendedor();

        $this->crear(['prospecto_nuevo' => ['telefono' => '1'], 'evento' => $this->evento()])
            ->assertUnprocessable()->assertJsonValidationErrors(['prospecto_nuevo.nombre']);
    }

    public function test_tipo_producto_es_obligatorio_si_la_empresa_tiene_commodities(): void
    {
        $this->permisosVendedor();
        CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Pepper', 'commodity' => 'Pepper',
            'unidad_medida' => 'kg', 'precio' => 10, 'activo' => true,
        ]);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])
            ->assertUnprocessable()->assertJsonValidationErrors(['tipo_producto']);

        $this->crear(['cliente_id' => $this->cliente->id, 'tipo_producto' => 'Pepper', 'evento' => $this->evento()])
            ->assertCreated();

        // Con una oportunidad existente no se abre nada: no se pide tipo de producto.
        $oportunidad = $this->oportunidadExistente();
        $this->crear(['oportunidad_id' => $oportunidad->id, 'evento' => $this->evento()])->assertCreated();
    }

    public function test_prospecto_ya_convertido_422_y_no_deja_nada(): void
    {
        $this->permisosVendedor();
        $prospecto = CrmProspecto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Convertido', 'estatus' => 'calificado',
        ]);
        CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Convertido SA', 'estatus' => 'activo', 'prospecto_id' => $prospecto->id,
        ]);

        $this->crear(['prospecto_id' => $prospecto->id, 'evento' => $this->evento()])
            ->assertUnprocessable()->assertJsonValidationErrors(['prospecto_id']);

        $this->assertSame(0, CrmOportunidad::count());
        $this->assertSame(0, CrmAgenda::count());
    }

    // ---- Permisos ----

    public function test_exige_mi_dia_ver(): void
    {
        $this->permisosVendedor(['mi-dia.ver']);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])->assertForbidden();
        $this->sinNadaCreado();
    }

    public function test_exige_agenda_crear(): void
    {
        $this->permisosVendedor(['agenda.crear']);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])->assertForbidden();

        $this->sinNadaCreado();
    }

    public function test_exige_actividades_crear(): void
    {
        $this->permisosVendedor(['actividades.crear']);

        $this->crear(['cliente_id' => $this->cliente->id, 'actividad' => $this->actividad()])->assertForbidden();

        $this->sinNadaCreado();
    }

    public function test_abrir_exige_oportunidades_crear(): void
    {
        $this->permisosVendedor(['oportunidades.crear']);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])->assertForbidden();

        $this->sinNadaCreado();
    }

    public function test_prospecto_nuevo_exige_prospectos_crear(): void
    {
        $this->permisosVendedor(['prospectos.crear']);

        $this->crear(['prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'], 'evento' => $this->evento()])->assertForbidden();

        $this->sinNadaCreado();
    }

    public function test_oportunidad_existente_no_exige_oportunidades_crear(): void
    {
        $this->permisosVendedor(['oportunidades.crear']);
        $oportunidad = $this->oportunidadExistente();

        $this->crear(['oportunidad_id' => $oportunidad->id, 'evento' => $this->evento()])->assertCreated();
    }

    public function test_cliente_de_otro_vendedor_sin_equipo_403(): void
    {
        $this->permisosVendedor();
        $ajeno = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'De Juan', 'estatus' => 'activo', 'vendedor_id' => $this->vendedor->id,
        ]);

        $this->crear(['cliente_id' => $ajeno->id, 'evento' => $this->evento()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Este cliente es de Juan Pérez; pide que te lo reasignen.');

        $this->sinNadaCreado();
    }

    public function test_sin_vendedor_propio_422(): void
    {
        $this->permisosVendedor();
        $this->propio->update(['activo' => false]);

        $this->crear(['cliente_id' => $this->cliente->id, 'evento' => $this->evento()])->assertUnprocessable();

        $this->sinNadaCreado();
    }

    // ---- Vendedor ----

    public function test_gerencia_crea_a_nombre_del_vendedor_visto(): void
    {
        $this->permisosVendedor();
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['equipo']);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);

        $this->crear([
            'vendedor_id' => $this->vendedor->id,
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'tipo_producto' => 'Pepper',
            'evento' => $this->evento(),
        ])->assertCreated()
            ->assertJsonPath('data.prospecto.vendedor_id', $this->vendedor->id)
            ->assertJsonPath('data.oportunidad.vendedor_id', $this->vendedor->id)
            ->assertJsonPath('data.evento.vendedor_id', $this->vendedor->id);

        $this->crear([
            'vendedor_id' => $this->vendedor->id,
            'oportunidad_id' => CrmOportunidad::firstOrFail()->id,
            'actividad' => $this->actividad(),
        ])->assertCreated()->assertJsonPath('data.actividad.vendedor_id', $this->vendedor->id);

        $this->assertSame(0, CrmAgenda::where('vendedor_id', $this->propio->id)->count());
        $this->assertSame(0, CrmActividad::where('vendedor_id', $this->propio->id)->count());
    }

    public function test_vendedor_ajeno_sin_mi_dia_equipo_403(): void
    {
        $this->permisosVendedor();

        $this->crear(['vendedor_id' => $this->vendedor->id, 'cliente_id' => $this->cliente->id, 'evento' => $this->evento()])
            ->assertForbidden();

        $this->sinNadaCreado();
    }

    // ---- Tiempo real y atomicidad ----

    public function test_emite_eventos_despues_de_guardar(): void
    {
        $this->permisosVendedor();
        Event::fake([ProspectoUpdated::class, OportunidadUpdated::class, AgendaUpdated::class, ActividadUpdated::class]);

        $this->crear([
            'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
            'evento' => $this->evento(),
        ])->assertCreated();

        Event::assertDispatched(ProspectoUpdated::class, fn ($e) => $e->action === 'created' && $e->data['nombre'] === 'Rancho Nuevo');
        Event::assertDispatched(OportunidadUpdated::class, fn ($e) => $e->action === 'created' && isset($e->data['monto_origen']));
        Event::assertDispatched(AgendaUpdated::class, fn ($e) => $e->action === 'created');
        Event::assertNotDispatched(ActividadUpdated::class);
    }

    public function test_actividad_emite_actividad_updated(): void
    {
        $this->permisosVendedor();
        $oportunidad = $this->oportunidadExistente();
        Event::fake([ProspectoUpdated::class, OportunidadUpdated::class, AgendaUpdated::class, ActividadUpdated::class]);

        $this->crear(['oportunidad_id' => $oportunidad->id, 'actividad' => $this->actividad()])->assertCreated();

        Event::assertDispatched(ActividadUpdated::class, fn ($e) => $e->action === 'created');
        // Una oportunidad existente no se «crea» de nuevo.
        Event::assertNotDispatched(OportunidadUpdated::class);
        Event::assertNotDispatched(ProspectoUpdated::class);
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_una_falla_al_final_revierte_prospecto_y_oportunidad(): void
    {
        $this->permisosVendedor();
        Event::fake([ProspectoUpdated::class, OportunidadUpdated::class, AgendaUpdated::class, ActividadUpdated::class]);

        // El prospecto y la oportunidad ya están escritos cuando falla el evento.
        $this->app->bind(SeguimientoService::class, fn () => new class extends SeguimientoService
        {
            public function programar(int $empresaId, int $vendedorId, ?\Illuminate\Database\Eloquent\Model $entidad, array $datos): CrmAgenda
            {
                throw new RuntimeException('falla simulada');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->crear([
                'prospecto_nuevo' => ['nombre' => 'Rancho Nuevo'],
                'tipo_producto' => 'Pepper',
                'evento' => $this->evento(),
            ]);
            $this->fail('Debió lanzar la falla simulada.');
        } catch (RuntimeException $e) {
            $this->assertSame('falla simulada', $e->getMessage());
        }

        $this->sinNadaCreado();
        Event::assertNothingDispatched();
    }
}
