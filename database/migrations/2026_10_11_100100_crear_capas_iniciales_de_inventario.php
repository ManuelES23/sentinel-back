<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Arranque del costeo PEPS: deja cada existencia con una capa de costo.
 *
 * Solo usa el query builder (ni modelos ni servicios) para no depender de código que cambie.
 * Es idempotente respecto a las capas: una cubeta (producto, almacén, área, lote normalizado)
 * que ya tiene alguna capa no recibe otra, igual que CapasCosto::conciliar() solo crea una
 * capa 'inicial' cuando la fila no tiene capas que la respalden.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->unirFilasDuplicadas();
        $this->recortarLotes();
        $this->crearCapas();
    }

    /** Une filas con la misma llave (nulos y '' son el mismo lote): suma cantidad, reserva y valor. */
    private function unirFilasDuplicadas(): void
    {
        DB::transaction(function () {
            $filas = DB::table('inventory_stock')->orderBy('id')->get();
            $grupos = $filas->groupBy(fn ($f) => $this->clave($f->product_id, $f->entity_id, $f->area_id, $f->lot_number));

            foreach ($grupos as $clave => $grupo) {
                if ($grupo->count() < 2) {
                    continue;
                }
                $primera = $grupo->first();
                $cantidad = round((float) $grupo->sum(fn ($f) => (float) $f->quantity), 4);
                $valor = (float) $grupo->sum(fn ($f) => (float) $f->quantity * (float) $f->unit_cost);

                $cambios = [
                    'quantity' => $cantidad,
                    'reserved_quantity' => round((float) $grupo->sum(fn ($f) => (float) $f->reserved_quantity), 4),
                    // El valor unido se conserva en total_cost; unit_cost (4 decimales) es solo el promedio redondeado.
                    'unit_cost' => $cantidad != 0.0 ? round($valor / $cantidad, 4) : $primera->unit_cost,
                    'total_cost' => round($valor, 4),
                    'lot_number' => trim((string) $primera->lot_number) === '' ? null : trim($primera->lot_number),
                ];
                if ($primera->expiry_date === null && ($caducidad = $grupo->pluck('expiry_date')->filter()->min())) {
                    $cambios['expiry_date'] = $caducidad;
                }
                if (($ultimo = $grupo->pluck('last_movement_at')->filter()->max())) {
                    $cambios['last_movement_at'] = $ultimo;
                }

                // Primero se borran los duplicados: al normalizar el lote de la superviviente (p. ej. ' L-1' -> 'L-1')
                // chocaría con el índice único contra la fila que se va a unir.
                DB::table('inventory_stock')->whereIn('id', $grupo->slice(1)->pluck('id')->all())->delete();
                DB::table('inventory_stock')->where('id', $primera->id)->update($cambios);
                Log::warning('Capas PEPS: filas de stock duplicadas unidas', ['clave' => $clave, 'ids' => $grupo->pluck('id')->all()]);
            }
        });
    }

    /**
     * Deja el lote de TODAS las filas recortado (y NULL si queda vacío), igual que Cubeta: el motor busca la
     * fila por el lote recortado, así que una fila no duplicada con ' L-1' nunca coincidiría con sus capas.
     * Va después de unir duplicados: dos filas que solo difieren en espacios ya son una sola, así que el
     * recorte no puede chocar con el índice único. Idempotente.
     */
    private function recortarLotes(): void
    {
        DB::transaction(function () {
            DB::table('inventory_stock')->whereNotNull('lot_number')->orderBy('id')->chunkById(500, function ($filas) {
                foreach ($filas as $f) {
                    $lote = trim($f->lot_number);
                    $lote = $lote === '' ? null : $lote;
                    if ($lote !== $f->lot_number) {
                        DB::table('inventory_stock')->where('id', $f->id)->update(['lot_number' => $lote]);
                    }
                }
            });
        });
    }

    /** Una capa 'inicial' (o 'faltante' si es negativa) por fila con existencia y sin capas en su cubeta. */
    private function crearCapas(): void
    {
        DB::transaction(function () {
            $conCapas = [];
            foreach (DB::table('inventory_cost_layers')->select('product_id', 'entity_id', 'area_id', 'lot_number')->distinct()->get() as $c) {
                $conCapas[$this->clave($c->product_id, $c->entity_id, $c->area_id, $c->lot_number)] = true;
            }

            // inventory_stock no tiene llaves foráneas; la capa sí: una existencia de un almacén o área borrados
            // a mano haría fallar el insert (y todo el despliegue), así que se omite y se avisa.
            $almacenes = array_flip(DB::table('entities')->pluck('id')->all());
            $areas = array_flip(DB::table('areas')->pluck('id')->all());

            $ahora = now();
            DB::table('inventory_stock')->where('quantity', '!=', 0)->orderBy('id')->chunkById(500, function ($filas) use (&$conCapas, $ahora, $almacenes, $areas) {
                foreach ($filas as $f) {
                    if (! isset($almacenes[$f->entity_id]) || ($f->area_id !== null && ! isset($areas[$f->area_id]))) {
                        Log::warning('Capas PEPS: existencia huérfana omitida', ['stock_id' => $f->id, 'entity_id' => $f->entity_id, 'area_id' => $f->area_id]);

                        continue;
                    }
                    $clave = $this->clave($f->product_id, $f->entity_id, $f->area_id, $f->lot_number);
                    if (isset($conCapas[$clave])) {
                        continue;
                    }
                    $conCapas[$clave] = true;

                    $cantidad = (float) $f->quantity;
                    $negativa = $cantidad < 0;
                    DB::table('inventory_cost_layers')->insert([
                        'product_id' => $f->product_id,
                        'entity_id' => $f->entity_id,
                        'area_id' => $f->area_id,
                        'lot_number' => trim((string) $f->lot_number) === '' ? null : trim($f->lot_number),
                        'fecha_entrada' => $f->last_movement_at ?? $f->updated_at ?? $ahora,
                        'unit_cost' => $this->costoDeLaCapa($cantidad, (float) $f->unit_cost, (float) $f->total_cost),
                        'cantidad_inicial' => $negativa ? 0 : $cantidad,
                        'cantidad_restante' => $cantidad,
                        'origen' => $negativa ? 'faltante' : 'inicial',
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }
            });
        });
    }

    /**
     * Costo de la capa. Si total_cost solo difiere de cantidad × unit_cost por el redondeo de
     * unit_cost (4 decimales), se usa total_cost / cantidad (6 decimales) para que el valor de las
     * capas coincida con el de la fila; si difiere más (dato legado inconsistente) se respeta
     * unit_cost y inventario:verificar-capas lo reporta para revisarlo a mano.
     */
    private function costoDeLaCapa(float $cantidad, float $unitCost, float $totalCost): float
    {
        if ($cantidad != 0.0 && abs($totalCost - $cantidad * $unitCost) <= 0.00005 * abs($cantidad) + 0.0001) {
            return round($totalCost / $cantidad, 6);
        }

        return $unitCost;
    }

    /** Llave de cubeta. El lote va sin espacios y en minúsculas: la colación de MySQL (utf8mb4_unicode_ci) no distingue mayúsculas. */
    private function clave(mixed $producto, mixed $almacen, mixed $area, mixed $lote): string
    {
        return implode('|', [$producto, $almacen, $area ?? '-', mb_strtolower(trim((string) $lote))]);
    }

    public function down(): void
    {
        DB::table('inventory_cost_layers')->whereIn('origen', ['inicial', 'faltante'])->whereNull('movement_id')->delete();
    }
};
