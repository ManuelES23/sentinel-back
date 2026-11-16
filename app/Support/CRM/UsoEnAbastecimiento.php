<?php

namespace App\Support\CRM;

use Illuminate\Support\Facades\DB;

/**
 * Abastecimiento (SBP) usa los productos del CRM como variedades y sus calibres
 * (`crm_producto_calibres`) en convenios, precios, metas, liquidaciones y recepciones. Borrar uno usado
 * rompería esas llaves: el CRM pregunta aquí antes de borrar y ofrece desactivar.
 */
class UsoEnAbastecimiento
{
    private const TABLAS_CALIBRE = [
        'abs_convenio_calibres',
        'abs_convenio_precios',
        'abs_convenio_metas',
        'abs_liquidacion_detalles',
        'abs_recepcion_calibres',
    ];

    public static function calibre(int $calibreId): bool
    {
        return self::algunCalibre([$calibreId]);
    }

    /** El producto está ligado a una variedad de Abastecimiento o alguno de sus calibres ya se usa allá. */
    public static function producto(int $productoId): bool
    {
        if (DB::table('abs_variedades')->where('producto_id', $productoId)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return self::algunCalibre(DB::table('crm_producto_calibres')->where('producto_id', $productoId)->pluck('id')->all());
    }

    /** @param  list<int>  $ids */
    private static function algunCalibre(array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        foreach (self::TABLAS_CALIBRE as $tabla) {
            if (DB::table($tabla)->whereIn('calibre_id', $ids)->exists()) {
                return true;
            }
        }

        return false;
    }
}
