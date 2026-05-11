<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\AbonoProductor;
use App\Models\LiquidacionConsignacion;
use App\Models\Productor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AbonoProductorController extends Controller
{
    private array $eagerLoad = [
        'productor:id,nombre,apellido,tipo',
        'temporada:id,nombre',
        'creador:id,name',
    ];

    /**
     * Listar abonos con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AbonoProductor::with($this->eagerLoad);

        if ($request->filled('productor_id')) {
            $query->porProductor($request->productor_id);
        }
        if ($request->filled('temporada_id')) {
            $query->porTemporada($request->temporada_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('fecha_inicio')) {
            $query->where('fecha', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->where('fecha', '<=', $request->fecha_fin);
        }

        $abonos = $query->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $abonos,
        ]);
    }

    /**
     * Crear abono.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'productor_id' => 'required|exists:productores,id',
            'temporada_id' => 'nullable|exists:temporadas,id',
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|in:efectivo,transferencia,cheque,deposito,otro',
            'referencia' => 'nullable|string|max:100',
            'notas' => 'nullable|string|max:2000',
        ]);

        $validated['folio_abono'] = $this->generarFolio();
        $validated['status'] = AbonoProductor::STATUS_ACTIVO;
        $validated['created_by'] = Auth::id();

        $abono = AbonoProductor::create($validated);
        $abono->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Abono registrado correctamente',
            'data' => $abono,
        ], 201);
    }

    /**
     * Detalle de un abono.
     */
    public function show(AbonoProductor $abono): JsonResponse
    {
        $abono->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'data' => $abono,
        ]);
    }

    /**
     * Actualizar abono.
     */
    public function update(Request $request, AbonoProductor $abono): JsonResponse
    {
        if ($abono->status === AbonoProductor::STATUS_CANCELADO) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar un abono cancelado',
            ], 422);
        }

        $validated = $request->validate([
            'productor_id' => 'sometimes|exists:productores,id',
            'temporada_id' => 'sometimes|nullable|exists:temporadas,id',
            'fecha' => 'sometimes|date',
            'monto' => 'sometimes|numeric|min:0.01',
            'metodo_pago' => 'sometimes|in:efectivo,transferencia,cheque,deposito,otro',
            'referencia' => 'sometimes|nullable|string|max:100',
            'notas' => 'sometimes|nullable|string|max:2000',
        ]);

        $abono->update($validated);
        $abono->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Abono actualizado correctamente',
            'data' => $abono,
        ]);
    }

    /**
     * Cancelar abono (soft cancel manteniendo historial).
     */
    public function destroy(Request $request, AbonoProductor $abono): JsonResponse
    {
        $validated = $request->validate([
            'motivo_cancelacion' => 'nullable|string|max:500',
        ]);

        $abono->update([
            'status' => AbonoProductor::STATUS_CANCELADO,
            'motivo_cancelacion' => $validated['motivo_cancelacion'] ?? null,
        ]);

        $abono->delete(); // soft delete

        return response()->json([
            'success' => true,
            'message' => 'Abono cancelado correctamente',
        ]);
    }

    /**
     * Estado de cuenta del productor:
     *   - Deuda por liquidaciones no pagadas (revisadas/aprobadas).
     *   - Total abonos activos.
     *   - Saldo a pagar / saldo a favor.
     *   - Historial de movimientos (liquidaciones + abonos).
     */
    public function estadoCuenta(Request $request, Productor $productor): JsonResponse
    {
        $temporadaId = $request->query('temporada_id');

        // ─── Liquidaciones del productor ─────────────────
        $liqQuery = LiquidacionConsignacion::where('productor_id', $productor->id)
            ->with([
                'convenioCompra:id,folio_convenio,modalidad',
                'temporada:id,nombre',
            ]);

        if ($temporadaId) {
            $liqQuery->where('temporada_id', $temporadaId);
        }

        $liquidaciones = $liqQuery->orderByDesc('periodo_inicio')->get();

        // Deuda base: liquidaciones revisadas o aprobadas (monto_final pendiente de pago)
        $totalDeudaLiquidaciones = $liquidaciones
            ->whereIn('status', [
                LiquidacionConsignacion::STATUS_REVISADA,
                LiquidacionConsignacion::STATUS_APROBADA,
            ])
            ->sum('monto_final');

        $totalDeuda = (float) $totalDeudaLiquidaciones;
        $movimientosDeudaEstadoCuenta = collect();

        // Si hay temporada seleccionada, la deuda debe salir del estado de cuenta del tablero.
        if ($temporadaId) {
            try {
                $tableroRequest = Request::create('/', 'GET', [
                    'temporada_id' => $temporadaId,
                    'fecha_inicio' => $request->query('fecha_inicio'),
                    'fecha_fin' => $request->query('fecha_fin'),
                ]);

                /** @var TableroProductoresController $tableroController */
                $tableroController = app(TableroProductoresController::class);
                $tableroResponse = $tableroController->show($tableroRequest, $productor->id);
                $tableroPayload = $tableroResponse->getData(true);

                if (($tableroPayload['success'] ?? false) && !empty($tableroPayload['data'])) {
                    $estadoCuentaRows = $tableroPayload['data']['estado_cuenta_rows'] ?? [];
                    $totalDeuda = (float) ($tableroPayload['data']['totales']['monto_neto'] ?? 0);

                    $movimientosDeudaEstadoCuenta = collect($estadoCuentaRows)
                        ->map(function ($row) {
                            $folio = $row['folio']
                                ?? $row['folio_recepcion']
                                ?? $row['folio_salida']
                                ?? '-';
                            $fecha = $row['fecha'] ?? null;
                            $convenio = $row['convenio_folio'] ?? 'Sin convenio';
                            $tipoCarga = $row['tipo_carga'] ?? 'N/A';
                            $lote = $row['lote'] ?? '—';
                            $cargo = (float) ($row['subtotal_neto'] ?? 0);

                            return [
                                'tipo' => 'liquidacion',
                                'id' => $row['id'] ?? md5(json_encode([$folio, $fecha, $cargo, $convenio])),
                                'folio' => $folio,
                                'fecha' => $fecha,
                                'concepto' => "Estado de cuenta {$convenio} · {$tipoCarga} · {$lote}",
                                'cargo' => round($cargo, 2),
                                'abono' => 0,
                                'status' => 'pendiente',
                            ];
                        })
                        ->filter(fn($mov) => ($mov['cargo'] ?? 0) > 0)
                        ->values();
                }
            } catch (Throwable $e) {
                // Si falla el cálculo del tablero, mantener fallback por liquidaciones.
                $totalDeuda = (float) $totalDeudaLiquidaciones;
            }
        }

        $totalPagadoLiquidaciones = $liquidaciones
            ->where('status', LiquidacionConsignacion::STATUS_PAGADA)
            ->sum('monto_final');

        // ─── Abonos del productor ────────────────────────
        $abonosQuery = AbonoProductor::activo()
            ->porProductor($productor->id)
            ->with(['temporada:id,nombre']);

        if ($temporadaId) {
            $abonosQuery->porTemporada($temporadaId);
        }

        $abonos = $abonosQuery->orderByDesc('fecha')->get();
        $totalAbonos = (float) $abonos->sum('monto');

        // ─── Saldos ──────────────────────────────────────
        $saldoAPagar = max(0, (float) $totalDeuda - $totalAbonos);
        $saldoAFavor = max(0, $totalAbonos - (float) $totalDeuda);

        // ─── Movimientos combinados ──────────────────────
        $movimientos = collect();

        if ($temporadaId && $movimientosDeudaEstadoCuenta->isNotEmpty()) {
            $movimientos = $movimientos->merge($movimientosDeudaEstadoCuenta);
        } else {
            foreach ($liquidaciones as $liq) {
                $movimientos->push([
                    'tipo' => 'liquidacion',
                    'id' => $liq->id,
                    'folio' => $liq->folio_liquidacion,
                    'fecha' => optional($liq->periodo_fin)->format('Y-m-d') ?? $liq->created_at->format('Y-m-d'),
                    'concepto' => 'Liquidación ' . ($liq->convenioCompra?->folio_convenio ?? ''),
                    'cargo' => (float) $liq->monto_final,
                    'abono' => 0,
                    'status' => $liq->status,
                ]);
            }
        }

        foreach ($abonos as $abono) {
            $movimientos->push([
                'tipo' => 'abono',
                'id' => $abono->id,
                'folio' => $abono->folio_abono,
                'fecha' => $abono->fecha->format('Y-m-d'),
                'concepto' => 'Abono ' . ucfirst($abono->metodo_pago)
                    . ($abono->referencia ? " ({$abono->referencia})" : ''),
                'cargo' => 0,
                'abono' => (float) $abono->monto,
                'status' => $abono->status,
            ]);
        }

        $movimientos = $movimientos->sortBy('fecha')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'productor' => [
                    'id' => $productor->id,
                    'nombre' => trim($productor->nombre . ' ' . ($productor->apellido ?? '')),
                    'tipo' => $productor->tipo,
                ],
                'resumen' => [
                    'total_liquidaciones' => $temporadaId && $movimientosDeudaEstadoCuenta->isNotEmpty()
                        ? $movimientosDeudaEstadoCuenta->count()
                        : $liquidaciones->count(),
                    'total_deuda' => round((float) $totalDeuda, 2),
                    'total_pagado_liquidaciones' => round((float) $totalPagadoLiquidaciones, 2),
                    'total_abonos' => round($totalAbonos, 2),
                    'saldo_a_pagar' => round($saldoAPagar, 2),
                    'saldo_a_favor' => round($saldoAFavor, 2),
                ],
                'liquidaciones' => $liquidaciones,
                'abonos' => $abonos,
                'movimientos' => $movimientos,
            ],
        ]);
    }

    /**
     * Generar folio consecutivo AB-XXXXXX (incluye soft-deleted).
     */
    private function generarFolio(): string
    {
        $last = AbonoProductor::withTrashed()
            ->where('folio_abono', 'like', 'AB-%')
            ->orderByDesc('id')
            ->value('folio_abono');

        $next = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'AB-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
