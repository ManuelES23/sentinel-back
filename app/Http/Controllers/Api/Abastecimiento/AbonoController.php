<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Enterprise;
use App\Services\Abastecimiento\AplicadorAbonos;
use App\Services\Abastecimiento\EstadoCuentaProductor;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\PresentadorLiquidacion;
use App\Services\Abastecimiento\SaldoProductor;
use App\Services\Abastecimiento\Semanas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Abonos y anticipos a productores (submódulo `abonos`): alta con aplicación a liquidaciones aprobadas,
 * cancelación, listado, productores con saldo y estado de cuenta. Las reglas de aplicación viven en
 * `AplicadorAbonos` (corre en la misma transacción del alta) y los saldos en `SaldoProductor`; aquí solo se
 * autoriza, se valida el cuerpo y, después de confirmar, se difunde.
 */
class AbonoController extends AbastecimientoController
{
    private const SUBMODULO = 'abonos';

    private const POR_PAGINA = 50;

    /** Productores de la empresa con su saldo por moneda; los que tienen movimiento primero. */
    public function productores(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // Un solo cálculo para todos los productores (nunca uno por productor).
        $saldos = SaldoProductor::porProductores($empresa, null, isset($filtros['temporada_id']) ? (int) $filtros['temporada_id'] : null);

        $productores = Productor::withTrashed()->deEmpresa($empresa->id)
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                // '!' como carácter de escape: funciona igual en MySQL y SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->whereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderBy('nombre')->orderBy('id')
            ->get(['id', 'nombre', 'tipo', 'is_active', 'deleted_at']);

        $filas = [];
        foreach ($productores as $p) {
            $monedas = collect($saldos->get($p->id, []))->sortKeys();
            $conMovimiento = $monedas->contains(fn (array $s) => $s['por_pagar'] != 0 || $s['anticipos_sin_aplicar'] != 0 || $s['sin_liquidar'] != 0);

            // Los inactivos o borrados sin ningún movimiento no aportan nada a esta pantalla.
            if (! $conMovimiento && ($p->deleted_at !== null || ! $p->is_active)) {
                continue;
            }

            $filas[] = [
                'con_movimiento' => $conMovimiento,
                'fila' => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'tipo' => $p->tipo,
                    'saldos' => $monedas->map(fn (array $s) => [
                        'moneda' => $s['moneda'],
                        'por_pagar' => $s['por_pagar'],
                        'anticipos_sin_aplicar' => $s['anticipos_sin_aplicar'],
                        'sin_liquidar' => $s['sin_liquidar'],
                        'liquidado' => $s['liquidado'],
                        'aplicado' => $s['aplicado'],
                    ])->values()->all(),
                ],
            ];
        }

        // Con movimiento primero; dentro de cada grupo se conserva el orden por nombre.
        usort($filas, fn ($a, $b) => (int) $b['con_movimiento'] <=> (int) $a['con_movimiento']);

        return response()->json(['success' => true, 'data' => array_column($filas, 'fila')]);
    }

    /** Estado de cuenta del productor en una moneda (por omisión, la primera con movimiento). */
    public function estadoCuenta(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $productor = Productor::withTrashed()->deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate(['moneda' => ['nullable', Rule::in(EstadoCuentaProductor::MONEDAS)]]);

        return response()->json(['success' => true, 'data' => EstadoCuentaProductor::armar($empresa, $productor, $datos['moneda'] ?? null)]);
    }

    /** Liquidaciones aprobadas con saldo del productor y la moneda, de la más antigua a la más nueva (`create`). */
    public function liquidacionesPendientes(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'productor_id' => ['required', 'integer'],
            'moneda' => ['required', Rule::in(EstadoCuentaProductor::MONEDAS)],
            'convenio_id' => ['nullable', 'integer'],
        ]);
        $productor = Productor::withTrashed()->deEmpresa($empresa->id)->findOrFail($datos['productor_id']);

        $liquidaciones = Liquidacion::deEmpresa($empresa->id)
            ->where('productor_id', $productor->id)->where('moneda', $datos['moneda'])->where('status', 'aprobada')
            ->when(! empty($datos['convenio_id']), fn ($q) => $q->where('convenio_id', $datos['convenio_id']))
            ->with('convenio:id,folio')
            ->orderByRaw('aprobada_at IS NULL')->orderBy('aprobada_at')->orderBy('id')
            ->get();

        $aplicado = $liquidaciones->isEmpty() ? collect() : DB::table('abs_abono_aplicaciones')
            ->whereIn('liquidacion_id', $liquidaciones->pluck('id')->all())
            ->groupBy('liquidacion_id')->selectRaw('liquidacion_id, SUM(monto) as total')
            ->pluck('total', 'liquidacion_id');

        $data = $liquidaciones->map(function (Liquidacion $l) use ($aplicado) {
            $yaAplicado = round((float) $aplicado->get($l->id, 0), 2);

            return [
                'id' => $l->id,
                'folio' => $l->folio,
                'convenio' => $l->convenio ? ['id' => $l->convenio->id, 'folio' => $l->convenio->folio] : null,
                'fecha' => ($l->aprobada_at ?? $l->created_at)?->copy()->setTimezone(Semanas::ZONA)->format('Y-m-d'),
                'monto_final' => (float) $l->monto_final,
                'aplicado' => $yaAplicado,
                'saldo' => round((float) $l->monto_final - $yaAplicado, 2),
            ];
        })->filter(fn (array $f) => $f['saldo'] > 0)->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Abonos de la empresa (más recientes primero), con sus aplicaciones; 50 por página. */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'productor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Abono::STATUSES)],
            'moneda' => ['nullable', Rule::in(EstadoCuentaProductor::MONEDAS)],
        ]);

        $pagina = Abono::deEmpresa($empresa->id)
            ->when(! empty($filtros['productor_id']), fn ($q) => $q->where('productor_id', $filtros['productor_id']))
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['moneda']), fn ($q) => $q->where('moneda', $filtros['moneda']))
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate(self::POR_PAGINA);

        $presentados = $this->presentarVarios(new EloquentCollection($pagina->items()));
        $pagina->setCollection($presentados);

        return response()->json(['success' => true, 'data' => $pagina]);
    }

    /**
     * Registra un abono y lo aplica a las liquidaciones indicadas (lo que no se aplique queda como anticipo),
     * todo en una transacción: si la aplicación falla (422) no queda abono ni folio consumido.
     */
    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);

        $aplicaciones = array_map(
            fn (array $a) => ['liquidacion_id' => (int) $a['liquidacion_id'], 'monto' => $a['monto']],
            $datos['aplicaciones'] ?? [],
        );

        $resultado = DB::transaction(function () use ($request, $empresa, $datos, $aplicaciones) {
            $productor = Productor::deEmpresa($empresa->id)->findOrFail($datos['productor_id']);
            $this->exigirConvenioDelProductor($empresa, $productor, $datos['convenio_id'] ?? null);

            $antes = $this->saldo($empresa, $productor->id, $datos['moneda']);

            $abono = Abono::create([
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'AB'),
                'productor_id' => $productor->id,
                'convenio_id' => $datos['convenio_id'] ?? null,
                'fecha' => $datos['fecha'],
                'monto' => $datos['monto'],
                'moneda' => $datos['moneda'],
                'metodo_pago' => $datos['metodo_pago'],
                'referencia' => $datos['referencia'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Misma transacción: valida todo antes de escribir y lanza 422 si algo no cuadra.
            AplicadorAbonos::aplicar($abono, $aplicaciones);

            $pagadas = $aplicaciones === [] ? collect() : Liquidacion::deEmpresa($empresa->id)
                ->whereIn('id', array_column($aplicaciones, 'liquidacion_id'))->where('status', 'pagada')
                ->orderBy('id')->get();

            return [$abono, $antes, $this->saldo($empresa, $productor->id, $datos['moneda']), $pagadas];
        }, 3);

        [$abono, $antes, $despues, $pagadas] = $resultado;

        $this->difundir('abono', 'created', $this->presentarVarios(new EloquentCollection([$abono]))->first(), $empresa);
        $this->difundirLiquidaciones($pagadas, $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Abono registrado.',
            'data' => $this->presentarVarios(new EloquentCollection([$abono]))->first() + [
                'saldo_antes' => $antes,
                'saldo_despues' => $despues,
                'liquidaciones_pagadas' => $pagadas->map(fn (Liquidacion $l) => ['id' => $l->id, 'folio' => $l->folio])->values()->all(),
            ],
        ], 201);
    }

    /**
     * Cancela el abono (`cancelar`): deshace sus aplicaciones y reabre las liquidaciones que había dejado pagadas.
     * No reaplica otros anticipos: lo liberado queda como anticipo sin aplicar.
     */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'cancelar');
        Abono::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate(
            ['motivo' => ['required', 'string', 'max:2000']],
            ['motivo.required' => 'Escribe el motivo de la cancelación.'],
        );
        $usuario = $request->user();

        [$abono, $reabiertas] = DB::transaction(function () use ($empresa, $id, $datos, $usuario) {
            // El abono se bloquea primero (mismo orden que el aplicador) y se lee qué liquidaciones tenía pagadas.
            $abono = Abono::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            $ids = AbonoAplicacion::where('abono_id', $abono->id)->pluck('liquidacion_id')->unique()->all();
            $pagadasAntes = $ids === [] ? [] : Liquidacion::whereIn('id', $ids)->where('status', 'pagada')->pluck('id')->all();

            AplicadorAbonos::cancelar($abono, $usuario, (string) $datos['motivo']);

            $reabiertas = $pagadasAntes === [] ? collect() : Liquidacion::whereIn('id', $pagadasAntes)->where('status', 'aprobada')
                ->orderBy('id')->get();

            return [$abono->fresh(), $reabiertas];
        }, 3);

        $presentado = $this->presentarVarios(new EloquentCollection([$abono]))->first();
        $this->difundir('abono', 'updated', $presentado, $empresa);
        $this->difundirLiquidaciones($reabiertas, $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Abono cancelado.',
            'data' => $presentado + [
                'liquidaciones_reabiertas' => $reabiertas->map(fn (Liquidacion $l) => ['id' => $l->id, 'folio' => $l->folio])->values()->all(),
            ],
        ]);
    }

    // ---- Cuerpo ----

    private function validar(Request $request, Enterprise $empresa): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');

        return $request->validate([
            'productor_id' => ['required', 'integer', $viva('abs_productores')],
            'convenio_id' => ['nullable', 'integer', $viva('abs_convenios')],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Semanas::hoy()->toDateString()],
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'moneda' => ['required', Rule::in(EstadoCuentaProductor::MONEDAS)],
            'metodo_pago' => ['required', Rule::in(Abono::METODOS_PAGO)],
            'referencia' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'aplicaciones' => ['nullable', 'array', 'max:200'],
            'aplicaciones.*' => ['array'],
            'aplicaciones.*.liquidacion_id' => ['required', 'integer'],
            'aplicaciones.*.monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
        ], [
            'productor_id.required' => 'Elige el productor.',
            'productor_id.exists' => 'El productor elegido no existe.',
            'convenio_id.exists' => 'El convenio elegido no existe.',
            'fecha.required' => 'Captura la fecha del abono.',
            'fecha.date_format' => 'La fecha no es válida.',
            'fecha.before_or_equal' => 'La fecha del abono no puede ser futura.',
            'monto.required' => 'Captura el monto del abono.',
            'monto.numeric' => 'El monto debe ser un número.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'monto.decimal' => 'El monto admite como máximo 2 decimales.',
            'moneda.required' => 'Elige la moneda.',
            'moneda.in' => 'La moneda debe ser MXN o USD.',
            'metodo_pago.required' => 'Elige el método de pago.',
            'metodo_pago.in' => 'El método de pago no es válido.',
            'aplicaciones.*.liquidacion_id.required' => 'Cada aplicación necesita una liquidación.',
            'aplicaciones.*.monto.required' => 'Cada aplicación necesita un monto.',
            'aplicaciones.*.monto.numeric' => 'El monto de la aplicación debe ser un número.',
            'aplicaciones.*.monto.gt' => 'El monto de la aplicación debe ser mayor a cero.',
            'aplicaciones.*.monto.decimal' => 'El monto de la aplicación admite como máximo 2 decimales.',
        ]);
    }

    /** Un anticipo ligado a un convenio debe ser de un convenio del mismo productor. */
    private function exigirConvenioDelProductor(Enterprise $empresa, Productor $productor, ?int $convenioId): void
    {
        if ($convenioId === null) {
            return;
        }
        $convenio = Convenio::deEmpresa($empresa->id)->findOrFail($convenioId);
        if ($convenio->productor_id !== $productor->id) {
            throw ValidationException::withMessages(['convenio_id' => ['El convenio elegido es de otro productor.']]);
        }
    }

    // ---- Saldos y presentación ----

    /** @return array{por_pagar: float, anticipos_sin_aplicar: float} */
    private function saldo(Enterprise $empresa, int $productorId, string $moneda): array
    {
        $s = SaldoProductor::porProductores($empresa, [$productorId])->get($productorId, [])[$moneda] ?? null;

        return [
            'por_pagar' => (float) ($s['por_pagar'] ?? 0),
            'anticipos_sin_aplicar' => (float) ($s['anticipos_sin_aplicar'] ?? 0),
        ];
    }

    /** Difunde el cambio de estado de las liquidaciones que pasaron a pagada o volvieron a aprobada. */
    private function difundirLiquidaciones(Collection $liquidaciones, Enterprise $empresa): void
    {
        if ($liquidaciones->isEmpty()) {
            return;
        }
        $presentador = app(PresentadorLiquidacion::class);
        foreach ($liquidaciones as $l) {
            $this->difundir('liquidacion', 'updated', $presentador->resumen($l), $empresa);
        }
    }

    /**
     * Forma pública del abono con sus aplicaciones (vivas, o las copiadas al cancelar), productor y convenio,
     * sin consultas por fila.
     *
     * @param  EloquentCollection<int, Abono>  $abonos
     * @return Collection<int, array>
     */
    private function presentarVarios(EloquentCollection $abonos): Collection
    {
        $abonos->load(['productor:id,nombre,tipo', 'convenio:id,folio', 'aplicaciones.liquidacion:id,folio']);

        $idsCanceladas = $abonos->flatMap(fn (Abono $a) => array_column($a->aplicaciones_canceladas ?? [], 'liquidacion_id'))->unique()->values()->all();
        $folios = $idsCanceladas === [] ? collect() : Liquidacion::withTrashed()->whereIn('id', $idsCanceladas)->pluck('folio', 'id');

        return $abonos->map(function (Abono $a) use ($folios) {
            $aplicaciones = $a->aplicaciones->sortBy('id')->map(fn (AbonoAplicacion $f) => [
                'id' => $f->id,
                'liquidacion_id' => $f->liquidacion_id,
                'folio' => $f->liquidacion?->folio,
                'monto' => (float) $f->monto,
            ])->values()->all();
            $aplicado = round(array_sum(array_column($aplicaciones, 'monto')), 2);

            return [
                'id' => $a->id,
                'folio' => $a->folio,
                'productor_id' => $a->productor_id,
                'productor' => $a->productor ? ['id' => $a->productor->id, 'nombre' => $a->productor->nombre, 'tipo' => $a->productor->tipo] : null,
                'convenio_id' => $a->convenio_id,
                'convenio' => $a->convenio ? ['id' => $a->convenio->id, 'folio' => $a->convenio->folio] : null,
                'fecha' => $a->fecha->format('Y-m-d'),
                'monto' => (float) $a->monto,
                'moneda' => $a->moneda,
                'metodo_pago' => $a->metodo_pago,
                'referencia' => $a->referencia,
                'notas' => $a->notas,
                'status' => $a->status,
                'aplicado' => $aplicado,
                'sin_aplicar' => $a->status === 'cancelado' ? 0.0 : round((float) $a->monto - $aplicado, 2),
                'aplicaciones' => $aplicaciones,
                'aplicaciones_canceladas' => array_map(fn ($f) => [
                    'liquidacion_id' => (int) $f['liquidacion_id'],
                    'folio' => $folios->get($f['liquidacion_id']),
                    'monto' => round((float) $f['monto'], 2),
                ], $a->aplicaciones_canceladas ?? []),
                'cancelado_motivo' => $a->cancelado_motivo,
                'cancelado_at' => $a->cancelado_at?->toIso8601String(),
                'created_by' => $a->created_by,
            ];
        })->values();
    }
}
