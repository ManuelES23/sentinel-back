<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\Calibre;
use App\Models\Cultivo as CultivoSf;
use App\Models\User;
use App\Models\Variedad as VariedadSf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** Importar a una variedad de Abastecimiento los calibres de la variedad homónima de Splendid Farms. */
class CalibresDeSplendidFarmsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private Variedad $variedad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $this->variedad = $mango->variedades()->orderBy('id')->firstOrFail();
    }

    private function url(string $ruta): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/{$ruta}";
    }

    private function sf(string $cultivo, string $variedad, array $calibres): VariedadSf
    {
        $c = CultivoSf::firstOrCreate(['nombre' => $cultivo]);
        $v = VariedadSf::create(['cultivo_id' => $c->id, 'nombre' => $variedad, 'user_id' => User::factory()->create()->id]);
        foreach ($calibres as $i => $datos) {
            [$nombre, $activo] = is_array($datos) ? $datos : [$datos, true];
            Calibre::create([
                'cultivo_id' => $c->id, 'variedad_id' => $v->id, 'nombre' => $nombre,
                'valor' => $nombre, 'is_active' => $activo, 'order' => $i,
            ]);
        }

        return $v;
    }

    private function disponibles(?Variedad $v = null)
    {
        return $this->getJson($this->url('variedades/'.($v ?? $this->variedad)->id.'/calibres/splendid-farms'));
    }

    public function test_lista_los_calibres_activos_de_la_variedad_del_mismo_nombre(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Calibre 10', ['Calibre 12', false], 'calibre 10', 'Malla']);
        $this->sf('Mango', 'Otra variedad', ['Calibre 99']);

        $this->disponibles()->assertOk()
            ->assertJsonPath('data.origen.cultivo', 'Mango')
            ->assertJsonPath('data.origen.variedad', $this->variedad->nombre)
            ->assertJsonPath('data.calibres', [
                ['nombre' => 'Calibre 10', 'ya_existe' => false],
                ['nombre' => 'Malla', 'ya_existe' => false],
            ]);
    }

    public function test_marca_los_que_ya_existen_incluso_inactivos_y_sin_acentos_ni_mayusculas(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Calibre 10', 'Calibre 12', 'Jumbo']);
        VariedadCalibre::create(['variedad_id' => $this->variedad->id, 'nombre' => 'CALIBRE 10', 'orden' => 1, 'is_active' => false]);

        $this->disponibles()->assertOk()
            ->assertJsonPath('data.calibres.0.ya_existe', true)
            ->assertJsonPath('data.calibres.1.ya_existe', false);
    }

    public function test_el_cultivo_de_abastecimiento_puede_ser_el_inicio_del_de_splendid_farms(): void
    {
        $elote = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Elote')->firstOrFail();
        $amarillo = $elote->variedades()->where('nombre', 'Amarillo')->firstOrFail();
        $this->sf('Elote Dulce', 'Amarillo', ['4 DOCENAS']);
        $this->sf('Elotes Raros', 'Amarillo', ['No debe salir']);

        $this->disponibles($amarillo)->assertOk()
            ->assertJsonPath('data.origen.cultivo', 'Elote Dulce')
            ->assertJsonCount(1, 'data.calibres')
            ->assertJsonPath('data.calibres.0.nombre', '4 DOCENAS');
    }

    public function test_con_dos_candidatas_gana_la_de_mas_calibres_activos(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Uno']);
        $this->sf('Mango Industrial', $this->variedad->nombre, ['Dos', 'Tres']);

        $this->disponibles()->assertOk()
            ->assertJsonPath('data.origen.cultivo', 'Mango Industrial')
            ->assertJsonCount(2, 'data.calibres');
    }

    public function test_sin_variedad_de_origen_devuelve_origen_nulo(): void
    {
        $this->disponibles()->assertOk()
            ->assertJsonPath('data.origen', null)
            ->assertJsonPath('data.calibres', []);
    }

    public function test_importar_crea_los_elegidos_con_orden_consecutivo_y_omite_los_existentes(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Calibre 10', 'Calibre 12', 'Calibre 14']);
        VariedadCalibre::create(['variedad_id' => $this->variedad->id, 'nombre' => 'Calibre 12', 'orden' => 7, 'is_active' => true]);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres/importar"), [
            'nombres' => ['Calibre 10', 'calibre 12', 'Calibre 14', 'CALIBRE 10'],
        ])->assertOk()
            ->assertJsonPath('data.creados', 2)
            ->assertJsonPath('data.omitidos', 1)
            ->assertJsonPath('message', '2 calibres importados.');

        $nombres = VariedadCalibre::where('variedad_id', $this->variedad->id)->orderBy('orden')->pluck('nombre', 'orden')->all();
        $this->assertSame([7 => 'Calibre 12', 8 => 'Calibre 10', 9 => 'Calibre 14'], $nombres);
    }

    public function test_importar_restaura_uno_borrado_y_lo_deja_activo(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Jumbo']);
        $viejo = VariedadCalibre::create(['variedad_id' => $this->variedad->id, 'nombre' => 'jumbo', 'orden' => 1, 'is_active' => false]);
        $viejo->delete();

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres/importar"), ['nombres' => ['Jumbo']])
            ->assertOk()->assertJsonPath('data.creados', 1);

        $this->assertFalse($viejo->fresh()->trashed());
        $this->assertTrue($viejo->fresh()->is_active);
        $this->assertSame('Jumbo', $viejo->fresh()->nombre);
        $this->assertSame(1, VariedadCalibre::withTrashed()->where('variedad_id', $this->variedad->id)->count());
    }

    public function test_importar_rechaza_un_nombre_que_no_esta_en_splendid_farms(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Jumbo']);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres/importar"), ['nombres' => ['Jumbo', 'Inventado']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombres.1' => 'Ese calibre no está en el catálogo de Splendid Farms.']);

        $this->assertSame(0, VariedadCalibre::count());
    }

    public function test_importar_sin_nombres_da_422(): void
    {
        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres/importar"), ['nombres' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['nombres']);
    }

    public function test_permisos_y_empresa(): void
    {
        $this->sf('Mango', $this->variedad->nombre, ['Jumbo']);
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'variedades', ['view']);

        $this->disponibles()->assertOk();
        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres/importar"), ['nombres' => ['Jumbo']])->assertForbidden();
        $this->assertSame(0, VariedadCalibre::count());

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $ajena = Cultivo::where('enterprise_id', $this->otra->id)->firstOrFail()->variedades()->firstOrFail();
        $this->disponibles($ajena)->assertNotFound();
        $this->postJson($this->url("variedades/{$ajena->id}/calibres/importar"), ['nombres' => ['Jumbo']])->assertNotFound();
    }
}
