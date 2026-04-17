<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\ConvenioCompra;
use App\Models\SalidaCampoCosecha;
use App\Models\RecepcionEmpaque;
use App\Models\ProcesoEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableroProductoresController extends Controller
{
    /**
     * Tablero general: resumen y desglose por productor
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'productor_id' => 'nullable|exists:productores,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        $convenios = ConvenioCompra::with([
                'productor:id,nombre,apellido,tipo',
                'cultivo:id,nombre',
                'variedad:id,nombre',
                'precios.tipoCarga:id,nombre,peso_estimado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->where('status', 'activo')
            ->when($request->filled('productor_id'), fn($q) => $q->where('productor_id', $request->productor_id))
            ->get();

        $resumen = [
            'total_productores' => 0,
            'total_convenios' => 0,
            'total_salidas' => 0,
            'total_kilos' => 0,
            'monto_total_bruto' => 0,
            'descuento_rezaga_total' => 0,
            'monto_total_neto' => 0,
        ];

        $productores = [];

        foreach ($convenios->groupBy('productor_id') as $productorId => $productorConvenios) {
            $productor = $productorConvenios->first()->productor;
            $productorData = [
                'productor' => $productor,
                'convenios' => [],
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

        $convenios = ConvenioCompra::with([
                'productor:id,nombre,apellido,tipo,telefono,email,rfc',
                'cultivo:id,nombre',
                'variedad:id,nombre',
                'precios.tipoCarga:id,nombre,peso_estimado_kg',
            ])
            ->where('temporada_id', $request->temporada_id)
            ->where('productor_id', $productorId)
            ->where('status', 'activo')
            ->get();

        if ($convenios->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron convenios activos para este productor en la temporada',
            ], 404);
        }

        $productor = $convenios->first()->productor;
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
                'totales' => $totales,
            ],
        ]);
    }

    /**
     * Cálculo resumido de un convenio (para index)
     */
    private function calcularConvenio(ConvenioCompra $convenio, Request $request): array
    {
        $esPorKilos = (bool) $convenio->calculo_por_kilos;
        // Si es por kilos, necesitamos recepciones para obtener peso_bascula
        $salidas = $this->getSalidas($convenio, $request, $esPorKilos);

        $montoBruto = 0;
        $salidasData = [];

        foreach ($salidas as $salida) {
            $precioUnitario = $this->obtenerPrecio($convenio, $salida->tipo_carga_id, $salida->fecha);

            if ($esPorKilos) {
                // Cálculo por kilos: usar peso_bascula de recepciones
                $pesoBascula = 0;
                foreach ($salida->recepciones as $recepcion) {
                    $pesoBascula += (float) ($recepcion->peso_bascula ?? 0);
                }
                $subtotal = $pesoBascula * $precioUnitario;
            } else {
                // Cálculo estándar: por cantidad (cajas/unidades)
                $subtotal = $salida->cantidad * $precioUnitario;
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
        }

        $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);
        $descuentoRezaga = $montoBruto * ($porcentajeRezaga / 100);
        $montoNeto = $montoBruto - $descuentoRezaga;

        return [
            'convenio' => [
                'id' => $convenio->id,
                'folio_convenio' => $convenio->folio_convenio,
                'modalidad' => $convenio->modalidad,
                'calculo_por_kilos' => $esPorKilos,
                'porcentaje_rezaga' => $porcentajeRezaga,
                'fecha_inicio' => $convenio->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $convenio->fecha_fin?->format('Y-m-d'),
            ],
            'cultivo' => $convenio->cultivo?->nombre,
            'variedad' => $convenio->variedad?->nombre,
            'total_salidas' => count($salidasData),
            'total_cantidad' => $salidas->sum('cantidad'),
            'total_kilos' => (float) $salidas->sum('peso_neto_kg'),
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
        $esPorKilos = (bool) $convenio->calculo_por_kilos;

        $montoBruto = 0;
        $totalRecibidoKg = 0;
        $totalProducidoCajas = 0;
        $totalProducidoKg = 0;
        $totalEmbarcadoCajas = 0;
        $totalRezagaKg = 0;
        $salidasDetalle = [];

        foreach ($salidas as $salida) {
            $precioUnitario = $this->obtenerPrecio($convenio, $salida->tipo_carga_id, $salida->fecha);

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
        }

        // Cálculo de descuento por rezaga
        $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);

        if ($esPorKilos) {
            // Para kilos: % rezaga real basado en peso báscula de recepciones
            $totalPesoBase = $salidas->sum(function ($s) {
                return $s->recepciones->sum('peso_bascula');
            });
            $porcentajeRealRezaga = $totalPesoBase > 0
                ? ($totalRezagaKg / $totalPesoBase) * 100
                : 0;
        } else {
            $totalPesoBase = (float) $salidas->sum('peso_neto_kg');
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
                'calculo_por_kilos' => $esPorKilos,
                'porcentaje_rezaga' => $porcentajeRezaga,
                'fecha_inicio' => $convenio->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $convenio->fecha_fin?->format('Y-m-d'),
            ],
            'cultivo' => $convenio->cultivo?->nombre,
            'variedad' => $convenio->variedad?->nombre,
            'total_salidas' => count($salidasDetalle),
            'total_cantidad' => $salidas->sum('cantidad'),
            'total_kilos' => (float) $salidas->sum('peso_neto_kg'),
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

        if ($esPorKilos) {
            $result['total_producido_kg'] = round($totalProducidoKg, 2);
        }

        return $result;
    }

    /**
     * Obtener precio unitario para un tipo de carga en una fecha.
     * Solo retorna precio si el tipo_carga tiene precio activo con vigencia
     * que cubra la fecha de la salida.
     */
    private function obtenerPrecio(ConvenioCompra $convenio, ?int $tipoCargaId, $fecha): float
    {
        if (!$tipoCargaId) {
            return 0;
        }

        $precio = $convenio->precios()
            ->where('is_active', true)
            ->where('tipo_carga_id', $tipoCargaId)
            ->where('vigencia_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigencia_fin')
                  ->orWhere('vigencia_fin', '>=', $fecha);
            })
            ->latest('vigencia_inicio')
            ->first();

        return $precio ? (float) $precio->precio_unitario : 0;
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
}
