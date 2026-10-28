<?php

namespace App\Services\Planeacion;

use App\Models\Etapa;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Temporada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Etapas del plan de siembra vistas desde el Programa semanal: cuáles entran
 * a cosecha en un rango de fechas y cuántas hectáreas les quedan por asignar
 * (superficie − cortes de todas las semanas − etapas adelantadas activas).
 */
class EtapasPlaneacion
{
    public function __construct(private readonly CatalogoPlaneacion $catalogo) {}

    /**
     * Hectáreas de la etapa que aún no se asignan a un corte ni a un adelanto
     * activo. Se puede ignorar un corte o un acuerdo (el que se está editando).
     */
    public function haDisponibles(int $etapaId, ?int $ignorarCorteId = null, ?int $ignorarAcuerdoId = null): float
    {
        $superficie = Etapa::whereKey($etapaId)->value('superficie');
        if ($superficie === null) {
            return 0.0;
        }

        $cortes = (float) ProgramaCorte::where('etapa_id', $etapaId)
            ->when($ignorarCorteId, fn ($q) => $q->whereKeyNot($ignorarCorteId))
            ->sum('hectareas');

        $adelantos = (float) ProgramaAcuerdo::where('etapa_id', $etapaId)
            ->where('tipo', 'adelantar_etapa')
            ->where('activo', true)
            ->when($ignorarAcuerdoId, fn ($q) => $q->whereKeyNot($ignorarAcuerdoId))
            ->sum('hectareas');

        return round((float) $superficie - $cortes - $adelantos, 2);
    }

    /**
     * Etapas activas de lotes activos en las temporadas dadas cuya fecha de
     * cosecha (proyectada, o estimada si no hay) cae en [$desde, $hasta], con
     * sus hectáreas asignadas y disponibles, ordenadas por fecha. Incluye las
     * que ya no tienen hectáreas disponibles: quien las usa decide.
     *
     * @param  Collection<int, Temporada>  $temporadas
     * @return list<array{etapa_id: int, temporada_id: int, lote_nombre: ?string, etapa_nombre: string, variedad_id: ?int, fecha_cosecha: string, superficie: float, ha_asignadas: float, ha_disponibles: float}>
     */
    public function candidatas(Collection $temporadas, string $desde, string $hasta): array
    {
        if ($temporadas->isEmpty()) {
            return [];
        }

        $cultivoDeTemporada = $temporadas->pluck('cultivo_id', 'id')->all();
        $temporadasDeLote = [];
        foreach (DB::table('temporada_lote')
            ->whereIn('temporada_id', array_keys($cultivoDeTemporada))
            ->where('is_active', true)
            ->orderBy('temporada_id')
            ->get(['temporada_id', 'lote_id']) as $par) {
            $temporadasDeLote[$par->lote_id][] = (int) $par->temporada_id;
        }

        if (! $temporadasDeLote) {
            return [];
        }

        $etapas = Etapa::with('lote:id,nombre,is_active')
            ->whereIn('lote_id', array_keys($temporadasDeLote))
            ->where('is_active', true)
            ->get(['id', 'lote_id', 'nombre', 'superficie', 'variedad_id', 'fecha_cosecha_estimada', 'fecha_cosecha_proyectada'])
            ->filter(function (Etapa $e) use ($desde, $hasta) {
                $fecha = $this->fechaCosecha($e);

                return $e->lote && $e->lote->is_active && $fecha !== null && $fecha >= $desde && $fecha <= $hasta;
            })
            ->values();

        if ($etapas->isEmpty()) {
            return [];
        }

        $asignadas = $this->haAsignadas($etapas->pluck('id')->all());

        $salida = [];
        foreach ($etapas as $etapa) {
            $superficie = round((float) $etapa->superficie, 2);
            $ha = round($asignadas[$etapa->id] ?? 0.0, 2);

            $salida[] = [
                'etapa_id' => $etapa->id,
                'temporada_id' => $this->temporadaDeEtapa($etapa, $temporadasDeLote[$etapa->lote_id], $cultivoDeTemporada),
                'lote_nombre' => $etapa->lote->nombre,
                'etapa_nombre' => $etapa->nombre,
                'variedad_id' => $etapa->variedad_id ? (int) $etapa->variedad_id : null,
                'fecha_cosecha' => $this->fechaCosecha($etapa),
                'superficie' => $superficie,
                'ha_asignadas' => $ha,
                'ha_disponibles' => round($superficie - $ha, 2),
            ];
        }

        usort($salida, fn ($a, $b) => [$a['fecha_cosecha'], $a['lote_nombre'], $a['etapa_nombre'], $a['etapa_id']]
            <=> [$b['fecha_cosecha'], $b['lote_nombre'], $b['etapa_nombre'], $b['etapa_id']]);

        return $salida;
    }

    private function fechaCosecha(Etapa $etapa): ?string
    {
        return ($etapa->fecha_cosecha_proyectada ?? $etapa->fecha_cosecha_estimada)?->toDateString();
    }

    /**
     * Si el lote está en varias temporadas, se prefiere la del cultivo de la
     * variedad de la etapa; si no, la primera.
     *
     * @param  int[]  $temporadaIds
     * @param  array<int, int>  $cultivoDeTemporada
     */
    private function temporadaDeEtapa(Etapa $etapa, array $temporadaIds, array $cultivoDeTemporada): int
    {
        $cultivo = $etapa->variedad_id ? $this->catalogo->variedades()->get($etapa->variedad_id)?->cultivo_id : null;
        foreach ($temporadaIds as $id) {
            if ($cultivo !== null && (int) $cultivoDeTemporada[$id] === (int) $cultivo) {
                return $id;
            }
        }

        return $temporadaIds[0];
    }

    /**
     * Σ cortes (todas las semanas) + Σ adelantos activos, por etapa.
     *
     * @param  int[]  $etapaIds
     * @return array<int, float>
     */
    private function haAsignadas(array $etapaIds): array
    {
        $ha = [];
        $sumar = function ($filas) use (&$ha) {
            foreach ($filas as $fila) {
                $ha[(int) $fila->etapa_id] = ($ha[(int) $fila->etapa_id] ?? 0.0) + (float) $fila->ha;
            }
        };

        $sumar(ProgramaCorte::whereIn('etapa_id', $etapaIds)
            ->groupBy('etapa_id')
            ->selectRaw('etapa_id, SUM(hectareas) AS ha')
            ->toBase()
            ->get());

        $sumar(ProgramaAcuerdo::whereIn('etapa_id', $etapaIds)
            ->where('tipo', 'adelantar_etapa')
            ->where('activo', true)
            ->groupBy('etapa_id')
            ->selectRaw('etapa_id, SUM(hectareas) AS ha')
            ->toBase()
            ->get());

        return $ha;
    }
}
