<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Forma JSON de las cuentas por convenio (solo lectura): una fila por convenio para la lista y la cuenta completa
 * con sus recepciones, pagos y acciones (`puede`). Los números salen de `CuentaConvenio` (única fuente); aquí solo
 * se da forma. Todo es dinero, así que solo se usa tras exigir `liquidaciones.view`.
 *
 * Forma de `recepciones` (la de `CuentaConvenio::recepciones`, estable): cada recepción trae SIEMPRE `precio`
 * (null en un convenio por calibre) y `renglones` ([] en un convenio clásico), además de `sin_valor` (bool).
 */
class PresentadorCuenta
{
    private const SUBMODULO = 'liquidaciones';

    public function __construct(private ContextoAbastecimiento $ctx, private PresentadorLiquidacion $liquidaciones)
    {
    }

    /** Relaciones del convenio que necesitan las filas y la cuenta (los precios los carga `CuentaConvenio`). */
    public static function carga(): array
    {
        return ['productor:id,nombre,tipo', 'variedad:id,nombre'];
    }

    /**
     * Fila de la lista de cuentas.
     *
     * @param  array  $totales  los de este convenio en `CuentaConvenio::totales`
     * @param  array<int, string>  $foliosLiquidacion  liquidacion_id => folio (para `liquidacion`)
     */
    public function fila(array $totales, Convenio $c, array $foliosLiquidacion = []): array
    {
        $liquidacionId = $totales['liquidacion_id'];

        return [
            'convenio' => $this->convenio($c),
            'productor' => $this->productor($c),
            'estado' => $totales['estado'],
            'valor_recibido' => $totales['valor_recibido'],
            'pagado' => $totales['pagado'],
            'cargos_iniciales' => $totales['cargos_iniciales'],
            'por_pagar' => $totales['por_pagar'],
            'sin_valor' => $totales['sin_valor'],
            'recepciones' => $totales['recepciones'],
            'liquidacion' => $liquidacionId === null ? null : ['id' => $liquidacionId, 'folio' => $foliosLiquidacion[$liquidacionId] ?? null],
        ];
    }

    /** Cuenta completa de un convenio con las acciones que `$usuario` tiene a la mano. */
    public function completa(Convenio $c, Enterprise $empresa, User $usuario): array
    {
        $c->loadMissing(self::carga());
        $totales = CuentaConvenio::totales(new EloquentCollection([$c]))[$c->id];
        $recepciones = CuentaConvenio::recepciones($c);
        $liquidacion = $totales['liquidacion_id'] === null ? null : Liquidacion::find($totales['liquidacion_id']);

        $sinValor = array_values(array_map(
            fn (array $r) => $r['folio'],
            array_filter($recepciones, fn (array $r) => $r['sin_valor']),
        ));

        return [
            'convenio' => $this->convenio($c) + [
                'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $c->fecha_fin?->format('Y-m-d'),
                'temporada_id' => $c->temporada_id,
            ],
            'productor' => $this->productor($c),
            'estado' => $totales['estado'],
            'totales' => [
                'valor_recibido' => $totales['valor_recibido'],
                'pagado' => $totales['pagado'],
                'cargos_iniciales' => $totales['cargos_iniciales'],
                'por_pagar' => $totales['por_pagar'],
            ],
            'sin_valor' => ['cantidad' => count($sinValor), 'folios' => $sinValor],
            'recepciones' => $recepciones,
            'pagos' => $this->pagos($c),
            'liquidacion' => $liquidacion === null ? null : $this->liquidaciones->resumen($liquidacion),
            'puede' => $this->puede($totales, $empresa, $usuario),
        ];
    }

    /**
     * Acciones de la cuenta según el permiso de `liquidaciones` y el estado (lo mismo que cada endpoint exigirá).
     *
     * @return array{pagar_recepciones: bool, abonar: bool, liquidar: bool, cancelar_pago: bool, cancelar_liquidacion: bool, saldo_inicial: bool}
     */
    private function puede(array $totales, Enterprise $empresa, User $usuario): array
    {
        $tiene = fn (string $permiso) => $this->ctx->puede($usuario, $empresa, self::SUBMODULO, $permiso);
        $abierta = $totales['estado'] === 'abierta';
        $liquidada = $totales['estado'] === 'liquidada';

        return [
            'pagar_recepciones' => $tiene('create') && $abierta,
            'abonar' => $tiene('create') && ($abierta || ($liquidada && $totales['por_pagar'] > 0)),
            'liquidar' => $tiene('liquidar') && $abierta,
            'cancelar_pago' => $tiene('cancelar') && $abierta,
            'cancelar_liquidacion' => $tiene('delete') && $liquidada,
            'saldo_inicial' => $tiene('carga-inicial') && $abierta,
        ];
    }

    /**
     * Pagos del convenio (todos los tipos y estados, también los cancelados) por fecha e id, con su reparto entre
     * recepciones. Tres consultas: pagos, reparto con folios y quién canceló.
     *
     * @return list<array<string, mixed>>
     */
    private function pagos(Convenio $c): array
    {
        $pagos = Abono::where('convenio_id', $c->id)
            ->with([
                'canceladoPor:id,name',
                'recepciones' => fn ($q) => $q->orderBy('id'),
                'recepciones.recepcion' => fn ($q) => $q->withTrashed()->select('id', 'folio'),
            ])
            ->orderBy('fecha')->orderBy('id')
            ->get();

        return $pagos->map(fn (Abono $p) => [
            'id' => $p->id,
            'folio' => $p->folio,
            'tipo' => $p->tipo,
            'fecha' => $p->fecha->format('Y-m-d'),
            'monto' => (float) $p->monto,
            'moneda' => $p->moneda,
            'metodo_pago' => $p->metodo_pago,
            'referencia' => $p->referencia,
            'notas' => $p->notas,
            'status' => $p->status,
            'cancelado_motivo' => $p->cancelado_motivo,
            'cancelado_at' => $p->cancelado_at?->toIso8601String(),
            'cancelado_por' => $p->canceladoPor?->name,
            'recepciones' => $p->recepciones->map(fn (AbonoRecepcion $r) => [
                'recepcion_id' => $r->recepcion_id,
                'folio' => $r->recepcion?->folio,
                'monto' => (float) $r->monto,
            ])->values()->all(),
            'liquidacion_id' => $p->liquidacion_id,
            'created_at' => $p->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /** Datos del convenio que comparten la lista y la cuenta. */
    private function convenio(Convenio $c): array
    {
        return [
            'id' => $c->id,
            'folio' => $c->folio,
            'status' => $c->status,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'usa_calibres' => (bool) $c->usa_calibres,
            'porcentaje_rezaga' => (float) $c->porcentaje_rezaga,
            'variedad' => $c->variedad ? ['id' => $c->variedad->id, 'nombre' => $c->variedad->nombre] : null,
        ];
    }

    private function productor(Convenio $c): ?array
    {
        $p = $c->productor;

        return $p ? ['id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo] : null;
    }
}
