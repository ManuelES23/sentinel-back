<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class FestivosApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/grupoesplendido/rh/festivos';

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloFestivos();
        $this->rh = User::factory()->create();
        Sanctum::actingAs($this->rh);
    }

    public function test_sin_view_no_lista(): void
    {
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_lista_el_anio_pedido_ordenado_por_fecha(): void
    {
        $this->concederFestivos($this->rh, 'view');
        Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad']);
        Holiday::create(['date' => '2026-01-01', 'name' => 'Año Nuevo']);
        Holiday::create(['date' => '2026-06-10', 'name' => 'Aniversario', 'enterprise_id' => $this->empresa->id]);
        Holiday::create(['date' => '2027-01-01', 'name' => 'Año Nuevo 2027']);

        $respuesta = $this->getJson(self::BASE.'?year=2026')->assertOk()->assertJsonPath('meta.year', 2026);

        $this->assertSame(['2026-01-01', '2026-06-10', '2026-12-25'], array_column($respuesta->json('data'), 'date'));
        $this->assertSame('Splendid Farms', $respuesta->json('data.1.enterprise.name'));
        $this->assertNull($respuesta->json('data.0.enterprise'));
    }

    public function test_alta_edicion_y_baja_cada_una_con_su_permiso(): void
    {
        $this->concederFestivos($this->rh, 'create');
        $id = $this->postJson(self::BASE, ['date' => '2026-11-05', 'name' => 'Festivo local'])
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-11-05')
            ->assertJsonPath('data.is_official', false)
            ->json('data.id');

        $this->putJson(self::BASE."/{$id}", ['date' => '2026-11-06', 'name' => 'Festivo local'])->assertForbidden();
        $this->concederFestivos($this->rh, 'edit');
        $this->putJson(self::BASE."/{$id}", ['date' => '2026-11-06', 'name' => 'Festivo movido'])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-11-06')
            ->assertJsonPath('data.name', 'Festivo movido');

        $this->deleteJson(self::BASE."/{$id}")->assertForbidden();
        $this->concederFestivos($this->rh, 'delete');
        $this->deleteJson(self::BASE."/{$id}")->assertOk();
        $this->assertDatabaseMissing('holidays', ['id' => $id]);
    }

    public function test_no_permite_repetir_la_fecha_general_ni_la_de_una_empresa(): void
    {
        $this->concederFestivos($this->rh, 'create', 'edit');
        Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad']);
        Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad SF', 'enterprise_id' => $this->empresa->id]);

        $this->postJson(self::BASE, ['date' => '2026-12-25', 'name' => 'Otra Navidad'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson(self::BASE, ['date' => '2026-12-25', 'name' => 'Otra Navidad SF', 'enterprise_id' => $this->empresa->id])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertSame(2, Holiday::count());
    }

    public function test_la_misma_fecha_en_otra_empresa_si_se_permite_y_editarse_a_si_mismo_tambien(): void
    {
        $this->concederFestivos($this->rh, 'create', 'edit');
        $general = Holiday::create(['date' => '2026-12-25', 'name' => 'Navidad']);

        $this->postJson(self::BASE, ['date' => '2026-12-25', 'name' => 'Navidad SF', 'enterprise_id' => $this->empresa->id])->assertCreated();
        $this->putJson(self::BASE."/{$general->id}", ['date' => '2026-12-25', 'name' => 'Navidad (nuevo nombre)'])->assertOk();
    }

    public function test_valida_el_formato_de_la_fecha_y_el_nombre(): void
    {
        $this->concederFestivos($this->rh, 'create');

        $this->postJson(self::BASE, ['date' => '25/12/2026', 'name' => 'Navidad'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->postJson(self::BASE, ['date' => '2026-12-25', 'name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson(self::BASE, ['date' => '2026-12-25', 'name' => 'X', 'enterprise_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('enterprise_id');
    }

    public function test_carga_los_oficiales_y_es_idempotente(): void
    {
        $this->concederFestivos($this->rh, 'create');

        $this->postJson(self::BASE.'/cargar-oficiales', ['year' => 2027])
            ->assertOk()->assertJsonPath('data.creados', 7)->assertJsonPath('data.existentes', 0);
        $this->postJson(self::BASE.'/cargar-oficiales', ['year' => 2027])
            ->assertOk()->assertJsonPath('data.creados', 0)->assertJsonPath('data.existentes', 7);

        $this->assertSame(7, Holiday::whereNull('enterprise_id')->where('is_official', true)->count());
        $this->postJson(self::BASE.'/cargar-oficiales', ['year' => 1999])->assertStatus(422);
    }

    public function test_mis_permisos(): void
    {
        $this->concederFestivos($this->rh, 'view', 'edit');

        $this->getJson(self::BASE.'/mis-permisos')->assertOk()->assertExactJson([
            'success' => true,
            'data' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        ]);
    }
}
