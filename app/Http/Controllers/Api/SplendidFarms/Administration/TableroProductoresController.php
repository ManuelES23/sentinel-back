<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\ConvenioCompra;
use App\Models\Productor;
use App\Models\SalidaCampoCosecha;
use App\Models\RecepcionEmpaque;
use App\Models\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableroProductoresController extends Controller
{
    /**
     * Tablero general: resumen y desglose por productor
     * Incluye AMBOS: productores con convenios activos Y productores con recepciones sin convenio
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'productor_id' => 'nullable|exists:productores,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        $resumen = [
            'total_productores' => 0,
            'total_convenios' => 0,
            'total_recepciones' => 0,
            'total_salidas' => 0,
            'total_kilos' => 0,
            'monto_total_bruto' => 0,
            'descuento_rezaga_total' => 0,
            'monto_total_neto' => 0,
        ];

        $productores = [];
        $productoresPorId = [];

        // 1. Procesar convenios activos
        $convenios = ConvenioCompra::with([
                'productor:id,nombre,apellido,tipo',
                'cultivo:id,nombre',
                'variedad:id,nombre',
                'precios.tipoCarga:id,nombre,peso_estimado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->whereIn('status', [ConvenioCompra::STATUS_ACTIVO, ConvenioCompra::STATUS_BORRADOR])
            ->when($request->filled('productor_id'), fn($q) => $q->where('productor_id', $request->productor_id))
            ->get();

        foreach ($convenios->groupBy('productor_id') as $productorId => $productorConvenios) {
            $productor = $productorConvenios->first()->productor;
            $productorData = [
                'productor' => $productor,
                'convenios' => [],
                'recepciones_sin_convenio' => [],
                'totales' => [
                    'total_salidas' => 0,
                    'total_cantidad' => 0,
                    'total_kilos' => 0,
                    'monto_bruto' => 0,
                    'descuento_rezaga' => 0,
                    'monto_neto' => 0,
                ],
            ];

            foreach ($productorConvenios as $convenio) {
                $convenioResult = $this->calcularConvenio($convenio, $request);
                $productorData['convenios'][] = $convenioResult;

                $productorData['totales']['total_salidas'] += $convenioResult['total_salidas'];
                $productorData['totales']['total_cantidad'] += $convenioResult['total_cantidad'];
                $productorData['totales']['total_kilos'] += $convenioResult['total_kilos'];
                $productorData['totales']['monto_bruto'] += $convenioResult['monto_bruto'];
                $productorData['totales']['descuento_rezaga'] += $convenioResult['descuento_rezaga'];
                $productorData['totales']['monto_neto'] += $convenioResult['monto_neto'];

                $resumen['total_convenios']++;
                $resumen['total_salidas'] += $convenioResult['total_salidas'];
                $resumen['total_kilos'] += $convenioResult['total_kilos'];
                $resumen['monto_total_bruto'] += $convenioResult['monto_bruto'];
                $resumen['descuento_rezaga_total'] += $convenioResult['descuento_rezaga'];
                $resumen['monto_total_neto'] += $convenioResult['monto_neto'];
            }

            $productores[] = $productorData;
            $productoresPorId[$productorId] = count($productores) - 1;
        }

        // 2. Procesar recepciones (con y sin convenio) para mostrar desglose de entradas
        $recepciones = RecepcionEmpaque::with([
                'productor:id,nombre,apellido,tipo',
                'lote:id,nombre,numero_lote',
                'tipoCarga:id,nombre',
                'salidaCampo:id,variedad_id',
                'salidaCampo.variedad:id,nombre',
                'procesos:id,recepcion_id,rezaga_lavado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->whereNotNull('productor_id')
            ->when($request->filled('productor_id'), fn($q) => $q->where('productor_id', $request->productor_id))
            ->get();

        foreach ($recepciones->groupBy('productor_id') as $productorId => $recepcionesProductor) {
            $productor = $recepcionesProductor->first()->productor;

            if (!array_key_exists($productorId, $productoresPorId)) {
                $productores[] = [
                    'productor' => $productor,
                    'convenios' => [],
                    'recepciones_sin_convenio' => [],
                    'totales' => [
                        'total_salidas' => 0,
                        'total_cantidad' => 0,
                        'total_kilos' => 0,
                        'monto_bruto' => 0,
                        'descuento_rezaga' => 0,
                        'monto_neto' => 0,
                    ],
                ];

                $productoresPorId[$productorId] = count($productores) - 1;
            }

            $index = $productoresPorId[$productorId];
            $esProductorSinConvenio = empty($productores[$index]['convenios']);

            foreach ($recepcionesProductor as $recepcion) {
                if ($request->filled('fecha_inicio') && $recepcion->fecha_recepcion < $request->fecha_inicio) {
                    continue;
                }
                if ($request->filled('fecha_fin') && $recepcion->fecha_recepcion > $request->fecha_fin) {
                    continue;
                }

                $pesoBascula = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);

                // Calcular rezaga de lavado desde procesos asociados
                $rezagaLavadoKg = 0;
                foreach ($recepcion->procesos as $proceso) {
                    $rezagaLavadoKg += (float) ($proceso->rezaga_lavado_kg ?? 0);
                }

                // Calcular porcentaje de rezaga
                $porcentajeRezaga = $pesoBascula > 0 
                    ? round(($rezagaLavadoKg / $pesoBascula) * 100, 2)
                    : 0;

                // Obtener variedad desde salida de campo
                $variedad = $recepcion->salidaCampo?->variedad?->nombre ?? '—';

                $productores[$index]['recepciones_sin_convenio'][] = [
                    'id' => $recepcion->id,
                    'folio_recepcion' => $recepcion->folio_recepcion,
                    'fecha_recepcion' => $recepcion->fecha_recepcion->format('Y-m-d'),
                    'variedad' => $variedad,
                    'cantidad' => $recepcion->cantidad_recibida ?? 0,
                    'peso_bascula_kg' => round($pesoBascula, 2),
                    'rezaga_lavado_kg' => round($rezagaLavadoKg, 2),
                    'porcentaje_rezaga' => $porcentajeRezaga,
                    'tipo_carga' => $recepcion->tipoCarga?->nombre ?? 'N/A',
                    'lote' => $recepcion->lote?->nombre ?? '—',
                    'monto_bruto' => 0,
                    'monto_neto' => 0,
                    'nota' => 'Sin convenio',
                ];

                if ($esProductorSinConvenio) {
                    $productores[$index]['totales']['total_salidas']++;
                    $productores[$index]['totales']['total_kilos'] += $pesoBascula;
                    $resumen['total_salidas']++;
                    $resumen['total_kilos'] += $pesoBascula;
                    $resumen['total_recepciones']++;
                }
            }
        }

        $resumen['total_productores'] = count($productores);

        return response()->json([
            'success' => true,
            'data' => [
                'resumen' => $resumen,
                'productores' => $productores,
            ],
        ]);
    }

    /**
     * Detalle (estado de cuenta) de un productor
     */
    public function show(Request $request, int $productorId): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        $productor = Productor::select('id', 'nombre', 'apellido', 'tipo', 'telefono', 'email', 'rfc')
            ->find($productorId);

        if (!$productor) {
            return response()->json([
                'success' => false,
                'message' => 'Productor no encontrado',
            ], 404);
        }

        $convenios = ConvenioCompra::with([
                'productor:id,nombre,apellido,tipo,telefono,email,rfc',
                'cultivo:id,nombre',
                'variedad:id,nombre',
                'precios.tipoCarga:id,nombre,peso_estimado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->where('productor_id', $productorId)
            ->whereIn('status', [ConvenioCompra::STATUS_ACTIVO, ConvenioCompra::STATUS_BORRADOR])
            ->orderBy('fecha_inicio')
            ->get();

        [$recepcionesDesglose, $recepcionesTotales] = $this->buildRecepcionesDesglose($request, $productorId);
        [$estadoCuentaRows, $estadoCuentaTotals] = $this->buildEstadoCuentaRows($request, $productorId, $convenios);

        if ($convenios->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'productor' => $productor,
                    'convenios' => [],
                    'estado_cuenta_rows' => $estadoCuentaRows,
                    'recepciones_desglose' => $recepcionesDesglose,
                    'totales' => [
                        'total_salidas' => $estadoCuentaTotals['total_salidas'] ?? $recepcionesTotales['total_salidas'],
                        'total_cantidad' => $estadoCuentaTotals['total_cantidad'] ?? $recepcionesTotales['total_cantidad'],
                        'total_kilos' => $estadoCuentaTotals['total_kilos'] ?? $recepcionesTotales['total_kilos'],
                        'total_recibido_kg' => $estadoCuentaTotals['total_recibido_kg'] ?? $recepcionesTotales['total_kilos'],
                        'total_producido_cajas' => $estadoCuentaTotals['total_producido_cajas'] ?? 0,
                        'total_embarcado_cajas' => $estadoCuentaTotals['total_embarcado_cajas'] ?? 0,
                        'total_rezaga_kg' => $estadoCuentaTotals['total_rezaga_kg'] ?? $recepcionesTotales['total_rezaga_kg'],
                        'total_producido_kg' => $estadoCuentaTotals['total_producido_kg'] ?? 0,
                        'monto_bruto' => $estadoCuentaTotals['monto_bruto'] ?? 0,
                        'descuento_rezaga' => $estadoCuentaTotals['descuento_rezaga'] ?? 0,
                        'monto_neto' => $estadoCuentaTotals['monto_neto'] ?? 0,
                    ],
                ],
            ]);
        }

        $conveniosData = [];
        $totales = [
            'total_salidas' => 0,
            'total_cantidad' => 0,
            'total_kilos' => 0,
            'total_recibido_kg' => 0,
            'total_producido_cajas' => 0,
            'total_embarcado_cajas' => 0,
            'total_rezaga_kg' => 0,
            'monto_bruto' => 0,
            'descuento_rezaga' => 0,
            'monto_neto' => 0,
        ];

        foreach ($convenios as $convenio) {
            if (!$convenio instanceof ConvenioCompra) {
                continue;
            }

            $convenioResult = $this->calcularConvenioDetallado($convenio, $request);
            $conveniosData[] = $convenioResult;

            $totales['total_salidas'] += $convenioResult['total_salidas'];
            $totales['total_cantidad'] += $convenioResult['total_cantidad'];
            $totales['total_kilos'] += $convenioResult['total_kilos'];
            $totales['total_recibido_kg'] += $convenioResult['total_recibido_kg'];
            $totales['total_producido_cajas'] += $convenioResult['total_producido_cajas'];
            $totales['total_embarcado_cajas'] += $convenioResult['total_embarcado_cajas'];
            $totales['total_rezaga_kg'] += $convenioResult['total_rezaga_kg'];
            $totales['monto_bruto'] += $convenioResult['monto_bruto'];
            $totales['descuento_rezaga'] += $convenioResult['descuento_rezaga'];
            $totales['monto_neto'] += $convenioResult['monto_neto'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'productor' => $productor,
                'convenios' => $conveniosData,
                'estado_cuenta_rows' => $estadoCuentaRows,
                'recepciones_desglose' => $recepcionesDesglose,
                'totales' => [
                    'total_salidas' => $estadoCuentaTotals['total_salidas'] ?? $totales['total_salidas'],
                    'total_cantidad' => $estadoCuentaTotals['total_cantidad'] ?? $totales['total_cantidad'],
                    'total_kilos' => $estadoCuentaTotals['total_kilos'] ?? $totales['total_kilos'],
                    'total_recibido_kg' => $estadoCuentaTotals['total_recibido_kg'] ?? $totales['total_recibido_kg'],
                    'total_producido_cajas' => $estadoCuentaTotals['total_producido_cajas'] ?? $totales['total_producido_cajas'],
                    'total_embarcado_cajas' => $estadoCuentaTotals['total_embarcado_cajas'] ?? $totales['total_embarcado_cajas'],
                    'total_rezaga_kg' => $estadoCuentaTotals['total_rezaga_kg'] ?? $totales['total_rezaga_kg'],
                    'total_producido_kg' => $estadoCuentaTotals['total_producido_kg'] ?? ($totales['total_producido_kg'] ?? 0),
                    'monto_bruto' => $estadoCuentaTotals['monto_bruto'] ?? $totales['monto_bruto'],
                    'descuento_rezaga' => $estadoCuentaTotals['descuento_rezaga'] ?? $totales['descuento_rezaga'],
                    'monto_neto' => $estadoCuentaTotals['monto_neto'] ?? $totales['monto_neto'],
                ],
            ],
        ]);
    }

    private function buildEstadoCuentaRows(Request $request, int $productorId, $convenios): array
    {
        $recepciones = RecepcionEmpaque::with([
                'lote:id,nombre,numero_lote',
                'zonaCultivo:id,nombre',
                'tipoCarga:id,nombre',
                'salidaCampo:id,variedad_id',
                'salidaCampo.variedad:id,nombre,cultivo_id',
                'procesos:id,recepcion_id,rezaga_lavado_kg',
                'procesos.producciones:id,proceso_id,total_cajas,peso_neto_kg',
                'procesos.producciones.embarqueDetalles:id,produccion_id,cajas',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->where('productor_id', $productorId)
            ->when($request->filled('fecha_inicio'), fn($q) => $q->whereDate('fecha_recepcion', '>=', $request->fecha_inicio))
            ->when($request->filled('fecha_fin'), fn($q) => $q->whereDate('fecha_recepcion', '<=', $request->fecha_fin))
            ->orderBy('fecha_recepcion')
            ->get();

        $rows = [];
        $totales = [
            'total_salidas' => 0,
            'total_cantidad' => 0,
            'total_kilos' => 0,
            'total_recibido_kg' => 0,
            'total_producido_cajas' => 0,
            'total_producido_kg' => 0,
            'total_embarcado_cajas' => 0,
            'total_rezaga_kg' => 0,
            'monto_bruto' => 0,
            'descuento_rezaga' => 0,
            'monto_neto' => 0,
        ];

        $conveniosOrdenados = $convenios->sortBy(function ($c) {
            return optional($c->fecha_inicio)->format('Y-m-d') ?? '0000-00-00';
        })->values();

        foreach ($recepciones as $recepcion) {
            $fechaRecepcion = $recepcion->fecha_recepcion;
            $convenioAsignado = null;

            foreach ($conveniosOrdenados as $convenio) {
                if ($convenio->fecha_inicio && $fechaRecepcion && $fechaRecepcion->lt($convenio->fecha_inicio)) {
                    break;
                }
                $convenioAsignado = $convenio;
            }

            $pesoBascula = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);
            $cantidad = (int) ($recepcion->cantidad_recibida ?? 0);

            $rezagaLavadoKg = 0;
            $producidoCajas = 0;
            $embarcadoCajas = 0;
            foreach ($recepcion->procesos as $proceso) {
                $rezagaLavadoKg += (float) ($proceso->rezaga_lavado_kg ?? 0);
                foreach ($proceso->producciones as $produccion) {
                    $producidoCajas += (int) ($produccion->total_cajas ?? 0);
                    foreach ($produccion->embarqueDetalles as $detalle) {
                        $embarcadoCajas += (int) ($detalle->cajas ?? 0);
                    }
                }
            }

            $porcentajeRezaga = $pesoBascula > 0
                ? round(($rezagaLavadoKg / $pesoBascula) * 100, 2)
                : 0;
            $producidoKg = max(0, $pesoBascula - $rezagaLavadoKg);

            $precioUnitario = $convenioAsignado
                ? $this->obtenerPrecio($convenioAsignado, $recepcion->tipo_carga_id, $fechaRecepcion)
                : 0;

            $esPorKilos = $this->debeCalcularPorKilos($convenioAsignado, $recepcion->tipo_carga_id, $recepcion->tipoCarga);
            $subtotal = $esPorKilos
                ? ($pesoBascula * $precioUnitario)
                : ($cantidad * $precioUnitario);

            $porcentajeAceptadoRezaga = (float) ($convenioAsignado?->porcentaje_rezaga ?? 0);
            $excedenteRezagaPct = max(0, $porcentajeRezaga - $porcentajeAceptadoRezaga);
            $descuentoRezaga = round($subtotal * ($excedenteRezagaPct / 100), 2);
            $subtotalNeto = round($subtotal - $descuentoRezaga, 2);

            $rows[] = [
                'id' => $recepcion->id,
                'folio' => $recepcion->folio_recepcion,
                'fecha' => $fechaRecepcion?->format('Y-m-d'),
                'convenio_folio' => $convenioAsignado?->folio_convenio,
                'convenio_modalidad' => $convenioAsignado?->modalidad,
                'convenio_porcentaje_rezaga' => $porcentajeAceptadoRezaga,
                'convenio_calculo_por_kilos' => $esPorKilos,
                'tipo_carga' => $recepcion->tipoCarga?->nombre,
                'lote' => $recepcion->lote?->nombre,
                'variedad' => $recepcion->salidaCampo?->variedad?->nombre,
                'cantidad' => $cantidad,
                'peso_bascula_kg' => round($pesoBascula, 2),
                'recibido_kg' => round($pesoBascula, 2),
                'producido_cajas' => $producidoCajas,
                'producido_kg' => round($producidoKg, 2),
                'embarcado_cajas' => $embarcadoCajas,
                'rezaga_lavado_kg' => round($rezagaLavadoKg, 2),
                'porcentaje_rezaga' => $porcentajeRezaga,
                'excedente_rezaga_pct' => round($excedenteRezagaPct, 2),
                'precio_unitario' => (float) $precioUnitario,
                'subtotal' => round($subtotal, 2),
                'descuento_rezaga' => $descuentoRezaga,
                'subtotal_neto' => $subtotalNeto,
            ];

            $totales['total_salidas']++;
            $totales['total_cantidad'] += $cantidad;
            $totales['total_kilos'] += $pesoBascula;
            $totales['total_recibido_kg'] += $pesoBascula;
            $totales['total_producido_cajas'] += $producidoCajas;
            $totales['total_producido_kg'] += $producidoKg;
            $totales['total_embarcado_cajas'] += $embarcadoCajas;
            $totales['total_rezaga_kg'] += $rezagaLavadoKg;
            $totales['monto_bruto'] += $subtotal;
            $totales['descuento_rezaga'] += $descuentoRezaga;
            $totales['monto_neto'] += $subtotalNeto;
        }

        $totales['total_kilos'] = round($totales['total_kilos'], 2);
        $totales['total_recibido_kg'] = round($totales['total_recibido_kg'], 2);
        $totales['total_producido_kg'] = round($totales['total_producido_kg'], 2);
        $totales['total_rezaga_kg'] = round($totales['total_rezaga_kg'], 2);
        $totales['monto_bruto'] = round($totales['monto_bruto'], 2);
        $totales['descuento_rezaga'] = round($totales['descuento_rezaga'], 2);
        $totales['monto_neto'] = round($totales['monto_neto'], 2);

        return [$rows, $totales];
    }

    private function buildRecepcionesDesglose(Request $request, int $productorId): array
    {
        $recepciones = RecepcionEmpaque::with([
                'lote:id,nombre,numero_lote',
                'tipoCarga:id,nombre',
                'salidaCampo:id,variedad_id',
                'salidaCampo.variedad:id,nombre',
                'procesos:id,recepcion_id,rezaga_lavado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->where('productor_id', $productorId)
            ->when($request->filled('fecha_inicio'), fn($q) => $q->whereDate('fecha_recepcion', '>=', $request->fecha_inicio))
            ->when($request->filled('fecha_fin'), fn($q) => $q->whereDate('fecha_recepcion', '<=', $request->fecha_fin))
            ->orderBy('fecha_recepcion')
            ->get();

        $desglose = [];
        $totalKilos = 0;
        $totalCantidad = 0;
        $totalRezagaKg = 0;

        foreach ($recepciones as $recepcion) {
            $pesoBascula = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);
            $cantidad = (int) ($recepcion->cantidad_recibida ?? 0);

            $rezagaLavadoKg = 0;
            foreach ($recepcion->procesos as $proceso) {
                $rezagaLavadoKg += (float) ($proceso->rezaga_lavado_kg ?? 0);
            }

            $porcentajeRezaga = $pesoBascula > 0
                ? round(($rezagaLavadoKg / $pesoBascula) * 100, 2)
                : 0;

            $desglose[] = [
                'id' => $recepcion->id,
                'folio_recepcion' => $recepcion->folio_recepcion,
                'fecha_recepcion' => $recepcion->fecha_recepcion?->format('Y-m-d'),
                'variedad' => $recepcion->salidaCampo?->variedad?->nombre ?? '—',
                'cantidad' => $cantidad,
                'peso_bascula_kg' => round($pesoBascula, 2),
                'rezaga_lavado_kg' => round($rezagaLavadoKg, 2),
                'porcentaje_rezaga' => $porcentajeRezaga,
                'tipo_carga' => $recepcion->tipoCarga?->nombre ?? 'N/A',
                'lote' => $recepcion->lote?->nombre ?? '—',
                'monto_bruto' => 0,
                'monto_neto' => 0,
                'nota' => 'Sin convenio',
            ];

            $totalKilos += $pesoBascula;
            $totalCantidad += $cantidad;
            $totalRezagaKg += $rezagaLavadoKg;
        }

        return [
            $desglose,
            [
                'total_salidas' => count($desglose),
                'total_cantidad' => $totalCantidad,
                'total_kilos' => round($totalKilos, 2),
                'total_rezaga_kg' => round($totalRezagaKg, 2),
            ],
        ];
    }

    /**
     * Cálculo resumido de un convenio (para index)
     */
    private function calcularConvenio(ConvenioCompra $convenio, Request $request): array
    {
        $convenioEsPorKilos = $this->debeCalcularPorKilos($convenio);
        // Trazabilidad siempre para calcular rezaga real (excedente)
        $salidas = $this->getSalidas($convenio, $request, true);
        $usarRecepcionesFallback = $salidas->isEmpty();
        $recepcionesFallback = $usarRecepcionesFallback
            ? $this->getRecepcionesParaConvenio($convenio, $request)
            : collect();

        $montoBruto = 0;
        $totalRezagaKg = 0;
        $totalPesoBase = 0;
        $salidasData = [];
        $totalCantidadConvenio = 0;
        $totalKilosConvenio = 0;

        if ($usarRecepcionesFallback) {
            foreach ($recepcionesFallback as $recepcion) {
                $fechaOperacion = $recepcion->fecha_recepcion;
                $precioUnitario = $this->obtenerPrecio($convenio, $recepcion->tipo_carga_id, $fechaOperacion);
                $esPorKilos = $this->debeCalcularPorKilos($convenio, $recepcion->tipo_carga_id, $recepcion->tipoCarga);

                $pesoBasculaRecepcion = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);
                $cantidad = (int) ($recepcion->cantidad_recibida ?? 0);

                $rezagaKg = 0;
                foreach ($recepcion->procesos as $proceso) {
                    $rezagaKg += (float) ($proceso->rezaga_lavado_kg ?? 0);
                }
                $totalRezagaKg += $rezagaKg;

                if ($esPorKilos) {
                    $subtotal = $pesoBasculaRecepcion * $precioUnitario;
                    $totalPesoBase += $pesoBasculaRecepcion;
                } else {
                    $subtotal = $cantidad * $precioUnitario;
                    $totalPesoBase += $pesoBasculaRecepcion;
                }
                $montoBruto += $subtotal;

                $salidasData[] = [
                    'id' => $recepcion->id,
                    'folio_salida' => $recepcion->folio_recepcion,
                    'fecha' => $fechaOperacion?->format('Y-m-d'),
                    'cantidad' => $cantidad,
                    'peso_neto_kg' => round($pesoBasculaRecepcion, 2),
                    'peso_bascula' => (float) ($recepcion->peso_bascula ?? 0),
                    'folio_ticket_bascula' => $recepcion->folio_ticket_bascula,
                    'tipo_carga' => $recepcion->tipoCarga?->nombre,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => round($subtotal, 2),
                    'origen' => 'recepcion',
                ];

                $totalCantidadConvenio += $cantidad;
                $totalKilosConvenio += $pesoBasculaRecepcion;
            }
        }

        foreach ($usarRecepcionesFallback ? collect() : $salidas as $salida) {
            $precioUnitario = $this->obtenerPrecio($convenio, $salida->tipo_carga_id, $salida->fecha);
            $esPorKilos = $this->debeCalcularPorKilos($convenio, $salida->tipo_carga_id, $salida->tipoCarga);

            // Peso báscula y rezaga real desde trazabilidad
            $pesoBasculaRecepcion = 0;
            $rezagaKg = 0;
            foreach ($salida->recepciones as $recepcion) {
                $pesoBasculaRecepcion += (float) ($recepcion->peso_bascula ?? 0);
                foreach ($recepcion->procesos as $proceso) {
                    foreach ($proceso->rezagas as $rezaga) {
                        $rezagaKg += (float) $rezaga->cantidad_kg;
                    }
                }
            }
            $totalRezagaKg += $rezagaKg;

            if ($esPorKilos) {
                $subtotal = $pesoBasculaRecepcion * $precioUnitario;
                $totalPesoBase += $pesoBasculaRecepcion;
            } else {
                $subtotal = $salida->cantidad * $precioUnitario;
                $totalPesoBase += (float) ($salida->peso_neto_kg ?? 0);
            }
            $montoBruto += $subtotal;

            $salidaData = [
                'id' => $salida->id,
                'folio_salida' => $salida->folio_salida,
                'fecha' => $salida->fecha->format('Y-m-d'),
                'cantidad' => $salida->cantidad,
                'peso_neto_kg' => (float) $salida->peso_neto_kg,
                'peso_bascula' => (float) ($salida->peso_bascula ?? 0),
                'folio_ticket_bascula' => $salida->folio_ticket_bascula,
                'tipo_carga' => $salida->tipoCarga?->nombre,
                'precio_unitario' => $precioUnitario,
                'subtotal' => $subtotal,
            ];

            if ($esPorKilos) {
                $pesoBasculaRecepcion = 0;
                foreach ($salida->recepciones as $recepcion) {
                    $pesoBasculaRecepcion += (float) ($recepcion->peso_bascula ?? 0);
                }
                $salidaData['peso_bascula_recepcion'] = round($pesoBasculaRecepcion, 2);
            }

            $salidasData[] = $salidaData;
            $totalCantidadConvenio += (int) ($salida->cantidad ?? 0);
            $totalKilosConvenio += (float) ($salida->peso_neto_kg ?? 0);
        }

        // Rezaga: solo se descuenta el EXCEDENTE sobre el % aceptado
        $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);
        $porcentajeRealRezaga = $totalPesoBase > 0
            ? ($totalRezagaKg / $totalPesoBase) * 100
            : 0;
        $excedentePorcentaje = max(0, $porcentajeRealRezaga - $porcentajeRezaga);
        $descuentoRezaga = $montoBruto * ($excedentePorcentaje / 100);
        $montoNeto = $montoBruto - $descuentoRezaga;

        return [
            'convenio' => [
                'id' => $convenio->id,
                'folio_convenio' => $convenio->folio_convenio,
                'modalidad' => $convenio->modalidad,
                'calculo_por_kilos' => $convenioEsPorKilos,
                'porcentaje_rezaga' => $porcentajeRezaga,
                'fecha_inicio' => $convenio->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $convenio->fecha_fin?->format('Y-m-d'),
            ],
            'cultivo' => $convenio->cultivo?->nombre,
            'variedad' => $convenio->variedad?->nombre,
            'total_salidas' => count($salidasData),
            'total_cantidad' => $totalCantidadConvenio,
            'total_kilos' => round($totalKilosConvenio, 2),
            'total_rezaga_kg' => round($totalRezagaKg, 2),
            'porcentaje_real_rezaga' => round($porcentajeRealRezaga, 2),
            'excedente_rezaga_pct' => round($excedentePorcentaje, 2),
            'monto_bruto' => $montoBruto,
            'descuento_rezaga' => $descuentoRezaga,
            'monto_neto' => $montoNeto,
            'salidas' => $salidasData,
        ];
    }

    /**
     * Cálculo detallado (estado de cuenta) con trazabilidad completa
     */
    private function calcularConvenioDetallado(ConvenioCompra $convenio, Request $request): array
    {
        $salidas = $this->getSalidas($convenio, $request, true);
        $convenioEsPorKilos = $this->debeCalcularPorKilos($convenio);
        $usarRecepcionesFallback = $salidas->isEmpty();
        $recepcionesFallback = $usarRecepcionesFallback
            ? $this->getRecepcionesParaConvenio($convenio, $request)
            : collect();

        $montoBruto = 0;
        $totalRecibidoKg = 0;
        $totalProducidoCajas = 0;
        $totalProducidoKg = 0;
        $totalEmbarcadoCajas = 0;
        $totalRezagaKg = 0;
        $salidasDetalle = [];
        $totalCantidadConvenio = 0;
        $totalKilosConvenio = 0;

        if ($usarRecepcionesFallback) {
            foreach ($recepcionesFallback as $recepcion) {
                $fechaOperacion = $recepcion->fecha_recepcion;
                $precioUnitario = $this->obtenerPrecio($convenio, $recepcion->tipo_carga_id, $fechaOperacion);
                $esPorKilos = $this->debeCalcularPorKilos($convenio, $recepcion->tipo_carga_id, $recepcion->tipoCarga);

                $pesoBasculaRecepcion = (float) ($recepcion->peso_bascula ?? $recepcion->peso_recibido_kg ?? 0);
                $cantidad = (int) ($recepcion->cantidad_recibida ?? 0);

                $rezagaKg = 0;
                foreach ($recepcion->procesos as $proceso) {
                    $rezagaKg += (float) ($proceso->rezaga_lavado_kg ?? 0);
                }

                $producidoKg = $esPorKilos ? max(0, $pesoBasculaRecepcion - $rezagaKg) : 0;

                if ($esPorKilos) {
                    $subtotal = $pesoBasculaRecepcion * $precioUnitario;
                } else {
                    $subtotal = $cantidad * $precioUnitario;
                }
                $montoBruto += $subtotal;

                $totalRecibidoKg += $pesoBasculaRecepcion;
                $totalProducidoKg += $producidoKg;
                $totalRezagaKg += $rezagaKg;

                $salidaDetalle = [
                    'id' => $recepcion->id,
                    'folio_salida' => $recepcion->folio_recepcion,
                    'fecha' => $fechaOperacion?->format('Y-m-d'),
                    'cantidad' => $cantidad,
                    'peso_neto_kg' => round($pesoBasculaRecepcion, 2),
                    'peso_bascula' => (float) ($recepcion->peso_bascula ?? 0),
                    'folio_ticket_bascula' => $recepcion->folio_ticket_bascula,
                    'tipo_carga' => $recepcion->tipoCarga?->nombre,
                    'tipo_carga_id' => $recepcion->tipo_carga_id,
                    'lote' => $recepcion->lote?->nombre,
                    'zona_cultivo' => $recepcion->zonaCultivo?->nombre,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => round($subtotal, 2),
                    'status' => $recepcion->status,
                    'recibido_kg' => round($pesoBasculaRecepcion, 2),
                    'producido_cajas' => 0,
                    'embarcado_cajas' => 0,
                    'rezaga_kg' => round($rezagaKg, 2),
                    'origen' => 'recepcion',
                ];

                if ($esPorKilos) {
                    $salidaDetalle['peso_bascula_recepcion'] = round($pesoBasculaRecepcion, 2);
                    $salidaDetalle['folio_ticket_bascula_recepcion'] = $recepcion->folio_ticket_bascula;
                    $salidaDetalle['producido_kg'] = round($producidoKg, 2);
                }

                $salidasDetalle[] = $salidaDetalle;
                $totalCantidadConvenio += $cantidad;
                $totalKilosConvenio += $pesoBasculaRecepcion;
            }
        }

        foreach ($usarRecepcionesFallback ? collect() : $salidas as $salida) {
            $precioUnitario = $this->obtenerPrecio($convenio, $salida->tipo_carga_id, $salida->fecha);
            $esPorKilos = $this->debeCalcularPorKilos($convenio, $salida->tipo_carga_id, $salida->tipoCarga);

            // Trazabilidad: recepciones de esta salida
            $recibidoKg = 0;
            $pesoBasculaRecepcion = 0;
            $folioTicketBascula = null;
            $producidoCajas = 0;
            $embarcadoCajas = 0;
            $rezagaKg = 0;

            foreach ($salida->recepciones as $recepcion) {
                $recibidoKg += (float) ($recepcion->peso_recibido_kg ?? $recepcion->peso_bascula ?? 0);
                $pesoBasculaRecepcion += (float) ($recepcion->peso_bascula ?? 0);
                if (!$folioTicketBascula && $recepcion->folio_ticket_bascula) {
                    $folioTicketBascula = $recepcion->folio_ticket_bascula;
                }

                foreach ($recepcion->procesos as $proceso) {
                    foreach ($proceso->producciones as $produccion) {
                        $producidoCajas += (int) $produccion->total_cajas;

                        foreach ($produccion->embarqueDetalles as $detalle) {
                            $embarcadoCajas += (int) $detalle->cajas;
                        }
                    }

                    foreach ($proceso->rezagas as $rezaga) {
                        $rezagaKg += (float) $rezaga->cantidad_kg;
                    }
                }
            }

            // Para convenio por kilos: producción kg = recibido - rezaga
            $producidoKg = $esPorKilos ? max(0, $pesoBasculaRecepcion - $rezagaKg) : 0;

            if ($esPorKilos) {
                // Cálculo por kilos: subtotal basado en peso_bascula de recepción
                $subtotal = $pesoBasculaRecepcion * $precioUnitario;
            } else {
                // Cálculo estándar: por cantidad (cajas/unidades)
                $subtotal = $salida->cantidad * $precioUnitario;
            }
            $montoBruto += $subtotal;

            $totalRecibidoKg += $recibidoKg;
            $totalProducidoCajas += $producidoCajas;
            $totalProducidoKg += $producidoKg;
            $totalEmbarcadoCajas += $embarcadoCajas;
            $totalRezagaKg += $rezagaKg;

            $salidaDetalle = [
                'id' => $salida->id,
                'folio_salida' => $salida->folio_salida,
                'fecha' => $salida->fecha->format('Y-m-d'),
                'cantidad' => $salida->cantidad,
                'peso_neto_kg' => (float) $salida->peso_neto_kg,
                'peso_bascula' => (float) ($salida->peso_bascula ?? 0),
                'folio_ticket_bascula' => $salida->folio_ticket_bascula,
                'tipo_carga' => $salida->tipoCarga?->nombre,
                'tipo_carga_id' => $salida->tipo_carga_id,
                'lote' => $salida->lote?->nombre,
                'zona_cultivo' => $salida->zonaCultivo?->nombre,
                'precio_unitario' => $precioUnitario,
                'subtotal' => $subtotal,
                'status' => $salida->status,
                // Trazabilidad
                'recibido_kg' => round($recibidoKg, 2),
                'producido_cajas' => $producidoCajas,
                'embarcado_cajas' => $embarcadoCajas,
                'rezaga_kg' => round($rezagaKg, 2),
            ];

            if ($esPorKilos) {
                $salidaDetalle['peso_bascula_recepcion'] = round($pesoBasculaRecepcion, 2);
                $salidaDetalle['folio_ticket_bascula_recepcion'] = $folioTicketBascula;
                $salidaDetalle['producido_kg'] = round($producidoKg, 2);
            }

            $salidasDetalle[] = $salidaDetalle;
            $totalCantidadConvenio += (int) ($salida->cantidad ?? 0);
            $totalKilosConvenio += (float) ($salida->peso_neto_kg ?? 0);
        }

        // Cálculo de descuento por rezaga
        $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);

        if ($convenioEsPorKilos) {
            // Para kilos: % rezaga real basado en peso báscula de recepciones
            $totalPesoBase = $usarRecepcionesFallback
                ? $totalRecibidoKg
                : $salidas->sum(function ($s) {
                    return $s->recepciones->sum('peso_bascula');
                });
            $porcentajeRealRezaga = $totalPesoBase > 0
                ? ($totalRezagaKg / $totalPesoBase) * 100
                : 0;
        } else {
            $totalPesoBase = $usarRecepcionesFallback
                ? $totalKilosConvenio
                : (float) $salidas->sum('peso_neto_kg');
            $porcentajeRealRezaga = $totalPesoBase > 0
                ? ($totalRezagaKg / $totalPesoBase) * 100
                : 0;
        }

        $excedentePorcentaje = max(0, $porcentajeRealRezaga - $porcentajeRezaga);
        $descuentoRezaga = $montoBruto * ($excedentePorcentaje / 100);
        $montoNeto = $montoBruto - $descuentoRezaga;

        $result = [
            'convenio' => [
                'id' => $convenio->id,
                'folio_convenio' => $convenio->folio_convenio,
                'modalidad' => $convenio->modalidad,
                'calculo_por_kilos' => $convenioEsPorKilos,
                'porcentaje_rezaga' => $porcentajeRezaga,
                'fecha_inicio' => $convenio->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $convenio->fecha_fin?->format('Y-m-d'),
            ],
            'cultivo' => $convenio->cultivo?->nombre,
            'variedad' => $convenio->variedad?->nombre,
            'total_salidas' => count($salidasDetalle),
            'total_cantidad' => $totalCantidadConvenio,
            'total_kilos' => round($totalKilosConvenio, 2),
            'total_recibido_kg' => round($totalRecibidoKg, 2),
            'total_producido_cajas' => $totalProducidoCajas,
            'total_embarcado_cajas' => $totalEmbarcadoCajas,
            'total_rezaga_kg' => round($totalRezagaKg, 2),
            'porcentaje_real_rezaga' => round($porcentajeRealRezaga, 2),
            'excedente_rezaga_pct' => round($excedentePorcentaje, 2),
            'monto_bruto' => round($montoBruto, 2),
            'descuento_rezaga' => round($descuentoRezaga, 2),
            'monto_neto' => round($montoNeto, 2),
            'salidas' => $salidasDetalle,
        ];

        if ($convenioEsPorKilos) {
            $result['total_producido_kg'] = round($totalProducidoKg, 2);
        }

        return $result;
    }

    private function getRecepcionesParaConvenio(ConvenioCompra $convenio, Request $request)
    {
        $query = RecepcionEmpaque::with([
                'lote:id,nombre,numero_lote',
                'zonaCultivo:id,nombre',
                'tipoCarga:id,nombre',
                'salidaCampo:id,variedad_id',
                'salidaCampo.variedad:id,nombre,cultivo_id',
                'procesos:id,recepcion_id,rezaga_lavado_kg',
            ])
            ->where('temporada_id', $convenio->temporada_id)
            ->where('productor_id', $convenio->productor_id);

        if ($convenio->fecha_inicio) {
            $query->whereDate('fecha_recepcion', '>=', $convenio->fecha_inicio);
        }
        if ($convenio->fecha_fin) {
            $query->whereDate('fecha_recepcion', '<=', $convenio->fecha_fin);
        }

        if ($convenio->variedad_id) {
            $query->whereHas('salidaCampo', function ($q) use ($convenio) {
                $q->where('variedad_id', $convenio->variedad_id);
            });
        } elseif ($convenio->cultivo_id) {
            $query->whereHas('salidaCampo.variedad', function ($q) use ($convenio) {
                $q->where('cultivo_id', $convenio->cultivo_id);
            });
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_recepcion', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_recepcion', '<=', $request->fecha_fin);
        }

        return $query->orderBy('fecha_recepcion')->get();
    }

    /**
     * Obtener precio unitario para un tipo de carga en una fecha.
     * Solo retorna precio si el tipo_carga tiene precio activo con vigencia
     * que cubra la fecha de la salida.
     */
    private function obtenerPrecio(ConvenioCompra $convenio, ?int $tipoCargaId, $fecha): float
    {
        $fechaRef = $fecha instanceof \DateTimeInterface
            ? $fecha->format('Y-m-d')
            : (string) $fecha;

        $baseQuery = $convenio->precios()
            ->where('is_active', true)
            ->whereDate('vigencia_inicio', '<=', $fechaRef)
            ->where(function ($q) use ($fechaRef) {
                $q->whereNull('vigencia_fin')
                  ->orWhereDate('vigencia_fin', '>=', $fechaRef);
            });

        // 1) Precio específico por tipo de carga
        $precio = null;
        if ($tipoCargaId) {
            $precio = (clone $baseQuery)
                ->where('tipo_carga_id', $tipoCargaId)
                ->latest('vigencia_inicio')
                ->first();
        }

        // 2) Fallback a precio genérico del convenio (sin tipo_carga)
        if (!$precio) {
            $precio = (clone $baseQuery)
                ->whereNull('tipo_carga_id')
                ->latest('vigencia_inicio')
                ->first();
        }

        // 3) Último recurso: cualquier precio vigente del convenio
        if (!$precio) {
            $precio = (clone $baseQuery)
                ->latest('vigencia_inicio')
                ->first();
        }

        // 4) Fallback sin vigencia exacta: último precio activo por tipo_carga
        if (!$precio && $tipoCargaId) {
            $precio = $convenio->precios()
                ->where('is_active', true)
                ->where('tipo_carga_id', $tipoCargaId)
                ->latest('vigencia_inicio')
                ->first();
        }

        // 5) Fallback final sin vigencia/tipo: cualquier precio activo más reciente
        if (!$precio) {
            $precio = $convenio->precios()
                ->where('is_active', true)
                ->latest('vigencia_inicio')
                ->first();
        }

        if (!$precio) {
            return 0;
        }

        return (float) ($precio->precio_unitario ?? $precio->precio_caja_empacada ?? 0);
    }

    /**
     * Obtener salidas de un convenio con filtros.
     * Busca salidas explícitamente ligadas al convenio O salidas sin convenio
     * que coincidan por productor + temporada + variedad + tipo_carga (con precio),
     * y cuya fecha esté dentro del período del convenio.
     */
    private function getSalidas(ConvenioCompra $convenio, Request $request, bool $conTrazabilidad = false)
    {
        // Tipos de carga que tienen precio definido en este convenio
        $tiposCargaConPrecio = $convenio->precios()
            ->where('is_active', true)
            ->whereNotNull('tipo_carga_id')
            ->pluck('tipo_carga_id')
            ->unique()
            ->values()
            ->toArray();

        $query = SalidaCampoCosecha::where('eliminado', false)
            ->where('temporada_id', $convenio->temporada_id)
            ->where('productor_id', $convenio->productor_id)
            ->where(function ($q) use ($convenio, $tiposCargaConPrecio) {
                // Salidas explícitamente ligadas al convenio
                $q->where('convenio_compra_id', $convenio->id)
                  // O salidas sin convenio que coinciden por variedad + tipo_carga
                  ->orWhere(function ($q2) use ($convenio, $tiposCargaConPrecio) {
                      $q2->whereNull('convenio_compra_id');

                      // Debe coincidir variedad
                      if ($convenio->variedad_id) {
                          $q2->where('variedad_id', $convenio->variedad_id);
                      } elseif ($convenio->cultivo_id) {
                          $q2->whereHas('variedad', fn($vq) => $vq->where('cultivo_id', $convenio->cultivo_id));
                      }

                      // Debe tener un tipo_carga con precio en el convenio
                      if (!empty($tiposCargaConPrecio)) {
                          $q2->whereIn('tipo_carga_id', $tiposCargaConPrecio);
                      }
                  });
            });

        // La fecha de la salida debe estar dentro del período del convenio
        if ($convenio->fecha_inicio) {
            $query->where('fecha', '>=', $convenio->fecha_inicio);
        }
        if ($convenio->fecha_fin) {
            $query->where('fecha', '<=', $convenio->fecha_fin);
        }

        $with = ['tipoCarga:id,nombre,peso_estimado_kg'];

        if ($conTrazabilidad) {
            $with = array_merge($with, [
                'lote:id,nombre',
                'zonaCultivo:id,nombre',
                'recepciones' => function ($q) {
                    $q->select('id', 'salida_campo_id', 'peso_recibido_kg', 'peso_bascula', 'folio_ticket_bascula');
                },
                'recepciones.procesos' => function ($q) {
                    $q->select('id', 'recepcion_id');
                },
                'recepciones.procesos.producciones' => function ($q) {
                    $q->select('id', 'proceso_id', 'total_cajas');
                },
                'recepciones.procesos.producciones.embarqueDetalles' => function ($q) {
                    $q->select('id', 'produccion_id', 'cajas');
                },
                'recepciones.procesos.rezagas' => function ($q) {
                    $q->select('id', 'proceso_id', 'cantidad_kg');
                },
            ]);
        }

        $query->with($with);

        if ($request->filled('fecha_inicio')) {
            $query->where('fecha', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->where('fecha', '<=', $request->fecha_fin);
        }

        return $query->orderBy('fecha')->get();
    }

    private function debeCalcularPorKilos(?ConvenioCompra $convenio, ?int $tipoCargaId = null, $tipoCarga = null): bool
    {
        if (!$convenio) {
            return false;
        }

        if ((bool) $convenio->calculo_por_kilos) {
            return true;
        }

        $nombreTipoCarga = strtolower(trim((string) ($tipoCarga?->nombre ?? '')));

        if ($nombreTipoCarga === '' && $tipoCargaId && $convenio->relationLoaded('precios')) {
            $precioRelacion = $convenio->precios->firstWhere('tipo_carga_id', $tipoCargaId);
            $nombreTipoCarga = strtolower(trim((string) ($precioRelacion?->tipoCarga?->nombre ?? '')));
        }

        if ($nombreTipoCarga === '' && $tipoCargaId) {
            $tipoCargaModel = TipoCarga::find($tipoCargaId);
            $nombreTipoCarga = strtolower(trim((string) ($tipoCargaModel?->nombre ?? '')));
        }

        if ($nombreTipoCarga === '' && $convenio->relationLoaded('precios')) {
            $precioRelacion = $convenio->precios->first(function ($precio) {
                $nombre = strtolower(trim((string) ($precio->tipoCarga?->nombre ?? '')));
                return str_contains($nombre, 'kilo') || str_contains($nombre, 'kg');
            });

            if ($precioRelacion) {
                $nombreTipoCarga = strtolower(trim((string) ($precioRelacion->tipoCarga?->nombre ?? '')));
            }
        }

        return str_contains($nombreTipoCarga, 'kilo') || str_contains($nombreTipoCarga, 'kg');
    }
}
