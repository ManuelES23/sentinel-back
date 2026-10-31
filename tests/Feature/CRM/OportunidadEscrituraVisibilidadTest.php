<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * update, destroy y cambiar-etapa exigen, además de su propio permiso, poder
 * VER la oportunidad (VisibilidadOportunidades, sabor estricto): sin equipo,
 * solo las del vendedor propio. Evita usar PUT {} como oráculo de lectura y
 * que un vendedor se quede con la oportunidad de otro.
 */
class OportunidadEscrituraVisibilidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const BASE_URL = '/api/crm/oportunidades';

    private const MENSAJE_AJENA = 'No puedes modificar las oportunidades de otro vendedor.';

    private CrmVendedor $propio;

    private CrmOportunidad $propia;

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

        $this->propia = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => 'Propia', 'etapa' => 'calificado',
            'monto_esperado' => 100,
        ]);
        $this->ajena = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Ajena', 'etapa' => 'calificado',
            'monto_esperado' => 999, 'notas' => 'Notas secretas',
        ]);
    }

    private function permisos(array $permisos): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', $permisos);
    }

    private function editar(CrmOportunidad $oportunidad, array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->putJson(self::BASE_URL."/{$oportunidad->id}", $datos);
    }

    private function eliminar(CrmOportunidad $oportunidad)
    {
        return $this->withHeaders($this->crmHeaders())->deleteJson(self::BASE_URL."/{$oportunidad->id}");
    }

    private function cambiarEtapa(CrmOportunidad $oportunidad, array $datos)
    {
        return $this->withHeaders($this->crmHeaders())->patchJson(self::BASE_URL."/{$oportunidad->id}/cambiar-etapa", $datos);
    }

    private function assertAjenaIntacta(): void
    {
        $fresca = $this->ajena->fresh();
        $this->assertNotNull($fresca);
        $this->assertSame('Ajena', $fresca->nombre);
        $this->assertSame($this->vendedor->id, (int) $fresca->vendedor_id);
        $this->assertSame('calificado', $fresca->etapa);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_put_vacio_sobre_una_ajena_responde_403_sin_filtrar_la_fila(): void
    {
        $this->permisos(['ver', 'editar']);

        $response = $this->editar($this->ajena, []);

        $response->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);
        $response->assertJsonMissing(['nombre' => 'Ajena']);
        $this->assertStringNotContainsString('Notas secretas', $response->getContent());
        $this->assertAjenaIntacta();
    }

    public function test_un_vendedor_no_puede_quedarse_con_la_oportunidad_de_otro(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->editar($this->ajena, ['vendedor_id' => $this->propio->id, 'nombre' => 'Mía'])->assertForbidden();

        $this->assertAjenaIntacta();
    }

    public function test_editar_una_propia_funciona(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->editar($this->propia, ['nombre' => 'Propia editada'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Propia editada');

        $this->assertSame('Propia editada', $this->propia->fresh()->nombre);
    }

    public function test_sin_equipo_no_puede_asignar_su_oportunidad_a_otro_vendedor(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->editar($this->propia, ['vendedor_id' => $this->vendedor->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes asignar la oportunidad a otro vendedor.');

        $this->assertSame($this->propio->id, (int) $this->propia->fresh()->vendedor_id);
    }

    public function test_sin_equipo_mandar_su_propio_vendedor_funciona(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->editar($this->propia, ['vendedor_id' => $this->propio->id, 'nombre' => 'Igual'])->assertOk();

        $this->assertSame('Igual', $this->propia->fresh()->nombre);
    }

    public function test_con_equipo_edita_y_reasigna_cualquier_oportunidad(): void
    {
        $this->permisos(['ver', 'editar', 'equipo']);

        $this->editar($this->ajena, ['nombre' => 'Ajena editada'])->assertOk()->assertJsonPath('data.nombre', 'Ajena editada');
        $this->editar($this->propia, ['vendedor_id' => $this->vendedor->id])->assertOk();

        $this->assertSame('Ajena editada', $this->ajena->fresh()->nombre);
        $this->assertSame($this->vendedor->id, (int) $this->propia->fresh()->vendedor_id);
    }

    public function test_editar_sin_ver_responde_403_aunque_sea_propia(): void
    {
        $this->permisos(['editar']);

        $this->editar($this->propia, ['nombre' => 'Cambio'])->assertForbidden();

        $this->assertSame('Propia', $this->propia->fresh()->nombre);
    }

    public function test_sin_editar_responde_403_con_el_mensaje_del_permiso_antes_que_el_de_visibilidad(): void
    {
        $this->permisos(['ver']);

        $this->editar($this->ajena, [])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para editar oportunidades.');
    }

    public function test_sin_vendedor_vinculado_y_sin_equipo_no_edita_nada(): void
    {
        $this->propio->update(['activo' => false]);
        $this->permisos(['ver', 'editar']);

        $this->editar($this->propia, ['nombre' => 'Cambio'])->assertForbidden();
        $this->editar($this->ajena, [])->assertForbidden();

        $this->assertSame('Propia', $this->propia->fresh()->nombre);
        $this->assertAjenaIntacta();
    }

    public function test_la_oportunidad_de_otra_empresa_sigue_respondiendo_404(): void
    {
        $otra = $this->crearOtraEmpresa();
        $deOtra = CrmOportunidad::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $this->propio->id, 'nombre' => 'De otra', 'etapa' => 'calificado',
        ]);
        $this->permisos(['ver', 'editar', 'eliminar', 'equipo']);

        $this->editar($deOtra, [])->assertNotFound();
        $this->eliminar($deOtra)->assertNotFound();
        $this->cambiarEtapa($deOtra, ['etapa' => 'propuesta'])->assertNotFound();
        $this->assertSame('calificado', $deOtra->fresh()->etapa);
    }

    // ── destroy ───────────────────────────────────────────────────────────

    public function test_sin_equipo_eliminar_una_ajena_responde_403_y_la_conserva(): void
    {
        $this->permisos(['ver', 'eliminar']);

        $this->eliminar($this->ajena)->assertForbidden()->assertJsonPath('message', self::MENSAJE_AJENA);

        $this->assertAjenaIntacta();
    }

    public function test_sin_equipo_eliminar_una_propia_funciona(): void
    {
        $this->permisos(['ver', 'eliminar']);

        $this->eliminar($this->propia)->assertOk();

        $this->assertNull(CrmOportunidad::find($this->propia->id));
    }

    public function test_con_equipo_elimina_una_ajena(): void
    {
        $this->permisos(['ver', 'eliminar', 'equipo']);

        $this->eliminar($this->ajena)->assertOk();

        $this->assertNull(CrmOportunidad::find($this->ajena->id));
    }

    public function test_eliminar_sin_ver_responde_403(): void
    {
        $this->permisos(['eliminar']);

        $this->eliminar($this->propia)->assertForbidden();

        $this->assertNotNull(CrmOportunidad::find($this->propia->id));
    }

    // ── cambiar-etapa ─────────────────────────────────────────────────────

    public function test_sin_equipo_mover_una_ajena_responde_403(): void
    {
        $this->permisos(['ver', 'editar']);

        $this->cambiarEtapa($this->ajena, ['etapa' => 'propuesta'])
            ->assertForbidden()
            ->assertJsonPath('message', self::MENSAJE_AJENA);

        $this->assertAjenaIntacta();
    }

    public function test_sin_equipo_cerrar_una_ajena_responde_403(): void
    {
        // Perder exige `editar` (Task 4): se concede para que el 403 sea por visibilidad.
        $this->permisos(['ver', 'editar', 'cerrar']);

        $this->cambiarEtapa($this->ajena, ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio'])->assertForbidden();
        $this->cambiarEtapa($this->ajena, ['etapa' => 'cerrado_ganado', 'forzar' => true])->assertForbidden();

        $this->assertAjenaIntacta();
    }

    public function test_sin_equipo_mover_y_cerrar_una_propia_funciona(): void
    {
        $this->permisos(['ver', 'editar', 'cerrar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'propuesta'])->assertOk();
        $this->cambiarEtapa($this->propia, ['etapa' => 'cerrado_perdido', 'motivo_perdida' => 'Precio'])->assertOk();

        $this->assertSame('cerrado_perdido', $this->propia->fresh()->etapa);
    }

    public function test_con_equipo_mueve_una_ajena(): void
    {
        $this->permisos(['ver', 'editar', 'equipo']);

        $this->cambiarEtapa($this->ajena, ['etapa' => 'propuesta'])->assertOk();

        $this->assertSame('propuesta', $this->ajena->fresh()->etapa);
    }

    public function test_cambiar_etapa_sin_ver_responde_403(): void
    {
        $this->permisos(['editar']);

        $this->cambiarEtapa($this->propia, ['etapa' => 'propuesta'])->assertForbidden();

        $this->assertSame('calificado', $this->propia->fresh()->etapa);
    }

    // ── store ─────────────────────────────────────────────────────────────

    private function crear(int $vendedorId)
    {
        return $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'nombre' => 'Nueva', 'cliente_id' => $this->propia->cliente_id, 'vendedor_id' => $vendedorId,
        ]);
    }

    public function test_sin_equipo_no_crea_una_oportunidad_para_otro_vendedor(): void
    {
        Event::fake([OportunidadUpdated::class]);
        $this->permisos(['ver', 'crear']);

        $this->crear($this->vendedor->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes asignar la oportunidad a otro vendedor.');

        $this->assertSame(2, CrmOportunidad::count());
        Event::assertNotDispatched(OportunidadUpdated::class);
    }

    public function test_sin_equipo_crea_oportunidades_para_su_vendedor(): void
    {
        $this->permisos(['crear']);

        $this->crear($this->propio->id)->assertCreated()->assertJsonPath('data.vendedor_id', $this->propio->id);

        $this->assertSame(2, CrmOportunidad::where('vendedor_id', $this->propio->id)->count());
    }

    public function test_sin_equipo_ni_vendedor_activo_no_crea_oportunidades(): void
    {
        $this->propio->update(['activo' => false]);
        $this->permisos(['ver', 'crear']);

        $this->crear($this->propio->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu usuario no está vinculado a un vendedor activo.');

        $this->assertSame(2, CrmOportunidad::count());
    }

    public function test_con_equipo_crea_oportunidades_para_cualquier_vendedor_de_la_empresa(): void
    {
        $this->permisos(['crear', 'equipo']);

        $this->crear($this->vendedor->id)->assertCreated();

        $this->assertSame(2, CrmOportunidad::where('vendedor_id', $this->vendedor->id)->count());
    }

    public function test_el_equipo_de_otra_empresa_no_sirve_para_crear_en_esta(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);
        $this->otorgarTodosLosPermisosCrm($otra);
        $this->permisos(['crear']);

        $this->crear($this->vendedor->id)->assertForbidden();

        $this->assertSame(2, CrmOportunidad::count());
    }
}
