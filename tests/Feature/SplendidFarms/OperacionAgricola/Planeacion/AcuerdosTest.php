<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Events\ProgramaSemanalUpdated;
use App\Models\Cultivo;
use App\Models\Etapa;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Variedad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Acuerdos de la junta: adelantar etapa, mover pedido (también a otra
 * semana) y conseguir producto, con su efecto en el balance, activar y
 * desactivar, borrar, historial y aviso en tiempo real.
 */
class AcuerdosTest extends TestCase
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
        $this->cargarWk48($this->programa);
    }

    private function comoJunta(): void
    {
        Sanctum::actingAs($this->usuarioCon(['view', 'acuerdos']));
    }

    private function url(?ProgramaSemanal $p = null): string
    {
        return self::BASE.'/programa/'.($p ?? $this->programa)->id.'/acuerdos';
    }

    private function urlActivo(int $id): string
    {
        return self::BASE.'/acuerdos/'.$id.'/activo';
    }

    private function semana(string $lunes = '2026-11-23'): array
    {
        return $this->getJson(self::BASE.'/programa?lunes='.$lunes)->assertOk()->json('data');
    }

    private function blanco(string $lunes = '2026-11-23'): array
    {
        return $this->semana($lunes)['balance'][(string) $this->grupo('Blanco')->id];
    }

    /** Etapa de Blanco que se cosecha la semana siguiente (WK49). */
    private function etapaWk49(float $superficie = 4): Etapa
    {
        return $this->crearEtapa('Blanco', $superficie, ['fecha_cosecha_estimada' => '2026-11-30']);
    }

    private function adelanto(Etapa $etapa, array $extra = []): array
    {
        return array_merge([
            'tipo' => 'adelantar_etapa',
            'etapa_id' => $etapa->id,
            'temporada_id' => $this->temporada->id,
            'dia' => 2,
            'hectareas' => 4,
        ], $extra);
    }

    private function vallarta(): ProgramaNecesidad
    {
        return $this->programa->necesidades()->where('cliente_nombre', 'Vallarta')->firstOrFail();
    }

    private function movimiento(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'mover_pedido',
            'necesidad_id' => $this->vallarta()->id,
            'dia_origen' => 2,
            'cajas' => 1000,
            'destino_lunes' => '2026-11-30',
            'dia_destino' => 0,
        ], $extra);
    }

    private function compra(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'conseguir_producto',
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 3,
            'cajas' => 1200,
            'origen_tipo' => 'productor',
        ], $extra);
    }

    // ── Adelantar etapa ─────────────────────────────────

    public function test_adelantar_etapa_suma_al_dia(): void
    {
        $this->comoJunta();
        $etapa = $this->etapaWk49();

        $this->postJson($this->url(), $this->adelanto($etapa))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'adelantar_etapa')
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.variedad_id', $this->variedades['Blanco']->id)
            ->assertJsonPath('data.descripcion', "El Carrizo · {$etapa->nombre} · Blanco 4.0 ha al mié 25");

        // 4 ha × 700 = 2,800 cajas el miércoles.
        $blanco = $this->blanco();
        $this->assertSame(-93, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);
        $this->assertSame(2450 + 2800, $blanco['produccion'][2]);

        $this->assertSame('Adelanto pedido por ventas', $this->postJson($this->url(), $this->adelanto($this->etapaWk49(), [
            'descripcion' => '  Adelanto pedido por ventas  ',
        ]))->assertCreated()->json('data.descripcion'));
    }

    public function test_adelanto_no_excede_superficie(): void
    {
        $this->comoJunta();
        $etapa = $this->etapaWk49(4);

        $this->postJson($this->url(), $this->adelanto($etapa, ['hectareas' => 4.5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.hectareas.0', 'La etapa tiene 4.0 ha disponibles');

        // Un corte de la etapa en WK49 cuenta contra el adelanto.
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->corte($wk49, 'Blanco', 0, 1.5, $etapa);

        $this->postJson($this->url(), $this->adelanto($etapa, ['hectareas' => 2]))->assertCreated();
        $this->postJson($this->url(), $this->adelanto($etapa, ['hectareas' => 1]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.hectareas.0', 'La etapa tiene 0.5 ha disponibles');
        $this->postJson($this->url(), $this->adelanto($etapa, ['hectareas' => 0.5]))->assertCreated();

        $this->assertSame(2, ProgramaAcuerdo::count());
    }

    public function test_adelantar_etapa_de_esta_semana_es_422(): void
    {
        $this->comoJunta();
        $sabado = $this->crearEtapa('Blanco', 4, ['fecha_cosecha_estimada' => '2026-11-28']);

        $this->postJson($this->url(), $this->adelanto($sabado))
            ->assertUnprocessable()
            ->assertJsonPath('errors.etapa_id.0', 'La etapa ya es de esta semana; agrégala como corte');

        // La proyectada manda sobre la estimada.
        $proyectada = $this->crearEtapa('Blanco', 4, [
            'fecha_cosecha_estimada' => '2026-12-07',
            'fecha_cosecha_proyectada' => '2026-11-26',
        ]);
        $this->postJson($this->url(), $this->adelanto($proyectada))
            ->assertUnprocessable()
            ->assertJsonPath('errors.etapa_id.0', 'La etapa ya es de esta semana; agrégala como corte');

        $this->assertSame(0, ProgramaAcuerdo::count());
    }

    public function test_adelanto_exige_temporada_de_la_semana_y_etapa_activa(): void
    {
        $this->comoJunta();
        $etapa = $this->etapaWk49();

        $this->temporada->update(['estado' => 'cerrada']);
        $this->postJson($this->url(), $this->adelanto($etapa))
            ->assertUnprocessable()->assertJsonValidationErrors('temporada_id');
        $this->temporada->update(['estado' => 'abierta']);

        $etapa->update(['is_active' => false]);
        $this->postJson($this->url(), $this->adelanto($etapa))
            ->assertUnprocessable()->assertJsonValidationErrors('etapa_id');
        $etapa->update(['is_active' => true]);

        $this->postJson($this->url(), $this->adelanto($etapa, ['dia' => 6]))
            ->assertUnprocessable()->assertJsonValidationErrors('dia');

        $this->postJson($this->url(), $this->adelanto($etapa))->assertCreated();
    }

    // ── Mover pedido ────────────────────────────────────

    public function test_mover_pedido_afecta_semana_destino_y_se_revierte(): void
    {
        $this->comoJunta();
        Event::fake([ProgramaSemanalUpdated::class]);

        $id = $this->postJson($this->url(), $this->movimiento())
            ->assertCreated()
            ->assertJsonPath('data.descripcion', 'Vallarta · 1,000 cj del mié 25 a lun 30 nov')
            ->assertJsonPath('data.destino_lunes', '2026-11-30')
            ->json('data.id');

        // El programa destino se crea al guardar el acuerdo, y también se avisa.
        $wk49 = ProgramaSemanal::whereDate('lunes', '2026-11-30')->first();
        $this->assertNotNull($wk49);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $wk49->id
            && $e->data['lunes'] === '2026-11-30');

        $this->assertSame(6584 - 1000, $this->blanco()['necesidad'][2]);
        $movida = collect($this->semana('2026-11-30')['necesidades'])->firstWhere('id', -$id);
        $this->assertNotNull($movida);
        $this->assertSame(['lunes' => '2026-11-23', 'acuerdo_id' => $id], $movida['movida_desde']);
        $this->assertSame(1000, $movida['cajas'][0]);
        $this->assertSame(1000, $this->blanco('2026-11-30')['necesidad'][0]);

        // Desactivar: ambas semanas como antes.
        $this->patchJson($this->urlActivo($id), ['activo' => false])
            ->assertOk()->assertJsonPath('data.activo', false);
        Event::assertDispatchedTimes(ProgramaSemanalUpdated::class, 4);

        $this->assertSame(6584, $this->blanco()['necesidad'][2]);
        $this->assertNull(collect($this->semana('2026-11-30')['necesidades'])->firstWhere('id', -$id));
        $this->assertSame(0, $this->blanco('2026-11-30')['necesidad'][0]);

        // Reactivar: vuelve a moverse.
        $this->patchJson($this->urlActivo($id), ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);
        $this->assertSame(6584 - 1000, $this->blanco()['necesidad'][2]);
    }

    public function test_mover_pedido_no_pasa_las_cajas_del_renglon_ese_dia(): void
    {
        $this->comoJunta();

        $this->postJson($this->url(), $this->movimiento(['cajas' => 6585]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cajas.0', 'El renglón tiene 6,584 cajas para mover ese día');
        $this->postJson($this->url(), $this->movimiento(['dia_origen' => 0]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cajas.0', 'El renglón tiene 0 cajas para mover ese día');

        // Dos movimientos activos del mismo renglón y día no pasan del total.
        $primero = $this->postJson($this->url(), $this->movimiento(['cajas' => 4000]))->assertCreated()->json('data.id');
        $this->postJson($this->url(), $this->movimiento(['cajas' => 3000, 'dia_destino' => 1]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cajas.0', 'El renglón tiene 2,584 cajas para mover ese día');
        $segundo = $this->postJson($this->url(), $this->movimiento(['cajas' => 2584, 'dia_destino' => 1]))
            ->assertCreated()->json('data.id');

        // Con el primero inactivo cabe otro; al reactivar el primero ya no alcanza.
        $this->patchJson($this->urlActivo($primero), ['activo' => false])->assertOk();
        $this->postJson($this->url(), $this->movimiento(['cajas' => 1000, 'dia_destino' => 2]))->assertCreated();
        $this->patchJson($this->urlActivo($primero), ['activo' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.cajas.0', 'El renglón tiene 3,000 cajas para mover ese día');
        $this->assertFalse(ProgramaAcuerdo::findOrFail($primero)->activo);

        $this->assertSame(6584 - 2584 - 1000, $this->blanco()['necesidad'][2]);
        $this->assertNotNull($segundo);
    }

    public function test_mover_pedido_valida_destino(): void
    {
        $this->comoJunta();

        $this->postJson($this->url(), $this->movimiento(['destino_lunes' => '2026-12-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('destino_lunes');
        $this->postJson($this->url(), $this->movimiento(['destino_lunes' => 'lunes']))
            ->assertUnprocessable()->assertJsonValidationErrors('destino_lunes');
        $this->postJson($this->url(), $this->movimiento(['dia_destino' => 6]))
            ->assertUnprocessable()->assertJsonValidationErrors('dia_destino');
        $this->postJson($this->url(), $this->movimiento(['dia_origen' => -1]))
            ->assertUnprocessable()->assertJsonValidationErrors('dia_origen');

        // Misma semana y mismo día no es mover.
        $this->postJson($this->url(), $this->movimiento(['destino_lunes' => '2026-11-23', 'dia_destino' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('dia_destino');

        // Renglón de otra semana.
        $otra = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $ajena = $this->necesidad($otra, 'Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 500, 0, 0, 0]);
        $this->postJson($this->url(), $this->movimiento(['necesidad_id' => $ajena->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('necesidad_id');

        $this->assertSame(0, ProgramaAcuerdo::count());

        // Misma semana a otro día sí se puede: se resta del mié y se suma al vie.
        $this->postJson($this->url(), $this->movimiento(['destino_lunes' => '2026-11-23', 'dia_destino' => 4]))
            ->assertCreated()
            ->assertJsonPath('data.descripcion', 'Vallarta · 1,000 cj del mié 25 a vie 27 nov');
        $blanco = $this->blanco();
        $this->assertSame(6584 - 1000, $blanco['necesidad'][2]);
        $this->assertSame(1111 + 1000, $blanco['necesidad'][4]);
        // Dentro de la semana no cambia el final (el renglón de WK47 arrastra −500).
        $this->assertSame($blanco['final_sin_acuerdos'], $blanco['final']);
        $this->assertSame(-2893 - 500, $blanco['final']);
    }

    // ── Conseguir producto ──────────────────────────────

    public function test_conseguir_producto_suma_produccion(): void
    {
        $this->comoJunta();

        $this->postJson($this->url(), $this->compra())
            ->assertCreated()
            ->assertJsonPath('data.descripcion', 'Compra a productor · 1,200 cj Blanco el jue 26')
            ->assertJsonPath('data.origen_tipo', 'productor')
            ->assertJsonPath('data.temporada_id', null);

        $blanco = $this->blanco();
        $this->assertSame(1200, $blanco['produccion'][3]);
        $this->assertSame(-2893 + 1200, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);

        // Con temporada cuenta en la producción de esa temporada.
        $this->postJson($this->url(), $this->compra([
            'temporada_id' => $this->temporada->id,
            'dia' => 4,
            'cajas' => 300,
            'origen_tipo' => 'empresa_grupo',
            'origen_texto' => 'Canes Agro',
        ]))->assertCreated()
            ->assertJsonPath('data.descripcion', 'Producto de empresa del grupo (Canes Agro) · 300 cj Blanco el vie 27');
        $this->assertSame(4 * 700 + 300, $this->blanco()['produccion_por_temporada'][$this->temporada->id][4]);
    }

    public function test_conseguir_producto_valida_campos(): void
    {
        $this->comoJunta();

        $this->postJson($this->url(), $this->compra(['variedad_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('variedad_id');
        $this->postJson($this->url(), $this->compra(['cajas' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors('cajas');
        $this->postJson($this->url(), $this->compra(['origen_tipo' => 'compras']))
            ->assertUnprocessable()->assertJsonValidationErrors('origen_tipo');
        $this->postJson($this->url(), $this->compra(['origen_texto' => str_repeat('x', 151)]))
            ->assertUnprocessable()->assertJsonValidationErrors('origen_texto');
        $this->postJson($this->url(), $this->compra(['descripcion' => str_repeat('x', 256)]))
            ->assertUnprocessable()->assertJsonValidationErrors('descripcion');
        $this->postJson($this->url(), $this->compra(['tipo' => 'regalar']))
            ->assertUnprocessable()->assertJsonValidationErrors('tipo');

        $otro = Cultivo::create(['nombre' => 'Tomate']);
        $ajena = Variedad::create(['cultivo_id' => $otro->id, 'nombre' => 'Saladette', 'user_id' => $this->autor->id]);
        $this->postJson($this->url(), $this->compra(['variedad_id' => $ajena->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('variedad_id');

        $this->assertSame(0, ProgramaAcuerdo::count());
    }

    // ── Activar, desactivar, borrar ─────────────────────

    public function test_desactivar_y_final_sin_acuerdos(): void
    {
        $this->comoJunta();
        $this->programa->update(['junta_registrada_at' => now(), 'junta_registrada_por' => $this->autor->id]);

        $adelanto = $this->postJson($this->url(), $this->adelanto($this->etapaWk49()))->assertCreated()->json('data.id');
        $compra = $this->postJson($this->url(), $this->compra())->assertCreated()->json('data.id');

        $blanco = $this->blanco();
        $this->assertSame(-2893 + 2800 + 1200, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);

        $this->patchJson($this->urlActivo($adelanto), ['activo' => false])->assertOk();
        $blanco = $this->blanco();
        $this->assertSame(-2893 + 1200, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);

        $this->patchJson($this->urlActivo($compra), ['activo' => false])->assertOk();
        $blanco = $this->blanco();
        $this->assertSame(-2893, $blanco['final']);
        $this->assertSame(-2893, $blanco['final_sin_acuerdos']);

        // El mismo valor no deja historial.
        $this->patchJson($this->urlActivo($compra), ['activo' => false])->assertOk();
        $this->patchJson($this->urlActivo($compra), ['activo' => true])->assertOk();
        $this->assertSame(-2893 + 1200, $this->blanco()['final']);

        $this->patchJson($this->urlActivo($compra), ['activo' => 'quizá'])
            ->assertUnprocessable()->assertJsonValidationErrors('activo');

        $this->assertSame(
            ['creado', 'creado', 'desactivado', 'desactivado', 'activado'],
            ProgramaCambio::where('entidad', 'acuerdo')->orderBy('id')->pluck('accion')->all(),
        );
        $cambio = ProgramaCambio::where('accion', 'desactivado')->orderBy('id')->firstOrFail();
        $this->assertSame($adelanto, $cambio->entidad_id);
        $this->assertTrue($cambio->antes['activo']);
        $this->assertFalse($cambio->despues['activo']);
        $this->assertSame(array_keys($cambio->antes), array_keys($cambio->despues));
    }

    public function test_reactivar_sin_ha_disponibles_es_422(): void
    {
        $this->comoJunta();
        $etapa = $this->etapaWk49(4);

        $id = $this->postJson($this->url(), $this->adelanto($etapa))->assertCreated()->json('data.id');
        $this->patchJson($this->urlActivo($id), ['activo' => false])->assertOk();

        // Mientras está inactivo, la etapa se corta en WK49.
        $wk49 = ProgramaSemanal::create(['lunes' => '2026-11-30']);
        $this->corte($wk49, 'Blanco', 0, 2, $etapa);

        $this->patchJson($this->urlActivo($id), ['activo' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.hectareas.0', 'La etapa tiene 2.0 ha disponibles');
        $this->assertFalse(ProgramaAcuerdo::findOrFail($id)->activo);
        $this->assertSame(-2893, $this->blanco()['final']);

        // Con espacio de nuevo, sí se reactiva (sus propias hectáreas no cuentan).
        $wk49->cortes()->delete();
        $this->patchJson($this->urlActivo($id), ['activo' => true])->assertOk();
        $this->assertSame(-93, $this->blanco()['final']);
    }

    public function test_borrar_acuerdo_revierte_y_registra(): void
    {
        $this->comoJunta();
        $this->programa->update(['junta_registrada_at' => now(), 'junta_registrada_por' => $this->autor->id]);
        $id = $this->postJson($this->url(), $this->movimiento())->assertCreated()->json('data.id');
        $wk49 = ProgramaSemanal::whereDate('lunes', '2026-11-30')->firstOrFail();

        Event::fake([ProgramaSemanalUpdated::class]);
        $this->deleteJson(self::BASE.'/acuerdos/'.$id)->assertOk()->assertJsonPath('success', true);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $wk49->id);
        Event::assertDispatched(ProgramaSemanalUpdated::class, fn ($e) => $e->data['programa_id'] === $this->programa->id);

        $this->assertSame(0, ProgramaAcuerdo::count());
        $this->assertSame(6584, $this->blanco()['necesidad'][2]);
        $cambio = ProgramaCambio::where('accion', 'borrado')->firstOrFail();
        $this->assertSame('acuerdo', $cambio->entidad);
        $this->assertSame('mover_pedido', $cambio->antes['tipo']);
        $this->assertNull($cambio->despues);
    }

    public function test_sin_permiso_acuerdos_es_403(): void
    {
        $acuerdo = ProgramaAcuerdo::create([
            'programa_id' => $this->programa->id,
            'tipo' => 'conseguir_producto',
            'activo' => true,
            'variedad_id' => $this->variedades['Blanco']->id,
            'dia' => 3,
            'cajas' => 1000,
            'origen_tipo' => 'otro',
        ]);
        Sanctum::actingAs($this->usuarioCon(['view', 'capturar-corte', 'capturar-ventas', 'capturar-logistica', 'configurar']));

        $this->postJson($this->url(), $this->compra())->assertForbidden();
        $this->patchJson($this->urlActivo($acuerdo->id), ['activo' => false])->assertForbidden();
        $this->deleteJson(self::BASE.'/acuerdos/'.$acuerdo->id)->assertForbidden();

        $this->assertSame(1, ProgramaAcuerdo::count());
        $this->assertTrue($acuerdo->fresh()->activo);
    }
}
