<?php

namespace App\Services\Empaque;

use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;

/**
 * Cálculo puro de lo que una receta de empaque descuenta del almacén: no escribe en la base.
 *
 * - Por caja: cantidad × (1 + merma%) × cajas ÷ base, con base = cantidad del renglón del grupo
 *   «caja» (sin distinguir mayúsculas), si no output_quantity, si no 1.
 * - Por pallet (al armar / al cerrar): cantidad × (1 + merma%), completa.
 * Los renglones opcionales nunca salen; en un grupo sale la alternativa elegida o la
 * predeterminada. `solo_interno` no cambia el consumo.
 *
 * Cada renglón resultante: ['product_id', 'recipe_item_id', 'group_key', 'cantidad'].
 */
class CalculoConsumoReceta
{
    /** @param array<string, int|string|null> $alternativas group_key => recipe_item_id */
    public function porCaja(Recipe $r, int $cajas, array $alternativas): array
    {
        if ($cajas <= 0) {
            return [];
        }

        $items = $this->consumibles($r, $alternativas);
        $escala = $cajas / $this->base($r, $items);

        return $this->renglones(
            $items->filter(fn (RecipeItem $i) => $this->consumo($i) === RecipeItem::CONSUMO_POR_CAJA),
            $escala,
        );
    }

    /** @param array<string, int|string|null> $alternativas group_key => recipe_item_id */
    public function porPallet(Recipe $r, string $momento, array $alternativas): array
    {
        return $this->renglones(
            $this->consumibles($r, $alternativas)->filter(fn (RecipeItem $i) => $this->consumo($i) === $momento),
            1.0,
        );
    }

    /**
     * Cada alternativa debe ser un renglón de esta receta y de ese grupo.
     *
     * @return array<string, string> 'alternativas.{grupo}' => mensaje
     */
    public function validarAlternativas(Recipe $r, array $alternativas): array
    {
        $errores = [];
        foreach ($alternativas as $grupo => $itemId) {
            if ($itemId === null || $itemId === '') {
                continue; // sin elección: la predeterminada
            }

            $item = $r->items->first(fn (RecipeItem $i) => (int) $i->id === (int) $itemId);
            if (! $item || $this->clave($item->group_key) !== $this->clave((string) $grupo)) {
                $errores["alternativas.{$grupo}"] = "La alternativa elegida para «{$grupo}» no pertenece a ese grupo de la receta.";
            }
        }

        return $errores;
    }

    // ---- internos ----

    /** Renglones con producto y cantidad, sin opcionales y con una sola alternativa por grupo. */
    private function consumibles(Recipe $r, array $alternativas): Collection
    {
        $elegidas = [];
        foreach ($alternativas as $grupo => $itemId) {
            if ($itemId !== null && $itemId !== '') {
                $elegidas[$this->clave((string) $grupo)] = (int) $itemId;
            }
        }

        $items = $r->items->filter(fn (RecipeItem $i) => $i->product_id && (float) $i->quantity > 0 && ! $i->is_optional);

        // Una elección solo cuenta si es un renglón de ese grupo; si no, queda la predeterminada.
        $validas = [];
        foreach ($elegidas as $grupo => $itemId) {
            if ($items->contains(fn (RecipeItem $i) => (int) $i->id === $itemId && $this->clave($i->group_key) === $grupo)) {
                $validas[$grupo] = $itemId;
            }
        }

        return $items->filter(function (RecipeItem $i) use ($validas) {
            $grupo = $this->clave($i->group_key);
            if ($grupo === '') {
                return true;
            }
            if (isset($validas[$grupo])) {
                return (int) $i->id === $validas[$grupo];
            }

            return $i->is_default !== false;
        })->values();
    }

    private function base(Recipe $r, Collection $consumibles): float
    {
        $esCaja = fn (RecipeItem $i) => $this->clave($i->group_key) === 'caja' && (float) $i->quantity > 0;
        // Priorizar el grupo caja: output_quantity puede representar pallets u otras unidades.
        $caja = $consumibles->first($esCaja) ?? $r->items->first($esCaja);
        $base = (float) ($caja?->quantity ?? 0);
        if ($base <= 0) {
            $base = (float) ($r->output_quantity ?? 0);
        }

        return $base > 0 ? $base : 1.0;
    }

    private function renglones(Collection $items, float $escala): array
    {
        $renglones = [];
        foreach ($items as $item) {
            $cantidad = round((float) $item->quantity * (1 + (float) ($item->waste_percentage ?? 0) / 100) * $escala, 4);
            if ($cantidad <= 0) {
                continue;
            }

            $renglones[] = [
                'product_id' => (int) $item->product_id,
                'recipe_item_id' => (int) $item->id,
                'group_key' => filled($item->group_key) ? (string) $item->group_key : null,
                'cantidad' => $cantidad,
            ];
        }

        return $renglones;
    }

    private function consumo(RecipeItem $i): string
    {
        return $i->consumo ?: RecipeItem::CONSUMO_POR_CAJA;
    }

    private function clave(?string $grupo): string
    {
        return mb_strtolower(trim((string) $grupo));
    }
}
