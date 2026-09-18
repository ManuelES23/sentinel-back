<?php

namespace App\Services\Compras;

use App\Models\RequisicionCampo;
use App\Models\RequisicionCotizacion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CotizacionService
{
    public function guardar(RequisicionCampo $req, array $datos, User $user, ?RequisicionCotizacion $cot = null): RequisicionCotizacion
    {
        return DB::transaction(function () use ($req, $datos, $user, $cot) {
            $cot ??= new RequisicionCotizacion(['requisicion_campo_id' => $req->id, 'created_by' => $user->id]);
            $cot->fill(Arr::only($datos, [
                'supplier_id', 'folio_proveedor', 'fecha', 'vigencia', 'dias_entrega', 'condiciones_pago', 'notas',
            ]))->save();

            $cot->detalles()->delete();
            foreach ($datos['detalles'] as $d) {
                $disponible = (bool) ($d['disponible'] ?? true);
                $cantidad = (float) $d['cantidad'];
                $precio = (float) ($d['precio_unitario'] ?? 0);
                $cot->detalles()->create([
                    'requisicion_detalle_id' => $d['requisicion_detalle_id'],
                    'disponible' => $disponible,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'tax_rate' => $d['tax_rate'] ?? 16,
                    'subtotal' => $disponible ? round($cantidad * $precio, 4) : 0,
                ]);
            }

            $this->recalcular($cot);

            if ($req->status === RequisicionCampo::STATUS_ENVIADA) {
                $req->update(['status' => RequisicionCampo::STATUS_EN_COTIZACION]);
            }

            return $cot->fresh(['supplier', 'detalles']);
        });
    }

    public function recalcular(RequisicionCotizacion $cot): void
    {
        $disponibles = $cot->detalles()->where('disponible', true)->get();
        $subtotal = $disponibles->sum(fn ($d) => (float) $d->subtotal);
        $iva = $disponibles->sum(fn ($d) => (float) $d->subtotal * (float) $d->tax_rate / 100);

        $cot->update([
            'subtotal' => round($subtotal, 4),
            'iva' => round($iva, 4),
            'total' => round($subtotal + $iva, 4),
        ]);
    }

    public function marcarGanadora(RequisicionCotizacion $cot): void
    {
        DB::transaction(function () use ($cot) {
            RequisicionCotizacion::where('requisicion_campo_id', $cot->requisicion_campo_id)
                ->where('id', '!=', $cot->id)
                ->update(['es_ganadora' => false]);
            $cot->update(['es_ganadora' => true]);
            $cot->requisicion->update(['status' => RequisicionCampo::STATUS_COTIZADA]);
        });
    }

    public function eliminar(RequisicionCotizacion $cot): void
    {
        DB::transaction(function () use ($cot) {
            $req = $cot->requisicion;
            $cot->delete();

            $quedan = $req->cotizaciones()->count();
            $hayGanadora = $req->cotizaciones()->where('es_ganadora', true)->exists();
            if (! $hayGanadora) {
                $req->update(['status' => $quedan > 0
                    ? RequisicionCampo::STATUS_EN_COTIZACION
                    : RequisicionCampo::STATUS_ENVIADA]);
            }
        });
    }
}
