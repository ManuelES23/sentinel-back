<?php

namespace Tests\Unit\Empaque;

use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\Empaque\CalculoConsumoReceta;
use Tests\TestCase;

/** Receta armada en memoria: el cálculo no toca la base de datos. */
class CalculoConsumoRecetaTest extends TestCase
{
    private const TARIMA = 1;
    private const ESQUINEROS = 2;
    private const FLEJE = 3;
    private const GRAPA = 4;
    private const ETIQUETA = 5;
    private const CAJA_DEFAULT = 6;
    private const CAJA_ALTERNA = 7;
    private const OPCIONAL = 8;

    private CalculoConsumoReceta $calculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculo = new CalculoConsumoReceta();
    }

    /** «Pallet Caja 8.85 LBS 240 B15»: el id del renglón es también el product_id + 100. */
    private function receta(array $cambios = []): Recipe
    {
        $renglones = [
            self::TARIMA => ['quantity' => 1, 'consumo' => 'al_armar'],
            self::ESQUINEROS => ['quantity' => 4, 'consumo' => 'al_cerrar'],
            self::FLEJE => ['quantity' => 40, 'consumo' => 'al_cerrar'],
            self::GRAPA => ['quantity' => 8, 'consumo' => 'al_cerrar'],
            self::ETIQUETA => ['quantity' => 242, 'consumo' => 'por_caja', 'solo_interno' => true],
            self::CAJA_DEFAULT => ['quantity' => 240, 'consumo' => 'por_caja', 'group_key' => 'Caja', 'is_default' => true],
            self::CAJA_ALTERNA => ['quantity' => 240, 'consumo' => 'por_caja', 'group_key' => 'Caja', 'is_default' => false],
            self::OPCIONAL => ['quantity' => 10, 'consumo' => 'por_caja', 'is_optional' => true],
        ];

        $items = [];
        foreach ($renglones as $id => $datos) {
            $item = new RecipeItem(array_merge([
                'recipe_id' => 50,
                'product_id' => $id + 100,
                'waste_percentage' => 0,
                'is_optional' => false,
                'is_default' => false,
                'group_key' => null,
                'solo_interno' => false,
            ], $datos, $cambios[$id] ?? []));
            $item->id = $id;
            $items[] = $item;
        }

        $receta = new Recipe(['name' => 'Pallet Caja 8.85 LBS 240 B15', 'output_quantity' => 1, 'recipe_type' => 'empaque']);
        $receta->id = 50;
        $receta->setRelation('items', collect($items));

        return $receta;
    }

    /** @return array<int, float> product_id => cantidad */
    private function porProducto(array $renglones): array
    {
        $r = [];
        foreach ($renglones as $renglon) {
            $r[$renglon['product_id']] = $renglon['cantidad'];
        }

        return $r;
    }

    public function test_por_caja_escala_por_la_caja_y_toma_la_predeterminada(): void
    {
        $renglones = $this->calculo->porCaja($this->receta(), 60, []);
        $cantidades = $this->porProducto($renglones);

        $this->assertEqualsWithDelta(60.5, $cantidades[self::ETIQUETA + 100], 0.0001);
        $this->assertEqualsWithDelta(60, $cantidades[self::CAJA_DEFAULT + 100], 0.0001);
        $this->assertArrayNotHasKey(self::CAJA_ALTERNA + 100, $cantidades);
        $this->assertArrayNotHasKey(self::TARIMA + 100, $cantidades);
        $this->assertArrayNotHasKey(self::ESQUINEROS + 100, $cantidades);
        $this->assertCount(2, $renglones);

        $caja = collect($renglones)->firstWhere('product_id', self::CAJA_DEFAULT + 100);
        $this->assertSame(self::CAJA_DEFAULT, $caja['recipe_item_id']);
        $this->assertSame('Caja', $caja['group_key']);
    }

    public function test_por_caja_respeta_la_alternativa_elegida(): void
    {
        $cantidades = $this->porProducto($this->calculo->porCaja($this->receta(), 60, ['Caja' => self::CAJA_ALTERNA]));

        $this->assertEqualsWithDelta(60, $cantidades[self::CAJA_ALTERNA + 100], 0.0001);
        $this->assertArrayNotHasKey(self::CAJA_DEFAULT + 100, $cantidades);
    }

    public function test_por_pallet_solo_toma_el_momento(): void
    {
        $cantidades = $this->porProducto($this->calculo->porPallet($this->receta(), 'al_cerrar', []));

        $this->assertEquals([
            self::ESQUINEROS + 100 => 4.0,
            self::FLEJE + 100 => 40.0,
            self::GRAPA + 100 => 8.0,
        ], $cantidades);

        $armar = $this->porProducto($this->calculo->porPallet($this->receta(), 'al_armar', []));
        $this->assertEquals([self::TARIMA + 100 => 1.0], $armar);
    }

    public function test_merma_se_suma_a_la_cantidad(): void
    {
        $receta = $this->receta([self::ETIQUETA => ['waste_percentage' => 10], self::FLEJE => ['waste_percentage' => 10]]);

        $this->assertEqualsWithDelta(66.55, $this->porProducto($this->calculo->porCaja($receta, 60, []))[self::ETIQUETA + 100], 0.0001);
        $this->assertEqualsWithDelta(44, $this->porProducto($this->calculo->porPallet($receta, 'al_cerrar', []))[self::FLEJE + 100], 0.0001);
    }

    public function test_renglon_opcional_nunca_sale(): void
    {
        $receta = $this->receta([self::OPCIONAL => ['consumo' => 'al_cerrar']]);

        $this->assertArrayNotHasKey(self::OPCIONAL + 100, $this->porProducto($this->calculo->porCaja($this->receta(), 60, [])));
        $this->assertArrayNotHasKey(self::OPCIONAL + 100, $this->porProducto($this->calculo->porPallet($receta, 'al_cerrar', [])));
    }

    public function test_validar_alternativas_rechaza_renglon_ajeno(): void
    {
        $receta = $this->receta();

        $this->assertSame([], $this->calculo->validarAlternativas($receta, ['Caja' => self::CAJA_ALTERNA]));
        $this->assertSame([], $this->calculo->validarAlternativas($receta, ['Caja' => null]));

        $errores = $this->calculo->validarAlternativas($receta, ['Caja' => 999]);
        $this->assertArrayHasKey('alternativas.Caja', $errores);

        // Renglón de la receta pero de otro grupo.
        $this->assertArrayHasKey('alternativas.Caja', $this->calculo->validarAlternativas($receta, ['Caja' => self::ETIQUETA]));
    }
}
