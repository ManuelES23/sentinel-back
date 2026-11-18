<?php

namespace App\Support\Empaque;

use App\Models\RecipeItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marcado inicial de recipe_items.consumo en recetas de empaque, por el nombre del producto
 * (sin mayúsculas ni acentos): tarima se usa al armar el pallet; esquinero, fleje, grapa e
 * interlock, al cerrarlo. Lo demás se queda por caja. Solo toca renglones que siguen en el
 * valor por defecto, así que volver a correrlo no pisa ajustes hechos a mano.
 */
class MarcadoConsumoRecetas
{
    private const PREFIJOS = [
        'tarima' => RecipeItem::CONSUMO_AL_ARMAR,
        'esquinero' => RecipeItem::CONSUMO_AL_CERRAR,
        'fleje' => RecipeItem::CONSUMO_AL_CERRAR,
        'grapa' => RecipeItem::CONSUMO_AL_CERRAR,
        'interlock' => RecipeItem::CONSUMO_AL_CERRAR,
    ];

    /** @return int renglones marcados */
    public static function aplicar(): int
    {
        $marcados = 0;

        DB::table('recipe_items')
            ->join('recipes', 'recipes.id', '=', 'recipe_items.recipe_id')
            ->join('products', 'products.id', '=', 'recipe_items.product_id')
            ->where('recipes.recipe_type', 'empaque')
            ->where('recipe_items.consumo', RecipeItem::CONSUMO_POR_CAJA)
            ->orderBy('recipe_items.id')
            ->select('recipe_items.id', 'products.name')
            ->chunkById(500, function ($filas) use (&$marcados) {
                foreach ($filas as $fila) {
                    $consumo = self::consumoPorNombre((string) $fila->name);
                    if ($consumo === null) {
                        continue;
                    }
                    $marcados += DB::table('recipe_items')->where('id', $fila->id)->update(['consumo' => $consumo]);
                }
            }, 'recipe_items.id', 'id');

        return $marcados;
    }

    public static function consumoPorNombre(string $nombre): ?string
    {
        $normalizado = mb_strtolower(Str::ascii($nombre));
        foreach (self::PREFIJOS as $prefijo => $consumo) {
            if (str_starts_with($normalizado, $prefijo)) {
                return $consumo;
            }
        }

        return null;
    }
}
