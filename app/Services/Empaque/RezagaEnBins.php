<?php

namespace App\Services\Empaque;

use App\Models\Temporada;
use App\Models\TipoCarga;
use Illuminate\Validation\ValidationException;

/**
 * Atributos de una rezaga capturada en bins (Elote en bins): el cliente manda
 * cuántos bins, de qué tipo de carga y (aparte) de qué clase de rezaga; los kg
 * estimados (bins × peso estimado del bin) y el tipo/subtipo de rezaga los
 * decide el servidor. Lo comparten la captura manual de rezaga y la de
 * producción.
 */
class RezagaEnBins
{
    /**
     * @param  Temporada  $temporada  Temporada en bins (decide el cultivo del bin).
     * @param  int  $tipoCargaId  Tipo de carga (bin) elegido.
     * @param  int  $bins  Cantidad de bins (>= 1).
     * @param  array  $clasesPermitidas  Clases de rezaga aceptadas (por defecto, todas).
     * @param  string|null  $clase  Clase de rezaga elegida APARTE en la captura
     *                              (hoja|fresco|cuarto_frio). Con valor, el bin puede ser
     *                              cualquiera del cultivo (de campo, sin clase). Con null se
     *                              conserva el camino legado: el bin debe traer su propia
     *                              `clase_rezaga` y esa clase manda.
     *
     * @throws ValidationException
     */
    public static function atributos(
        Temporada $temporada,
        int $tipoCargaId,
        int $bins,
        array $clasesPermitidas = TipoCarga::CLASES_REZAGA,
        ?string $clase = null,
    ): array {
        $tipoCarga = TipoCarga::query()->find($tipoCargaId);

        $tipoValido = $tipoCarga !== null
            && (int) $tipoCarga->cultivo_id === (int) $temporada->cultivo_id
            && $tipoCarga->is_active;

        if ($clase !== null) {
            // Camino nuevo: la clase se elige aparte; el bin puede no tener clase.
            if (! $tipoValido) {
                throw ValidationException::withMessages([
                    'tipo_carga_id' => 'Elige un bin de rezaga de este cultivo.',
                ]);
            }

            $mapeo = TipoCarga::mapeoDeClase($clase);

            if ($mapeo === null || ! in_array($clase, $clasesPermitidas, true)) {
                throw ValidationException::withMessages([
                    'clase_rezaga' => 'Elige un tipo de rezaga válido.',
                ]);
            }
        } else {
            // Camino legado: el bin lleva su propia clase y esa clase manda.
            $mapeo = $tipoCarga?->mapeoRezaga();

            if (
                ! $tipoValido
                || ($mapeo !== null && ! in_array($tipoCarga->clase_rezaga, $clasesPermitidas, true))
            ) {
                throw ValidationException::withMessages([
                    'tipo_carga_id' => 'Elige un bin de rezaga de este cultivo.',
                ]);
            }

            if ($mapeo === null) {
                // Un bin sin clase y sin clase elegida: se marca la clase que falta y,
                // por compatibilidad con el contrato anterior, también el bin.
                throw ValidationException::withMessages([
                    'tipo_carga_id' => 'Elige un bin de rezaga de este cultivo.',
                    'clase_rezaga' => 'Elige el tipo de rezaga.',
                ]);
            }
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
