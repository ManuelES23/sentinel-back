<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\ConsumoCapa;
use App\Models\InventoryKardex;
use App\Models\InventoryStock;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\RecipeItem;
use App\Models\SystemNotification;
use App\Services\Empaque\ConsumoProduccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

class ConsumoProduccionTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
    }

    private function servicio(): ConsumoProduccion
    {
        return app(ConsumoProduccion::class);
    }

    /** Simula una petición nueva: el servicio Scoped empieza sin lo observado. */
    private function nuevaPeticion(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function surtirTodo(float $cantidad = 1000): void
    {
        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna] as $insumo) {
            $this->darExistencia($insumo, $cantidad);
        }
    }

    public function test_consumir_registra_renglon_porciones_y_kardex(): void
    {
        $this->darExistencia($this->cajaB15, 300, 2.5);
        $this->darExistencia($this->etiqueta, 300, 0.1);
        $pallet = $this->crearPallet();
        $entrada = $this->crearEntrada($pallet, 1, 60);

        DB::transaction(fn () => $this->servicio()->consumirPorCaja($pallet, $entrada, $this->receta, 60, []));

        $renglon = ProduccionEmpaqueConsumo::where('product_id', $this->cajaB15->id)->sole();
        $this->assertEqualsWithDelta(60, (float) $renglon->cantidad, 0.0001);
        $this->assertSame(60, $renglon->cajas);
        $this->assertSame(RecipeItem::CONSUMO_POR_CAJA, $renglon->tipo);
        $this->assertSame($entrada->id, (int) $renglon->detalle_id);
        $this->assertSame($this->itemCaja->id, (int) $renglon->recipe_item_id);
        $this->assertSame('Caja', $renglon->group_key);
        $this->assertEqualsWithDelta(150, (float) $renglon->costo_total, 0.0001);

        $porciones = ConsumoCapa::where('referencia', "empaque_consumo:{$renglon->id}")->get();
        $this->assertEqualsWithDelta(60, (float) $porciones->sum('cantidad'), 0.0001);

        $kardex = InventoryKardex::where('product_id', $this->cajaB15->id)->sole();
        $this->assertSame('decrease', $kardex->transaction_type);
        $this->assertEqualsWithDelta(60, (float) $kardex->quantity, 0.0001);
        $this->assertEqualsWithDelta(2.5, (float) $kardex->unit_cost, 0.0001);
        $this->assertNull($kardex->movement_id);
        $this->assertSame('CHA-0001', $kardex->document_number);
        $this->assertSame('2026-02-03', $kardex->movement_date->toDateString());
        $this->assertSame('Producción empaque · pallet CHA-0001 · entrada 1', $kardex->description);

        $this->assertEqualsWithDelta(240, $this->existencia($this->cajaB15), 0.0001);
        $this->assertEqualsWithDelta(239.5, $this->existencia($this->etiqueta), 0.0001);
        $this->assertSame([], $this->servicio()->faltantes());
    }

    public function test_devolver_tras_editar_receta_devuelve_lo_registrado(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearPallet();
        $entrada = $this->crearEntrada($pallet, 1, 60);

        DB::transaction(function () use ($pallet, $entrada) {
            $this->servicio()->consumirPorCaja($pallet, $entrada, $this->receta, 60, ['Caja' => $this->itemCajaAlterna->id]);
            $this->servicio()->consumirPorPallet($pallet, $this->receta, RecipeItem::CONSUMO_AL_ARMAR);
            $this->servicio()->consumirPorPallet($pallet, $this->receta, RecipeItem::CONSUMO_AL_CERRAR);
        });
        $this->assertEqualsWithDelta(940, $this->existencia($this->cajaAlterna), 0.0001);
        $this->assertEqualsWithDelta(960, $this->existencia($this->fleje), 0.0001);

        // La receta cambia por completo después del consumo.
        $this->receta->items()->delete();
        $nuevo = $this->insumoEmpaque('INS-NUE', 'Interlock');
        $this->renglonReceta($this->fleje, 99, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->cajaB15, 300, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true]);
        $this->renglonReceta($nuevo, 5, RecipeItem::CONSUMO_AL_ARMAR);
        $this->receta->unsetRelation('items');

        $this->nuevaPeticion();
        DB::transaction(fn () => $this->servicio()->devolverTodo($pallet));

        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna] as $insumo) {
            $this->assertEqualsWithDelta(1000, $this->existencia($insumo), 0.0001, $insumo->name);
        }
        $this->assertFalse(InventoryStock::where('product_id', $nuevo->id)->exists());
        $this->assertFalse(ProduccionEmpaqueConsumo::vigentes()->exists());
        $this->assertSame(
            'Devolución por corrección · pallet CHA-0001',
            InventoryKardex::where('transaction_type', 'increase')->where('product_id', $this->fleje->id)->value('description'),
        );
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_faltante_reporta_y_notifica_una_vez(): void
    {
        $this->darExistencia($this->etiqueta, 1000);
        $this->darExistencia($this->cajaB15, 10);
        $encargado = $this->asignarEncargado();
        $pallet = $this->crearPallet();
        $uno = $this->crearEntrada($pallet, 1, 60);
        $dos = $this->crearEntrada($pallet, 2, 20);

        DB::transaction(function () use ($pallet, $uno, $dos) {
            $this->servicio()->consumirPorCaja($pallet, $uno, $this->receta, 60, []);
            $this->servicio()->consumirPorCaja($pallet, $dos, $this->receta, 20, []);
            $this->assertSame(0, SystemNotification::count(), 'la notificación sale hasta el commit');
        });

        $faltantes = $this->servicio()->faltantes();
        $this->assertCount(1, $faltantes);
        $this->assertSame($this->cajaB15->id, $faltantes[0]['producto_id']);
        $this->assertSame('Caja de cartón 8.85 LBS B15', $faltantes[0]['producto']);
        $this->assertSame($this->entity->id, $faltantes[0]['almacen_id']);
        $this->assertSame('Empaque Principal', $faltantes[0]['almacen']);
        $this->assertEqualsWithDelta(-70, $faltantes[0]['existencia'], 0.0001);

        $aviso = SystemNotification::sole();
        $this->assertSame($encargado->id, (int) $aviso->user_id);
        $this->assertSame('Faltante de insumo en Empaque Principal', $aviso->title);
        $this->assertSame('Caja de cartón 8.85 LBS B15: quedan -70. Se descontó por el pallet CHA-0001.', $aviso->message);
        $this->assertSame("/splendidfarms/inventario/reportes/stock?entidad={$this->entity->id}&faltantes=1", $aviso->action_url);
        $this->assertSame('Ver faltantes', $aviso->action_label);

        // Sin encargado: no notifica, no falla y queda un WARNING.
        $this->entity->update(['encargado_user_id' => null]);
        $this->darExistencia($this->cajaAlterna, 5);
        $otro = $this->crearPallet();
        $this->nuevaPeticion();
        Log::spy();

        DB::transaction(fn () => $this->servicio()->consumirPorCaja($otro, null, $this->receta, 60, ['Caja' => $this->itemCajaAlterna->id]));

        $this->assertSame(1, SystemNotification::count());
        $this->assertEqualsWithDelta(-55, collect($this->servicio()->faltantes())->firstWhere('producto_id', $this->cajaAlterna->id)['existencia'], 0.0001);
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje) => str_contains($mensaje, 'sin encargado'))->once();
    }

    public function test_regularizar_y_volver_a_faltar_notifica_de_nuevo(): void
    {
        $this->darExistencia($this->etiqueta, 1000);
        $this->darExistencia($this->cajaB15, 10);
        $this->asignarEncargado();

        DB::transaction(fn () => $this->servicio()->consumirPorCaja($this->crearPallet(), null, $this->receta, 60, []));
        $this->assertSame(1, SystemNotification::count());
        $this->assertEqualsWithDelta(-50, $this->existencia($this->cajaB15), 0.0001);

        // Sigue en negativo: no se repite.
        $this->nuevaPeticion();
        DB::transaction(fn () => $this->servicio()->consumirPorCaja($this->crearPallet(), null, $this->receta, 20, []));
        $this->assertSame(1, SystemNotification::count());

        // Se regulariza y vuelve a faltar: otra notificación.
        $this->darExistencia($this->cajaB15, 100);
        $this->assertNull(InventoryStock::where('product_id', $this->cajaB15->id)->value('negativo_desde'));
        $this->nuevaPeticion();
        DB::transaction(fn () => $this->servicio()->consumirPorCaja($this->crearPallet(), null, $this->receta, 60, []));

        $this->assertEqualsWithDelta(-30, $this->existencia($this->cajaB15), 0.0001);
        $this->assertSame(2, SystemNotification::count());
        $this->assertTrue(SystemNotification::where('message', 'like', '%quedan -30.%')->exists());
    }

    public function test_por_pallet_no_se_duplica(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearPallet();

        DB::transaction(function () use ($pallet) {
            $this->servicio()->consumirPorPallet($pallet, $this->receta, RecipeItem::CONSUMO_AL_CERRAR);
            $this->servicio()->consumirPorPallet($pallet, $this->receta, RecipeItem::CONSUMO_AL_CERRAR);
        });

        $this->assertSame(1, ProduccionEmpaqueConsumo::where('product_id', $this->esquineros->id)->count());
        $this->assertSame(3, ProduccionEmpaqueConsumo::where('tipo', RecipeItem::CONSUMO_AL_CERRAR)->count());
        $this->assertEqualsWithDelta(996, $this->existencia($this->esquineros), 0.0001);
        $this->assertEqualsWithDelta(1000, $this->existencia($this->tarima), 0.0001);

        // Devuelto el momento, se puede volver a consumir.
        DB::transaction(function () use ($pallet) {
            $this->servicio()->devolverMomento($pallet, RecipeItem::CONSUMO_AL_CERRAR);
            $this->servicio()->consumirPorPallet($pallet, $this->receta, RecipeItem::CONSUMO_AL_CERRAR);
        });
        $this->assertEqualsWithDelta(996, $this->existencia($this->esquineros), 0.0001);
    }

    public function test_mover_por_caja_parte_sin_mover_inventario_y_devuelve_exacto(): void
    {
        $this->surtirTodo();
        $cola = $this->crearPallet(['is_cola' => true, 'total_cajas' => 100]);
        $mixto = $this->crearPallet();
        $entradaCola = $this->crearEntrada($cola, 1, 100);
        $entradaMixto = $this->crearEntrada($mixto, 1, 40);

        DB::transaction(fn () => $this->servicio()->consumirPorCaja($cola, $entradaCola, $this->receta, 100, []));
        $this->assertEqualsWithDelta(900, $this->existencia($this->cajaB15), 0.0001);

        DB::transaction(fn () => $this->servicio()->moverPorCaja($entradaCola, $cola, $entradaMixto, 40, 100));

        $this->assertEqualsWithDelta(900, $this->existencia($this->cajaB15), 0.0001, 'mover no toca inventario');
        $enMixto = ProduccionEmpaqueConsumo::where('produccion_id', $mixto->id)->where('product_id', $this->cajaB15->id)->sole();
        $enCola = ProduccionEmpaqueConsumo::where('produccion_id', $cola->id)->where('product_id', $this->cajaB15->id)->sole();
        $this->assertEqualsWithDelta(40, $enMixto->vigente(), 0.0001);
        $this->assertSame(40, $enMixto->cajas);
        $this->assertEqualsWithDelta(60, $enCola->vigente(), 0.0001);
        $this->assertSame(60, $enCola->cajas);
        $this->assertEqualsWithDelta(40, (float) ConsumoCapa::where('referencia', $enMixto->referencia())->sum('cantidad'), 0.0001);

        DB::transaction(fn () => $this->servicio()->devolverEntrada($mixto, $entradaMixto));
        $this->assertEqualsWithDelta(940, $this->existencia($this->cajaB15), 0.0001);
        DB::transaction(fn () => $this->servicio()->devolverTodo($cola));
        $this->assertEqualsWithDelta(1000, $this->existencia($this->cajaB15), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_receta_de_pallet_alternativas_y_ligar_base(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearPallet();
        $this->assertSame($this->receta->id, $this->servicio()->recetaDePallet($pallet)->id);

        DB::transaction(fn () => $this->servicio()->consumirPorCaja($pallet, null, $this->receta, 30, ['Caja' => $this->itemCajaAlterna->id]));
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]], $this->servicio()->alternativasDe($pallet, null));

        $base = $this->crearEntrada($pallet, 1, 30);
        $this->servicio()->ligarBase($pallet, $base);
        $this->assertSame([], $this->servicio()->alternativasDe($pallet, null));
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]], $this->servicio()->alternativasDe($pallet, $base));

        // La entrada con más cajas manda; empate → la primera.
        $otraReceta = $this->receta->replicate();
        $otraReceta->code = 'REC-OTRA';
        $otraReceta->save();
        $this->crearEntrada($pallet, 2, 30, ['recipe_id' => $otraReceta->id]);
        $this->assertSame($this->receta->id, $this->servicio()->recetaDePallet($pallet)->id);
        $this->crearEntrada($pallet, 3, 50, ['recipe_id' => $otraReceta->id]);
        $this->assertSame($otraReceta->id, $this->servicio()->recetaDePallet($pallet)->id);
    }
}
