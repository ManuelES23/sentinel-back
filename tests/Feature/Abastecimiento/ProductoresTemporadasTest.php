<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class ProductoresTemporadasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private const EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function url(string $ruta, string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function cultivo(string $nombre, $empresa = null): Cultivo
    {
        return Cultivo::where('enterprise_id', ($empresa ?? $this->sbp)->id)->where('nombre', $nombre)->firstOrFail();
    }

    private function productor(string $nombre, array $over = [], $empresa = null): Productor
    {
        return Productor::create(array_merge([
            'enterprise_id' => ($empresa ?? $this->sbp)->id,
            'tipo' => 'productor',
            'nombre' => $nombre,
            'is_active' => true,
        ], $over));
    }

    // --- Productores -------------------------------------------------------

    public function test_alta_de_productor_con_cultivos(): void
    {
        $mango = $this->cultivo('Mango');
        $res = $this->postJson($this->url('productores'), [
            'tipo' => 'productor', 'nombre' => 'Rancho El Sol', 'contacto' => 'Ana Pérez',
            'email' => 'ana@elsol.mx', 'cultivo_ids' => [$mango->id],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Rancho El Sol')
            ->assertJsonPath('data.cultivos.0.id', $mango->id)
            ->assertJsonPath('data.convenios_count', 0);

        $productor = Productor::findOrFail($res->json('data.id'));
        $this->assertSame($this->sbp->id, $productor->enterprise_id);
        $this->assertSame([$mango->id], $productor->cultivos()->pluck('abs_cultivos.id')->all());
    }

    public function test_rfc_en_minusculas_se_guarda_en_mayusculas(): void
    {
        $res = $this->postJson($this->url('productores'), ['tipo' => 'empaque', 'nombre' => 'Empaque Norte', 'rfc' => ' abc010203xy1 '])
            ->assertCreated()
            ->assertJsonPath('data.rfc', 'ABC010203XY1');

        $this->assertSame('ABC010203XY1', Productor::findOrFail($res->json('data.id'))->rfc);
    }

    public function test_validacion_de_productor(): void
    {
        $this->postJson($this->url('productores'), ['tipo' => 'otro', 'nombre' => '', 'rfc' => 'ABC', 'email' => 'no-es-correo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo', 'nombre', 'rfc', 'email']);
    }

    public function test_cultivo_ids_solo_acepta_cultivos_vivos_de_la_empresa(): void
    {
        $ajeno = $this->cultivo('Mango', $this->otra);
        $this->postJson($this->url('productores'), ['tipo' => 'productor', 'nombre' => 'X', 'cultivo_ids' => [$ajeno->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cultivo_ids.0']);

        $borrado = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Borrado', 'is_active' => true]);
        $borrado->delete();
        $this->postJson($this->url('productores'), ['tipo' => 'productor', 'nombre' => 'X', 'cultivo_ids' => [$borrado->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cultivo_ids.0']);
    }

    public function test_actualizar_productor_reemplaza_cultivos_y_los_conserva_si_no_vienen(): void
    {
        $mango = $this->cultivo('Mango');
        $otro = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        $productor = $this->productor('Rancho Uno');
        $productor->cultivos()->sync([$mango->id]);

        $this->putJson($this->url("productores/{$productor->id}"), ['tipo' => 'productor', 'nombre' => 'Rancho Uno B'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Rancho Uno B')
            ->assertJsonPath('data.cultivos.0.id', $mango->id);

        $this->putJson($this->url("productores/{$productor->id}"), ['tipo' => 'productor', 'nombre' => 'Rancho Uno B', 'cultivo_ids' => [$otro->id], 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertSame([$otro->id], $productor->cultivos()->pluck('abs_cultivos.id')->all());
    }

    public function test_get_productores_filtra_busca_y_pagina_con_la_empresa(): void
    {
        $this->productor('Huerta Real', ['tipo' => 'empaque', 'contacto' => 'Luis']);
        $this->productor('Rancho Inactivo', ['is_active' => false]);
        $this->productor('Rancho Activo', ['rfc' => 'XYZ010101AAA']);
        $this->productor('De otra empresa', [], $this->otra);

        $this->getJson($this->url('productores'))->assertOk()->assertJsonPath('data.total', 3);
        $this->getJson($this->url('productores?tipo=empaque'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($this->url('productores?activos=1'))->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson($this->url('productores?search=xyz0101'))->assertOk()->assertJsonPath('data.data.0.nombre', 'Rancho Activo');
        $this->getJson($this->url('productores?search=luis'))->assertOk()->assertJsonPath('data.data.0.nombre', 'Huerta Real');
        $this->getJson($this->url('productores?per_page=2'))->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_busqueda_con_comodines_los_toma_literales(): void
    {
        $this->productor('Huerta Real');
        $this->productor('Rancho 100% Orgánico');

        $this->getJson($this->url('productores?search=%25'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.nombre', 'Rancho 100% Orgánico');
        $this->getJson($this->url('productores?search=_'))->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_get_productores_trae_cultivos_y_conteo_de_convenios(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $productor = Productor::findOrFail($convenio->productor_id);
        $productor->cultivos()->sync([$convenio->cultivo_id]);

        $this->getJson($this->url('productores'))
            ->assertOk()
            ->assertJsonPath('data.data.0.convenios_count', 1)
            ->assertJsonPath('data.data.0.en_uso', true)
            ->assertJsonPath('data.data.0.cultivos.0.nombre', 'Mango');
    }

    public function test_list_de_productores_trae_solo_activos_de_la_empresa(): void
    {
        $this->productor('Activo A');
        $this->productor('Inactivo', ['is_active' => false]);
        $this->productor('Ajeno', [], $this->otra);

        $res = $this->getJson($this->url('productores/list'))->assertOk();
        $this->assertSame(['Activo A'], collect($res->json('data'))->pluck('nombre')->all());
        $this->assertSame(['id', 'nombre', 'tipo', 'saldo'], array_keys($res->json('data.0')));
    }

    public function test_posibles_duplicados_por_nombre_sin_acentos_ni_mayusculas_y_por_rfc(): void
    {
        $huerta = $this->productor('Empaque Huerta Real', ['tipo' => 'empaque', 'rfc' => 'HRE010101AB1']);
        $this->productor('Otro Nombre');
        $this->productor('Empaque Huerta Real', [], $this->otra);

        $this->getJson($this->url('productores/posibles-duplicados?nombre='.rawurlencode('empaque huerta real')))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['id' => $huerta->id, 'nombre' => 'Empaque Huerta Real', 'rfc' => 'HRE010101AB1', 'motivo' => 'nombre']);

        $this->getJson($this->url('productores/posibles-duplicados?nombre='.rawurlencode('EMPAQUE  HUERTA REAL')))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson($this->url('productores/posibles-duplicados?rfc=hre010101ab1'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.motivo', 'rfc');

        $acentuado = $this->productor('Cooperativa Agrícola Peña');
        $this->getJson($this->url('productores/posibles-duplicados?nombre='.rawurlencode('cooperativa agricola pena')))
            ->assertOk()->assertJsonPath('data.0.id', $acentuado->id);

        $this->getJson($this->url('productores/posibles-duplicados?nombre='.rawurlencode('empaque huerta real').'&excluir_id='.$huerta->id))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('productores/posibles-duplicados'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_alta_con_nombre_repetido_no_se_bloquea(): void
    {
        $this->productor('Empaque Huerta Real');
        $this->postJson($this->url('productores'), ['tipo' => 'empaque', 'nombre' => 'empaque huerta real'])->assertCreated();
    }

    public function test_productor_con_convenios_no_se_borra(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $this->deleteJson($this->url("productores/{$convenio->productor_id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);
        $this->assertNotNull(Productor::find($convenio->productor_id));
    }

    public function test_productor_sin_convenios_se_borra_con_sus_cultivos(): void
    {
        $productor = $this->productor('Sin uso');
        $productor->cultivos()->sync([$this->cultivo('Mango')->id]);

        $this->deleteJson($this->url("productores/{$productor->id}"))->assertOk()->assertJsonPath('data.id', $productor->id);

        $this->assertSoftDeleted('abs_productores', ['id' => $productor->id]);
        $this->assertSame(0, DB::table('abs_productor_cultivo')->where('productor_id', $productor->id)->count());
    }

    public function test_productor_de_otra_empresa_responde_404(): void
    {
        $ajeno = $this->productor('Ajeno', [], $this->otra);

        $this->putJson($this->url("productores/{$ajeno->id}"), ['tipo' => 'productor', 'nombre' => 'Y'])->assertNotFound();
        $this->deleteJson($this->url("productores/{$ajeno->id}"))->assertNotFound();
        $this->putJson($this->url("productores/{$ajeno->id}", 'otraempresa'), ['tipo' => 'productor', 'nombre' => 'Y'])->assertOk();
    }

    public function test_permisos_de_productores_y_temporadas_son_independientes(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($user, $this->sbp, 'productores', ['view']);
        $this->otorgarAbs($user, $this->sbp, 'temporadas', ['view']);
        Sanctum::actingAs($user);

        $this->getJson($this->url('productores'))->assertOk();
        $this->getJson($this->url('productores/list'))->assertOk();
        $this->getJson($this->url('productores/posibles-duplicados'))->assertOk();
        $this->getJson($this->url('temporadas'))->assertOk();
        $this->postJson($this->url('productores'), ['tipo' => 'productor', 'nombre' => 'X'])->assertForbidden();
        $this->postJson($this->url('temporadas'), [])->assertForbidden();
        $this->deleteJson($this->url('temporadas/1'))->assertForbidden();
    }

    // --- Temporadas --------------------------------------------------------

    private function cuerpoTemporada(array $over = []): array
    {
        return array_merge([
            'cultivo_id' => $this->cultivo('Mango')->id,
            'nombre' => 'Mango 2027',
            'fecha_inicio' => '2027-03-01',
            'fecha_fin' => '2027-08-31',
            'estado' => 'planeada',
        ], $over);
    }

    public function test_alta_de_temporada(): void
    {
        $res = $this->postJson($this->url('temporadas'), $this->cuerpoTemporada())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Mango 2027')
            ->assertJsonPath('data.fecha_inicio', '2027-03-01')
            ->assertJsonPath('data.cultivo.nombre', 'Mango')
            ->assertJsonPath('data.convenios_count', 0);

        $this->assertSame($this->sbp->id, Temporada::findOrFail($res->json('data.id'))->enterprise_id);
    }

    public function test_temporada_con_fin_antes_del_inicio_responde_422_en_fecha_fin(): void
    {
        $this->postJson($this->url('temporadas'), $this->cuerpoTemporada(['fecha_fin' => '2027-02-28']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_fin']);

        // Una temporada de un solo día es válida.
        $this->postJson($this->url('temporadas'), $this->cuerpoTemporada(['fecha_fin' => '2027-03-01']))->assertCreated();
    }

    public function test_validacion_de_temporada(): void
    {
        $this->postJson($this->url('temporadas'), ['estado' => 'abierta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cultivo_id', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado']);

        $ajeno = $this->cultivo('Mango', $this->otra);
        $this->postJson($this->url('temporadas'), $this->cuerpoTemporada(['cultivo_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cultivo_id']);
    }

    public function test_get_temporadas_ordena_activas_planeadas_cerradas_y_cuenta_convenios(): void
    {
        $mango = $this->cultivo('Mango');
        $crear = fn (string $nombre, string $estado, string $inicio) => Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $mango->id, 'nombre' => $nombre,
            'fecha_inicio' => $inicio, 'fecha_fin' => '2030-12-31', 'estado' => $estado,
        ]);
        $crear('Cerrada nueva', 'cerrada', '2026-01-01');
        $crear('Planeada vieja', 'planeada', '2025-01-01');
        $crear('Activa vieja', 'activa', '2025-02-01');
        $crear('Activa nueva', 'activa', '2026-02-01');
        $crear('Planeada nueva', 'planeada', '2026-03-01');
        Temporada::create([
            'enterprise_id' => $this->otra->id, 'cultivo_id' => $this->cultivo('Mango', $this->otra)->id, 'nombre' => 'Ajena',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'estado' => 'activa',
        ]);

        $res = $this->getJson($this->url('temporadas'))->assertOk();
        $this->assertSame(
            ['Activa nueva', 'Activa vieja', 'Planeada nueva', 'Planeada vieja', 'Cerrada nueva'],
            collect($res->json('data'))->pluck('nombre')->all(),
        );

        $convenio = $this->crearConvenio($this->sbp);
        $fila = collect($this->getJson($this->url('temporadas'))->json('data'))->firstWhere('id', $convenio->temporada_id);
        $this->assertSame(1, $fila['convenios_count']);
        $this->assertTrue($fila['en_uso']);
        $this->assertSame('Mango', $fila['cultivo']['nombre']);
    }

    public function test_actualizar_temporada(): void
    {
        $id = $this->postJson($this->url('temporadas'), $this->cuerpoTemporada())->json('data.id');

        $this->putJson($this->url("temporadas/{$id}"), $this->cuerpoTemporada(['nombre' => 'Mango 2027 B', 'estado' => 'activa']))
            ->assertOk()
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('data.nombre', 'Mango 2027 B');

        $this->putJson($this->url("temporadas/{$id}"), $this->cuerpoTemporada(['fecha_fin' => '2027-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_fin']);
    }

    public function test_temporada_con_convenios_no_cambia_de_cultivo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otro = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        $temporada = Temporada::findOrFail($convenio->temporada_id);

        $this->putJson($this->url("temporadas/{$temporada->id}"), $this->cuerpoTemporada(['cultivo_id' => $otro->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['cultivo_id']);
        $this->putJson($this->url("temporadas/{$temporada->id}"), $this->cuerpoTemporada(['cultivo_id' => $convenio->cultivo_id, 'estado' => 'cerrada']))
            ->assertOk()->assertJsonPath('data.estado', 'cerrada');
    }

    public function test_temporada_con_convenios_no_se_borra(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $this->deleteJson($this->url("temporadas/{$convenio->temporada_id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);
    }

    public function test_temporada_sin_convenios_se_borra(): void
    {
        $id = $this->postJson($this->url('temporadas'), $this->cuerpoTemporada())->json('data.id');

        $this->deleteJson($this->url("temporadas/{$id}"))->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSoftDeleted('abs_temporadas', ['id' => $id]);
    }

    public function test_temporada_de_otra_empresa_responde_404(): void
    {
        $ajena = Temporada::create([
            'enterprise_id' => $this->otra->id, 'cultivo_id' => $this->cultivo('Mango', $this->otra)->id, 'nombre' => 'Ajena',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'estado' => 'activa',
        ]);

        $this->putJson($this->url("temporadas/{$ajena->id}"), $this->cuerpoTemporada())->assertNotFound();
        $this->deleteJson($this->url("temporadas/{$ajena->id}"))->assertNotFound();
    }

    public function test_productores_y_temporadas_difunden_abastecimiento_updated(): void
    {
        Event::fake([AbastecimientoUpdated::class]);

        $id = $this->postJson($this->url('productores'), ['tipo' => 'productor', 'nombre' => 'Difunde'])->assertCreated()->json('data.id');
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'productor' && $e->action === 'created');

        $this->putJson($this->url("productores/{$id}"), ['tipo' => 'productor', 'nombre' => 'Difunde 2'])->assertOk();
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'productor' && $e->action === 'updated');

        $this->deleteJson($this->url("productores/{$id}"))->assertOk();
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'productor' && $e->action === 'deleted');

        $this->postJson($this->url('temporadas'), $this->cuerpoTemporada())->assertCreated();
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'temporada' && $e->action === 'created');
    }
}
