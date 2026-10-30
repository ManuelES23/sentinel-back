<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * PATCH crm/oportunidades/{id}/cobro (spec 2026-10-30 §7 «Cobro»): solo en
 * ganadas, exige oportunidades.cobro, deja una actividad de sistema y no toca
 * etapa, fecha de cierre ni monto.
 */
class OportunidadCobroTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private CrmVendedor $propio;

    private CrmOportunidad $ganada;

    private CrmOportunidad $ajena;

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

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
        ]);

        $this->ganada = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Ganada', 'etapa' => 'cerrado_ganado',
            'monto_esperado' => 5000, 'fecha_cierre_real' => '2026-10-01 10:00:00',
            'estado_cobro' => 'por_cobrar',
        ]);
        $this->ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena', 'etapa' => 'cerrado_ganado',
            'monto_esperado' => 900, 'estado_cobro' => 'por_cobrar',
        ]);
    }

    private function permisos(array $permisos = ['ver', 'cobro']): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
    }

    private function cobro(CrmOportunidad $oportunidad, array $datos)
    {
        return $this->withHeaders($this->crmHeaders())
            ->patchJson("/api/crm/oportunidades/{$oportunidad->id}/cobro", $datos);
    }

    private function actividadesDe(CrmOportunidad $oportunidad)
    {
        return CrmActividad::where('entidad_type', CrmOportunidad::class)->where('entidad_id', $oportunidad->id);
    }

    public function test_exige_permiso_cobro(): void
    {
        $this->permisos(['ver', 'editar', 'cerrar']);

        $this->cobro($this->ganada, ['estado' => 'cobrada'])->assertForbidden();

        $this->assertSame('por_cobrar', $this->ganada->fresh()->estado_cobro);
        $this->assertSame(0, $this->actividadesDe($this->ganada)->count());
    }

    public function test_otra_empresa_responde_404(): void
    {
        $this->permisos();
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);

        $this->withHeaders($this->crmHeaders($otra->id))
            ->patchJson("/api/crm/oportunidades/{$this->ganada->id}/cobro", ['estado' => 'cobrada'])
            ->assertNotFound();
    }

    public function test_solo_en_ganada_422(): void
    {
        $this->permisos();
        $this->ganada->update(['etapa' => 'negociacion', 'estado_cobro' => null]);

        $this->cobro($this->ganada, ['estado' => 'cobrada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');

        $this->assertNull($this->ganada->fresh()->estado_cobro);
    }

    public function test_estado_fuera_de_la_lista_422(): void
    {
        $this->permisos();

        $this->cobro($this->ganada, ['estado' => 'pagada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado');
    }

    public function test_rechazada_exige_motivo(): void
    {
        $this->permisos();

        $this->cobro($this->ganada, ['estado' => 'rechazada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->cobro($this->ganada, ['estado' => 'rechazada', 'motivo' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertSame('por_cobrar', $this->ganada->fresh()->estado_cobro);
    }

    public function test_rechazada_crea_actividad_sistema_con_el_motivo(): void
    {
        $this->permisos();
        Event::fake([OportunidadUpdated::class, ActividadUpdated::class]);

        $this->cobro($this->ganada, ['estado' => 'rechazada', 'motivo' => 'El cliente no recibió la factura'])
            ->assertOk()
            ->assertJsonPath('data.estado_cobro', 'rechazada')
            ->assertJsonPath('data.etapa', 'cerrado_ganado')
            ->assertJsonPath('data.monto_origen.tipo', 'estimado');

        $actividad = $this->actividadesDe($this->ganada)->sole();
        $this->assertSame('Cobro rechazado: El cliente no recibió la factura', $actividad->descripcion);
        $this->assertSame('nota', $actividad->tipo);
        $this->assertSame('sistema', $actividad->fuente);
        $this->assertNull($actividad->etiqueta_id);
        $this->assertSame($this->propio->id, (int) $actividad->vendedor_id);
        $this->assertSame($this->enterprise->id, (int) $actividad->empresa_id);
        $this->assertNotNull($actividad->fecha_actividad);

        $fresca = $this->ganada->fresh();
        $this->assertSame('rechazada', $fresca->estado_cobro);
        $this->assertSame('cerrado_ganado', $fresca->etapa);
        $this->assertSame('2026-10-01 10:00:00', $fresca->fecha_cierre_real->format('Y-m-d H:i:s'));
        $this->assertSame('5000.00', (string) $fresca->monto_esperado);

        Event::assertDispatched(OportunidadUpdated::class);
        Event::assertDispatched(ActividadUpdated::class);
    }

    public function test_cobrada_crea_actividad_y_guarda_quien_y_cuando(): void
    {
        $this->permisos();
        $this->travelTo(now()->addDay());

        $this->cobro($this->ganada, ['estado' => 'cobrada'])
            ->assertOk()
            ->assertJsonPath('data.estado_cobro', 'cobrada');

        $fresca = $this->ganada->fresh();
        $this->assertSame('cobrada', $fresca->estado_cobro);
        $this->assertSame($this->actingUser->id, (int) $fresca->cobro_actualizado_por);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $fresca->cobro_actualizado_at->format('Y-m-d H:i:s'));

        $actividad = $this->actividadesDe($this->ganada)->sole();
        $this->assertSame('Cobro: Cobrada', $actividad->descripcion);
        $this->assertSame('sistema', $actividad->fuente);
        $this->assertNull($actividad->etiqueta_id);
    }

    public function test_volver_a_por_cobrar_deja_su_actividad(): void
    {
        $this->permisos();
        $this->ganada->update(['estado_cobro' => 'rechazada']);

        $this->cobro($this->ganada, ['estado' => 'por_cobrar'])->assertOk();

        $this->assertSame('por_cobrar', $this->ganada->fresh()->estado_cobro);
        $this->assertSame('Cobro: Por cobrar', $this->actividadesDe($this->ganada)->sole()->descripcion);
    }

    public function test_el_mismo_estado_es_un_no_op_sin_actividad_ni_eventos(): void
    {
        $this->permisos();
        Event::fake([OportunidadUpdated::class, ActividadUpdated::class]);

        $this->cobro($this->ganada, ['estado' => 'por_cobrar'])
            ->assertOk()
            ->assertJsonPath('data.estado_cobro', 'por_cobrar');

        $this->assertSame(0, $this->actividadesDe($this->ganada)->count());
        $this->assertNull($this->ganada->fresh()->cobro_actualizado_por);
        Event::assertNotDispatched(OportunidadUpdated::class);
        Event::assertNotDispatched(ActividadUpdated::class);
    }

    public function test_ajena_sin_equipo_403(): void
    {
        $this->permisos();

        $this->cobro($this->ajena, ['estado' => 'cobrada'])->assertForbidden();

        $this->assertSame('por_cobrar', $this->ajena->fresh()->estado_cobro);
        $this->assertSame(0, $this->actividadesDe($this->ajena)->count());
    }

    public function test_ajena_con_equipo_funciona(): void
    {
        $this->permisos(['ver', 'cobro', 'equipo']);

        $this->cobro($this->ajena, ['estado' => 'cobrada'])->assertOk();

        $actividad = $this->actividadesDe($this->ajena)->sole();
        $this->assertSame($this->vendedor->id, (int) $actividad->vendedor_id);
    }
}
