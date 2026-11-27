<?php

namespace App\Services\Planeacion;

use App\Models\Etapa;
use App\Models\Temporada;
use App\Services\Cosecha\ConsultasCosecha;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rendimiento real acumulado por temporada y variedad, para verlo junto a los
 * estimados (no entra a ningún cálculo de Planeación). Mismas reglas que el
 * Dashboard de cosecha: solo etapas de lotes activos en la temporada; lo
 * cosechado de una etapa se topa a su superficie descontando la venta nacional;
 * las cajas son las empacadas de sus procesos.
 *
 * A diferencia del Dashboard, solo cuentan las etapas con hectáreas cosechadas
 * cerradas: las cajas y bins de una etapa sin cierre entrarían sin hectáreas e
 * inflarían el cociente a media cosecha. Esas etapas solo se avisan (`sin_cierre`).
 *
 * Los bins son los de las salidas de campo: en una temporada que opera en bins,
 * todas; en las demás, solo las de un tipo de carga «Bin» que no sea de rezaga.
 *
 * Devuelve totales (no cocientes) para poder juntar temporadas sin redondeos.
 */
class RendimientoReal
{
    public function __construct(private readonly ConsultasCosecha $consultas = new ConsultasCosecha) {}

    /**
     * @param  int[]  $temporadaIds
     * @return list<array{temporada_id: int, variedad_id: int, ha: float, cajas: int|null, bins: int, sin_cierre: bool}>
     *                                                                                                                    Solo las variedades con hectáreas o con datos por contar. `cajas` es null si la
     *                                                                                                                    temporada opera en bins; `sin_cierre` dice que hay etapas con cajas o bins que
     *                                                                                                                    aún no cuentan por no tener cosecha cerrada.
     */
    public function de(array $temporadaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $temporadaIds)));
        if ($ids === []) {
            return [];
        }

        $temporadas = Temporada::whereIn('id', $ids)->orderBy('id')->get();
        $hectareas = $this->hectareas($ids);
        $cajas = $this->cajas($ids);
        $bins = $this->bins($ids);

        $salida = [];
        foreach ($temporadas as $temporada) {
            $enBins = (bool) $temporada->opera_en_bins;
            $porVariedad = [];
            foreach ($this->etapasDe($temporada) as $etapa) {
                $llave = $temporada->id.'-'.$etapa->id;
                $superficie = (float) $etapa->superficie;
                $nacional = min($hectareas[$llave]['nacional'] ?? 0.0, $superficie);
                $cosechada = max(0.0, min($hectareas[$llave]['normal'] ?? 0.0, $superficie - $nacional));

                $susCajas = $enBins ? 0 : ($cajas[$llave] ?? 0);
                $susBins = $bins[$llave] ?? 0;

                $v = $porVariedad[$etapa->variedad_id] ?? ['ha' => 0.0, 'cajas' => 0, 'bins' => 0, 'sin_cierre' => false];
                if (round($cosechada, 4) > 0) {
                    $v['ha'] += $cosechada;
                    $v['cajas'] += $susCajas;
                    $v['bins'] += $susBins;
                } elseif ($susCajas > 0 || $susBins > 0) {
                    $v['sin_cierre'] = true;
                }
                $porVariedad[$etapa->variedad_id] = $v;
            }
            ksort($porVariedad);

            foreach ($porVariedad as $variedadId => $v) {
                $ha = round($v['ha'], 4);
                if ($ha > 0 || $v['sin_cierre']) {
                    $salida[] = [
                        'temporada_id' => (int) $temporada->id,
                        'variedad_id' => (int) $variedadId,
                        'ha' => $ha,
                        'cajas' => $enBins ? null : $v['cajas'],
                        'bins' => $v['bins'],
                        'sin_cierre' => $v['sin_cierre'],
                    ];
                }
            }
        }

        return $salida;
    }

    /** Etapas con variedad de los lotes activos en la temporada. */
    private function etapasDe(Temporada $temporada): Collection
    {
        return Etapa::whereIn('lote_id', $temporada->lotesActivos()->pluck('lotes.id'))
            ->whereNotNull('variedad_id')
            ->get(['id', 'variedad_id', 'superficie']);
    }

    /** @return array<string, array{normal: float, nacional: float}> por "temporada-etapa" */
    private function hectareas(array $ids): array
    {
        $salida = [];
        foreach ($this->consultas->cierresPorDia($ids)->get() as $c) {
            $llave = $c->temporada_id.'-'.$c->etapa_id;
            $salida[$llave] ??= ['normal' => 0.0, 'nacional' => 0.0];
            $salida[$llave][$c->con_venta ? 'nacional' : 'normal'] += (float) $c->ha;
        }

        return $salida;
    }

    /** @return array<string, int> cajas empacadas por "temporada-etapa" */
    private function cajas(array $ids): array
    {
        return DB::query()
            ->fromSub($this->consultas->partesDeProduccion($ids), 'p')
            ->whereNotNull('p.etapa_id')
            ->selectRaw('p.temporada_id, p.etapa_id, COALESCE(SUM(p.cajas), 0) AS total')
            ->groupBy('p.temporada_id', 'p.etapa_id')
            ->get()
            ->mapWithKeys(fn ($f) => [$f->temporada_id.'-'.$f->etapa_id => (int) $f->total])
            ->all();
    }

    /** @return array<string, int> bins salidos de campo por "temporada-etapa" */
    private function bins(array $ids): array
    {
        return DB::table('salidas_campo_cosecha as s')
            ->join('temporadas as t', 't.id', '=', 's.temporada_id')
            ->leftJoin('tipos_carga as tc', 'tc.id', '=', 's.tipo_carga_id')
            ->whereIn('s.temporada_id', $ids)
            ->where('s.eliminado', false)
            ->whereNotNull('s.etapa_id')
            ->where(fn ($q) => $q->where('t.opera_en_bins', true)
                ->orWhere(fn ($b) => $b->whereNull('tc.clase_rezaga')->whereRaw("LOWER(TRIM(tc.nombre)) LIKE 'bin%'")))
            ->selectRaw('s.temporada_id, s.etapa_id, COALESCE(SUM(s.cantidad), 0) AS total')
            ->groupBy('s.temporada_id', 's.etapa_id')
            ->get()
            ->mapWithKeys(fn ($f) => [$f->temporada_id.'-'.$f->etapa_id => (int) $f->total])
            ->all();
    }
}
