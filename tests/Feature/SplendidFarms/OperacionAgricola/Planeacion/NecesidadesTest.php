<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Captura de necesidades de ventas (renglones de pedido por cliente, bodega
 * y presentación) del Programa semanal.
 */
class NecesidadesTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/operacion-agricola/planeacion';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function comoVendedor(array $extra = []): void
    {
        Sanctum::actingAs($this->usuarioCon(array_merge(['view', 'capturar-ventas'], $extra)));
    }

    private function url(?ProgramaSemanal $p = null, string $sufijo = ''): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/necesidades'.$sufijo;
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    /** Payload válido de un pedido de Walmart con variedades Amarillo + Bicolor. */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'bodega_id' => $this->bodega('Nogales')->id,
            'cliente_nombre' => 'Walmart',
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            'cajas' => [0, 850, 0, 8500, 1520, 0],
        ], $cambios);
    }

    private function empresaSbp(): Enterprise
    {
        return Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            ['name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true],
        );
    }

    public function test_crea_necesidad_ama_bi(): void
    {
        $this->comoVendedor();

        $this->postJson($this->url(), $this->datos())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cultivo_id', $this->cultivo->id)
            ->assertJsonPath('data.cajas', [0, 850, 0, 8500, 1520, 0]);

        $necesidad = ProgramaNecesidad::firstOrFail();
        $this->assertSame($this->programa->id, $necesidad->programa_id);
        $this->assertSame($this->cultivo->id, $necesidad->cultivo_id);
        $this->assertSame([0, 850, 0, 8500, 1520, 0], $necesidad->cajasPorDia());
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $necesidad->variedades()->pluck('variedades.id')->all(),
        );

        $data = $this->semana();
        $grupoAb = (string) $this->grupo('Amarillo / Bicolor')->id;
        $this->assertSame([0, 850, 0, 8500, 1520, 0], $data['balance'][$grupoAb]['necesidad']);
        $this->assertEquals($grupoAb, $data['necesidades'][0]['grupo_id']);
    }

    public function test_variedades_de_grupos_distintos_es_422(): void
    {
        $this->comoVendedor();

        $this->postJson($this->url(), $this->datos([
            'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Blanco']->id],
        ]))->assertUnprocessable()
            ->assertJsonPath('errors.variedad_ids.0', 'Las variedades deben ser del mismo grupo de balance');

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_cliente_texto_libre_queda_fuera_del_crm(): void
    {
        $this->comoVendedor();

        $this->postJson($this->url(), $this->datos(['cliente_nombre' => '  Cliente Nuevo  ']))->assertCreated();

        $necesidad = ProgramaNecesidad::firstOrFail();
        $this->assertNull($necesidad->cliente_id);
        $this->assertSame('Cliente Nuevo', $necesidad->cliente_nombre);

        $renglon = $this->semana()['necesidades'][0];
        $this->assertFalse($renglon['en_crm']);
        $this->assertSame('Cliente Nuevo', $renglon['cliente_nombre']);
    }

    public function test_cliente_del_crm_toma_el_nombre_de_ahi(): void
    {
        $this->comoVendedor();
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart de México']);

        $this->postJson($this->url(), $this->datos(['cliente_id' => $cliente->id, 'cliente_nombre' => 'Otro nombre']))
            ->assertCreated();

        $necesidad = ProgramaNecesidad::firstOrFail();
        $this->assertSame($cliente->id, $necesidad->cliente_id);
        $this->assertSame('Walmart de México', $necesidad->cliente_nombre);
        $this->assertTrue($this->semana()['necesidades'][0]['en_crm']);
    }

    public function test_cliente_de_otra_empresa_es_422(): void
    {
        $this->comoVendedor();
        $this->empresaSbp();
        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        $ajeno = CrmCliente::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno']);

        $datos = $this->datos(['cliente_id' => $ajeno->id]);
        unset($datos['cliente_nombre']);

        $this->postJson($this->url(), $datos)->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_validaciones_de_campos(): void
    {
        $this->comoVendedor();

        // Sin cliente_id el nombre es obligatorio.
        $sinNombre = $this->datos();
        unset($sinNombre['cliente_nombre']);
        $this->postJson($this->url(), $sinNombre)->assertUnprocessable()->assertJsonValidationErrors('cliente_nombre');

        $this->postJson($this->url(), $this->datos(['cliente_nombre' => str_repeat('a', 151)]))
            ->assertUnprocessable()->assertJsonValidationErrors('cliente_nombre');

        // Exactamente seis enteros no negativos.
        foreach ([[1, 2, 3], [1, 2, 3, 4, 5, 6, 7]] as $cajas) {
            $this->postJson($this->url(), $this->datos(['cajas' => $cajas]))
                ->assertUnprocessable()->assertJsonValidationErrors('cajas');
        }
        foreach ([[1, 2, 3, 4, 5, -1], [1, 2, 3, 4, 5, 1.5], [1, 2, 3, 4, 5, 1000000]] as $cajas) {
            $this->postJson($this->url(), $this->datos(['cajas' => $cajas]))
                ->assertUnprocessable()->assertJsonValidationErrors('cajas.5');
        }

        // Variedades: al menos una, sin repetir.
        $this->postJson($this->url(), $this->datos(['variedad_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('variedad_ids');
        $amarillo = $this->variedades['Amarillo']->id;
        $this->postJson($this->url(), $this->datos(['variedad_ids' => [$amarillo, $amarillo]]))
            ->assertUnprocessable()->assertJsonValidationErrors('variedad_ids.0');

        // Variedad de otro cultivo.
        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);
        $this->postJson($this->url(), $this->datos(['variedad_ids' => [$ajena->id]]))->assertUnprocessable()->assertJsonValidationErrors('variedad_ids');

        // Catálogos inactivos.
        $bodega = $this->bodega('Nogales');
        $bodega->update(['is_active' => false]);
        $this->postJson($this->url(), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('bodega_id');
        $bodega->update(['is_active' => true]);

        $tipo = $this->tipoEmpaque('RPC');
        $tipo->update(['is_active' => false]);
        $this->postJson($this->url(), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('tipo_empaque_id');
        $tipo->update(['is_active' => true]);

        $presentacion = $this->presentacion('Bulk');
        $presentacion->update(['is_active' => false]);
        $this->postJson($this->url(), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('presentacion_id');

        $this->assertSame(0, ProgramaNecesidad::count());
    }

    public function test_actualiza_un_dia(): void
    {
        $this->comoVendedor();
        $necesidad = $this->necesidad($this->programa, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [100, 200, 300, 400, 500, 600]);

        $this->putJson(self::BASE.'/necesidades/'.$necesidad->id, ['dia' => 2, 'cajas' => 5584])
            ->assertOk()
            ->assertJsonPath('data.cajas', [100, 200, 5584, 400, 500, 600]);

        $this->assertSame([100, 200, 5584, 400, 500, 600], $necesidad->fresh()->cajasPorDia());
    }

    public function test_actualiza_solo_las_cajas_y_los_campos_completos(): void
    {
        $this->comoVendedor();
        $necesidad = $this->necesidad($this->programa, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [100, 200, 300, 400, 500, 600]);
        $url = self::BASE.'/necesidades/'.$necesidad->id;

        $this->putJson($url, ['cajas' => [1, 2, 3, 4, 5, 6]])->assertOk();
        $this->assertSame([1, 2, 3, 4, 5, 6], $necesidad->fresh()->cajasPorDia());
        $this->assertSame('Vallarta', $necesidad->fresh()->cliente_nombre);

        $this->putJson($url, $this->datos())->assertOk();
        $fresca = $necesidad->fresh();
        $this->assertSame('Walmart', $fresca->cliente_nombre);
        $this->assertEqualsCanonicalizing(
            [$this->variedades['Amarillo']->id, $this->variedades['Bicolor']->id],
            $fresca->variedades()->pluck('variedades.id')->all(),
        );
        $this->assertSame([0, 850, 0, 8500, 1520, 0], $fresca->cajasPorDia());

        // Día fuera de rango, cajas inválidas.
        $this->putJson($url, ['dia' => 6, 'cajas' => 1])->assertUnprocessable()->assertJsonValidationErrors('dia');
        $this->putJson($url, ['dia' => 1, 'cajas' => -3])->assertUnprocessable()->assertJsonValidationErrors('cajas');
        $this->putJson($url, ['cajas' => [1, 2]])->assertUnprocessable()->assertJsonValidationErrors('cajas');
    }

    public function test_un_id_inexistente_o_virtual_es_404(): void
    {
        $this->comoVendedor();

        $this->putJson(self::BASE.'/necesidades/-5', ['dia' => 1, 'cajas' => 3])->assertNotFound();
        $this->deleteJson(self::BASE.'/necesidades/99999')->assertNotFound();
    }

    public function test_borrar_una_necesidad(): void
    {
        $this->comoVendedor();
        $necesidad = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1480, 0, 0, 0, 0, 0]);

        $this->deleteJson(self::BASE.'/necesidades/'.$necesidad->id)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, ProgramaNecesidad::count());
        $this->assertSame([], $this->semana()['necesidades']);
    }

    public function test_con_junta_cada_escritura_deja_historial_con_las_mismas_llaves(): void
    {
        $this->comoVendedor();
        $this->programa->update(['junta_registrada_at' => now()]);

        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        $this->putJson(self::BASE.'/necesidades/'.$id, ['dia' => 0, 'cajas' => 77])->assertOk();
        // Sin cambio real no hay escritura ni historial.
        $this->putJson(self::BASE.'/necesidades/'.$id, ['dia' => 0, 'cajas' => 77])->assertOk();
        $this->deleteJson(self::BASE.'/necesidades/'.$id)->assertOk();

        $cambios = ProgramaCambio::where('entidad', 'necesidad')->orderBy('id')->get();
        $this->assertSame(['creado', 'actualizado', 'borrado'], $cambios->pluck('accion')->all());
        $this->assertSame($id, $cambios[0]->entidad_id);
        $this->assertNull($cambios[0]->antes);
        $llaves = array_keys($cambios[0]->despues);
        $this->assertSame($llaves, array_keys($cambios[1]->antes));
        $this->assertSame($llaves, array_keys($cambios[1]->despues));
        $this->assertSame($llaves, array_keys($cambios[2]->antes));
        $this->assertNull($cambios[2]->despues);
        $this->assertSame(0, $cambios[1]->antes['cajas'][0]);
        $this->assertSame(77, $cambios[1]->despues['cajas'][0]);
    }

    public function test_copiar_semana_anterior(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->cargarWk48($anterior); // 12 renglones
        $cliente = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Cliente CRM']);
        ProgramaNecesidad::where('programa_id', $anterior->id)->first()->update(['cliente_id' => $cliente->id, 'cliente_nombre' => 'Cliente CRM']);
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $anterior->id)->count());

        $this->postJson($this->url(null, '/copiar-anterior'))
            ->assertOk()
            ->assertJsonPath('data.copiadas', 12);

        $copias = ProgramaNecesidad::with('variedades')->where('programa_id', $this->programa->id)->orderBy('id')->get();
        $origen = ProgramaNecesidad::with('variedades')->where('programa_id', $anterior->id)->orderBy('id')->get();
        $this->assertCount(12, $copias);
        foreach ($origen as $i => $o) {
            $c = $copias[$i];
            $this->assertSame($o->bodega_id, $c->bodega_id);
            $this->assertSame($o->cliente_id, $c->cliente_id);
            $this->assertSame($o->cliente_nombre, $c->cliente_nombre);
            $this->assertSame($o->cultivo_id, $c->cultivo_id);
            $this->assertSame($o->presentacion_id, $c->presentacion_id);
            $this->assertSame($o->tipo_empaque_id, $c->tipo_empaque_id);
            $this->assertSame($o->cajasPorDia(), $c->cajasPorDia());
            $this->assertEqualsCanonicalizing($o->variedades->pluck('id')->all(), $c->variedades->pluck('id')->all());
        }

        // La semana anterior no se toca y la segunda vez ya no copia.
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $anterior->id)->count());
        $this->postJson($this->url(null, '/copiar-anterior'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.semana.0', 'Esta semana ya tiene necesidades');
        $this->assertSame(12, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
    }

    public function test_copiar_sin_semana_anterior_o_vacia_es_422(): void
    {
        $this->comoVendedor();

        $this->postJson($this->url(null, '/copiar-anterior'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.semana.0', 'La semana anterior no tiene necesidades');

        // Existe pero sin renglones; y una semana de hace 14 días no cuenta.
        ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->postJson($this->url(null, '/copiar-anterior'))->assertUnprocessable();

        $lejana = ProgramaSemanal::create(['lunes' => '2026-11-02']);
        $this->cargarWk48($lejana);
        $this->postJson($this->url(null, '/copiar-anterior'))->assertUnprocessable();
        $this->assertSame(0, ProgramaNecesidad::where('programa_id', $this->programa->id)->count());
    }

    public function test_escritura_emite_programa_updated(): void
    {
        $this->comoVendedor();
        Event::fake([ProgramaSemanalUpdated::class]);

        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);

        $this->putJson(self::BASE.'/necesidades/'.$id, ['dia' => 1, 'cajas' => 9])->assertOk();
        $this->deleteJson(self::BASE.'/necesidades/'.$id)->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 3);
    }

    public function test_copiar_semana_anterior_emite_un_solo_evento(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $this->cargarWk48($anterior); // 12 renglones
        Event::fake([ProgramaSemanalUpdated::class]);

        $this->postJson($this->url(null, '/copiar-anterior'))->assertOk()->assertJsonPath('data.copiadas', 12);

        // Un aviso por programa y petición, no uno por renglón.
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 1);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);

        // La siguiente escritura vuelve a avisar.
        $id = ProgramaNecesidad::where('programa_id', $this->programa->id)->value('id');
        $this->putJson(self::BASE.'/necesidades/'.$id, ['dia' => 1, 'cajas' => 9])->assertOk();
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 2);
    }

    public function test_sin_permiso_capturar_ventas_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'capturar-corte']));
        $necesidad = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0]);

        $this->postJson($this->url(), $this->datos())->assertForbidden();
        $this->putJson(self::BASE.'/necesidades/'.$necesidad->id, ['dia' => 0, 'cajas' => 5])->assertForbidden();
        $this->deleteJson(self::BASE.'/necesidades/'.$necesidad->id)->assertForbidden();
        $this->postJson($this->url(null, '/copiar-anterior'))->assertForbidden();

        $this->assertSame(1, ProgramaNecesidad::count());
        $this->assertSame(1, ProgramaNecesidad::first()->cajas_0);
    }
}
