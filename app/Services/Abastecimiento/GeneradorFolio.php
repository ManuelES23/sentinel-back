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

    /** Folios candidatos que se revisan contra los ocupados en una sola consulta. */
    private const TRAMO = 500;

    public function siguiente(Enterprise $empresa, string $prefijo): string
    {
        return $this->tomar($empresa, $prefijo, 1)[0];
    }

    /**
     * Reserva `$cantidad` folios consecutivos con UN solo bloqueo del contador y UN solo UPDATE
     * (la importación de cientos de recepciones). Mismo formato y misma lógica que `siguiente`:
     * solo para CV y RC salta los folios ya ocupados, también por registros borrados; para LQ y AB
     * (`ocupados()` devuelve []) el contador es la única fuente y no se revisa ninguna tabla.
     * Debe llamarse dentro de la transacción del alta.
     *
     * @return list<string>
     */
    public function reservar(Enterprise $empresa, string $prefijo, int $cantidad): array
    {
        return $cantidad < 1 ? [] : $this->tomar($empresa, $prefijo, $cantidad);
    }

    /** @return list<string> */
    private function tomar(Enterprise $empresa, string $prefijo, int $cantidad): array
    {
        // El INSERT IGNORE que choca deja un bloqueo compartido en InnoDB y dos altas simultáneas se
        // interbloquean (1213): solo se inserta si la fila del contador todavía no existe.
        $contador = fn () => DB::table('abs_folios')->where('enterprise_id', $empresa->id)->where('prefijo', $prefijo);
        if (! $contador()->exists()) {
            DB::table('abs_folios')->insertOrIgnore([
                'enterprise_id' => $empresa->id, 'prefijo' => $prefijo, 'ultimo' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $numero = (int) $contador()->lockForUpdate()->value('ultimo');

        $folios = [];
        while (count($folios) < $cantidad) {
            // Candidatos de un tramo; una sola consulta dice cuáles están ocupados.
            $faltan = min($cantidad - count($folios), self::TRAMO);
            $candidatos = [];
            for ($i = 1; $i <= $faltan; $i++) {
                $candidatos[] = $prefijo.'-'.str_pad((string) ($numero + $i), self::DIGITOS, '0', STR_PAD_LEFT);
            }
            $ocupados = array_flip($this->ocupados($empresa, $prefijo, $candidatos));
            foreach ($candidatos as $folio) {
                $numero++;
                if (! isset($ocupados[$folio])) {
                    $folios[] = $folio;
                }
            }
        }

        $contador()->update(['ultimo' => $numero, 'updated_at' => now()]);

        return $folios;
    }

    /**
     * Folios del tramo que ya existen (con borrados lógicos incluidos).
     *
     * @param  list<string>  $folios
     * @return list<string>
     */
    private function ocupados(Enterprise $empresa, string $prefijo, array $folios): array
    {
        $modelo = match ($prefijo) {
            'CV' => Convenio::class,
            'RC' => Recepcion::class,
            default => null,
        };

        return $modelo === null ? [] : $modelo::withTrashed()
            ->where('enterprise_id', $empresa->id)->whereIn('folio', $folios)->pluck('folio')->all();
    }
}
