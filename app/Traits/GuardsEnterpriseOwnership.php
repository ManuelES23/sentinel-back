<?php

namespace App\Traits;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Aborta con 404 (no 403 — no revelar existencia a otra empresa) si un
 * recurso resuelto por route-model-binding pertenece a una empresa distinta
 * de la resuelta desde el header X-Enterprise-Slug. Si el header está
 * ausente o no resuelve a ninguna Enterprise conocida, no bloquea — mismo
 * patrón "lenient-if-absent" que RecipeController::resolveEnterprise()/
 * assertRecipeBelongsToResolvedEnterprise() ya usa.
 *
 * Pensado para modelos aislados vía pivote muchos-a-muchos (ProductCategory,
 * Brand, UnitOfMeasure — todos con enterprises(): BelongsToMany y
 * scopeForEnterprise() idénticos a los de Product).
 */
trait GuardsEnterpriseOwnership
{
    protected function resolveEnterpriseFromHeader(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');

        return $slug ? Enterprise::where('slug', $slug)->first() : null;
    }

    protected function assertBelongsToResolvedEnterprise(Model $model, Request $request): void
    {
        $enterprise = $this->resolveEnterpriseFromHeader($request);

        if (! $enterprise) {
            return;
        }

        $belongs = $model->newQuery()
            ->whereKey($model->getKey())
            ->forEnterprise($enterprise->id)
            ->exists();

        if (! $belongs) {
            abort(404);
        }
    }
}
