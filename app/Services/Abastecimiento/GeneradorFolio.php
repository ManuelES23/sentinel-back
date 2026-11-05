<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use Illuminate\Support\Facades\DB;

/**
 * Folios consecutivos de Abastecimiento por empresa y prefijo (CV-0001, RC-0001).
 * La fila del contador (`abs_folios`) se bloquea con SELECT ... FOR UPDATE, así
 * que siguiente() debe llamarse dentro de la transacción del alta. Un folio ya
 * ocupado (también por un registro borrado lógicamente) se salta.
 */
class GeneradorFolio
{
    private const DIGITOS = 4;

    public function siguiente(Enterprise $empresa, string $prefijo): string
    {
        DB::table('abs_folios')->insertOrIgnore([
            'enterprise_id' => $empresa->id, 'prefijo' => $prefijo, 'ultimo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $numero = (int) DB::table('abs_folios')->where('enterprise_id', $empresa->id)
            ->where('prefijo', $prefijo)->lockForUpdate()->value('ultimo');

        do {
            $numero++;
            $folio = $prefijo.'-'.str_pad((string) $numero, self::DIGITOS, '0', STR_PAD_LEFT);
        } while ($this->ocupado($empresa, $prefijo, $folio));

        DB::table('abs_folios')->where('enterprise_id', $empresa->id)->where('prefijo', $prefijo)
            ->update(['ultimo' => $numero, 'updated_at' => now()]);

        return $folio;
    }

    private function ocupado(Enterprise $empresa, string $prefijo, string $folio): bool
    {
        $modelo = match ($prefijo) {
            'CV' => Convenio::class,
            'RC' => Recepcion::class,
            default => null,
        };

        return $modelo !== null
            && $modelo::withTrashed()->where('enterprise_id', $empresa->id)->where('folio', $folio)->exists();
    }
}
