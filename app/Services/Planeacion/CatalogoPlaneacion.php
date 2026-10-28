<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\RecetaCombinacion;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Support\Collection;

/**
 * Catálogos del Programa semanal que se leen una sola vez por petición
 * (variedades, grupos de balance, rangos de rendimiento, recetas por
 * combinación y temporadas abiertas). Son tablas chicas, así que se cargan
 * completas y se consultan en memoria.
 */
class CatalogoPlaneacion
{
    /** @var Collection<int, Variedad>|null */
    private ?Collection $variedades = null;

    /** @var Collection<int, GrupoBalance>|null */
    private ?Collection $grupos = null;

    /** @var array<int, int>|null variedad_id => grupo_id */
    private ?array $grupoPorVariedad = null;

    /** @var array<string, array{0: float, 1: float}>|null "temporada-variedad" => [mínimo, máximo] */
    private ?array $rangos = null;

    /** @var array<string, array{id: int, nombre: string}>|null "presentación-empaque-variedad" => receta */
    private ?array $recetas = null;

    /** @var Collection<int, Temporada>|null */
    private ?Collection $temporadasAbiertas = null;

    /** Descarta lo leído para que la siguiente consulta vuelva a la base de datos. */
    public function olvidar(): void
    {
        $this->variedades = null;
        $this->grupos = null;
        $this->grupoPorVariedad = null;
        $this->rangos = null;
        $this->recetas = null;
        $this->temporadasAbiertas = null;
    }

    /** @return Collection<int, Variedad> por id, incluidas las borradas (los renglones viejos las citan). */
    public function variedades(): Collection
    {
        return $this->variedades ??= Variedad::withTrashed()
            ->orderBy('nombre')
            ->get(['id', 'cultivo_id', 'nombre', 'deleted_at'])
            ->keyBy('id');
    }

    /** @return Collection<int, GrupoBalance> por id, con sus variedades. */
    public function grupos(): Collection
    {
        if ($this->grupos === null) {
            $this->grupos = GrupoBalance::with('variedades:id')
                ->orderBy('cultivo_id')
                ->orderBy('orden')
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $this->grupoPorVariedad = [];
            foreach ($this->grupos as $grupo) {
                foreach ($grupo->variedades as $variedad) {
                    $this->grupoPorVariedad[$variedad->id] = $grupo->id;
                }
            }
        }

        return $this->grupos;
    }

    /**
     * Clave del grupo de balance de una variedad: el id del grupo como texto,
     * o «v{id}» si la variedad no tiene grupo (grupo implícito).
     */
    public function grupoDeVariedad(int $variedadId): string
    {
        $this->grupos();

        return isset($this->grupoPorVariedad[$variedadId])
            ? (string) $this->grupoPorVariedad[$variedadId]
            : 'v'.$variedadId;
    }

    /** Id público de un grupo: entero si es explícito, «v{id}» si es implícito. */
    public static function idPublico(string $clave): int|string
    {
        return ctype_digit($clave) ? (int) $clave : $clave;
    }

    public function cultivoDeGrupo(string $clave): ?int
    {
        if (str_starts_with($clave, 'v')) {
            $variedad = $this->variedades()->get((int) substr($clave, 1));

            return $variedad ? (int) $variedad->cultivo_id : null;
        }

        $grupo = $this->grupos()->get((int) $clave);

        return $grupo ? (int) $grupo->cultivo_id : null;
    }

    /**
     * Grupos (explícitos e implícitos) de los cultivos dados, en el formato del
     * payload. Las variedades borradas no forman grupo implícito.
     *
     * @param  int[]  $cultivoIds
     * @return list<array{clave: string, id: int|string, cultivo_id: int, nombre: string, variedad_ids: int[], implicito: bool}>
     */
    public function gruposDeCultivos(array $cultivoIds): array
    {
        $salida = [];
        foreach ($this->grupos() as $grupo) {
            if (in_array($grupo->cultivo_id, $cultivoIds, true)) {
                $salida[] = [
                    'clave' => (string) $grupo->id,
                    'id' => $grupo->id,
                    'cultivo_id' => $grupo->cultivo_id,
                    'nombre' => $grupo->nombre,
                    'variedad_ids' => $grupo->variedades->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                    'implicito' => false,
                ];
            }
        }

        foreach ($this->variedades() as $variedad) {
            if ($variedad->deleted_at === null
                && in_array((int) $variedad->cultivo_id, $cultivoIds, true)
                && ! isset($this->grupoPorVariedad[$variedad->id])) {
                $salida[] = [
                    'clave' => 'v'.$variedad->id,
                    'id' => 'v'.$variedad->id,
                    'cultivo_id' => (int) $variedad->cultivo_id,
                    'nombre' => $variedad->nombre,
                    'variedad_ids' => [$variedad->id],
                    'implicito' => true,
                ];
            }
        }

        return $salida;
    }

    /** @return array{0: float, 1: float}|null [mínimo, máximo] de la temporada y variedad. */
    public function rango(?int $temporadaId, int $variedadId): ?array
    {
        if ($temporadaId === null) {
            return null;
        }

        if ($this->rangos === null) {
            $this->rangos = [];
            foreach (RendimientoVariedad::get(['temporada_id', 'variedad_id', 'minimo', 'maximo']) as $r) {
                $this->rangos[$r->temporada_id.'-'.$r->variedad_id] = [(float) $r->minimo, (float) $r->maximo];
            }
        }

        return $this->rangos[$temporadaId.'-'.$variedadId] ?? null;
    }

    /**
     * Rendimiento con el que se planea (cajas/ha): mínimo, medio, máximo o el
     * exacto capturado; sin elección, el medio. Sin rango no hay rendimiento
     * (null) y la variedad no produce cajas.
     */
    public function valorUsado(?int $temporadaId, int $variedadId, ?ProgramaRendimiento $eleccion): ?float
    {
        $rango = $this->rango($temporadaId, $variedadId);
        if ($rango === null) {
            return null;
        }

        [$minimo, $maximo] = $rango;

        return match ($eleccion?->modo) {
            'minimo' => $minimo,
            'maximo' => $maximo,
            'exacto' => $eleccion->valor_exacto !== null ? (float) $eleccion->valor_exacto : ($minimo + $maximo) / 2,
            default => ($minimo + $maximo) / 2,
        };
    }

    /**
     * Receta de empaque de un renglón: primero la de su variedad (si acepta
     * una sola) y después la general (variedad nula).
     *
     * @param  int[]  $variedadIds
     * @return array{id: int, nombre: string}|null
     */
    public function receta(int $presentacionId, int $tipoEmpaqueId, array $variedadIds): ?array
    {
        if ($this->recetas === null) {
            $this->recetas = [];
            foreach (RecetaCombinacion::with('receta:id,name')->get() as $c) {
                if ($c->receta) {
                    $this->recetas[$c->presentacion_id.'-'.$c->tipo_empaque_id.'-'.($c->variedad_id ?? '*')] = [
                        'id' => $c->receta->id,
                        'nombre' => $c->receta->name,
                    ];
                }
            }
        }

        $base = $presentacionId.'-'.$tipoEmpaqueId.'-';
        if (count($variedadIds) === 1 && isset($this->recetas[$base.$variedadIds[0]])) {
            return $this->recetas[$base.$variedadIds[0]];
        }

        return $this->recetas[$base.'*'] ?? null;
    }

    /** @return Collection<int, Temporada> todas las temporadas abiertas. */
    public function temporadasAbiertas(): Collection
    {
        return $this->temporadasAbiertas ??= Temporada::where('estado', 'abierta')
            ->orderBy('fecha_inicio')
            ->orderBy('id')
            ->get(['id', 'cultivo_id', 'nombre', 'locacion', 'fecha_inicio', 'fecha_fin']);
    }

    /**
     * Temporadas abiertas cuyo rango [fecha_inicio, fecha_fin] toca [$desde, $hasta]
     * (fechas AAAA-MM-DD). Una fecha nula se toma como sin límite.
     *
     * @return Collection<int, Temporada>
     */
    public function temporadasQueTocan(string $desde, string $hasta): Collection
    {
        return $this->temporadasAbiertas()
            ->filter(fn (Temporada $t) => ($t->fecha_inicio === null || $t->fecha_inicio->toDateString() <= $hasta)
                && ($t->fecha_fin === null || $t->fecha_fin->toDateString() >= $desde))
            ->values();
    }
}
