<?php

namespace App\Events;

use App\Models\Enterprise;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Cambio en el catálogo central de tipos de activo: se avisa a todas las
 * empresas que tienen Activos Fijos, porque todas lo leen.
 */
class AssetCategoryUpdated extends ModelBroadcastEvent
{
    public function __construct(string $action, array $data)
    {
        parent::__construct($action, $data, null, 'administration', 'activos-fijos');
    }

    public function broadcastAs(): string
    {
        return 'asset-category.updated';
    }

    public function broadcastOn(): array
    {
        return Enterprise::query()
            ->whereHas('applications', fn ($a) => $a->where('slug', 'administration')
                ->whereHas('modules', fn ($m) => $m->where('slug', 'activos-fijos')))
            ->pluck('slug')
            ->map(fn ($slug) => new PrivateChannel("module.{$slug}.administration.activos-fijos"))
            ->all();
    }
}
