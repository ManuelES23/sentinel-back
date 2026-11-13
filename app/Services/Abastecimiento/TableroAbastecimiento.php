<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Tablero de Abastecimiento (spec 5.1): indicadores, convenios, gráfica de meta
 * contra recibido y avisos de UNA temporada y UNA moneda. Es de solo lectura.
 *
 * Los montos nunca se suman entre monedas, así que todo el tablero es de una
 * sola moneda (la pedida o la primera de la temporada). Los convenios
 * cancelados no entran en nada. El número de consultas no depende del número
 * de convenios: relaciones en carga ansiosa y las recepciones (todas y las
 * no liquidadas) en una consulta agrupada cada una.
 */
class TableroAbastecimiento
{
    /** Orden en que se listan los convenios según su estado del avance. */
    private const ORDEN_ESTADOS = ['atrasado', 'riesgo', 'en_meta', 'adelantado', 'por_iniciar', 'finalizado'];

    /**
     * @return array{
     *   monedas: array<int, string>, moneda: string|null,
     *   semana: array{lunes: string, numero: int, domingo: string},
     *   kpis: array, convenios: array<int, array>,
     *   grafica: array{caja: array, kg: array}, avisos: array<int, array>
     * }
     */
    public static function armar(Enterprise $empresa, int $temporadaId, ?string $monedaPedida = null, ?CarbonImmutable $hoy = null): array
    {
        $hoy ??= Semanas::hoy();
        $lunesHoy = Semanas::lunesDe($hoy);
        $rango = Semanas::rango($lunesHoy);

        $todos = Convenio::deEmpresa($empresa->id)
            ->where('temporada_id', $temporadaId)
            ->where('status', '!=', 'cancelado')
            ->with(['productor:id,nombre,tipo', 'variedad:id,nombre', 'precios.tipoCarga:id,nombre', 'metas'])
            ->orderBy('folio')
            ->get();

        $monedas = $todos->pluck('moneda')->unique()->sort()->values()->all();
        $moneda = ($monedaPedida !== null && in_array($monedaPedida, $monedas, true)) ? $monedaPedida : ($monedas[0] ?? null);

        $convenios = new EloquentCollection($todos->where('moneda', $moneda)->values()->all());
        // Calibres y precios por calibre en una consulta por relación, solo si hay convenios por calibre
        // (los clásicos no agregan consultas). Con borrados, como `IndicadoresConvenio::calibresDe`.
        CalibresConvenio::cargar($convenios);
        $lineas = IndicadoresConvenio::lineas($convenios);
        $sinLiquidar = IndicadoresConvenio::lineas($convenios, soloSinLiquidar: true);

        // Indicadores completos (con semanas) de cada convenio, una sola vez.
        $detalle = $convenios->map(fn (Convenio $c) => [
            'convenio' => $c,
            'indicadores' => IndicadoresConvenio::de($c, $hoy, $lineas->get($c->id, collect())),
        ])->sortBy([
            fn (array $a, array $b) => array_search($a['indicadores']['estado'], self::ORDEN_ESTADOS, true)
                <=> array_search($b['indicadores']['estado'], self::ORDEN_ESTADOS, true),
            fn (array $a, array $b) => strcmp($a['convenio']->folio, $b['convenio']->folio),
        ])->values();

        $kpis = IndicadoresConvenio::agregado($convenios, $hoy, $lineas) + [
            'sin_liquidar' => round($convenios->sum(
                fn (Convenio $c) => IndicadoresConvenio::valorDe($c, $sinLiquidar->get($c->id, collect())),
            ), 2),
        ];

        // Dinero (plan 2): la misma definición de SaldoProductor, limitada a la temporada (Ruling 10).
        $saldoPorPagar = $moneda === null ? 0.0 : (SaldoProductor::porPagarDeTemporada($empresa, $temporadaId)[$moneda] ?? 0.0);

        return [
            'monedas' => $monedas,
            'moneda' => $moneda,
            'semana' => ['lunes' => $rango['lunes'], 'numero' => Semanas::numero($rango['lunes']), 'domingo' => $rango['domingo']],
            'kpis' => [
                'cumplimiento' => $kpis['cumplimiento'],
                'comprometido' => $kpis['comprometido'],
                'valor_recibido' => $kpis['valor_recibido'],
                'sin_liquidar' => $kpis['sin_liquidar'],
                'saldo_por_pagar' => $saldoPorPagar,
                'abajo_de_meta' => $kpis['abajo_de_meta'],
                'con_avance' => $kpis['con_avance'],
            ],
            'convenios' => $detalle->map(fn (array $d) => self::fila($d['convenio'], $d['indicadores']))->all(),
            'grafica' => self::grafica($detalle),
            'avisos' => array_merge(
                self::avisos($detalle, $lunesHoy),
                self::avisoLiquidacionesEnRevision($empresa, $temporadaId, $moneda),
            ),
        ];
    }

    /** Un renglón del tablero: datos del convenio + indicadores sin el detalle por semana. */
    private static function fila(Convenio $c, array $indicadores): array
    {
        unset($indicadores['semanas']);

        return [
            'id' => $c->id,
            'folio' => $c->folio,
            'productor' => $c->productor,
            'variedad' => $c->variedad,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'status' => $c->status,
            'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
            'precio_principal' => self::precioPrincipal($c),
            'indicadores' => $indicadores,
        ];
    }

    /** Precio principal del convenio clásico; un convenio por calibre no tiene uno solo (nulo). */
    private static function precioPrincipal(Convenio $c): ?array
    {
        if ($c->usa_calibres) {
            return null;
        }

        $p = PrecioVigente::principalEnColeccion($c->precios, $c->fecha_inicio->format('Y-m-d'));

        return $p === null ? null : [
            'id' => $p->id,
            'tipo_carga_id' => $p->tipo_carga_id,
            'tipo_carga' => $p->tipoCarga ? ['id' => $p->tipoCarga->id, 'nombre' => $p->tipoCarga->nombre] : null,
            'precio' => $p->precio,
            'porcentaje_productor' => $p->porcentaje_productor,
            'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
        ];
    }

    /**
     * Meta contra recibido por semana, una serie por unidad: suma de los
     * convenios de esa unidad (y de la moneda del tablero).
     *
     * @return array{caja: array<int, array>, kg: array<int, array>}
     */
    private static function grafica(Collection $detalle): array
    {
        $serie = [];
        foreach (Convenio::UNIDADES as $unidad) {
            $semanas = [];
            foreach ($detalle as $d) {
                // El borrador aún no opera: no suma a la gráfica.
                if ($d['convenio']->status === 'borrador') {
                    continue;
                }
                if ($d['convenio']->unidad !== $unidad) {
                    continue;
                }
                foreach ($d['indicadores']['semanas'] as $s) {
                    $semanas[$s['lunes']] ??= ['lunes' => $s['lunes'], 'numero' => $s['numero'], 'meta' => 0.0, 'recibido' => 0.0];
                    $semanas[$s['lunes']]['meta'] += $s['meta'];
                    $semanas[$s['lunes']]['recibido'] += $s['recibido'];
                }
            }
            ksort($semanas);
            $serie[$unidad] = array_values(array_map(fn (array $s) => [
                'lunes' => $s['lunes'],
                'numero' => $s['numero'],
                'meta' => round($s['meta'], 2),
                'recibido' => round($s['recibido'], 2),
            ], $semanas));
        }

        return $serie;
    }

    /**
     * Atrasados (en el orden del tablero) y convenios que empiezan la semana
     * siguiente (por fecha de inicio y folio).
     *
     * @return array<int, array{tipo: string, convenio_id: int, texto: string}>
     */
    private static function avisos(Collection $detalle, string $lunesHoy): array
    {
        $avisos = [];

        foreach ($detalle as $d) {
            $i = $d['indicadores'];
            if ($i['estado'] !== 'atrasado') {
                continue;
            }
            $c = $d['convenio'];
            $faltan = $i['meta_a_la_fecha'] - $i['recibido_a_la_fecha'];
            $avisos[] = [
                'tipo' => 'atrasado',
                'convenio_id' => $c->id,
                'texto' => sprintf(
                    '%s · %s recibió %s %% de su meta en las semanas cerradas. Faltan %s.',
                    $c->folio,
                    $c->productor?->nombre ?? 'Sin productor',
                    self::numero(round($i['cumplimiento'] * 100)),
                    self::cantidad($faltan, $c->unidad),
                ),
            ];
        }

        $siguiente = CarbonImmutable::parse($lunesHoy)->addWeek()->toDateString();
        $inician = $detalle
            ->filter(fn (array $d) => $d['indicadores']['estado'] === 'por_iniciar'
                && Semanas::lunesDe($d['convenio']->fecha_inicio) === $siguiente)
            ->sortBy([
                fn (array $a, array $b) => strcmp($a['convenio']->fecha_inicio->format('Y-m-d'), $b['convenio']->fecha_inicio->format('Y-m-d')),
                fn (array $a, array $b) => strcmp($a['convenio']->folio, $b['convenio']->folio),
            ]);

        foreach ($inician as $d) {
            $c = $d['convenio'];
            $meta = collect($d['indicadores']['semanas'])->firstWhere('lunes', $siguiente)['meta'] ?? 0.0;
            $avisos[] = [
                'tipo' => 'inicia',
                'convenio_id' => $c->id,
                'texto' => sprintf(
                    '%s · %s empieza la semana %d con %s.',
                    $c->folio,
                    $c->productor?->nombre ?? 'Sin productor',
                    Semanas::numero($siguiente),
                    self::cantidad($meta, $c->unidad),
                ),
            ];
        }

        return $avisos;
    }

    /**
     * «N liquidaciones esperando revisión» de los convenios de la temporada en la moneda del tablero (una sola
     * consulta; ninguno si no hay). No es de un convenio, así que `convenio_id` va nulo.
     *
     * @return array<int, array{tipo: string, convenio_id: null, texto: string}>
     */
    private static function avisoLiquidacionesEnRevision(Enterprise $empresa, int $temporadaId, ?string $moneda): array
    {
        if ($moneda === null) {
            return [];
        }

        // Excluye convenios cancelados como el resto del tablero; el KPI de saldo (SaldoProductor) no los excluye, pero hoy no
        // hay diferencia: ConvenioController no deja cancelar un convenio con recepciones.
        $n = Liquidacion::where('enterprise_id', $empresa->id)
            ->where('status', 'en_revision')
            ->where('moneda', $moneda)
            ->whereIn('convenio_id', Convenio::deEmpresa($empresa->id)
                ->where('temporada_id', $temporadaId)
                ->where('status', '!=', 'cancelado')
                ->select('id'))
            ->count();

        return $n === 0 ? [] : [[
            'tipo' => 'liquidaciones_en_revision',
            'convenio_id' => null,
            'texto' => $n === 1 ? '1 liquidación esperando revisión' : self::numero($n).' liquidaciones esperando revisión',
        ]];
    }

    /** «1,000 cajas», «1 caja» o «1,500 kg». */
    private static function cantidad(float $valor, string $unidad): string
    {
        $texto = self::numero($valor);
        if ($unidad === 'kg') {
            return "{$texto} kg";
        }

        return $texto === '1' ? '1 caja' : "{$texto} cajas";
    }

    /** Miles con coma; decimales (hasta 2) solo si los hay. */
    private static function numero(float $valor): string
    {
        $valor = round($valor, 2);
        $decimales = abs($valor - round($valor)) < 0.005 ? 0 : 2;

        $texto = number_format($valor, $decimales, '.', ',');

        return $decimales > 0 ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }
}
