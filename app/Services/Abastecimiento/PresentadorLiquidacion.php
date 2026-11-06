<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Enterprise;
use App\Models\User;

/**
 * Forma JSON de las liquidaciones: resumen (listados y eventos), ficha completa y las acciones (`puede`)
 * que tiene a la mano cada usuario. Punto único de la forma pública; el controlador solo la entrega.
 */
class PresentadorLiquidacion
{
    private const SUBMODULO = 'liquidaciones';

    public function __construct(private ContextoAbastecimiento $ctx)
    {
    }

    /** Relaciones que necesita el resumen (el convenio trae el % de rezaga que muestran las filas y los eventos). */
    public function cargaResumen(): array
    {
        return [
            'convenio:id,folio,productor_id,variedad_id,modalidad,unidad,moneda,porcentaje_rezaga,temporada_id',
            'convenio.variedad:id,nombre',
            'productor:id,nombre,tipo',
        ];
    }

    /**
     * Carga del evento en tiempo real de una liquidación. El canal del módulo lo recibe cualquiera con acceso al
     * módulo (también quien solo captura recepciones), así que NO lleva montos, ajustes ni notas: el front solo
     * filtra por `entidad` y vuelve a pedir lo que sí puede ver.
     *
     * @return array{id: int, folio: string, status: string, convenio_id: int, productor_id: int, moneda: string}
     */
    public static function evento(Liquidacion $l): array
    {
        return [
            'id' => (int) $l->id,
            'folio' => $l->folio,
            'status' => $l->status,
            'convenio_id' => (int) $l->convenio_id,
            'productor_id' => (int) $l->productor_id,
            'moneda' => $l->moneda,
        ];
    }

    /** Para listados: la liquidación con su convenio y productor. */
    public function resumen(Liquidacion $l): array
    {
        $l->loadMissing($this->cargaResumen());

        return $this->base($l) + [
            'convenio' => $this->datosConvenio($l),
            'productor' => $this->datosProductor($l),
        ];
    }

    /**
     * Ficha completa: detalle, convenio, productor, pasos de la ronda vigente (`aprobaciones`) y de las anteriores
     * (`rondas`), acciones posibles del usuario y datos de la empresa.
     */
    public function completa(Liquidacion $l, Enterprise $empresa, ?User $usuario): array
    {
        $l->load([
            'convenio:id,folio,productor_id,variedad_id,modalidad,unidad,moneda,porcentaje_rezaga,temporada_id',
            'convenio.variedad:id,nombre',
            'productor:id,nombre,tipo',
            'detalles' => fn ($q) => $q->orderBy('id'),
            'detalles.recepcion:id,folio,fecha,tipo_carga_id',
            'detalles.recepcion.tipoCarga:id,nombre',
            'aprobaciones' => fn ($q) => $q->orderBy('ronda')->orderBy('step_order')->orderBy('id'),
            'aprobaciones.decisor:id,name',
        ]);

        $porRonda = $l->aprobaciones->groupBy('ronda');
        $rondas = $porRonda->filter(fn ($pasos, $ronda) => (int) $ronda < (int) $l->ronda)
            ->map(fn ($pasos, $ronda) => [
                'ronda' => (int) $ronda,
                'aprobaciones' => $pasos->map(fn (LiquidacionAprobacion $a) => $this->aprobacion($a))->values()->all(),
            ])->sortKeys()->values()->all();

        return $this->base($l) + [
            'convenio' => $this->datosConvenio($l),
            'productor' => $this->datosProductor($l),
            'detalles' => $l->detalles->map(fn (LiquidacionDetalle $d) => [
                'id' => $d->id,
                'recepcion_id' => $d->recepcion_id,
                'folio' => $d->recepcion?->folio,
                'fecha' => $d->recepcion?->fecha->format('Y-m-d'),
                'tipo_carga' => $d->recepcion?->tipoCarga ? ['id' => $d->recepcion->tipoCarga->id, 'nombre' => $d->recepcion->tipoCarga->nombre] : null,
                'cantidad' => $this->f($d->cantidad),
                'kilos' => $this->f($d->kilos),
                'rezaga_kg' => $this->f($d->rezaga_kg),
                'precio' => $this->f($d->precio),
                'porcentaje_productor' => $this->f($d->porcentaje_productor),
                'subtotal' => $this->f($d->subtotal),
                'descuento' => $this->f($d->descuento),
                'a_pagar' => $this->f($d->a_pagar),
            ])->values()->all(),
            'aprobaciones' => ($porRonda->get((int) $l->ronda) ?? collect())
                ->map(fn (LiquidacionAprobacion $a) => $this->aprobacion($a))->values()->all(),
            'rondas' => $rondas,
            'puede' => $this->puede($l, $empresa, $usuario),
            'empresa' => [
                'id' => $empresa->id,
                'nombre' => $empresa->name,
                'razon_social' => $empresa->razon_social,
                'rfc' => $empresa->rfc,
                'direccion' => $empresa->direccion,
                'ciudad' => $empresa->ciudad,
                'pais' => $empresa->pais,
                'telefono' => $empresa->telefono,
            ],
        ];
    }

    /**
     * Acciones que el usuario tiene a la mano sobre la liquidación (lo mismo que el controlador exige al ejecutarlas):
     * editar y enviar = borrador y `edit`; aprobar y rechazar = en revisión, `view` y que le toque el paso actual
     * (cada regla permite aprobar y/o rechazar); cancelar = `delete` en borrador, en revisión o aprobada sin abonos aplicados.
     *
     * @return array{editar: bool, enviar: bool, aprobar: bool, rechazar: bool, cancelar: bool}
     */
    public function puede(Liquidacion $l, Enterprise $empresa, ?User $usuario): array
    {
        $nada = ['editar' => false, 'enviar' => false, 'aprobar' => false, 'rechazar' => false, 'cancelar' => false];
        if ($usuario === null) {
            return $nada;
        }

        $borrador = $l->status === 'borrador';
        $edita = $this->ctx->puede($usuario, $empresa, self::SUBMODULO, 'edit');

        $aprueba = false;
        $rechaza = false;
        if ($l->status === 'en_revision' && $this->ctx->puede($usuario, $empresa, self::SUBMODULO, 'view')) {
            $paso = CadenaLiquidacion::pasoActual($l);
            if ($paso !== null) {
                $aprueba = AprobadorLiquidaciones::puedeDecidir($usuario, $l, $paso, 'aprobar');
                $rechaza = AprobadorLiquidaciones::puedeDecidir($usuario, $l, $paso, 'rechazar');
            }
        }

        $cancela = false;
        if ($this->ctx->puede($usuario, $empresa, self::SUBMODULO, 'delete')) {
            $cancela = in_array($l->status, ['borrador', 'en_revision'], true)
                || ($l->status === 'aprobada' && ! AbonoAplicacion::where('liquidacion_id', $l->id)->exists());
        }

        return [
            'editar' => $borrador && $edita,
            'enviar' => $borrador && $edita,
            'aprobar' => $aprueba,
            'rechazar' => $rechaza,
            'cancelar' => $cancela,
        ] + $nada;
    }

    // ---- Piezas ----

    private function f($valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }

    private function aprobacion(LiquidacionAprobacion $a): array
    {
        return [
            'id' => $a->id,
            'ronda' => $a->ronda,
            'step_order' => $a->step_order,
            'description' => $a->description,
            'decision' => $a->decision,
            'decided_by' => $a->decided_by,
            'decided_by_name' => $a->decisor?->name,
            'comment' => $a->comment,
            'decided_at' => $a->decided_at?->toIso8601String(),
        ];
    }

    /** Campos propios de la liquidación, con los importes como números. */
    private function base(Liquidacion $l): array
    {
        return [
            'id' => $l->id,
            'folio' => $l->folio,
            'convenio_id' => $l->convenio_id,
            'productor_id' => $l->productor_id,
            'moneda' => $l->moneda,
            'periodo_inicio' => $l->periodo_inicio->format('Y-m-d'),
            'periodo_fin' => $l->periodo_fin->format('Y-m-d'),
            'cantidad' => $this->f($l->cantidad),
            'kilos' => $this->f($l->kilos),
            'bruto' => $this->f($l->bruto),
            'rezaga_real_pct' => $this->f($l->rezaga_real_pct),
            'rezaga_aceptada_pct' => $this->f($l->rezaga_aceptada_pct),
            'descuento' => $this->f($l->descuento),
            'monto_calculado' => $this->f($l->monto_calculado),
            'monto_ajustado' => $this->f($l->monto_ajustado),
            'motivo_ajuste' => $l->motivo_ajuste,
            'monto_final' => $this->f($l->monto_final),
            'status' => $l->status,
            'origen' => $l->origen,
            'paso_actual' => $l->paso_actual,
            'ronda' => $l->ronda,
            'enviada_at' => $l->enviada_at?->toIso8601String(),
            'aprobada_at' => $l->aprobada_at?->toIso8601String(),
            'rechazo_motivo' => $l->rechazo_motivo,
            'rechazada_at' => $l->rechazada_at?->toIso8601String(),
            'cancelada_at' => $l->cancelada_at?->toIso8601String(),
            'cancelada_motivo' => $l->cancelada_motivo,
            'notas' => $l->notas,
            'created_by' => $l->created_by,
        ];
    }

    private function datosConvenio(Liquidacion $l): array
    {
        $c = $l->convenio;

        return [
            'id' => $c->id,
            'folio' => $c->folio,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => $this->f($c->porcentaje_rezaga),
            'variedad' => $c->variedad ? ['id' => $c->variedad->id, 'nombre' => $c->variedad->nombre] : null,
        ];
    }

    private function datosProductor(Liquidacion $l): ?array
    {
        $p = $l->productor;

        return $p ? ['id' => $p->id, 'nombre' => $p->nombre, 'tipo' => $p->tipo] : null;
    }
}
