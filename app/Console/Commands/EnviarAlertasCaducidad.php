<?php

namespace App\Console\Commands;

use App\Models\Enterprise;
use App\Models\InventoryStock;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
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

            $usuarioIds = UserEnterpriseAccess::where('enterprise_id', $empresa->id)
                ->where('is_active', true)
                ->pluck('user_id');

            foreach (User::whereIn('id', $usuarioIds)->get() as $user) {
                $visibles = $almacenes->idsVisibles($user, $empresa);
                $suyas = $filasEmpresa->filter(fn ($s) => in_array((int) $s->entity_id, $visibles, true));

                if ($suyas->isEmpty() || $this->yaAvisado($user)) {
                    continue;
                }

                $vencidos = $suyas->filter(fn ($s) => $s->expiry_date->lt($hoy))->count();
                $porCaducar = $suyas->count() - $vencidos;
                $nombres = $suyas->pluck('entity.name')->unique()->implode(', ');

                NotificationService::toUser($user)
                    ->high()
                    ->withAction("/{$empresa->slug}/inventario/reportes/stock", 'Ver stock')
                    ->withData(['tipo' => 'alertas_caducidad', 'vencidos' => $vencidos, 'por_caducar' => $porCaducar])
                    ->alert(self::TITULO, "Tienes {$vencidos} lote(s) vencido(s) y {$porCaducar} por caducar en: {$nombres}.");

                $enviadas++;
            }
        }

        $this->info("Notificaciones enviadas: {$enviadas}");

        return self::SUCCESS;
    }

    private function yaAvisado(User $user): bool
    {
        return SystemNotification::where('user_id', $user->id)
            ->where('title', self::TITULO)
            ->whereDate('created_at', now()->toDateString())
            ->exists();
    }
}
