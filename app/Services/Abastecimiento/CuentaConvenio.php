<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta de un convenio (spec 5.3), solo lectura. Única regla del saldo:
 *  - `valor_recibido` = Σ valor a cuenta (ValorACuenta, vivo y sin rezaga) de las recepciones no borradas;
 *  - `pagado` = Σ de los pagos no cancelados de todos los tipos salvo `saldo_inicial`;
 *  - `cargos_iniciales` = Σ de los pagos `saldo_inicial` no cancelados;
 *  - cuenta abierta: `por_pagar` = valor_recibido + cargos_iniciales − pagado;
 *  - cuenta liquidada (hay una liquidación `liquidada`): `por_pagar` = monto_final − pagado.
 *
 * El dinero se suma en centavos enteros y cada cifra sale redondeada a 2 decimales. La moneda es la del
 * convenio: cada cuenta está en una sola moneda.
 */
class CuentaConvenio
{
    /**
     * Totales de varios convenios con un número fijo de consultas (no depende de cuántos sean):
     * recepciones (con sus renglones y calibres si hay convenios por calibre), precios (si no vienen
     * cargados), pagos agrupados por convenio y tipo, y la liquidación vigente.
     *
     * @param  EloquentCollection<int, Convenio>  $convenios
     * @return array<int, array{moneda: string, valor_recibido: float, pagado: float, cargos_iniciales: float, por_pagar: float, estado: 'abierta'|'liquidada', liquidacion_id: ?int, sin_valor: int, recepciones: int}>
     *                                                                                                                                                                                                                   convenio_id => totales, en el orden de `$convenios`
     */
    public static function totales(EloquentCollection $convenios): array
    {
        if ($convenios->isEmpty()) {
            return [];
        }
        $ids = $convenios->modelKeys();
        $porId = $convenios->keyBy('id');

        // 1. Recepciones no borradas (con renglones y calibres en los convenios por calibre).
        $recepciones = self::recepcionesDe($convenios);

        // 2. Precios.
        $convenios->loadMissing('precios');

        // 3. Pagos no cancelados, sumados por convenio y tipo.
        $pagos = DB::table('abs_abonos')
            ->whereIn('convenio_id', $ids)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelado')
            ->groupBy('convenio_id', 'tipo')
            ->selectRaw('convenio_id, tipo, SUM(monto) as total')
            ->get();

        // 4. La liquidación vigente de cada convenio.
        $liquidaciones = self::liquidacionesVigentes($ids);

        $cuentas = [];
        foreach ($convenios as $convenio) {
            $cuentas[$convenio->id] = ['valor' => 0, 'pagado' => 0, 'cargos' => 0, 'sin_valor' => 0, 'recepciones' => 0];
        }

        foreach ($recepciones as $r) {
            $valor = ValorACuenta::de($porId[$r->convenio_id], $r);
            $cuentas[$r->convenio_id]['valor'] += self::centavos($valor['valor']);
            $cuentas[$r->convenio_id]['sin_valor'] += $valor['sin_valor'] ? 1 : 0;
            $cuentas[$r->convenio_id]['recepciones']++;
        }

        foreach ($pagos as $p) {
            $campo = $p->tipo === 'saldo_inicial' ? 'cargos' : 'pagado';
            $cuentas[(int) $p->convenio_id][$campo] += self::centavos((float) $p->total);
        }

        $totales = [];
        foreach ($convenios as $convenio) {
            $c = $cuentas[$convenio->id];
            $liquidacion = $liquidaciones[$convenio->id] ?? null;
            $porPagar = $liquidacion !== null
                ? self::centavos((float) $liquidacion->monto_final) - $c['pagado']
                : $c['valor'] + $c['cargos'] - $c['pagado'];

            $totales[$convenio->id] = [
                'moneda' => $convenio->moneda,
                'valor_recibido' => self::pesos($c['valor']),
                'pagado' => self::pesos($c['pagado']),
                'cargos_iniciales' => self::pesos($c['cargos']),
                'por_pagar' => self::pesos($porPagar),
                'estado' => $liquidacion !== null ? 'liquidada' : 'abierta',
                'liquidacion_id' => $liquidacion?->id,
                'sin_valor' => $c['sin_valor'],
                'recepciones' => $c['recepciones'],
            ];
        }

        return $totales;
    }

    /**
     * Detalle por recepción de la cuenta de un convenio (fecha y id ascendentes): valor a cuenta, lo pagado por
     * los pagos vigentes, lo que falta o lo pagado de más, y su estado:
     * `liquidada` (en la liquidación vigente) › `no_pagable` (con motivo) › `pagada` › `parcial` › `pendiente`.
     * `precio` solo trae valor en un convenio clásico (null en uno por calibre) y `renglones` solo en uno por
     * calibre ([] en uno clásico). `sin_valor` es true cuando la recepción no vale nada por falta de precio o de kilos
     * (la falta de rezaga solo da `motivo`).
     *
     * @return list<array{id: int, folio: string, fecha: string, tipo_carga: ?array{id: int, nombre: string}, cantidad: float, kilos: ?float, rezaga_kg: ?float, precio: ?float, renglones: list<array{calibre_id: int, calibre: ?string, cantidad: float, kilos: ?float, rezaga_kg: ?float, precio: ?float}>, valor: float, sin_valor: bool, pagado: float, falta: float, de_mas: float, estado: string, motivo: ?string, pagos: list<string>}>
     */
    public static function recepciones(Convenio $convenio): array
    {
        $recepciones = self::recepcionesDe(new EloquentCollection([$convenio]));
        $recepciones->load('tipoCarga:id,nombre');
        $convenio->loadMissing('precios');
        $liquidacion = self::liquidacionesVigentes([$convenio->id])[$convenio->id] ?? null;

        // Reparto de los pagos por recepción vigentes (no cancelados ni borrados) entre las recepciones. Solo los de
        // tipo `recepcion` tienen reparto; el filtro evita contar filas sueltas colgadas de otro tipo de pago.
        $pagado = [];
        $folios = [];
        foreach (DB::table('abs_abono_recepciones as ar')
            ->join('abs_abonos as a', 'a.id', '=', 'ar.abono_id')
            ->where('a.convenio_id', $convenio->id)
            ->where('a.tipo', 'recepcion')
            ->whereNull('a.deleted_at')
            ->where('a.status', '!=', 'cancelado')
            ->orderBy('a.id')
            ->get(['ar.recepcion_id', 'ar.monto', 'a.folio']) as $fila) {
            $id = (int) $fila->recepcion_id;
            $pagado[$id] = ($pagado[$id] ?? 0) + self::centavos((float) $fila->monto);
            $folios[$id][] = $fila->folio;
        }

        $numero = fn ($v) => $v === null ? null : (float) $v;

        return $recepciones->map(function (Recepcion $r) use ($convenio, $liquidacion, $pagado, $folios, $numero) {
            $fecha = $r->fecha->format('Y-m-d');
            $valor = ValorACuenta::de($convenio, $r);
            $valorCts = self::centavos($valor['valor']);
            $pagadoCts = $pagado[$r->id] ?? 0;

            $estado = match (true) {
                $liquidacion !== null && $r->liquidacion_id === $liquidacion->id => 'liquidada',
                $valor['motivo'] !== null => 'no_pagable',
                $valorCts > 0 && $pagadoCts >= $valorCts => 'pagada',
                $pagadoCts > 0 => 'parcial',
                default => 'pendiente',
            };

            $precioDe = fn (?int $calibreId) => $numero(PrecioVigente::enColeccion($convenio->precios, (int) $r->tipo_carga_id, $fecha, $calibreId)?->precio);

            return [
                'id' => $r->id,
                'folio' => $r->folio,
                'fecha' => $fecha,
                'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
                'cantidad' => (float) $r->cantidad,
                'kilos' => $numero($r->kilos),
                'rezaga_kg' => $numero($r->rezaga_kg),
                'precio' => $convenio->usa_calibres ? null : $precioDe(null),
                'renglones' => ! $convenio->usa_calibres ? [] : $r->renglones->map(fn ($g) => [
                    'calibre_id' => (int) $g->calibre_id,
                    'calibre' => $g->calibre?->nombre,
                    'cantidad' => (float) $g->cantidad,
                    'kilos' => $numero($g->kilos),
                    'rezaga_kg' => $numero($g->rezaga_kg),
                    'precio' => $precioDe((int) $g->calibre_id),
                ])->values()->all(),
                'valor' => self::pesos($valorCts),
                'sin_valor' => $valor['sin_valor'],
                'pagado' => self::pesos($pagadoCts),
                'falta' => self::pesos(max(0, $valorCts - $pagadoCts)),
                'de_mas' => self::pesos(max(0, $pagadoCts - $valorCts)),
                'estado' => $estado,
                'motivo' => $valor['motivo'],
                'pagos' => array_values(array_unique($folios[$r->id] ?? [])),
            ];
        })->values()->all();
    }

    /**
     * Recepciones no borradas de los convenios, por fecha e id; en las de convenios por calibre carga
     * `renglones.calibre` (una consulta para todos), en las clásicas deja los renglones vacíos sin consultar.
     *
     * @param  EloquentCollection<int, Convenio>  $convenios
     * @return EloquentCollection<int, Recepcion>
     */
    private static function recepcionesDe(EloquentCollection $convenios): EloquentCollection
    {
        $porId = $convenios->keyBy('id');
        $recepciones = Recepcion::whereIn('convenio_id', $convenios->modelKeys())
            ->orderBy('fecha')->orderBy('id')
            ->get();

        $porCalibre = new EloquentCollection;
        foreach ($recepciones as $r) {
            if ($porId[$r->convenio_id]->usa_calibres) {
                $porCalibre->push($r);
            } else {
                $r->setRelation('renglones', new EloquentCollection);
            }
        }
        if ($porCalibre->isNotEmpty()) {
            $porCalibre->load(['renglones.calibre' => fn ($q) => $q->select('id', 'nombre')]);
        }

        return $recepciones;
    }

    /**
     * La liquidación `liquidada` (vigente) de cada convenio; si hubiera más de una, la más reciente.
     *
     * @param  array<int, int>  $ids
     * @return array<int, Liquidacion> convenio_id => liquidación
     */
    private static function liquidacionesVigentes(array $ids): array
    {
        return Liquidacion::whereIn('convenio_id', $ids)
            ->where('status', 'liquidada')
            ->orderBy('id')
            ->get(['id', 'convenio_id', 'monto_final'])
            ->keyBy('convenio_id')
            ->all();
    }

    private static function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }

    private static function pesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
