<?php

namespace App\Services\Planeacion;

use App\Models\Etapa;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Temporada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Etapas del plan de siembra vistas desde Planeación: las de las temporadas
 * de una semana y cuántas hectáreas les quedan por asignar (superficie −
 * cortes de todas las semanas).
 */
class EtapasPlaneacion
{
    /** Una etapa es «próxima» si cosecha entre el lunes y 20 días después. */
    private const DIAS_PROXIMA = 20;

    public function __construct(private readonly CatalogoPlaneacion $catalogo) {}

    /**
     * Hectáreas de la etapa que aún no se asignan a un corte de ninguna
     * semana, redondeadas a 2 decimales. Se pueden ignorar cortes (los que se
     * están editando).
     *
     * @param  int[]  $ignorarCorteIds
     */
    public function haDisponibles(int $etapaId, array $ignorarCorteIds = []): float
    {
        $superficie = Etapa::whereKey($etapaId)->value('superficie');
        if ($superficie === null) {
            return 0.0;
        }

        $cortes = (float) ProgramaCorte::where('etapa_id', $etapaId)
            ->when($ignorarCorteIds, fn ($q) => $q->whereKeyNot($ignorarCorteIds))
            ->sum('hectareas');

        return round((float) $superficie - $cortes, 2);
    }

    /**
     * Si la etapa está activa y su lote, también activo, participa en la
     * temporada (temporada_lote activo).
     */
    public function enTemporada(int $etapaId, int $temporadaId): bool
    {
        $etapa = Etapa::with('lote:id,is_active')->whereKey($etapaId)->first(['id', 'lote_id', 'is_active']);
        if (! $etapa || ! $etapa->is_active || ! $etapa->lote || ! $etapa->lote->is_active) {
            return false;
        }

        return DB::table('temporada_lote')
            ->where('temporada_id', $temporadaId)
            ->where('lote_id', $etapa->lote_id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Todas las etapas activas de lotes activos de las temporadas dadas (las
     * abiertas que tocan la semana), con sus hectáreas disponibles y si son
     * «próximas» (cosechan entre el lunes y 20 días después). Ordenadas por
     * fecha de cosecha (proyectada, o estimada si no hay; sin fecha al final).
     *
     * @param  Collection<int, Temporada>  $temporadas
     * @return list<array{etapa_id: int, temporada_id: int, lote_id: int, lote_nombre: ?string, etapa_nombre: string, codigo: ?string, variedad_id: ?int, superficie: float, ha_disponibles: float, fecha_cosecha: ?string, proxima: bool}>
     */
    public function paraSemana(Collection $temporadas, string $lunes): array
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
            ->get(['id', 'lote_id', 'nombre', 'codigo', 'superficie', 'variedad_id', 'fecha_cosecha_estimada', 'fecha_cosecha_proyectada'])
            ->filter(fn (Etapa $e) => $e->lote && $e->lote->is_active)
            ->values();

        if ($etapas->isEmpty()) {
            return [];
        }

        $asignadas = $this->haAsignadas($etapas->pluck('id')->all());
        $hastaProxima = Carbon::parse($lunes)->addDays(self::DIAS_PROXIMA)->toDateString();

        $salida = [];
        foreach ($etapas as $etapa) {
            $superficie = round((float) $etapa->superficie, 2);
            $fecha = $this->fechaCosecha($etapa);

            $salida[] = [
                'etapa_id' => $etapa->id,
                'temporada_id' => $this->temporadaDeEtapa($etapa, $temporadasDeLote[$etapa->lote_id], $cultivoDeTemporada),
                'lote_id' => (int) $etapa->lote_id,
                'lote_nombre' => $etapa->lote->nombre,
                'etapa_nombre' => $etapa->nombre,
                'codigo' => $etapa->codigo,
                'variedad_id' => $etapa->variedad_id ? (int) $etapa->variedad_id : null,
                'superficie' => $superficie,
                'ha_disponibles' => round($superficie - ($asignadas[$etapa->id] ?? 0.0), 2),
                'fecha_cosecha' => $fecha,
                'proxima' => $fecha !== null && $fecha >= $lunes && $fecha <= $hastaProxima,
            ];
        }

        // Sin fecha de cosecha van al final.
        usort($salida, fn ($a, $b) => [$a['fecha_cosecha'] === null, $a['fecha_cosecha'], $a['lote_nombre'], $a['etapa_nombre'], $a['etapa_id']]
            <=> [$b['fecha_cosecha'] === null, $b['fecha_cosecha'], $b['lote_nombre'], $b['etapa_nombre'], $b['etapa_id']]);

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
     * Σ cortes de todas las semanas, por etapa.
     *
     * @param  int[]  $etapaIds
     * @return array<int, float>
     */
    private function haAsignadas(array $etapaIds): array
    {
        $ha = [];
        foreach (ProgramaCorte::whereIn('etapa_id', $etapaIds)
            ->groupBy('etapa_id')
            ->selectRaw('etapa_id, SUM(hectareas) AS ha')
            ->toBase()
            ->get() as $fila) {
            $ha[(int) $fila->etapa_id] = (float) $fila->ha;
        }

        return $ha;
    }
}
