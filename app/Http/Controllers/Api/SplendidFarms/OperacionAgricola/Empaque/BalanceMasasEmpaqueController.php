<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BalanceMasasEmpaqueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'nullable|exists:entities,id',
            'fecha' => 'nullable|date',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'folio' => 'nullable|string',
            'folios' => 'nullable',
            'productor_id' => 'nullable|exists:productores,id',
            'lote_id' => 'nullable|exists:lotes,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'excluir_maquila' => 'nullable|boolean',
        ]);

        [$fechaInicio, $fechaFin] = $this->resolveDateRange($validated);
        $folios = $this->parseFolios($request, $validated);

        $recepciones = $this->queryRecepciones($validated, $fechaInicio, $fechaFin, $folios)
            ->keyBy('id');

        $procesos = $this->queryProcesos($validated, $fechaInicio, $fechaFin, $folios);

        foreach ($procesos as $proceso) {
            if ($proceso->recepcion && !$recepciones->has($proceso->recepcion->id)) {
                $recepciones->put($proceso->recepcion->id, $proceso->recepcion);
            }
        }

        $groups = $this->buildGroups($recepciones, $procesos, $folios);
        $processIds = collect($groups)
            ->flatMap(fn (array $group) => collect($group['procesos'])->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        $rezagasByProceso = $this->queryRezagasByProceso($processIds);
        [$produccionNetaByFolio, $produccionBasculaByFolio] = $this->queryProduccionAtribuida($validated, $processIds, $groups);

        $rows = collect($groups)
            ->map(function (array $group) use ($rezagasByProceso, $produccionNetaByFolio, $produccionBasculaByFolio) {
                return $this->buildRow($group, $rezagasByProceso, $produccionNetaByFolio, $produccionBasculaByFolio);
            })
            ->sortBy([
                ['fecha_recepcion', 'desc'],
                ['folio', 'asc'],
            ])
            ->values();

        $summary = [
            'folios' => $rows->count(),
            'peso_recibido_bascula_kg' => round((float) $rows->sum('peso_recibido_bascula_kg'), 2),
            'peso_producido_bascula_kg' => round((float) $rows->sum('peso_producido_bascula_kg'), 2),
            'peso_rezaga_lavado_kg' => round((float) $rows->sum('peso_rezaga_lavado_kg'), 2),
            'peso_rezaga_produccion_kg' => round((float) $rows->sum('peso_rezaga_produccion_kg'), 2),
            'peso_rezaga_total_kg' => round((float) $rows->sum('peso_rezaga_total_kg'), 2),
            'peso_salida_control_kg' => round((float) $rows->sum('peso_salida_control_kg'), 2),
            'diferencia_kg' => round((float) $rows->sum('diferencia_kg'), 2),
        ];

        $summary['aprovechamiento_pct'] = $summary['peso_recibido_bascula_kg'] > 0
            ? round(($summary['peso_salida_control_kg'] / $summary['peso_recibido_bascula_kg']) * 100, 2)
            : 0.0;

        $options = $this->buildOptions($validated, $fechaInicio, $fechaFin, $folios);

        return response()->json([
            'success' => true,
            'data' => [
                'rows' => $rows,
                'summary' => $summary,
                'options' => $options,
            ],
        ]);
    }

    private function resolveDateRange(array $validated): array
    {
        if (!empty($validated['fecha'])) {
            return [$validated['fecha'], $validated['fecha']];
        }

        $inicio = $validated['fecha_inicio'] ?? $validated['from_date'] ?? null;
        $fin = $validated['fecha_fin'] ?? $validated['to_date'] ?? null;

        return [$inicio, $fin];
    }

    private function parseFolios(Request $request, array $validated): array
    {
        $raw = [];

        if (!empty($validated['folio'])) {
            $raw[] = $validated['folio'];
        }

        if (isset($validated['folios'])) {
            if (is_array($validated['folios'])) {
                $raw = [...$raw, ...$validated['folios']];
            } else {
                $raw[] = (string) $validated['folios'];
            }
        }

        $rawFromArray = $request->input('folios');
        if (is_array($rawFromArray)) {
            $raw = [...$raw, ...$rawFromArray];
        }

        return collect($raw)
            ->flatMap(function ($value) {
                if (is_string($value)) {
                    return preg_split('/[\n,;]+/', $value) ?: [];
                }

                return [(string) $value];
            })
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function queryRecepciones(array $validated, ?string $fechaInicio, ?string $fechaFin, array $folios): Collection
    {
        return $this->baseRecepcionesQuery($validated, $fechaInicio, $fechaFin, $folios)
            ->get();
    }

    private function baseRecepcionesQuery(array $validated, ?string $fechaInicio, ?string $fechaFin, array $folios)
    {
        return RecepcionEmpaque::query()
            ->with([
                'productor:id,nombre,apellido',
                'lote:id,nombre,numero_lote,zona_cultivo_id',
                'lote.zonaCultivo:id,nombre',
                'zonaCultivo:id,nombre',
                'variedad:id,nombre',
                'etapa:id,variedad_id',
                'etapa.variedad:id,nombre',
                'salidaCampo:id,variedad_id',
                'salidaCampo.variedad:id,nombre',
            ])
            ->where('temporada_id', $validated['temporada_id'])
            ->when(!empty($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->when(!empty($fechaInicio), fn ($q) => $q->whereDate('fecha_recepcion', '>=', $fechaInicio))
            ->when(!empty($fechaFin), fn ($q) => $q->whereDate('fecha_recepcion', '<=', $fechaFin))
            ->when(!empty($validated['productor_id']), fn ($q) => $q->where('productor_id', $validated['productor_id']))
            ->when(!empty($validated['lote_id']), fn ($q) => $q->where('lote_id', $validated['lote_id']))
            ->when(!empty($validated['zona_cultivo_id']), fn ($q) => $q->where('zona_cultivo_id', $validated['zona_cultivo_id']))
            ->when(!empty($validated['variedad_id']), fn ($q) => $q->where('variedad_id', $validated['variedad_id']))
            ->when(!empty($validated['excluir_maquila']), function ($q) {
                $q->whereDoesntHave('productor', function ($sub) {
                    $sub->where('maquila', true);
                });
            })
            ->when(!empty($folios), fn ($q) => $q->whereIn('folio_recepcion', $folios));
    }

    private function queryProcesos(array $validated, ?string $fechaInicio, ?string $fechaFin, array $folios): Collection
    {
        return ProcesoEmpaque::withTrashed()
            ->with([
                'recepcion:id,folio_recepcion,fecha_recepcion,productor_id,lote_id,zona_cultivo_id,variedad_id,etapa_id,salida_campo_id,peso_bascula,peso_recibido_kg,cantidad_recibida',
                'recepcion.productor:id,nombre,apellido',
                'recepcion.lote:id,nombre,numero_lote,zona_cultivo_id',
                'recepcion.lote.zonaCultivo:id,nombre',
                'recepcion.zonaCultivo:id,nombre',
                'recepcion.variedad:id,nombre',
                'recepcion.etapa:id,variedad_id',
                'recepcion.etapa.variedad:id,nombre',
                'recepcion.salidaCampo:id,variedad_id',
                'recepcion.salidaCampo.variedad:id,nombre',
                'productor:id,nombre,apellido',
                'lote:id,nombre,numero_lote,zona_cultivo_id',
                'lote.zonaCultivo:id,nombre',
                'etapa:id,nombre,variedad_id',
                'etapa.variedad:id,nombre',
            ])
            ->where('temporada_id', $validated['temporada_id'])
            ->when(!empty($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->when(!empty($validated['excluir_maquila']), function ($q) {
                $q->whereDoesntHave('productor', function ($sub) {
                    $sub->where('maquila', true);
                })->whereDoesntHave('recepcion.productor', function ($sub) {
                    $sub->where('maquila', true);
                });
            })
            ->when(!empty($folios), function ($q) use ($folios) {
                $q->where(function ($sub) use ($folios) {
                    $sub->whereIn('folio_proceso', $folios)
                        ->orWhereHas('recepcion', fn ($recepcionQuery) => $recepcionQuery->whereIn('folio_recepcion', $folios));
                });
            })
            ->where(function ($q) use ($validated, $fechaInicio, $fechaFin) {
                $q->whereHas('recepcion', function ($recepcionQuery) use ($validated, $fechaInicio, $fechaFin) {
                    $recepcionQuery
                        ->when(!empty($fechaInicio), fn ($sub) => $sub->whereDate('fecha_recepcion', '>=', $fechaInicio))
                        ->when(!empty($fechaFin), fn ($sub) => $sub->whereDate('fecha_recepcion', '<=', $fechaFin))
                        ->when(!empty($validated['productor_id']), fn ($sub) => $sub->where('productor_id', $validated['productor_id']))
                        ->when(!empty($validated['lote_id']), fn ($sub) => $sub->where('lote_id', $validated['lote_id']))
                        ->when(!empty($validated['zona_cultivo_id']), fn ($sub) => $sub->where('zona_cultivo_id', $validated['zona_cultivo_id']))
                        ->when(!empty($validated['variedad_id']), fn ($sub) => $sub->where('variedad_id', $validated['variedad_id']));
                });
            })
            ->orderBy('id')
            ->get();
    }

    private function buildGroups(Collection $recepciones, Collection $procesos, array $folios): array
    {
        $groups = [];

        foreach ($procesos as $proceso) {
            $folio = trim((string) ($proceso->recepcion?->folio_recepcion ?: $proceso->folio_proceso ?: ''));
            if ($folio === '') {
                continue;
            }

            if (!isset($groups[$folio])) {
                $groups[$folio] = [
                    'folio' => $folio,
                    'recepcion' => $proceso->recepcion,
                    'procesos' => [],
                ];
            }

            if (!$groups[$folio]['recepcion'] && $proceso->recepcion) {
                $groups[$folio]['recepcion'] = $proceso->recepcion;
            }

            $groups[$folio]['procesos'][] = $proceso;
        }

        foreach ($recepciones as $recepcion) {
            $folioRecepcion = trim((string) $recepcion->folio_recepcion);
            if ($folioRecepcion === '') {
                continue;
            }

            if (!isset($groups[$folioRecepcion])) {
                $groups[$folioRecepcion] = [
                    'folio' => $folioRecepcion,
                    'recepcion' => $recepcion,
                    'procesos' => [],
                ];
            }
        }

        if (!empty($folios)) {
            $groups = collect($groups)
                ->filter(fn (array $group) => in_array($group['folio'], $folios, true))
                ->all();
        }

        return $groups;
    }

    private function queryRezagasByProceso(Collection $processIds): Collection
    {
        if ($processIds->isEmpty()) {
            return collect();
        }

        return RezagaEmpaque::query()
            ->with([
                'ventaDetalles:id,rezaga_id,peso_kg',
                'ajustesPeso:id,rezaga_empaque_id,kg_antes,kg_despues',
            ])
            ->whereIn('proceso_id', $processIds)
            ->get()
            ->groupBy('proceso_id');
    }

    private function queryProduccionAtribuida(array $validated, Collection $processIds, array $groups): array
    {
        $produccionNetaByFolio = [];
        $produccionBasculaByFolio = [];

        if ($processIds->isEmpty()) {
            return [$produccionNetaByFolio, $produccionBasculaByFolio];
        }

        $procesoToFolio = [];
        foreach ($groups as $group) {
            foreach ($group['procesos'] as $proceso) {
                $procesoId = (int) ($proceso->id ?? 0);
                if ($procesoId <= 0) {
                    continue;
                }

                $procesoToFolio[$procesoId] = $group['folio'];
            }
        }

        $producciones = ProduccionEmpaque::query()
            ->with([
                'proceso:id,folio_proceso',
                'detalles:id,produccion_id,proceso_id,peso_neto_kg,total_cajas',
            ])
            ->when(!empty($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->where('temporada_id', $validated['temporada_id'])
            ->where(function ($q) use ($processIds) {
                $q->whereIn('proceso_id', $processIds)
                    ->orWhereHas('detalles', fn ($detalleQuery) => $detalleQuery->whereIn('proceso_id', $processIds));
            })
            ->get();

        foreach ($producciones as $produccion) {
            $attributionNeta = $this->getProduccionAtribuidaPorFolio($produccion, $procesoToFolio, 'peso_neto_kg', 'peso_bascula_kg');
            $attributionBascula = $this->getProduccionAtribuidaPorFolio($produccion, $procesoToFolio, 'peso_bascula_kg', null);

            foreach ($attributionNeta as $folio => $pesoKg) {
                $produccionNetaByFolio[$folio] = round((float) ($produccionNetaByFolio[$folio] ?? 0) + $pesoKg, 2);
            }

            foreach ($attributionBascula as $folio => $pesoKg) {
                $produccionBasculaByFolio[$folio] = round((float) ($produccionBasculaByFolio[$folio] ?? 0) + $pesoKg, 2);
            }
        }

        return [$produccionNetaByFolio, $produccionBasculaByFolio];
    }

    private function getProduccionAtribuidaPorFolio(ProduccionEmpaque $produccion, array $procesoToFolio, string $mainField, ?string $fallbackField = null): array
    {
        $atribucion = [];
        $pesoPrincipal = (float) ($produccion->{$mainField} ?? 0);
        $pesoFallback = $fallbackField ? (float) ($produccion->{$fallbackField} ?? 0) : 0.0;
        $pesoPallet = max($pesoPrincipal, $pesoFallback, 0);
        $detalles = $produccion->detalles ?? collect();

        if ($detalles->isEmpty()) {
            $folio = $procesoToFolio[(int) ($produccion->proceso_id ?? 0)] ?? null;
            if ($folio) {
                $atribucion[$folio] = $pesoPallet;
            }

            return $atribucion;
        }

        $totalCajasDetalles = (float) $detalles->sum(fn ($detalle) => (float) ($detalle->total_cajas ?? 0));
        $pesoDetallesTotal = 0.0;
        foreach ($detalles as $detalle) {
            $pesoDetalle = (float) ($detalle->peso_neto_kg ?? 0);
            if ($pesoDetalle <= 0 && $pesoPallet > 0 && $totalCajasDetalles > 0) {
                $cajasDetalle = (float) ($detalle->total_cajas ?? 0);
                if ($cajasDetalle > 0) {
                    $pesoDetalle = ($pesoPallet / $totalCajasDetalles) * $cajasDetalle;
                }
            }
            $pesoDetallesTotal += $pesoDetalle;

            $folio = $procesoToFolio[(int) ($detalle->proceso_id ?? 0)] ?? null;
            if (!$folio) {
                continue;
            }

            $atribucion[$folio] = ($atribucion[$folio] ?? 0) + $pesoDetalle;
        }

        $folioPrincipal = $procesoToFolio[(int) ($produccion->proceso_id ?? 0)] ?? null;
        if ($folioPrincipal) {
            $pesoBase = max($pesoPallet - $pesoDetallesTotal, 0);
            $atribucion[$folioPrincipal] = ($atribucion[$folioPrincipal] ?? 0) + $pesoBase;
        }

        return collect($atribucion)
            ->map(fn ($peso) => round((float) $peso, 2))
            ->all();
    }

    private function buildRow(array $group, Collection $rezagasByProceso, array $produccionNetaByFolio, array $produccionBasculaByFolio): array
    {
        $recepcion = $group['recepcion'];
        $procesos = collect($group['procesos']);
        $folio = $group['folio'];

        $pesoEntradaProcesos = (float) $procesos->sum(fn ($proceso) => (float) ($proceso->peso_entrada_kg ?? 0));
        $cantidadEntradaProcesos = (int) $procesos->sum(fn ($proceso) => (int) ($proceso->cantidad_entrada ?? 0));

        $pesoEntradaRecepcion = 0.0;
        $cantidadEntradaRecepcion = 0;
        if ($recepcion) {
            $pesoEntradaRecepcion = (float) ($recepcion->peso_bascula ?: $recepcion->peso_recibido_kg ?: 0);
            $cantidadEntradaRecepcion = (int) ($recepcion->cantidad_recibida ?? 0);
        }

        $pesoEntrada = $pesoEntradaProcesos > 0 ? $pesoEntradaProcesos : $pesoEntradaRecepcion;
        $cantidadEntrada = $cantidadEntradaProcesos > 0 ? $cantidadEntradaProcesos : $cantidadEntradaRecepcion;

        $pesoRezagaLavado = 0.0;
        $pesoRezagaProduccion = 0.0;
        foreach ($procesos as $proceso) {
            $rezagas = $rezagasByProceso->get((int) $proceso->id, collect());
            foreach ($rezagas as $rezaga) {
                $historicaKg = $this->resolveRezagaHistoricaKg($rezaga);
                $tipoRezaga = strtolower(trim((string) ($rezaga->tipo_rezaga ?? '')));

                if ($tipoRezaga === 'lavado') {
                    $pesoRezagaLavado += $historicaKg;
                } else {
                    $pesoRezagaProduccion += $historicaKg;
                }
            }
        }

        $pesoProduccionNeto = (float) ($produccionNetaByFolio[$folio] ?? 0);
        $pesoProduccionBascula = (float) ($produccionBasculaByFolio[$folio] ?? 0);

        $pesoRecibidoBascula = 0.0;
        if ($recepcion) {
            $pesoRecibidoBascula = (float) ($recepcion->peso_bascula ?? 0);
            if ($pesoRecibidoBascula <= 0) {
                $pesoRecibidoBascula = (float) ($recepcion->peso_recibido_kg ?? 0);
            }
        }
        if ($pesoRecibidoBascula <= 0) {
            $pesoRecibidoBascula = $pesoEntrada;
        }

        $pesoRezagaTotal = $pesoRezagaLavado + $pesoRezagaProduccion;
        $pesoSalidaControl = $pesoProduccionBascula + $pesoRezagaTotal;
        $diferenciaKg = $pesoRecibidoBascula - $pesoSalidaControl;

        $productor = $recepcion?->productor
            ?: $procesos->map(fn ($proceso) => $proceso->productor ?: $proceso->recepcion?->productor)->first(fn ($item) => !is_null($item));

        $lote = $recepcion?->lote
            ?: $procesos->map(fn ($proceso) => $proceso->lote ?: $proceso->recepcion?->lote)->first(fn ($item) => !is_null($item));

        $zonaCultivo = $recepcion?->zonaCultivo
            ?: $recepcion?->lote?->zonaCultivo
            ?: $procesos
                ->map(function ($proceso) {
                    return $proceso->recepcion?->zonaCultivo
                        ?: $proceso->recepcion?->lote?->zonaCultivo
                        ?: $proceso->lote?->zonaCultivo;
                })
                ->first(fn ($item) => !is_null($item));

        $variedad = $recepcion?->variedad
            ?: $recepcion?->etapa?->variedad
            ?: $recepcion?->salidaCampo?->variedad
            ?: $procesos
                ->map(function ($proceso) {
                    return $proceso->etapa?->variedad
                        ?: $proceso->recepcion?->variedad
                        ?: $proceso->recepcion?->etapa?->variedad
                        ?: $proceso->recepcion?->salidaCampo?->variedad;
                })
                ->first(fn ($item) => !is_null($item));

        $fechaRecepcion = $recepcion?->fecha_recepcion
            ?: $procesos->pluck('fecha_entrada')->filter()->sort()->first()
            ?: $procesos->pluck('fecha_proceso')->filter()->sort()->first();

        return [
            'folio' => $folio,
            'fecha_recepcion' => $fechaRecepcion,
            'productor' => [
                'id' => $productor?->id,
                'nombre' => trim(($productor?->nombre ?? '') . ' ' . ($productor?->apellido ?? '')),
            ],
            'lote' => [
                'id' => $lote?->id,
                'nombre' => $lote?->nombre,
                'numero_lote' => $lote?->numero_lote,
            ],
            'zona_cultivo' => [
                'id' => $zonaCultivo?->id,
                'nombre' => $zonaCultivo?->nombre,
            ],
            'variedad' => [
                'id' => $variedad?->id,
                'nombre' => $variedad?->nombre,
            ],
            'procesos_count' => $procesos->count(),
            'cantidad_entrada' => $cantidadEntrada,
            'peso_entrada_kg' => round($pesoEntrada, 2),
            'peso_recibido_bascula_kg' => round($pesoRecibidoBascula, 2),
            'peso_producido_bascula_kg' => round($pesoProduccionBascula, 2),
            'peso_produccion_kg' => round($pesoProduccionNeto, 2),
            'peso_rezaga_lavado_kg' => round($pesoRezagaLavado, 2),
            'peso_rezaga_produccion_kg' => round($pesoRezagaProduccion, 2),
            'peso_rezaga_total_kg' => round($pesoRezagaTotal, 2),
            'peso_salida_control_kg' => round($pesoSalidaControl, 2),
            'diferencia_kg' => round($diferenciaKg, 2),
            'aprovechamiento_pct' => $pesoRecibidoBascula > 0 ? round(($pesoSalidaControl / $pesoRecibidoBascula) * 100, 2) : 0.0,
        ];
    }

    private function resolveRezagaHistoricaKg(RezagaEmpaque $rezaga): float
    {
        $vendidoKg = (float) $rezaga->ventaDetalles->sum('peso_kg');
        $ajusteInicialKg = (float) $rezaga->ajustesPeso
            ->sortByDesc('id')
            ->pluck('kg_antes')
            ->first();

        return (float) max(
            (float) ($rezaga->cantidad_kg ?? 0) + $vendidoKg,
            $ajusteInicialKg,
            (float) ($rezaga->cantidad_kg ?? 0)
        );
    }

    private function buildOptions(array $validated, ?string $fechaInicio, ?string $fechaFin, array $folios): array
    {
        $recepciones = $this->baseRecepcionesQuery($validated, $fechaInicio, $fechaFin, $folios)->get();

        $productores = $recepciones
            ->filter(fn ($recepcion) => $recepcion->productor)
            ->map(fn ($recepcion) => [
                'id' => $recepcion->productor->id,
                'nombre' => trim($recepcion->productor->nombre . ' ' . $recepcion->productor->apellido),
            ])
            ->unique('id')
            ->sortBy('nombre')
            ->values();

        $lotes = $recepciones
            ->filter(fn ($recepcion) => $recepcion->lote)
            ->map(fn ($recepcion) => [
                'id' => $recepcion->lote->id,
                'nombre' => $recepcion->lote->nombre,
                'numero_lote' => $recepcion->lote->numero_lote,
            ])
            ->unique('id')
            ->sortBy('nombre')
            ->values();

        $zonas = $recepciones
            ->map(fn ($recepcion) => $recepcion->zonaCultivo ?: $recepcion->lote?->zonaCultivo)
            ->filter()
            ->map(fn ($zona) => [
                'id' => $zona->id,
                'nombre' => $zona->nombre,
            ])
            ->unique('id')
            ->sortBy('nombre')
            ->values();

        $variedades = $recepciones
            ->map(function ($recepcion) {
                return $recepcion->variedad
                    ?: $recepcion->etapa?->variedad
                    ?: $recepcion->salidaCampo?->variedad;
            })
            ->filter()
            ->map(fn ($variedad) => [
                'id' => $variedad->id,
                'nombre' => $variedad->nombre,
            ])
            ->unique('id')
            ->sortBy('nombre')
            ->values();

        return [
            'productores' => $productores,
            'lotes' => $lotes,
            'zonas_cultivo' => $zonas,
            'variedades' => $variedades,
        ];
    }
}
