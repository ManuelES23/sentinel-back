<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Variedad as VariedadAbs;
use App\Models\Abastecimiento\VariedadCalibre;
use App\Models\Calibre;
use App\Models\Variedad as VariedadSf;
use Illuminate\Support\Str;

/**
 * Trae a una variedad de Abastecimiento los calibres de la variedad del mismo
 * nombre en el catálogo de Splendid Farms (`calibres`, ligado a `variedades`).
 *
 * El catálogo de SF no se modifica nunca. La variedad de origen se busca por
 * nombre (sin mayúsculas, acentos ni espacios dobles) dentro de un cultivo de SF
 * cuyo nombre sea igual al del cultivo de Abastecimiento o lo empiece como
 * palabras completas («Elote» → «Elote Dulce»). Con varias candidatas gana la de
 * más calibres activos y, a igualdad, la de menor id (el mismo criterio que
 * `CotizacionPorCajaSbp` del CRM).
 */
class CalibresDeSplendidFarms
{
    /** @return array{origen: ?array{cultivo: string, variedad: string}, calibres: list<array{nombre: string, ya_existe: bool}>} */
    public function disponibles(VariedadAbs $variedad): array
    {
        $origen = $this->variedadDeOrigen($variedad);
        if (! $origen) {
            return ['origen' => null, 'calibres' => []];
        }

        $existentes = $this->nombresExistentes($variedad);
        $calibres = collect($this->nombresDeOrigen($origen))
            ->map(fn (string $n) => ['nombre' => $n, 'ya_existe' => isset($existentes[$this->clave($n)])])
            ->all();

        return [
            'origen' => ['cultivo' => (string) $origen->cultivo?->nombre, 'variedad' => $origen->nombre],
            'calibres' => $calibres,
        ];
    }

    /**
     * Crea (o restaura si estaban borrados) los calibres pedidos. Los que ya
     * existen vivos, activos o no, se omiten. Debe llamarse dentro de una transacción.
     *
     * @param  list<string>  $nombres  ya validados contra `disponibles`
     * @return array{creados: int, omitidos: int}
     */
    public function importar(VariedadAbs $variedad, array $nombres): array
    {
        $creados = 0;
        $omitidos = 0;
        $orden = (int) VariedadCalibre::where('variedad_id', $variedad->id)->max('orden');

        foreach ($nombres as $nombre) {
            $existente = VariedadCalibre::withTrashed()
                ->where('variedad_id', $variedad->id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
                ->first();

            if ($existente && ! $existente->trashed()) {
                $omitidos++;

                continue;
            }

            if ($existente) {
                $existente->restore();
                $existente->update(['nombre' => $nombre, 'is_active' => true, 'orden' => ++$orden]);
            } else {
                VariedadCalibre::create([
                    'variedad_id' => $variedad->id, 'nombre' => $nombre, 'orden' => ++$orden, 'is_active' => true,
                ]);
            }
            $creados++;
        }

        return ['creados' => $creados, 'omitidos' => $omitidos];
    }

    /** Nombres que sí se pueden importar a esa variedad (para validar la petición). */
    public function nombresImportables(VariedadAbs $variedad): array
    {
        $origen = $this->variedadDeOrigen($variedad);

        return $origen ? $this->nombresDeOrigen($origen) : [];
    }

    private function variedadDeOrigen(VariedadAbs $variedad): ?VariedadSf
    {
        $variedad->loadMissing('cultivo');
        $cultivo = $this->clave((string) $variedad->cultivo?->nombre);
        $nombre = $this->clave($variedad->nombre);

        $activos = Calibre::query()->where('is_active', true)
            ->selectRaw('variedad_id, COUNT(*) as n')->groupBy('variedad_id')->pluck('n', 'variedad_id');

        return VariedadSf::query()
            ->with('cultivo')
            ->get()
            ->filter(fn (VariedadSf $v) => $this->clave($v->nombre) === $nombre
                && $v->cultivo
                && $this->mismoCultivo($cultivo, $this->clave($v->cultivo->nombre)))
            ->sortBy([fn (VariedadSf $a, VariedadSf $b) => ($activos[$b->id] ?? 0) <=> ($activos[$a->id] ?? 0), ['id', 'asc']])
            ->first();
    }

    private function mismoCultivo(string $abs, string $sf): bool
    {
        return $abs !== '' && ($sf === $abs || str_starts_with($sf, $abs.' '));
    }

    /** @return list<string> activos, sin repetir (sin distinguir mayúsculas), por orden y nombre */
    private function nombresDeOrigen(VariedadSf $origen): array
    {
        $vistos = [];
        foreach (Calibre::query()->where('variedad_id', $origen->id)->where('is_active', true)
            ->orderBy('order')->orderBy('nombre')->pluck('nombre') as $nombre) {
            $nombre = trim((string) $nombre);
            if ($nombre !== '' && mb_strlen($nombre) <= 60) {
                $vistos[mb_strtolower($nombre)] ??= $nombre;
            }
        }

        return array_values($vistos);
    }

    /** @return array<string, true> */
    private function nombresExistentes(VariedadAbs $variedad): array
    {
        return VariedadCalibre::where('variedad_id', $variedad->id)->pluck('nombre')
            ->mapWithKeys(fn ($n) => [$this->clave($n) => true])->all();
    }

    private function clave(string $texto): string
    {
        return (string) Str::of($texto)->ascii()->lower()->squish();
    }
}
