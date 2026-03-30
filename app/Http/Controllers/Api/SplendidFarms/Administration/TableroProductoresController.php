<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\ConvenioCompra;
use App\Models\SalidaCampoCosecha;
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
                'precios.tipoCarga:id,nombre',
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
                $salidaQuery = SalidaCampoCosecha::where('convenio_compra_id', $convenio->id)
                    ->where('eliminado', false)
                    ->with('tipoCarga:id,nombre');

                if ($request->filled('fecha_inicio')) {
                    $salidaQuery->where('fecha', '>=', $request->fecha_inicio);
                }
                if ($request->filled('fecha_fin')) {
                    $salidaQuery->where('fecha', '<=', $request->fecha_fin);
                }

                $salidas = $salidaQuery->get();

                $montoBruto = 0;
                $salidasData = [];

                foreach ($salidas as $salida) {
                    $precio = $convenio->precioVigente($salida->tipo_carga_id, $salida->fecha);
                    $precioUnitario = $precio ? (float) $precio->precio_unitario : 0;
                    $subtotal = $salida->cantidad * $precioUnitario;
                    $montoBruto += $subtotal;

                    $salidasData[] = [
                        'id' => $salida->id,
                        'folio_salida' => $salida->folio_salida,
                        'fecha' => $salida->fecha->format('Y-m-d'),
                        'cantidad' => $salida->cantidad,
                        'peso_neto_kg' => (float) $salida->peso_neto_kg,
                        'tipo_carga' => $salida->tipoCarga?->nombre,
                        'precio_unitario' => $precioUnitario,
                        'subtotal' => $subtotal,
                    ];
                }

                $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);
                $descuentoRezaga = $montoBruto * ($porcentajeRezaga / 100);
                $montoNeto = $montoBruto - $descuentoRezaga;

                $convenioData = [
                    'convenio' => [
                        'id' => $convenio->id,
                        'folio_convenio' => $convenio->folio_convenio,
                        'modalidad' => $convenio->modalidad,
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

                $productorData['convenios'][] = $convenioData;
                $productorData['totales']['total_salidas'] += $convenioData['total_salidas'];
                $productorData['totales']['total_cantidad'] += $convenioData['total_cantidad'];
                $productorData['totales']['total_kilos'] += $convenioData['total_kilos'];
                $productorData['totales']['monto_bruto'] += $montoBruto;
                $productorData['totales']['descuento_rezaga'] += $descuentoRezaga;
                $productorData['totales']['monto_neto'] += $montoNeto;

                $resumen['total_convenios']++;
                $resumen['total_salidas'] += $convenioData['total_salidas'];
                $resumen['total_kilos'] += $convenioData['total_kilos'];
                $resumen['monto_total_bruto'] += $montoBruto;
                $resumen['descuento_rezaga_total'] += $descuentoRezaga;
                $resumen['monto_total_neto'] += $montoNeto;
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
     * Detalle de un productor para reporte de pago
     */
    public function show(Request $request, int $productorId): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
        ]);

        $convenios = ConvenioCompra::with([
                'productor:id,nombre,apellido,tipo',
                'cultivo:id,nombre',
                'variedad:id,nombre',
                'precios.tipoCarga:id,nombre',
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
            'monto_bruto' => 0,
            'descuento_rezaga' => 0,
            'monto_neto' => 0,
        ];

        foreach ($convenios as $convenio) {
            $salidaQuery = SalidaCampoCosecha::where('convenio_compra_id', $convenio->id)
                ->where('eliminado', false)
                ->with(['tipoCarga:id,nombre', 'lote:id,nombre', 'zonaCultivo:id,nombre']);

            if ($request->filled('fecha_inicio')) {
                $salidaQuery->where('fecha', '>=', $request->fecha_inicio);
            }
            if ($request->filled('fecha_fin')) {
                $salidaQuery->where('fecha', '<=', $request->fecha_fin);
            }

            $salidas = $salidaQuery->orderBy('fecha')->get();

            $montoBruto = 0;
            $salidasDetalle = [];

            foreach ($salidas as $salida) {
                $precio = $convenio->precioVigente($salida->tipo_carga_id, $salida->fecha);
                $precioUnitario = $precio ? (float) $precio->precio_unitario : 0;
                $subtotal = $salida->cantidad * $precioUnitario;
                $montoBruto += $subtotal;

                $salidasDetalle[] = [
                    'id' => $salida->id,
                    'folio_salida' => $salida->folio_salida,
                    'fecha' => $salida->fecha->format('Y-m-d'),
                    'cantidad' => $salida->cantidad,
                    'peso_neto_kg' => (float) $salida->peso_neto_kg,
                    'tipo_carga' => $salida->tipoCarga?->nombre,
                    'lote' => $salida->lote?->nombre,
                    'zona_cultivo' => $salida->zonaCultivo?->nombre,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                ];
            }

            $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);
            $descuentoRezaga = $montoBruto * ($porcentajeRezaga / 100);
            $montoNeto = $montoBruto - $descuentoRezaga;

            $conveniosData[] = [
                'convenio' => [
                    'id' => $convenio->id,
                    'folio_convenio' => $convenio->folio_convenio,
                    'modalidad' => $convenio->modalidad,
                    'porcentaje_rezaga' => $porcentajeRezaga,
                    'fecha_inicio' => $convenio->fecha_inicio?->format('Y-m-d'),
                    'fecha_fin' => $convenio->fecha_fin?->format('Y-m-d'),
                ],
                'cultivo' => $convenio->cultivo?->nombre,
                'variedad' => $convenio->variedad?->nombre,
                'total_salidas' => count($salidasDetalle),
                'total_cantidad' => $salidas->sum('cantidad'),
                'total_kilos' => (float) $salidas->sum('peso_neto_kg'),
                'monto_bruto' => $montoBruto,
                'descuento_rezaga' => $descuentoRezaga,
                'monto_neto' => $montoNeto,
                'salidas' => $salidasDetalle,
            ];

            $totales['total_salidas'] += count($salidasDetalle);
            $totales['total_cantidad'] += $salidas->sum('cantidad');
            $totales['total_kilos'] += (float) $salidas->sum('peso_neto_kg');
            $totales['monto_bruto'] += $montoBruto;
            $totales['descuento_rezaga'] += $descuentoRezaga;
            $totales['monto_neto'] += $montoNeto;
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
}
