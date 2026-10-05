<?php

namespace App\Services\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consecutivos de Activos Fijos. La fila del consecutivo se bloquea
 * (SELECT ... FOR UPDATE) para que dos altas simultáneas no calculen el
 * mismo número; por eso siguiente() y siguienteTipo() deben llamarse dentro
 * de la transacción del alta. Si un número ya está ocupado (código
 * capturado a mano o importado) se salta.
 */
class GeneradorCodigoActivo
{
    private const DIGITOS = 6;

    public function siguiente(Enterprise $empresa): string
    {
        $prefijo = $this->prefijo($empresa);

        DB::table('fixed_asset_sequences')->insertOrIgnore([
            'enterprise_id' => $empresa->id, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $numero = (int) DB::table('fixed_asset_sequences')->where('enterprise_id', $empresa->id)
            ->lockForUpdate()->value('last_number');

        do {
            $numero++;
            $codigo = $this->formato($prefijo, $numero);
        } while (FixedAsset::withTrashed()->where('code', $codigo)->exists());

        DB::table('fixed_asset_sequences')->where('enterprise_id', $empresa->id)
            ->update(['last_number' => $numero, 'updated_at' => now()]);

        return $codigo;
    }

    public function vistaPrevia(Enterprise $empresa): ?string
    {
        if (! $empresa->asset_code_prefix) {
            return null;
        }

        $numero = (int) DB::table('fixed_asset_sequences')->where('enterprise_id', $empresa->id)->value('last_number');

        do {
            $numero++;
            $codigo = $this->formato($empresa->asset_code_prefix, $numero);
        } while (FixedAsset::withTrashed()->where('code', $codigo)->exists());

        return $codigo;
    }

    public function siguienteTipo(): string
    {
        DB::table('asset_category_sequences')->insertOrIgnore([
            'id' => 1, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $numero = (int) DB::table('asset_category_sequences')->where('id', 1)->lockForUpdate()->value('last_number');

        do {
            $numero++;
            $codigo = 'TAC-'.str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
        } while (AssetCategory::withTrashed()->where('code', $codigo)->exists());

        DB::table('asset_category_sequences')->where('id', 1)->update(['last_number' => $numero, 'updated_at' => now()]);

        return $codigo;
    }

    private function prefijo(Enterprise $empresa): string
    {
        if (! $empresa->asset_code_prefix) {
            throw ValidationException::withMessages([
                'code' => "La empresa {$empresa->name} no tiene configurado un prefijo para los códigos de activo.",
            ]);
        }

        return $empresa->asset_code_prefix;
    }

    private function formato(string $prefijo, int $numero): string
    {
        return $prefijo.'-AF-'.str_pad((string) $numero, self::DIGITOS, '0', STR_PAD_LEFT);
    }
}
