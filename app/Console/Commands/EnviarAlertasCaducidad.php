<?php

namespace App\Console\Commands;

use App\Models\Enterprise;
use App\Models\InventoryStock;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Services\Inventory\AlmacenAccessService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Avisa a cada usuario, una vez al día, de los lotes vencidos o por caducar
 * en los almacenes que puede ver. "Por caducar" usa los días de alerta de
 * cada artículo.
 */
class EnviarAlertasCaducidad extends Command
{
    public const TITULO = 'Lotes vencidos o por caducar';

    protected $signature = 'inventario:alertas-caducidad';

    protected $description = 'Notifica lotes vencidos o próximos a caducar a los usuarios de cada almacén';

    public function handle(AlmacenAccessService $almacenes): int
    {
        $hoy = now()->startOfDay();

        $filas = InventoryStock::with([
            'product:id,name,dias_alerta_caducidad',
            'entity:id,name,branch_id',
            'entity.branch:id,enterprise_id',
        ])
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->get()
            ->filter(fn ($s) => $s->expiry_date->lte(
                $hoy->copy()->addDays((int) ($s->product?->dias_alerta_caducidad ?? 30))
            ));

        $enviadas = 0;

        foreach ($filas->groupBy(fn ($s) => $s->entity?->branch?->enterprise_id) as $empresaId => $filasEmpresa) {
            $empresa = $empresaId ? Enterprise::find($empresaId) : null;
            if (! $empresa) {
                continue;
            }

            // Fetch all user IDs with active access in this enterprise
            $usuarioIds = UserEnterpriseAccess::where('enterprise_id', $empresa->id)
                ->where('is_active', true)
                ->pluck('user_id')
                ->toArray();

            // Prefetch all user_entity_access for this enterprise in one query
            $userEntityAccesses = UserEntityAccess::where('enterprise_id', $empresa->id)
                ->whereIn('user_id', $usuarioIds)
                ->get()
                ->groupBy('user_id');

            // Prefetch already-notified user IDs for this enterprise today in one query
            $yaAvisados = SystemNotification::where('title', self::TITULO)
                ->whereDate('created_at', $hoy->toDateString())
                ->whereIn('user_id', $usuarioIds)
                ->whereJsonContains('data->enterprise_id', $empresa->id)
                ->pluck('user_id')
                ->toArray();

            // Cache enterprise-wide data to avoid recomputation per user
            $idsDeEmpresa = $almacenes->idsDeEmpresa($empresa);

            foreach (User::whereIn('id', $usuarioIds)->get() as $user) {
                // Skip if already notified today for this enterprise
                if (in_array($user->id, $yaAvisados, true)) {
                    continue;
                }

                // Build visible warehouse IDs for this user
                $visibles = $this->idsVisiblesLocal($user, $empresa, $almacenes, $idsDeEmpresa, collect($userEntityAccesses[$user->id] ?? []));

                $suyas = $filasEmpresa->filter(fn ($s) => in_array((int) $s->entity_id, $visibles, true));

                if ($suyas->isEmpty()) {
                    continue;
                }

                $vencidos = $suyas->filter(fn ($s) => $s->expiry_date->lt($hoy))->count();
                $porCaducar = $suyas->count() - $vencidos;
                $nombres = $suyas->pluck('entity.name')->unique()->implode(', ');

                NotificationService::toUser($user)
                    ->high()
                    ->withAction("/{$empresa->slug}/inventario/reportes/stock", 'Ver stock')
                    ->withData(['tipo' => 'alertas_caducidad', 'vencidos' => $vencidos, 'por_caducar' => $porCaducar, 'enterprise_id' => $empresa->id])
                    ->alert(self::TITULO, "Tienes {$vencidos} lote(s) vencido(s) y {$porCaducar} por caducar en: {$nombres}.");

                $enviadas++;
            }
        }

        $this->info("Notificaciones enviadas: {$enviadas}");

        return self::SUCCESS;
    }

    /**
     * Compute visible warehouse IDs for a user in an enterprise without calling
     * AlmacenAccessService per user (which would cause N+1 queries).
     * Replicates the logic: admin/superadmin or ver_todos_almacenes permission
     * sees all enterprise warehouses; otherwise sees only assigned in user_entity_access
     * (intersected with valid enterprise entities to exclude stale grants).
     */
    private function idsVisiblesLocal(User $user, Enterprise $empresa, AlmacenAccessService $almacenes, array $idsDeEmpresa, $userEntityAccessCollection): array
    {
        if ($almacenes->esAdmin($user)) {
            return $idsDeEmpresa;
        }

        if ($almacenes->puedeVerTodos($user, $empresa)) {
            return $idsDeEmpresa;
        }

        // User can only see assigned warehouses that are still part of the enterprise
        $asignadas = $userEntityAccessCollection
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($idsDeEmpresa, $asignadas));
    }
}
