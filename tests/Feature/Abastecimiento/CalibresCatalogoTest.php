<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class CalibresCatalogoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private const EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    private User $admin;

    private Variedad $variedad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $this->variedad = $mango->variedades()->orderBy('id')->firstOrFail();
    }

    private function url(string $ruta, string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function calibre(string $nombre, int $orden = 0, bool $activo = true, ?Variedad $variedad = null): VariedadCalibre
    {
        return VariedadCalibre::create([
            'variedad_id' => ($variedad ?? $this->variedad)->id,
            'nombre' => $nombre, 'orden' => $orden, 'is_active' => $activo,
        ]);
    }

    public function test_crear_calibre_asigna_orden_consecutivo(): void
    {
        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'Jumbo'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Jumbo')
            ->assertJsonPath('data.orden', 1)
            ->assertJsonPath('data.en_uso', false)
            ->assertJsonPath('data.variedad_id', $this->variedad->id);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'Grande'])
            ->assertCreated()
            ->assertJsonPath('data.orden', 2);
    }

    public function test_nombre_duplicado_sin_distinguir_mayusculas_da_422(): void
    {
        $this->calibre('Jumbo', 1);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'jumbo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre'])
            ->assertJsonPath('errors.nombre.0', 'Ya existe un calibre con ese nombre en la variedad.');
    }

    public function test_alta_con_nombre_de_uno_borrado_lo_restaura_activo(): void
    {
        $viejo = $this->calibre('Jumbo', 1, false);
        $viejo->delete();

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'JUMBO'])
            ->assertCreated()
            ->assertJsonPath('data.id', $viejo->id)
            ->assertJsonPath('data.nombre', 'JUMBO')
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse($viejo->fresh()->trashed());
        $this->assertSame(1, VariedadCalibre::withTrashed()->where('variedad_id', $this->variedad->id)->count());
    }

    public function test_renombrar_y_desactivar(): void
    {
        $calibre = $this->calibre('Jumbo', 1);
        $otro = $this->calibre('Grande', 2);

        $this->putJson($this->url("calibres/{$calibre->id}"), ['nombre' => 'Jumbo XL', 'is_active' => false, 'orden' => 5])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Jumbo XL')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.orden', 5);

        $this->putJson($this->url("calibres/{$calibre->id}"), ['nombre' => 'grande'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nombre.0', 'Ya existe un calibre con ese nombre en la variedad.');

        $this->assertSame('Jumbo XL', $calibre->fresh()->nombre);
        $this->assertFalse($calibre->fresh()->is_active);
        $this->assertSame('Grande', $otro->fresh()->nombre);
    }

    public function test_eliminar_calibre_en_uso_da_422(): void
    {
        $calibre = $this->calibre('Jumbo', 1);
        $convenio = $this->crearConvenio($this->sbp, ['variedad_id' => $this->variedad->id]);
        ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $calibre->id, 'volumen_total' => 10]);

        $this->deleteJson($this->url("calibres/{$calibre->id}"))
            ->assertStatus(422)
            ->assertJsonPath('errors.id.0', self::EN_USO);
        $this->assertFalse($calibre->fresh()->trashed());

        $libre = $this->calibre('Chico', 2);
        $this->deleteJson($this->url("calibres/{$libre->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $libre->id);
        $this->assertTrue(VariedadCalibre::withTrashed()->find($libre->id)->trashed());
    }

    public function test_index_trae_calibres_por_variedad_ordenados_y_con_en_uso(): void
    {
        $segundo = $this->calibre('Grande', 2);
        $this->calibre('Chico', 2);
        $primero = $this->calibre('Jumbo', 1);
        $convenio = $this->crearConvenio($this->sbp, ['variedad_id' => $this->variedad->id]);
        ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $primero->id]);

        $res = $this->getJson($this->url('cultivos'))->assertOk();
        $variedad = collect($res->json('data'))->firstWhere('nombre', 'Mango');
        $variedad = collect($variedad['variedades'])->firstWhere('id', $this->variedad->id);

        $this->assertSame(['Jumbo', 'Chico', 'Grande'], array_column($variedad['calibres'], 'nombre'));
        $this->assertEqualsCanonicalizing(
            ['id', 'variedad_id', 'nombre', 'orden', 'is_active', 'en_uso'],
            array_keys($variedad['calibres'][0]),
        );
        $this->assertSame([true, false, false], array_column($variedad['calibres'], 'en_uso'));
        $this->assertSame($segundo->id, $variedad['calibres'][2]['id']);
    }

    public function test_index_activos_oculta_calibres_inactivos(): void
    {
        $this->calibre('Jumbo', 1);
        $this->calibre('Inactivo', 2, false);

        $res = $this->getJson($this->url('cultivos?activos=1'))->assertOk();
        $mango = collect($res->json('data'))->firstWhere('nombre', 'Mango');
        $variedad = collect($mango['variedades'])->firstWhere('id', $this->variedad->id);

        $this->assertSame(['Jumbo'], array_column($variedad['calibres'], 'nombre'));
    }

    public function test_sin_permiso_da_403(): void
    {
        $calibre = $this->calibre('Jumbo', 1);
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'variedades', ['view']);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'Grande'])->assertForbidden();
        $this->putJson($this->url("calibres/{$calibre->id}"), ['nombre' => 'X'])->assertForbidden();
        $this->deleteJson($this->url("calibres/{$calibre->id}"))->assertForbidden();
        $this->assertSame(1, VariedadCalibre::count());
    }

    public function test_calibre_de_otra_empresa_da_404(): void
    {
        $cultivoOtra = Cultivo::where('enterprise_id', $this->otra->id)->firstOrFail();
        $variedadOtra = $cultivoOtra->variedades()->firstOrFail();
        $calibreOtro = $this->calibre('Ajeno', 1, true, $variedadOtra);

        $this->postJson($this->url("variedades/{$variedadOtra->id}/calibres"), ['nombre' => 'X'])->assertNotFound();
        $this->putJson($this->url("calibres/{$calibreOtro->id}"), ['nombre' => 'X'])->assertNotFound();
        $this->deleteJson($this->url("calibres/{$calibreOtro->id}"))->assertNotFound();
        $this->assertSame('Ajeno', $calibreOtro->fresh()->nombre);
    }

    public function test_borrar_variedad_borra_sus_calibres(): void
    {
        $cultivo = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        $v1 = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Maradol', 'is_active' => true]);
        $v2 = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Roja', 'is_active' => true]);
        $c1 = $this->calibre('Grande', 1, true, $v1);
        $c2 = $this->calibre('Chica', 1, true, $v2);

        $this->deleteJson($this->url("variedades/{$v1->id}"))->assertOk();
        $this->assertTrue(VariedadCalibre::withTrashed()->find($c1->id)->trashed());
        $this->assertFalse($c2->fresh()->trashed());

        $this->deleteJson($this->url("cultivos/{$cultivo->id}"))->assertOk();
        $this->assertTrue(VariedadCalibre::withTrashed()->find($c2->id)->trashed());
    }
}
