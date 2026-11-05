<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class CatalogosBasicosTest extends TestCase
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

    public function test_alta_de_cultivo_con_dos_variedades(): void
    {
        $res = $this->postJson($this->url('cultivos'), ['nombre' => 'Zarzamora', 'variedades' => ['Tupy', 'Brazos']])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Zarzamora')
            ->assertJsonCount(2, 'data.variedades');

        $cultivo = Cultivo::findOrFail($res->json('data.id'));
        $this->assertSame($this->sbp->id, $cultivo->enterprise_id);
        $this->assertEqualsCanonicalizing(['Tupy', 'Brazos'], $cultivo->variedades()->pluck('nombre')->all());
    }

    public function test_nombre_de_cultivo_duplicado_responde_422_en_espanol(): void
    {
        $this->postJson($this->url('cultivos'), ['nombre' => 'mango'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre'])
            ->assertJsonPath('errors.nombre.0', 'Ya existe un cultivo con ese nombre.');
    }

    public function test_variedad_duplicada_en_el_cultivo_responde_422_pero_en_otro_cultivo_no(): void
    {
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $existente = $mango->variedades()->firstOrFail();

        $this->postJson($this->url("cultivos/{$mango->id}/variedades"), ['nombre' => $existente->nombre])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'Ya existe una variedad con ese nombre en el cultivo.');

        $otro = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        $this->postJson($this->url("cultivos/{$otro->id}/variedades"), ['nombre' => $existente->nombre])
            ->assertCreated();
    }

    public function test_variedad_en_uso_no_se_borra_pero_se_desactiva(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $variedad = Variedad::findOrFail($convenio->variedad_id);

        $this->deleteJson($this->url("variedades/{$variedad->id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);
        $this->assertNull($variedad->fresh()->deleted_at);

        $this->putJson($this->url("variedades/{$variedad->id}"), ['nombre' => $variedad->nombre, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse($variedad->fresh()->is_active);
    }

    public function test_cultivo_en_uso_por_convenio_o_temporada_no_se_borra(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $this->deleteJson($this->url("cultivos/{$convenio->cultivo_id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);

        // Cultivo solo con temporada (sin convenios) tampoco se borra.
        $papaya = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        \App\Models\Abastecimiento\Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $papaya->id, 'nombre' => 'T1',
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-09-30', 'estado' => 'activa',
        ]);
        $this->deleteJson($this->url("cultivos/{$papaya->id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);
    }

    public function test_cultivo_sin_uso_se_borra_con_sus_variedades(): void
    {
        $res = $this->postJson($this->url('cultivos'), ['nombre' => 'Zarzamora', 'variedades' => ['Tupy']])->assertCreated();
        $id = $res->json('data.id');

        $this->deleteJson($this->url("cultivos/{$id}"))->assertOk()->assertJsonPath('success', true);

        $this->assertSoftDeleted('abs_cultivos', ['id' => $id]);
        $this->assertSame(0, Variedad::where('cultivo_id', $id)->count());
    }

    public function test_get_cultivos_solo_trae_los_de_la_empresa_y_marca_en_uso(): void
    {
        Cultivo::where('enterprise_id', $this->otra->id)->firstOrFail()->update(['nombre' => 'Solo de otra']);
        $convenio = $this->crearConvenio($this->sbp);

        $res = $this->getJson($this->url('cultivos'))->assertOk();

        $nombres = collect($res->json('data'))->pluck('nombre');
        $this->assertFalse($nombres->contains('Solo de otra'));
        $this->assertTrue($nombres->contains('Mango'));

        $mango = collect($res->json('data'))->firstWhere('nombre', 'Mango');
        $this->assertTrue($mango['en_uso']);
        $this->assertTrue(collect($mango['variedades'])->firstWhere('id', $convenio->variedad_id)['en_uso']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('nombre', 'Arándano')['en_uso'] ?? false);
    }

    public function test_get_cultivos_con_activos_filtra_inactivos(): void
    {
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = $mango->variedades()->firstOrFail();
        $variedad->update(['is_active' => false]);
        Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', '!=', 'Mango')->update(['is_active' => false]);

        $todos = $this->getJson($this->url('cultivos'))->assertOk()->json('data');
        $this->assertGreaterThan(1, count($todos));
        $this->assertFalse(collect(collect($todos)->firstWhere('nombre', 'Mango')['variedades'])->firstWhere('id', $variedad->id)['is_active']);

        $activos = $this->getJson($this->url('cultivos?activos=1'))->assertOk()->json('data');
        $this->assertSame(['Mango'], collect($activos)->pluck('nombre')->all());
        $this->assertNull(collect(collect($activos)->first()['variedades'])->firstWhere('id', $variedad->id));
    }

    public function test_actualizar_y_borrar_de_otra_empresa_responde_404(): void
    {
        $tipoOtra = TipoCarga::where('enterprise_id', $this->otra->id)->firstOrFail();
        $cultivoOtra = Cultivo::where('enterprise_id', $this->otra->id)->firstOrFail();
        $variedadOtra = $cultivoOtra->variedades()->firstOrFail();

        $this->putJson($this->url("tipos-carga/{$tipoOtra->id}"), ['nombre' => 'X', 'is_active' => true])->assertNotFound();
        $this->deleteJson($this->url("tipos-carga/{$tipoOtra->id}"))->assertNotFound();
        $this->putJson($this->url("cultivos/{$cultivoOtra->id}"), ['nombre' => 'X', 'is_active' => true])->assertNotFound();
        $this->deleteJson($this->url("cultivos/{$cultivoOtra->id}"))->assertNotFound();
        $this->postJson($this->url("cultivos/{$cultivoOtra->id}/variedades"), ['nombre' => 'X'])->assertNotFound();
        $this->putJson($this->url("variedades/{$variedadOtra->id}"), ['nombre' => 'X', 'is_active' => true])->assertNotFound();
        $this->deleteJson($this->url("variedades/{$variedadOtra->id}"))->assertNotFound();

        $this->assertNotSame('X', $tipoOtra->fresh()->nombre);
    }

    public function test_sin_permiso_create_de_tipos_carga_responde_403(): void
    {
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'tipos-carga', ['view', 'edit']);

        $this->getJson($this->url('tipos-carga'))->assertOk();
        $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima'])->assertForbidden();
        $this->assertSame(0, TipoCarga::where('nombre', 'Tarima')->count());
    }

    public function test_permisos_de_cultivos_se_piden_al_submodulo_variedades(): void
    {
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'tipos-carga', ['view', 'create', 'edit', 'delete']);

        // Los permisos de tipos-carga no sirven para cultivos.
        $this->getJson($this->url('cultivos'))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'variedades', ['view', 'create']);
        $this->getJson($this->url('cultivos'))->assertOk();
        $this->postJson($this->url('cultivos'), ['nombre' => 'Papaya'])->assertCreated();
        $id = Cultivo::where('nombre', 'Papaya')->value('id');
        $this->putJson($this->url("cultivos/{$id}"), ['nombre' => 'Papaya', 'is_active' => true])->assertForbidden();
        $this->deleteJson($this->url("cultivos/{$id}"))->assertForbidden();
    }

    public function test_crear_difunde_abastecimiento_updated(): void
    {
        Event::fake([AbastecimientoUpdated::class]);

        $this->postJson($this->url('cultivos'), ['nombre' => 'Papaya'])->assertCreated();
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'cultivo' && $e->action === 'created');

        $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima', 'peso_kg' => 500])->assertCreated();
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'tipo_carga' && $e->action === 'created');
    }

    public function test_tipo_de_carga_crud_validacion_y_uso(): void
    {
        $res = $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima', 'peso_kg' => 450.5])->assertCreated();
        $id = $res->json('data.id');
        $this->assertEquals(450.5, $res->json('data.peso_kg'));

        $this->postJson($this->url('tipos-carga'), ['nombre' => 'tarima'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un tipo de carga con ese nombre.');
        $this->postJson($this->url('tipos-carga'), ['nombre' => 'Negativo', 'peso_kg' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['peso_kg']);

        $this->putJson($this->url("tipos-carga/{$id}"), ['nombre' => 'Tarima grande', 'peso_kg' => null, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.nombre', 'Tarima grande')->assertJsonPath('data.is_active', false);

        $lista = $this->getJson($this->url('tipos-carga'))->assertOk()->json('data');
        $this->assertContains('Tarima grande', collect($lista)->pluck('nombre')->all());

        $this->deleteJson($this->url("tipos-carga/{$id}"))->assertOk();
        $this->assertSoftDeleted('abs_tipos_carga', ['id' => $id]);

        // Un tipo usado por un convenio no se borra.
        $convenio = $this->crearConvenio($this->sbp);
        $usado = $convenio->precios->first()->tipo_carga_id;
        $this->deleteJson($this->url("tipos-carga/{$usado}"))
            ->assertStatus(422)->assertJsonPath('errors.id.0', self::EN_USO);
    }

    public function test_crear_con_nombre_de_fila_borrada_la_restaura(): void
    {
        // Cultivo borrado, con una variedad borrada.
        $res = $this->postJson($this->url('cultivos'), ['nombre' => 'Zarzamora', 'variedades' => ['Tupy']])->assertCreated();
        $cultivoId = $res->json('data.id');
        $this->deleteJson($this->url("cultivos/{$cultivoId}"))->assertOk();

        $nuevo = $this->postJson($this->url('cultivos'), ['nombre' => 'zarzamora', 'variedades' => ['Tupy', 'Brazos']])
            ->assertCreated();
        $this->assertSame($cultivoId, $nuevo->json('data.id'), 'Restaura la fila en lugar de insertar');
        $this->assertSame('zarzamora', $nuevo->json('data.nombre'));
        $this->assertTrue($nuevo->json('data.is_active'));
        $this->assertCount(2, $nuevo->json('data.variedades'));
        $this->assertSame(1, Cultivo::withTrashed()->where('enterprise_id', $this->sbp->id)->whereRaw('LOWER(nombre) = ?', ['zarzamora'])->count());
        $this->assertSame(2, Variedad::withTrashed()->where('cultivo_id', $cultivoId)->count());

        // Variedad borrada → se restaura al crear de nuevo.
        $variedadId = Variedad::where('cultivo_id', $cultivoId)->where('nombre', 'Brazos')->value('id');
        $this->deleteJson($this->url("variedades/{$variedadId}"))->assertOk();
        $v = $this->postJson($this->url("cultivos/{$cultivoId}/variedades"), ['nombre' => 'Brazos'])->assertCreated();
        $this->assertSame($variedadId, $v->json('data.id'));
        $this->assertNull(Variedad::find($variedadId)->deleted_at);

        // Tipo de carga borrado → se restaura con los datos nuevos.
        $t = $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima', 'peso_kg' => 400])->assertCreated();
        $this->deleteJson($this->url('tipos-carga/'.$t->json('data.id')))->assertOk();
        $t2 = $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima', 'peso_kg' => 500])->assertCreated();
        $this->assertSame($t->json('data.id'), $t2->json('data.id'));
        $this->assertEquals(500, $t2->json('data.peso_kg'));

        // Contra una fila viva sigue el 422.
        $this->postJson($this->url('tipos-carga'), ['nombre' => 'Tarima'])->assertStatus(422);
    }
}
