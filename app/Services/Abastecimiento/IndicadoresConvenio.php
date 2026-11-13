<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Indicadores de avance de un convenio (spec 6.3): meta y recibido a la fecha,
 * cumplimiento, proyección, estado del avance y valor. Es de solo lectura.
 *
 * Para no consultar por convenio, las recepciones se leen UNA vez para todos
 * los convenios, agrupadas por convenio, día y tipo de carga (`lineas`), y los
 * precios se resuelven sobre la relación `precios` ya cargada con
 * `PrecioVigente::enColeccion`. `de` y `agregado` aceptan esas líneas ya
 * leídas; sin ellas las leen por su cuenta.
 */
class IndicadoresConvenio
{
    public const ESTADOS = ['por_iniciar', 'atrasado', 'riesgo', 'en_meta', 'adelantado', 'finalizado', 'cancelado'];

    /**
     * @param  Collection|null  $lineas  líneas de recepciones de ESTE convenio (ver `lineas`)
     * @return array{
     *   pactado: float, recibido: float, meta_a_la_fecha: float, recibido_a_la_fecha: float,
     *   cumplimiento: float|null, avance: float|null, proyeccion: float, estado: string,
     *   semana_actual: array{lunes: string, numero: int, meta: float, recibido: float},
     *   comprometido: float, valor_recibido: float,
     *   calibres: array<int, array{calibre_id: int, nombre: string|null, pactado: float, comprometido: float}>,
     *   semanas: array<int, array{lunes: string, numero: int, meta: float, recibido: float, cerrada: bool}>
     * }
     */
    public static function de(Convenio $convenio, ?CarbonImmutable $hoy = null, ?Collection $lineas = null): array
    {
        $lineas ??= self::lineas(collect([$convenio]))->get($convenio->id, collect());

        return self::calcular($convenio, $hoy ?? Semanas::hoy(), $lineas)['indicadores'];
    }

    /**
     * Indicadores de varios convenios de UNA misma moneda, ponderados por valor
     * (cantidad × precio principal × % del productor).
     *
     * @param  Collection<int, Convenio>  $convenios
     * @param  Collection|null  $lineas  líneas por convenio (ver `lineas`)
     * @return array{cumplimiento: float|null, comprometido: float, valor_recibido: float, abajo_de_meta: int, con_avance: int}
     */
    public static function agregado(Collection $convenios, ?CarbonImmutable $hoy = null, ?Collection $lineas = null): array
    {
        $monedas = $convenios->pluck('moneda')->unique()->values();
        if ($monedas->count() > 1) {
            throw new InvalidArgumentException('No se pueden agregar convenios de distinta moneda: '.$monedas->implode(', ').'.');
        }

        $convenios = new EloquentCollection($convenios->values()->all());
        $convenios->loadMissing(['precios', 'metas']);
        $hoy ??= Semanas::hoy();
        $lineas ??= self::lineas($convenios);

        $ponderadoRecibido = 0.0;
        $ponderadoMeta = 0.0;
        $comprometido = 0.0;
        $valorRecibido = 0.0;
        $abajo = 0;
        $conAvance = 0;

        foreach ($convenios as $convenio) {
            // Un borrador aún no opera: no entra al cumplimiento ponderado ni a lo comprometido.
            if ($convenio->status === 'borrador') {
                continue;
            }

            $r = self::calcular($convenio, $hoy, $lineas->get($convenio->id, collect()));
            $i = $r['indicadores'];

            $ponderadoRecibido += $i['recibido_a_la_fecha'] * $r['peso'];
            $ponderadoMeta += $i['meta_a_la_fecha'] * $r['peso'];
            $comprometido += $i['comprometido'];
            $valorRecibido += $i['valor_recibido'];

            if (in_array($i['estado'], ['atrasado', 'riesgo'], true)) {
                $abajo++;
            }
            if ($i['estado'] !== 'por_iniciar') {
                $conAvance++;
            }
        }

        return [
            'cumplimiento' => $ponderadoMeta > 0 ? round($ponderadoRecibido / $ponderadoMeta, 4) : null,
            'comprometido' => round($comprometido, 2),
            'valor_recibido' => round($valorRecibido, 2),
            'abajo_de_meta' => $abajo,
            'con_avance' => $conAvance,
        ];
    }

    /**
     * Recepciones de todos los convenios en UNA consulta agrupada por convenio,
     * día y tipo de carga. La cantidad ya viene en la unidad del convenio
     * (kilos si se paga por kilo; si no, la cantidad capturada).
     *
     * Con `$soloSinLiquidar` solo cuenta las recepciones sin liquidación (`liquidacion_id` nulo
     * o ligado a una liquidación cancelada).
     *
     * @param  Collection<int, Convenio>  $convenios
     * @return Collection<int, Collection<int, array{fecha: string, tipo_carga_id: int, cantidad: float}>> convenio_id => líneas
     */
    public static function lineas(Collection $convenios, bool $soloSinLiquidar = false): Collection
    {
        $unidades = $convenios->pluck('unidad', 'id');
        if ($unidades->isEmpty()) {
            return collect();
        }

        return DB::table('abs_recepciones')
            ->whereIn('convenio_id', $unidades->keys()->all())
            ->whereNull('deleted_at')
            // Libre = sin liquidación; por si acaso, una que apunta a una liquidación cancelada también (Ruling 12 ya la libera).
            ->when($soloSinLiquidar, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('liquidacion_id')
                ->orWhereIn('liquidacion_id', DB::table('abs_liquidaciones')->select('id')->where('status', 'cancelada'))))
            ->groupBy('convenio_id', 'fecha', 'tipo_carga_id')
            ->selectRaw('convenio_id, fecha, tipo_carga_id, SUM(cantidad) as cantidad, SUM(kilos) as kilos')
            ->get()
            ->groupBy('convenio_id')
            ->map(fn (Collection $filas, $convenioId) => $filas->map(fn ($f) => [
                'fecha' => substr((string) $f->fecha, 0, 10),
                'tipo_carga_id' => (int) $f->tipo_carga_id,
                'cantidad' => (float) ($unidades->get((int) $convenioId) === 'kg' ? $f->kilos : $f->cantidad),
            ])->values());
    }

    /**
     * Valor de unas líneas de recepciones del convenio: cantidad × precio vigente
     * de su tipo y fecha × % del productor. Usa los precios ya cargados (sin consultas).
     *
     * @param  Collection  $lineas  líneas de ESTE convenio (ver `lineas`)
     */
    public static function valorDe(Convenio $convenio, Collection $lineas): float
    {
        $precios = $convenio->relationLoaded('precios') ? $convenio->precios : $convenio->precios()->get();

        return round($lineas->sum(fn (array $linea) => self::valorLinea($precios, $linea)), 2);
    }

    /** Valor de una línea; sin precio vigente para su tipo y fecha no vale nada. */
    private static function valorLinea(Collection $precios, array $linea): float
    {
        $precio = PrecioVigente::enColeccion($precios, $linea['tipo_carga_id'], $linea['fecha']);

        return $precio === null
            ? 0.0
            : $linea['cantidad'] * (float) $precio->precio * self::porcentaje($precio->porcentaje_productor) / 100;
    }

    /** @return array{indicadores: array, peso: float} peso = precio principal × % del productor / 100 */
    private static function calcular(Convenio $convenio, CarbonImmutable $hoy, Collection $lineas): array
    {
        $hoyDia = $hoy->setTimezone(Semanas::ZONA)->toDateString();
        $inicio = $convenio->fecha_inicio->format('Y-m-d');
        $fin = $convenio->fecha_fin->format('Y-m-d');
        $precios = $convenio->relationLoaded('precios') ? $convenio->precios : $convenio->precios()->get();

        $metas = MetasConvenio::metas($convenio);
        $pactado = round(array_sum($metas), 2);

        $porSemana = [];
        $lunesDeFecha = [];
        $recibido = 0.0;
        $valorRecibido = 0.0;
        foreach ($lineas as $linea) {
            $lunes = $lunesDeFecha[$linea['fecha']] ??= Semanas::lunesDe($linea['fecha']);
            $porSemana[$lunes] = ($porSemana[$lunes] ?? 0.0) + $linea['cantidad'];
            $recibido += $linea['cantidad'];

            $valorRecibido += self::valorLinea($precios, $linea);
        }

        // Semanas del convenio: las de su rango, las de sus metas y las que tengan recepciones.
        $lunes = array_values(array_unique(array_merge(Semanas::entre($inicio, $fin), array_keys($metas), array_keys($porSemana))));
        sort($lunes);

        $semanas = [];
        $metaFecha = 0.0;
        $recibidoFecha = 0.0;
        $cerradas = 0;
        foreach ($lunes as $l) {
            $meta = $metas[$l] ?? 0.0;
            $rec = $porSemana[$l] ?? 0.0;
            $cerrada = Semanas::cerrada($l, $hoy);
            if ($cerrada) {
                $metaFecha += $meta;
                $recibidoFecha += $rec;
                $cerradas++;
            }
            $semanas[] = [
                'lunes' => $l,
                'numero' => Semanas::numero($l),
                'meta' => round($meta, 2),
                'recibido' => round($rec, 2),
                'cerrada' => $cerrada,
            ];
        }

        $razon = $metaFecha > 0 && $convenio->status !== 'borrador' ? $recibidoFecha / $metaFecha : null;
        $proyeccion = $cerradas === 0
            ? $recibido
            : $recibidoFecha + ($recibidoFecha / $cerradas) * (count($lunes) - $cerradas);

        $calibres = [];
        if ($convenio->usa_calibres) {
            // Comprometido = Σ por calibre de pactado × precio del tipo principal vigente al inicio en ese calibre
            // × % del productor; el peso es su promedio por unidad pactada (misma ponderación entre convenios).
            $comprometido = 0.0;
            foreach (self::calibresDe($convenio, $precios, $inicio) as $fila) {
                $comprometido += $fila['comprometido'];
                $fila['comprometido'] = round($fila['comprometido'], 2);
                $calibres[] = $fila;
            }
            $peso = $pactado > 0 ? $comprometido / $pactado : 0.0;
        } else {
            $principal = PrecioVigente::principalEnColeccion($precios, $inicio);
            $peso = $principal !== null ? (float) $principal->precio * self::porcentaje($principal->porcentaje_productor) / 100 : 0.0;
            $comprometido = $pactado * $peso;
        }

        $actual = Semanas::lunesDe($hoyDia);

        return [
            'peso' => $peso,
            'indicadores' => [
                'pactado' => $pactado,
                'recibido' => round($recibido, 2),
                'meta_a_la_fecha' => round($metaFecha, 2),
                'recibido_a_la_fecha' => round($recibidoFecha, 2),
                'cumplimiento' => $razon === null ? null : round($razon, 4),
                'avance' => $pactado > 0 ? round($recibido / $pactado, 4) : null,
                'proyeccion' => round($proyeccion, 2),
                'estado' => self::estado($convenio->status, $inicio, $fin, $hoyDia, $razon),
                'semana_actual' => [
                    'lunes' => $actual,
                    'numero' => Semanas::numero($actual),
                    'meta' => round($metas[$actual] ?? 0.0, 2),
                    'recibido' => round($porSemana[$actual] ?? 0.0, 2),
                ],
                'comprometido' => round($comprometido, 2),
                'valor_recibido' => round($valorRecibido, 2),
                'calibres' => $calibres,
                'semanas' => $semanas,
            ],
        ];
    }

    /**
     * Todos los calibres del convenio (también los que no tengan metas), por orden del catálogo,
     * con su pactado y su comprometido sin redondear.
     *
     * @return array<int, array{calibre_id: int, nombre: string|null, pactado: float, comprometido: float}>
     */
    private static function calibresDe(Convenio $convenio, Collection $precios, string $inicio): array
    {
        $filas = $convenio->relationLoaded('calibres')
            ? $convenio->calibres
            : $convenio->calibres()->get();
        $filas->loadMissing(['calibre' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'orden')]);
        $pactado = MetasConvenio::pactadoPorCalibre($convenio);

        return $filas
            ->sortBy([fn ($a, $b) => ($a->calibre?->orden ?? 0) <=> ($b->calibre?->orden ?? 0), ['calibre_id', 'asc']])
            ->map(function ($fila) use ($precios, $inicio, $pactado) {
                $cantidad = (float) ($pactado[$fila->calibre_id] ?? 0.0);
                $principal = PrecioVigente::principalEnColeccion($precios, $inicio, (int) $fila->calibre_id);
                $precio = $principal !== null ? (float) $principal->precio * self::porcentaje($principal->porcentaje_productor) / 100 : 0.0;

                return [
                    'calibre_id' => (int) $fila->calibre_id,
                    'nombre' => $fila->calibre?->nombre,
                    'pactado' => round($cantidad, 2),
                    'comprometido' => $cantidad * $precio,
                ];
            })->values()->all();
    }

    /**
     * Estado del avance (distinto del estatus del convenio, salvo cancelado y finalizado).
     * Un borrador aún no opera: es «por iniciar» aunque su fecha de inicio ya haya pasado.
     */
    private static function estado(string $status, string $inicio, string $fin, string $hoy, ?float $razon): string
    {
        if ($status === 'cancelado' || $status === 'finalizado') {
            return $status;
        }
        if ($status === 'borrador' || $inicio > $hoy) {
            return 'por_iniciar';
        }
        if ($fin < $hoy) {
            return 'finalizado';
        }
        if ($razon === null) {
            return 'en_meta';
        }

        return match (true) {
            $razon < 0.85 => 'atrasado',
            $razon < 0.97 => 'riesgo',
            $razon <= 1.05 => 'en_meta',
            default => 'adelantado',
        };
    }

    /** Sin porcentaje (compra directa) el productor recibe el 100 %. */
    private static function porcentaje(mixed $valor): float
    {
        return $valor === null ? 100.0 : (float) $valor;
    }
}
