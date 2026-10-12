<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerificarCapasInventario extends Command
{
    protected $signature = 'inventario:verificar-capas';

    protected $description = 'Compara cada existencia contra sus capas de costo PEPS y lista diferencias';

    public function handle(): int
    {
        $capas = DB::table('inventory_cost_layers')
            ->selectRaw("product_id, entity_id, COALESCE(area_id, 0) a, COALESCE(NULLIF(lot_number, ''), '') l, SUM(cantidad_restante) q, SUM(cantidad_restante * unit_cost) v")
            ->groupBy('product_id', 'entity_id', DB::raw('COALESCE(area_id, 0)'), DB::raw("COALESCE(NULLIF(lot_number, ''), '')"))
            ->get()
            ->keyBy(fn ($r) => "{$r->product_id}|{$r->entity_id}|{$r->a}|{$r->l}");

        $diferencias = [];
        foreach (InventoryStock::orderBy('id')->cursor() as $f) {
            $clave = "{$f->product_id}|{$f->entity_id}|" . ($f->area_id ?? 0) . '|' . trim((string) $f->lot_number);
            $c = $capas->get($clave);
            $dq = round((float) $f->quantity - (float) ($c->q ?? 0), 4);
            $dv = round((float) $f->total_cost - (float) ($c->v ?? 0), 2);
            // 0.01 más la precisión de unit_cost de las capas (6 decimales): 0.0000005 por unidad.
            if (abs($dq) > 0.0001 || abs($dv) > 0.01 + 0.0000005 * abs((float) $f->quantity)) {
                $diferencias[] = [$f->id, $f->product_id, $f->entity_id, $f->lot_number, (float) $f->quantity, (float) ($c->q ?? 0), (float) $f->total_cost, round((float) ($c->v ?? 0), 2)];
            }
        }

        if ($diferencias === []) {
            $this->info('Sin diferencias entre existencias y capas.');

            return self::SUCCESS;
        }

        $this->table(['stock_id', 'producto', 'almacén', 'lote', 'cantidad', 'capas', 'valor', 'valor capas'], $diferencias);
        $this->error(count($diferencias) . ' existencia(s) con diferencias.');

        return self::FAILURE;
    }
}
