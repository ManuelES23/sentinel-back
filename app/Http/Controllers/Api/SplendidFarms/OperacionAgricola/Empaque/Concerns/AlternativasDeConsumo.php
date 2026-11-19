<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque\Concerns;

use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use App\Models\RecipeItem;

/**
 * Alternativas de consumo (grupos de la receta) registradas en un pallet y su traducción a los
 * renglones actuales de la receta; las comparten producción y embarques.
 */
trait AlternativasDeConsumo
{
    /**
     * Alternativas registradas vigentes: grupo (minúsculas) => product_id elegidos. Por producto y no
     * por renglón: el editor de recetas borra y recrea los renglones, el producto se conserva.
     * Por entrada, del pallet sin entrada ($d = null) o de todo el pallet ($todoElPallet).
     *
     * @return array<string, int[]>
     */
    protected function alternativasRegistradas(ProduccionEmpaque $p, ?ProduccionEmpaqueDetalle $d, bool $todoElPallet = false): array
    {
        $registradas = [];
        ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->when(! $todoElPallet, fn ($q) => $d ? $q->where('detalle_id', $d->id) : $q->whereNull('detalle_id'))
            ->whereNotNull('group_key')
            ->vigentes()
            ->orderBy('id')
            ->get(['group_key', 'product_id'])
            ->each(function (ProduccionEmpaqueConsumo $c) use (&$registradas) {
                $registradas[$this->claveGrupo($c->group_key)][] = (int) $c->product_id;
            });

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $registradas);
    }

    /**
     * [grupo => recipe_item_id] para el servicio: lo registrado traducido al renglón ACTUAL de la
     * receta con ese grupo y ese producto (si ya no está, queda la predeterminada), con lo pedido encima.
     */
    protected function alternativasParaConsumir(array $registradas, ?array $pedidas, ?Recipe $receta): array
    {
        $alternativas = [];
        if ($receta) {
            foreach ($registradas as $grupo => $productos) {
                $item = $receta->items->first(fn (RecipeItem $i) => $this->claveGrupo($i->group_key) === $grupo
                    && (int) $i->product_id === $productos[0]);
                if ($item) {
                    $alternativas[$grupo] = (int) $item->id;
                }
            }
        }
        foreach ($pedidas ?? [] as $grupo => $itemId) {
            $alternativas[$this->claveGrupo($grupo)] = $itemId;
        }

        return $alternativas;
    }

    protected function claveGrupo(?string $grupo): string
    {
        return mb_strtolower(trim((string) $grupo));
    }
}
