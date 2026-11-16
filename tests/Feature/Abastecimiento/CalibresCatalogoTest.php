<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Variedad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use App\Models\User;
use App\Services\Abastecimiento\EnlaceProductosCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Los calibres de Abastecimiento no tienen catálogo propio: son los calibres del producto del CRM de la
 * empresa (`crm_producto_calibres`) ligado a la variedad (`abs_variedades.producto_id`).
 */
class CalibresCatalogoTest extends TestCase
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

    private function url(string $ruta, string $slug = 'splendidbyporvenir'): string
    {
        return "/api/{$slug}/administration/abastecimiento/{$ruta}";
    }

    private function calibre(string $nombre, int $orden = 0, bool $activo = true, ?Variedad $variedad = null): CrmProductoCalibre
    {
        return $this->crearCalibre($variedad ?? $this->variedad, $nombre, $activo, $orden);
    }

    private function producto(string $nombre, ?string $commodity = 'Mango', array $calibres = [], ?int $empresaId = null, bool $activo = true): CrmProducto
    {
        $p = CrmProducto::create([
            'empresa_id' => $empresaId ?? $this->sbp->id, 'nombre' => $nombre, 'commodity' => $commodity,
            'precio' => 0, 'activo' => $activo,
        ]);
        foreach ($calibres as $i => $c) {
            CrmProductoCalibre::create(['producto_id' => $p->id, 'nombre' => $c, 'orden' => $i]);
        }

        return $p;
    }

    private function variedadDelIndex(int $variedadId, string $query = ''): array
    {
        $res = $this->getJson($this->url('cultivos'.$query))->assertOk();
        $mango = collect($res->json('data'))->firstWhere('nombre', 'Mango');

        return collect($mango['variedades'])->firstWhere('id', $variedadId);
    }

    public function test_ya_no_existe_el_alta_edicion_ni_baja_de_calibres_en_abastecimiento(): void
    {
        $calibre = $this->calibre('Jumbo', 1);

        $this->postJson($this->url("variedades/{$this->variedad->id}/calibres"), ['nombre' => 'Grande'])->assertNotFound();
        $this->putJson($this->url("calibres/{$calibre->id}"), ['nombre' => 'X'])->assertNotFound();
        $this->deleteJson($this->url("calibres/{$calibre->id}"))->assertNotFound();
        $this->assertSame('Jumbo', $calibre->fresh()->nombre);
    }

    public function test_index_trae_los_calibres_del_producto_ordenados_con_en_uso_y_la_liga(): void
    {
        $this->calibre('Grande', 2);
        $this->calibre('Chico', 2);
        $primero = $this->calibre('Jumbo', 1);
        $convenio = $this->crearConvenio($this->sbp, ['variedad_id' => $this->variedad->id]);
        ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $primero->id]);

        $variedad = $this->variedadDelIndex($this->variedad->id);

        $this->assertSame(['Jumbo', 'Chico', 'Grande'], array_column($variedad['calibres'], 'nombre'));
        $this->assertEqualsCanonicalizing(
            ['id', 'producto_id', 'nombre', 'orden', 'is_active', 'en_uso'],
            array_keys($variedad['calibres'][0]),
        );
        $this->assertSame([true, false, false], array_column($variedad['calibres'], 'en_uso'));
        $this->assertSame($this->variedad->fresh()->producto_id, $variedad['producto_id']);
        $this->assertSame('Mango', $variedad['producto']['commodity']);
    }

    public function test_variedad_sin_liga_no_tiene_calibres(): void
    {
        $variedad = $this->variedadDelIndex($this->variedad->id);

        $this->assertNull($variedad['producto_id']);
        $this->assertNull($variedad['producto']);
        $this->assertSame([], $variedad['calibres']);
    }

    public function test_index_activos_oculta_calibres_inactivos(): void
    {
        $this->calibre('Jumbo', 1);
        $this->calibre('Inactivo', 2, false);

        $variedad = $this->variedadDelIndex($this->variedad->id, '?activos=1');

        $this->assertSame(['Jumbo'], array_column($variedad['calibres'], 'nombre'));
    }

    public function test_alta_de_variedad_se_liga_sola_al_producto_con_su_nombre(): void
    {
        $this->producto('Irwin Organic Mango', 'Mango', ['Org']);
        $kent = $this->producto('Irwin Mango', 'Mango', ['Calibre 8', 'Calibre 9']);
        $this->producto('Irwin Mango', 'Mango', [], $this->otra->id);
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();

        // Entre los que dicen «Irwin» con commodity Mango gana el nombre más corto (no el orgánico).
        $this->postJson($this->url("cultivos/{$mango->id}/variedades"), ['nombre' => 'irwin'])
            ->assertCreated()
            ->assertJsonPath('data.producto_id', $kent->id)
            ->assertJsonPath('data.calibres.0.nombre', 'Calibre 8');
    }

    public function test_la_propuesta_prefiere_commodity_igual_y_no_toma_palabras_a_medias(): void
    {
        $this->producto('Glenn Pepper', 'Pepper');
        $glenn = $this->producto('Glenn Mango', 'Mango', ['A']);
        $this->producto('Glennita Mango', 'Mango');
        $variedad = Variedad::create(['cultivo_id' => $this->variedad->cultivo_id, 'nombre' => 'Glenn', 'is_active' => true]);

        $this->assertSame($glenn->id, EnlaceProductosCrm::proponer($variedad, $this->sbp->id)?->id);

        $sinPar = Variedad::create(['cultivo_id' => $this->variedad->cultivo_id, 'nombre' => 'Glen', 'is_active' => true]);
        $this->assertNull(EnlaceProductosCrm::proponer($sinPar, $this->sbp->id));
    }

    public function test_alta_de_variedad_sin_producto_queda_sin_liga(): void
    {
        $mango = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();

        $this->postJson($this->url("cultivos/{$mango->id}/variedades"), ['nombre' => 'Rara'])
            ->assertCreated()
            ->assertJsonPath('data.producto_id', null)
            ->assertJsonPath('data.calibres', []);
    }

    public function test_aplicar_no_cambia_una_liga_ya_puesta(): void
    {
        $manual = $this->producto('Cualquiera');
        $this->variedad->update(['producto_id' => $manual->id]);
        $this->producto($this->variedad->nombre.' Mango');

        EnlaceProductosCrm::aplicar();

        $this->assertSame($manual->id, $this->variedad->fresh()->producto_id);
    }

    public function test_cambiar_la_liga_a_mano_y_quitarla(): void
    {
        $uno = $this->producto('Uno', 'Mango', ['A']);

        $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => $uno->id])
            ->assertOk()
            ->assertJsonPath('data.producto.id', $uno->id)
            ->assertJsonPath('data.calibres.0.nombre', 'A');

        $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => null])
            ->assertOk()
            ->assertJsonPath('data.producto_id', null)
            ->assertJsonPath('data.calibres', []);
    }

    public function test_un_producto_inexistente_o_de_otra_empresa_da_422(): void
    {
        $ajeno = $this->producto('Ajeno', 'Mango', [], $this->otra->id);

        foreach ([99999, $ajeno->id] as $id) {
            $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => $id])
                ->assertStatus(422)->assertJsonValidationErrors(['producto_id']);
        }
        $this->assertNull($this->variedad->fresh()->producto_id);
    }

    public function test_con_un_convenio_por_calibre_no_se_puede_cambiar_la_liga(): void
    {
        $jumbo = $this->calibre('Jumbo', 1);
        $liga = $this->variedad->fresh()->producto_id;
        $convenio = $this->crearConvenio($this->sbp, ['variedad_id' => $this->variedad->id]);
        ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $jumbo->id]);
        $otro = $this->producto('Otro', 'Mango', ['Z']);

        $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => $otro->id])
            ->assertStatus(422)->assertJsonValidationErrors(['producto_id']);
        $this->assertSame($liga, $this->variedad->fresh()->producto_id);

        // Reenviar la misma liga no es un cambio.
        $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => $liga])
            ->assertOk();
    }

    public function test_lista_los_productos_de_la_empresa_para_elegir_la_liga(): void
    {
        $kent = $this->producto('Kent Mango', 'Mango', ['A', 'B']);
        $this->producto('Ajeno', 'Mango', ['A'], $this->otra->id);
        $inactivo = $this->producto('Viejo', 'Mango', [], null, false);

        $res = $this->getJson($this->url('productos-crm'))->assertOk();

        $this->assertSame(['Kent Mango', 'Viejo'], collect($res->json('data'))->pluck('nombre')->all());
        $fila = collect($res->json('data'))->firstWhere('id', $kent->id);
        $this->assertSame(['id' => $kent->id, 'nombre' => 'Kent Mango', 'commodity' => 'Mango', 'calibres' => 2, 'activo' => true], $fila);
        $this->assertFalse(collect($res->json('data'))->firstWhere('id', $inactivo->id)['activo']);
    }

    public function test_borrar_variedad_o_cultivo_no_toca_los_productos_ni_sus_calibres(): void
    {
        $cultivo = Cultivo::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Papaya', 'is_active' => true]);
        $v1 = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Maradol', 'is_active' => true]);
        $v2 = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Roja', 'is_active' => true]);
        $c1 = $this->calibre('Grande', 1, true, $v1);
        $c2 = $this->calibre('Chica', 1, true, $v2);

        $this->deleteJson($this->url("variedades/{$v1->id}"))->assertOk();
        $this->assertTrue(Variedad::withTrashed()->find($v1->id)->trashed());
        $this->assertNotNull($c1->fresh());

        $this->deleteJson($this->url("cultivos/{$cultivo->id}"))->assertOk();
        $this->assertNotNull($c2->fresh());
    }

    public function test_sin_permiso_da_403_y_no_cambia_la_liga(): void
    {
        $uno = $this->producto('Uno', 'Mango', ['A']);
        Sanctum::actingAs($this->captor);
        $this->otorgarAbs($this->captor, $this->sbp, 'variedades', ['view']);

        $this->getJson($this->url('productos-crm'))->assertOk();
        $this->putJson($this->url("variedades/{$this->variedad->id}"), ['nombre' => $this->variedad->nombre, 'producto_id' => $uno->id])->assertForbidden();
        $this->assertNull($this->variedad->fresh()->producto_id);
    }

    public function test_variedad_de_otra_empresa_da_404(): void
    {
        $uno = $this->producto('Uno', 'Mango', ['A']);
        $variedadOtra = Cultivo::where('enterprise_id', $this->otra->id)->firstOrFail()->variedades()->firstOrFail();

        $this->putJson($this->url("variedades/{$variedadOtra->id}"), ['nombre' => $variedadOtra->nombre, 'producto_id' => $uno->id])->assertNotFound();
        $this->assertNull($variedadOtra->fresh()->producto_id);
    }
}
