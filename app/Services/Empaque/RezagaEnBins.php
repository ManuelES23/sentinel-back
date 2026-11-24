<?php

namespace App\Services\Empaque;

use App\Models\Temporada;
use App\Models\TipoCarga;
use Illuminate\Validation\ValidationException;

/**
 * Atributos de una rezaga capturada en bins (Elote en bins): el cliente solo
 * manda cuántos bins y de qué tipo; los kg estimados (bins × peso estimado del
 * bin) y el tipo/subtipo de rezaga los decide el servidor a partir de la clase
 * del bin. Lo comparten la captura manual de rezaga y la de producción.
 */
class RezagaEnBins
{
    /**
     * @param  Temporada  $temporada  Temporada en bins (decide el cultivo del bin).
     * @param  int  $tipoCargaId  Bin de rezaga elegido.
     * @param  int  $bins  Cantidad de bins (>= 1).
     * @param  array  $clasesPermitidas  Clases de bin aceptadas (por defecto, todas).
     *
     * @throws ValidationException
     */
    public static function atributos(
        Temporada $temporada,
        int $tipoCargaId,
        int $bins,
        array $clasesPermitidas = TipoCarga::CLASES_REZAGA,
    ): array {
        $tipoCarga = TipoCarga::query()->find($tipoCargaId);

        $mapeo = $tipoCarga?->mapeoRezaga();

        if (
            $tipoCarga === null
            || (int) $tipoCarga->cultivo_id !== (int) $temporada->cultivo_id
            || ! $tipoCarga->is_active
            || $mapeo === null
            || ! in_array($tipoCarga->clase_rezaga, $clasesPermitidas, true)
        ) {
            throw ValidationException::withMessages([
                'tipo_carga_id' => 'Elige un bin de rezaga de este cultivo.',
            ]);
        }

        if ($bins < 1) {
            throw ValidationException::withMessages([
                'cantidad_bins' => 'La cantidad de bins debe ser de al menos 1.',
            ]);
        }

        return [
            'tipo_carga_id' => $tipoCarga->id,
            'cantidad_bins' => $bins,
            'cantidad_kg' => round($bins * (float) $tipoCarga->peso_estimado_kg, 2),
            'tipo_rezaga' => $mapeo['tipo_rezaga'],
            'subtipo_rezaga' => $mapeo['subtipo_rezaga'],
            'modo_registro' => 'general',
            'total_cajas' => null,
            'peso_bascula_kg' => null,
        ];
    }
}
