<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\Abastecimiento\CalculadoraLiquidacion;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasLiquidacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Liquidaciones a productores (submódulo `liquidaciones`). Esta parte cubre el borrador:
 * recepciones disponibles, vista previa, alta, edición, baja, listado y ficha. El flujo
 * de revisión y aprobación vive en otros servicios y endpoints.
 */
class LiquidacionController extends AbastecimientoController
{
    private const SUBMODULO = 'liquidaciones';

    private const TOPE_LISTADO = 300;

    /** Recepciones que el formulario puede ofrecer para un convenio y un periodo (más las del borrador que se edita). */
    public function recepcionesDisponibles(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'convenio_id' => ['required', 'integer', $this->viva($empresa, 'abs_convenios')],
            'periodo_inicio' => ['required', 'date_format:Y-m-d'],
            'periodo_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_inicio'],
            'liquidacion_id' => ['nullable', 'integer', $this->viva($empresa, 'abs_liquidaciones')],
        ]);

        $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
        $recepciones = ReglasLiquidacion::recepcionesDisponibles(
            $convenio, $datos['periodo_inicio'], $datos['periodo_fin'], $datos['liquidacion_id'] ?? null,
        )->load('tipoCarga:id,nombre');

        $data = $recepciones->map(function (Recepcion $r) use ($convenio) {
            $precio = PrecioVigente::enColeccion($convenio->precios, (int) $r->tipo_carga_id, $r->fecha->format('Y-m-d'));

            return [
                'id' => $r->id,
                'folio' => $r->folio,
                'fecha' => $r->fecha->format('Y-m-d'),
                'tipo_carga_id' => $r->tipo_carga_id,
                'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
                'cantidad' => (float) $r->cantidad,
                'kilos' => $r->kilos === null ? null : (float) $r->kilos,
                'rezaga_kg' => $r->rezaga_kg === null ? null : (float) $r->rezaga_kg,
                'ticket_proveedor' => $r->ticket_proveedor,
                'precio' => $precio === null ? null : (float) $precio->precio,
                'liquidacion_id' => $r->liquidacion_id,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Calcula la liquidación con la selección dada sin guardar nada. */
    public function previa(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa, true);

        $convenio = Convenio::deEmpresa($empresa->id)->with(['precios', 'variedad:id,nombre'])->findOrFail($datos['convenio_id']);
        $recepciones = ReglasLiquidacion::validarSeleccion(
            $convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin'], $datos['liquidacion_id'] ?? null,
        )->load('tipoCarga:id,nombre');
        $calculo = CalculadoraLiquidacion::calcular(
            $convenio, $recepciones,
            isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null,
            $datos['motivo_ajuste'] ?? null,
        );

        $porId = $recepciones->keyBy('id');
        $calculo['detalles'] = array_map(fn (array $d) => $this->detallePrevio($d, $porId[$d['recepcion_id']]), $calculo['detalles']);

        return response()->json(['success' => true, 'data' => $calculo + [
            'convenio_id' => $convenio->id,
            'moneda' => $convenio->moneda,
            'unidad' => $convenio->unidad,
            'periodo_inicio' => $datos['periodo_inicio'],
            'periodo_fin' => $datos['periodo_fin'],
        ]]);
    }

    /** Liquidaciones de la empresa, más recientes primero (tope de 300), sin canceladas salvo que se pidan. */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(Liquidacion::STATUSES)],
            'productor_id' => ['nullable', 'integer'],
            'convenio_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $verCanceladas = $request->boolean('con_canceladas') || ($filtros['status'] ?? null) === 'cancelada';

        $liquidaciones = Liquidacion::deEmpresa($empresa->id)
            ->with(['convenio:id,folio,productor_id,variedad_id,modalidad,unidad,moneda,temporada_id', 'convenio.variedad:id,nombre', 'productor:id,nombre,tipo'])
            ->when(! $verCanceladas, fn ($q) => $q->where('status', '!=', 'cancelada'))
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['productor_id']), fn ($q) => $q->where('productor_id', $filtros['productor_id']))
            ->when(! empty($filtros['convenio_id']), fn ($q) => $q->where('convenio_id', $filtros['convenio_id']))
            ->when(! empty($filtros['temporada_id']), fn ($q) => $q->whereIn(
                'convenio_id',
                Convenio::deEmpresa($empresa->id)->where('temporada_id', $filtros['temporada_id'])->select('id'),
            ))
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                // '!' como carácter de escape: funciona igual en MySQL y SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->where(function ($q) use ($like) {
                    $q->whereRaw("abs_liquidaciones.folio LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('productor', fn ($p) => $p->whereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$like]))
                        ->orWhereHas('convenio', fn ($c) => $c->whereRaw("abs_convenios.folio LIKE ? ESCAPE '!'", [$like]));
                });
            })
            ->orderByDesc('id')
            ->limit(self::TOPE_LISTADO)
            ->get()
            ->map(fn (Liquidacion $l) => $this->presentarResumen($l))
            ->values();

        return response()->json(['success' => true, 'data' => $liquidaciones]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $liquidacion = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);

        $liquidacion = DB::transaction(function () use ($request, $empresa, $datos) {
            $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
            $recepciones = ReglasLiquidacion::validarSeleccion($convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin']);
            $calculo = $this->calcular($convenio, $recepciones, $datos);

            $liquidacion = Liquidacion::create($this->columnas($convenio, $datos, $calculo) + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'LQ'),
                'created_by' => $request->user()?->id,
            ]);
            $this->guardarDetalles($liquidacion, $calculo, $recepciones);

            return $liquidacion;
        }, 3);

        $this->difundir('liquidacion', 'created', $this->presentarResumen($liquidacion->load($this->cargaResumen())), $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Liquidación guardada como borrador.',
            'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        Liquidacion::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa);

        $liquidacion = DB::transaction(function () use ($empresa, $id, $datos) {
            // Se relee con bloqueo: un envío o una cancelación concurrente no debe colarse entre la revisión y el cambio.
            $liquidacion = Liquidacion::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            ReglasLiquidacion::exigirBorrador($liquidacion);

            $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
            $recepciones = ReglasLiquidacion::validarSeleccion($convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin'], $liquidacion->id);
            $calculo = $this->calcular($convenio, $recepciones, $datos);

            // Se rehace todo el detalle: primero se sueltan las recepciones y luego se ligan las elegidas.
            ReglasLiquidacion::liberarRecepciones($liquidacion);
            LiquidacionDetalle::where('liquidacion_id', $liquidacion->id)->delete();
            $liquidacion->update($this->columnas($convenio, $datos, $calculo));
            $this->guardarDetalles($liquidacion, $calculo, $recepciones);

            return $liquidacion;
        }, 3);

        $this->difundir('liquidacion', 'updated', $this->presentarResumen($liquidacion->load($this->cargaResumen())), $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Liquidación actualizada.',
            'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        $data = DB::transaction(function () use ($empresa, $id) {
            $liquidacion = Liquidacion::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            ReglasLiquidacion::exigirBorrador($liquidacion);

            $data = $this->presentarResumen($liquidacion->load($this->cargaResumen()));
            ReglasLiquidacion::liberarRecepciones($liquidacion);
            LiquidacionDetalle::where('liquidacion_id', $liquidacion->id)->delete();
            $liquidacion->delete();

            return $data;
        }, 3);

        $this->difundir('liquidacion', 'deleted', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Liquidación eliminada.', 'data' => ['id' => $id]]);
    }

    // ---- Cuerpo y cálculo ----

    /** Cuerpo de alta, edición y vista previa con tipos y llaves de la empresa ya validados. */
    private function validar(Request $request, Enterprise $empresa, bool $conLiquidacion = false): array
    {
        $reglas = [
            'convenio_id' => ['required', 'integer', $this->viva($empresa, 'abs_convenios')],
            'periodo_inicio' => ['required', 'date_format:Y-m-d'],
            'periodo_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_inicio'],
            'recepcion_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recepcion_ids.*' => ['integer'],
            'monto_ajustado' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'motivo_ajuste' => ['nullable', 'string', 'max:2000'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
        if ($conLiquidacion) {
            $reglas['liquidacion_id'] = ['nullable', 'integer', $this->viva($empresa, 'abs_liquidaciones')];
        }

        return $request->validate($reglas, [
            'recepcion_ids.required' => 'Elige al menos una recepción.',
            'recepcion_ids.min' => 'Elige al menos una recepción.',
            'recepcion_ids.*.integer' => 'Las recepciones elegidas no son válidas.',
            'periodo_inicio.required' => 'Captura el inicio del periodo.',
            'periodo_fin.required' => 'Captura el fin del periodo.',
            'periodo_fin.after_or_equal' => 'El fin del periodo no puede ser anterior al inicio.',
            'monto_ajustado.numeric' => 'El monto ajustado debe ser un número.',
            'monto_ajustado.min' => 'El monto ajustado no puede ser negativo.',
        ]);
    }

    private function viva(Enterprise $empresa, string $tabla)
    {
        return Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');
    }

    private function calcular(Convenio $convenio, Collection $recepciones, array $datos): array
    {
        return CalculadoraLiquidacion::calcular(
            $convenio, $recepciones,
            isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null,
            $datos['motivo_ajuste'] ?? null,
        );
    }

    /** Columnas de la liquidación a partir del convenio, el cuerpo y el cálculo. */
    private function columnas(Convenio $convenio, array $datos, array $calculo): array
    {
        return [
            'convenio_id' => $convenio->id,
            'productor_id' => $convenio->productor_id,
            'moneda' => $convenio->moneda,
            'periodo_inicio' => $datos['periodo_inicio'],
            'periodo_fin' => $datos['periodo_fin'],
            'cantidad' => $calculo['cantidad'],
            'kilos' => $calculo['kilos'],
            'bruto' => $calculo['bruto'],
            'rezaga_real_pct' => $calculo['rezaga_real_pct'],
            'rezaga_aceptada_pct' => $calculo['rezaga_aceptada_pct'],
            'descuento' => $calculo['descuento'],
            'monto_calculado' => $calculo['monto_calculado'],
            'monto_ajustado' => $calculo['monto_ajustado'],
            'motivo_ajuste' => $calculo['motivo_ajuste'],
            'monto_final' => $calculo['monto_final'],
            'notas' => $datos['notas'] ?? null,
        ];
    }

    /**
     * Escribe el detalle y liga las recepciones. Los renglones no llevan bitácora propia
     * (se reescriben completos en cada guardado): la deja la liquidación.
     */
    private function guardarDetalles(Liquidacion $liquidacion, array $calculo, Collection $recepciones): void
    {
        foreach ($calculo['detalles'] as $detalle) {
            LiquidacionDetalle::create($detalle + ['liquidacion_id' => $liquidacion->id]);
        }

        Recepcion::whereIn('id', $recepciones->pluck('id'))->update(['liquidacion_id' => $liquidacion->id]);
    }

    // ---- Presentación ----

    private function cargaResumen(): array
    {
        return ['convenio:id,folio,productor_id,variedad_id,modalidad,unidad,moneda,temporada_id', 'convenio.variedad:id,nombre', 'productor:id,nombre,tipo'];
    }

    private function f($valor): ?float
    {
        return $valor === null ? null : (float) $valor;
    }

    /** Campos propios de la liquidación, con los importes como números. */
    private function presentarBase(Liquidacion $l): array
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

    /** Para listados y eventos: la liquidación con su convenio y productor (ya cargados). */
    private function presentarResumen(Liquidacion $l): array
    {
        $l->loadMissing($this->cargaResumen());

        return $this->presentarBase($l) + [
            'convenio' => $this->datosConvenio($l),
            'productor' => $this->datosProductor($l),
        ];
    }

    /** Ficha completa: detalle, convenio, productor, pasos de aprobación, acciones posibles y datos de la empresa. */
    private function presentarCompleta(Liquidacion $l, Enterprise $empresa, ?User $usuario): array
    {
        $l->load([
            'convenio:id,folio,productor_id,variedad_id,modalidad,unidad,moneda,porcentaje_rezaga,temporada_id',
            'convenio.variedad:id,nombre',
            'productor:id,nombre,tipo',
            'detalles' => fn ($q) => $q->orderBy('id'),
            'detalles.recepcion:id,folio,fecha,tipo_carga_id',
            'detalles.recepcion.tipoCarga:id,nombre',
            'aprobaciones' => fn ($q) => $q->orderBy('ronda')->orderBy('step_order')->orderBy('id'),
        ]);

        return $this->presentarBase($l) + [
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
            'aprobaciones' => $l->aprobaciones->map(fn ($a) => [
                'id' => $a->id,
                'ronda' => $a->ronda,
                'step_order' => $a->step_order,
                'description' => $a->description,
                'decision' => $a->decision,
                'decided_by' => $a->decided_by,
                'comment' => $a->comment,
                'decided_at' => $a->decided_at?->toIso8601String(),
            ])->values()->all(),
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
     * Acciones que el usuario tiene a la mano sobre la liquidación. Punto único de extensión:
     * la cadena de aprobación (aprobar, rechazar) y la cancelación se suman aquí.
     *
     * @return array{editar: bool, enviar: bool, aprobar: bool, rechazar: bool, cancelar: bool}
     */
    private function puede(Liquidacion $l, Enterprise $empresa, ?User $usuario): array
    {
        $borrador = $l->status === 'borrador';
        $edita = $usuario !== null && $this->ctx->puede($usuario, $empresa, self::SUBMODULO, 'edit');

        return [
            'editar' => $borrador && $edita,
            'enviar' => $borrador && $edita,
            'aprobar' => false,
            'rechazar' => false,
            'cancelar' => false,
        ];
    }

    /** Renglón de la vista previa: el cálculo más lo que la tabla muestra de la recepción. */
    private function detallePrevio(array $d, Recepcion $r): array
    {
        return $d + [
            'folio' => $r->folio,
            'fecha' => $r->fecha->format('Y-m-d'),
            'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
        ];
    }
}
