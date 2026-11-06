<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use App\Services\Abastecimiento\PrecioVigente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class ConveniosPreciosTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

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

    private function tipo(int $posicion = 0, $empresa = null): TipoCarga
    {
        return TipoCarga::where('enterprise_id', ($empresa ?? $this->sbp)->id)->orderBy('id')->skip($posicion)->firstOrFail();
    }

    /** Crea tipos de carga extra para probar varios tipos en un mismo convenio. */
    private function tipoExtra(string $nombre, $empresa = null): TipoCarga
    {
        return TipoCarga::create(['enterprise_id' => ($empresa ?? $this->sbp)->id, 'nombre' => $nombre, 'is_active' => true]);
    }

    /** @return array{0: Temporada, 1: Productor, 2: Variedad} */
    private function base($empresa = null, string $cultivo = 'Mango'): array
    {
        $empresa ??= $this->sbp;
        $c = Cultivo::where('enterprise_id', $empresa->id)->where('nombre', $cultivo)->firstOrFail();
        $temporada = Temporada::create([
            'enterprise_id' => $empresa->id, 'cultivo_id' => $c->id, 'nombre' => "Temp {$cultivo}",
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-09-30', 'estado' => 'activa',
        ]);
        $productor = Productor::create(['enterprise_id' => $empresa->id, 'tipo' => 'productor', 'nombre' => 'Rancho Uno', 'is_active' => true]);
        $variedad = Variedad::where('cultivo_id', $c->id)->orderBy('id')->firstOrFail();

        return [$temporada, $productor, $variedad];
    }

    private function cuerpo(array $over = []): array
    {
        [$temporada, $productor, $variedad] = $this->base();

        return array_merge([
            'temporada_id' => $temporada->id,
            'productor_id' => $productor->id,
            'variedad_id' => $variedad->id,
            'modalidad' => 'compra_directa',
            'unidad' => 'caja',
            'moneda' => 'MXN',
            'porcentaje_rezaga' => 5,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-28',
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 100], ['semana' => '2026-06-08', 'cantidad' => 120]],
            'precios' => [['tipo_carga_id' => $this->tipo()->id, 'precio' => 185, 'vigencia_inicio' => '2026-06-01']],
        ], $over);
    }

    public function test_alta_de_convenio_compra_directa(): void
    {
        Event::fake([AbastecimientoUpdated::class]);
        $body = $this->cuerpo(['precios' => [[
            'tipo_carga_id' => $this->tipo()->id, 'precio' => 185.5, 'porcentaje_productor' => 70, 'vigencia_inicio' => '2026-06-01',
        ]]]);

        $res = $this->postJson($this->url('convenios'), $body)->assertCreated();

        $res->assertJsonPath('data.folio', 'CV-0001')
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.modalidad', 'compra_directa')
            ->assertJsonPath('data.cultivo_id', Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->value('id'))
            ->assertJsonPath('data.precios.0.porcentaje_productor', null)
            ->assertJsonPath('data.precios.0.es_principal', true)
            ->assertJsonCount(2, 'data.metas');
        $this->assertSame(1, Convenio::where('enterprise_id', $this->sbp->id)->count());
        Event::assertDispatched(AbastecimientoUpdated::class, fn ($e) => $e->data['entidad'] === 'convenio' && $e->action === 'created');
    }

    public function test_folios_consecutivos(): void
    {
        $this->postJson($this->url('convenios'), $this->cuerpo())->assertCreated()->assertJsonPath('data.folio', 'CV-0001');
        $this->postJson($this->url('convenios'), $this->cuerpo())->assertCreated()->assertJsonPath('data.folio', 'CV-0002');
    }

    public function test_consignacion_exige_porcentaje_del_productor(): void
    {
        $body = $this->cuerpo(['modalidad' => 'consignacion']);

        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.0.porcentaje_productor']);
        $this->assertSame(0, Convenio::count());

        $body['precios'][0]['porcentaje_productor'] = 85;
        $this->postJson($this->url('convenios'), $body)->assertCreated()
            ->assertJsonPath('data.precios.0.porcentaje_productor', '85.00');
    }

    public function test_porcentaje_fuera_de_rango_422(): void
    {
        $body = $this->cuerpo(['modalidad' => 'consignacion']);
        $body['precios'][0]['porcentaje_productor'] = 120;

        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.0.porcentaje_productor']);
    }

    public function test_precio_repetido_mismo_tipo_y_vigencia(): void
    {
        $tipo = $this->tipo()->id;
        $body = $this->cuerpo(['precios' => [
            ['tipo_carga_id' => $tipo, 'precio' => 185, 'vigencia_inicio' => '2026-06-01'],
            ['tipo_carga_id' => $tipo, 'precio' => 190, 'vigencia_inicio' => '2026-06-01'],
        ]]);

        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.1.vigencia_inicio']);
        $this->assertSame(0, Convenio::count());
    }

    public function test_precio_debe_ser_mayor_a_cero_y_hay_al_menos_uno(): void
    {
        $body = $this->cuerpo();
        $body['precios'][0]['precio'] = 0;
        $this->postJson($this->url('convenios'), $body)->assertStatus(422)->assertJsonValidationErrors(['precios.0.precio']);

        $this->postJson($this->url('convenios'), $this->cuerpo(['precios' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['precios']);
    }

    public function test_el_primer_tipo_es_principal_si_ninguno_se_marca_y_se_normaliza_por_tipo(): void
    {
        $a = $this->tipo()->id;
        $b = $this->tipoExtra('Granel')->id;
        $body = $this->cuerpo(['precios' => [
            ['tipo_carga_id' => $a, 'precio' => 185, 'vigencia_inicio' => '2026-06-01'],
            ['tipo_carga_id' => $b, 'precio' => 150, 'vigencia_inicio' => '2026-06-01'],
            ['tipo_carga_id' => $a, 'precio' => 190, 'vigencia_inicio' => '2026-07-06'],
        ]]);

        $res = $this->postJson($this->url('convenios'), $body)->assertCreated();

        $precios = collect($res->json('data.precios'));
        $this->assertCount(3, $precios);
        $this->assertTrue($precios->where('tipo_carga_id', $a)->every(fn ($p) => $p['es_principal'] === true));
        $this->assertTrue($precios->where('tipo_carga_id', $b)->every(fn ($p) => $p['es_principal'] === false));
    }

    public function test_marcar_un_renglon_marca_todo_su_tipo_y_desmarca_los_demas(): void
    {
        $a = $this->tipo()->id;
        $b = $this->tipoExtra('Granel')->id;
        $body = $this->cuerpo(['precios' => [
            ['tipo_carga_id' => $a, 'precio' => 185, 'vigencia_inicio' => '2026-06-01'],
            ['tipo_carga_id' => $b, 'precio' => 150, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true],
            ['tipo_carga_id' => $b, 'precio' => 155, 'vigencia_inicio' => '2026-07-01'],
        ]]);

        $res = $this->postJson($this->url('convenios'), $body)->assertCreated();

        $precios = collect($res->json('data.precios'));
        $this->assertTrue($precios->where('tipo_carga_id', $b)->every(fn ($p) => $p['es_principal'] === true));
        $this->assertTrue($precios->where('tipo_carga_id', $a)->every(fn ($p) => $p['es_principal'] === false));
    }

    public function test_dos_tipos_marcados_como_principal_422(): void
    {
        $a = $this->tipo()->id;
        $b = $this->tipoExtra('Granel')->id;
        $body = $this->cuerpo(['precios' => [
            ['tipo_carga_id' => $a, 'precio' => 185, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true],
            ['tipo_carga_id' => $b, 'precio' => 150, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true],
        ]]);

        $this->postJson($this->url('convenios'), $body)
            ->assertStatus(422)->assertJsonValidationErrors(['precios.1.es_principal']);
    }

    public function test_editar_reemplaza_los_precios(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $original = $convenio->precios->first();
        $otro = $this->tipoExtra('Granel');
        $body = $this->cuerpo([
            'temporada_id' => $convenio->temporada_id,
            'productor_id' => $convenio->productor_id,
            'variedad_id' => $convenio->variedad_id,
            'precios' => [['tipo_carga_id' => $otro->id, 'precio' => 210, 'vigencia_inicio' => '2026-06-01']],
        ]);

        $this->putJson($this->url("convenios/{$convenio->id}"), $body)
            ->assertOk()
            ->assertJsonCount(1, 'data.precios')
            ->assertJsonPath('data.precios.0.tipo_carga_id', $otro->id)
            ->assertJsonPath('data.precios.0.precio', '210.0000');

        $vivos = ConvenioPrecio::where('convenio_id', $convenio->id)->get();
        $this->assertCount(1, $vivos);
        $this->assertSame($otro->id, $vivos->first()->tipo_carga_id);
        $this->assertSoftDeleted('abs_convenio_precios', ['id' => $original->id]);
    }

    public function test_editar_conserva_el_folio_y_reemplaza_las_metas_solo_si_vienen(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $body = $this->cuerpo([
            'temporada_id' => $convenio->temporada_id,
            'productor_id' => $convenio->productor_id,
            'variedad_id' => $convenio->variedad_id,
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 50]],
        ]);

        $this->putJson($this->url("convenios/{$convenio->id}"), $body)
            ->assertOk()->assertJsonPath('data.folio', $convenio->folio)->assertJsonCount(1, 'data.metas');
        $this->assertSame(1, $convenio->metas()->count());

        unset($body['metas']);
        $this->putJson($this->url("convenios/{$convenio->id}"), $body)->assertOk()->assertJsonCount(1, 'data.metas');
    }

    public function test_editar_con_un_precio_borrado_antes_lo_restaura_sin_chocar_con_el_indice_unico(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $tipo = $convenio->precios->first()->tipo_carga_id;
        $base = $this->cuerpo([
            'temporada_id' => $convenio->temporada_id,
            'productor_id' => $convenio->productor_id,
            'variedad_id' => $convenio->variedad_id,
        ]);
        $otroTipo = $this->tipoExtra('Granel')->id;

        // 1) Se quita el precio original (queda borrado lógico)...
        $base['precios'] = [['tipo_carga_id' => $otroTipo, 'precio' => 99, 'vigencia_inicio' => '2026-06-01']];
        $this->putJson($this->url("convenios/{$convenio->id}"), $base)->assertOk();

        // 2) ...y se vuelve a capturar el mismo tipo y vigencia.
        $base['precios'] = [['tipo_carga_id' => $tipo, 'precio' => 123, 'vigencia_inicio' => '2026-06-01']];
        $this->putJson($this->url("convenios/{$convenio->id}"), $base)
            ->assertOk()->assertJsonCount(1, 'data.precios')->assertJsonPath('data.precios.0.precio', '123.0000');

        $this->assertSame(1, ConvenioPrecio::where('convenio_id', $convenio->id)->count());
        $this->assertSame(2, ConvenioPrecio::withTrashed()->where('convenio_id', $convenio->id)->count());
    }

    public function test_precio_vigente_por_fecha(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['precio' => 185]);
        $tipo = $convenio->precios->first();
        $tipo->update(['vigencia_inicio' => '2026-06-22']);
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id, 'tipo_carga_id' => $tipo->tipo_carga_id,
            'precio' => 190, 'vigencia_inicio' => '2026-07-06', 'es_principal' => true,
        ]);
        $convenio->refresh();

        $vigente = app(PrecioVigente::class);
        $this->assertSame('185.0000', (string) $vigente->para($convenio, $tipo->tipo_carga_id, '2026-07-01')->precio);
        $this->assertSame('190.0000', (string) $vigente->para($convenio, $tipo->tipo_carga_id, '2026-07-06')->precio);
        $this->assertSame('190.0000', (string) $vigente->para($convenio, $tipo->tipo_carga_id, '2026-12-01')->precio);
        $this->assertNull($vigente->para($convenio, $tipo->tipo_carga_id, '2026-06-01'));
    }

    public function test_precio_vigente_principal_con_dos_vigencias_del_tipo_principal(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-06-15', 'fecha_fin' => '2026-08-30']);
        $a = $convenio->precios->first();
        $a->update(['precio' => 185, 'vigencia_inicio' => '2026-06-22']);
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id, 'tipo_carga_id' => $a->tipo_carga_id,
            'precio' => 190, 'vigencia_inicio' => '2026-07-06', 'es_principal' => true,
        ]);
        $b = $this->tipoExtra('Granel');
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id, 'tipo_carga_id' => $b->id,
            'precio' => 10, 'vigencia_inicio' => '2026-06-01', 'es_principal' => false,
        ]);
        $convenio->refresh();

        $vigente = app(PrecioVigente::class);
        // Al inicio del convenio (15 jun) el tipo principal aún no tiene precio vigente: su primera vigencia.
        $this->assertSame('185.0000', (string) $vigente->principal($convenio)->precio);
        $this->assertSame('185.0000', (string) $vigente->principal($convenio, '2026-07-05')->precio);
        $this->assertSame('190.0000', (string) $vigente->principal($convenio, '2026-07-06')->precio);
        // Nunca devuelve el precio de otro tipo.
        $this->assertSame($a->tipo_carga_id, $vigente->principal($convenio, '2026-06-02')->tipo_carga_id);
    }

    public function test_variedad_de_otro_cultivo_422(): void
    {
        $elote = Variedad::where('cultivo_id', Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Elote')->value('id'))->firstOrFail();

        $this->postJson($this->url('convenios'), $this->cuerpo(['variedad_id' => $elote->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['variedad_id']);
    }

    public function test_referencias_de_otra_empresa_422(): void
    {
        [$tempO, $prodO, $varO] = $this->base($this->otra);
        $tipoO = $this->tipo(0, $this->otra);

        $this->postJson($this->url('convenios'), $this->cuerpo(['temporada_id' => $tempO->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['temporada_id']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['productor_id' => $prodO->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['productor_id']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['variedad_id' => $varO->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['variedad_id']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['precios' => [
            ['tipo_carga_id' => $tipoO->id, 'precio' => 10, 'vigencia_inicio' => '2026-06-01'],
        ]]))->assertStatus(422)->assertJsonValidationErrors(['precios.0.tipo_carga_id']);
        $this->assertSame(0, Convenio::count());
    }

    public function test_campos_invalidos_422(): void
    {
        $this->postJson($this->url('convenios'), $this->cuerpo(['porcentaje_rezaga' => 101]))
            ->assertStatus(422)->assertJsonValidationErrors(['porcentaje_rezaga']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['fecha_fin' => '2026-05-01']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_fin']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['modalidad' => 'x', 'unidad' => 'x', 'moneda' => 'EUR', 'modo_volumen' => 'x']))
            ->assertStatus(422)->assertJsonValidationErrors(['modalidad', 'unidad', 'moneda', 'modo_volumen']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['volumen_total' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors(['volumen_total']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['metas' => [['semana' => '2026-06-01', 'cantidad' => -5]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['metas.0.cantidad']);
        $this->postJson($this->url('convenios'), $this->cuerpo(['metas' => [
            ['semana' => '2026-06-01', 'cantidad' => 5], ['semana' => '2026-06-01', 'cantidad' => 6],
        ]]))->assertStatus(422)->assertJsonValidationErrors(['metas.1.semana']);
    }

    public function test_encimados_detecta_fechas_que_se_tocan(): void
    {
        $existente = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28', 'status' => 'activo']);
        // Un convenio cancelado del mismo productor y variedad no cuenta como encimado.
        $this->crearConvenio($this->sbp, [
            'productor_id' => $existente->productor_id, 'temporada_id' => $existente->temporada_id,
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28', 'status' => 'cancelado',
        ]);
        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => 'Otro', 'is_active' => true]);
        $this->crearConvenio($this->sbp, [
            'productor_id' => $otroProductor->id, 'temporada_id' => $existente->temporada_id,
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28',
        ]);

        $consulta = fn (array $q) => $this->getJson($this->url('convenios/encimados').'?'.http_build_query(array_merge([
            'productor_id' => $existente->productor_id, 'variedad_id' => $existente->variedad_id,
        ], $q)));

        // Se tocan en un solo día (el último del existente): cuenta como encimado.
        $consulta(['fecha_inicio' => '2026-06-28', 'fecha_fin' => '2026-07-10'])
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $existente->id)
            ->assertJsonPath('data.0.folio', $existente->folio)
            ->assertJsonPath('data.0.fecha_inicio', '2026-06-01')
            ->assertJsonPath('data.0.fecha_fin', '2026-06-28')
            ->assertJsonPath('data.0.status', 'activo');
        // Rango contenido.
        $consulta(['fecha_inicio' => '2026-06-10', 'fecha_fin' => '2026-06-12'])->assertOk()->assertJsonCount(1, 'data');
        // Fechas que no se tocan.
        $consulta(['fecha_inicio' => '2026-06-29', 'fecha_fin' => '2026-07-10'])->assertOk()->assertJsonCount(0, 'data');
        // Se excluye el convenio que se está editando.
        $consulta(['fecha_inicio' => '2026-06-10', 'fecha_fin' => '2026-06-12', 'excluir_id' => $existente->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_encimados_no_cruza_empresas(): void
    {
        $otraConv = $this->crearConvenio($this->otra, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28']);

        $this->getJson($this->url('convenios/encimados').'?'.http_build_query([
            'productor_id' => $otraConv->productor_id, 'variedad_id' => $otraConv->variedad_id,
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28',
        ]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_aislamiento_put_de_otra_empresa_404(): void
    {
        $ajeno = $this->crearConvenio($this->otra, ['status' => 'borrador']);

        $this->putJson($this->url("convenios/{$ajeno->id}"), $this->cuerpo())->assertNotFound();
        $this->putJson($this->url('convenios/999999'), $this->cuerpo())->assertNotFound();
    }

    public function test_permisos_de_convenios(): void
    {
        Sanctum::actingAs($this->captor);
        $this->postJson($this->url('convenios'), $this->cuerpo())->assertForbidden();
        $this->getJson($this->url('convenios/encimados'))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['create']);
        $this->postJson($this->url('convenios'), $this->cuerpo())->assertCreated();
        $id = Convenio::value('id');
        $this->putJson($this->url("convenios/{$id}"), $this->cuerpo())->assertForbidden();
    }
}
