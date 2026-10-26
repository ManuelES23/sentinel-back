<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Support\CRM\RangoDashboard;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Por qué se pierden» del Dashboard (spec 2026-10-23 §4.6): oportunidades
 * cerradas como perdidas dentro del periodo (fecha_cierre_real), agrupadas por
 * la categoría del motivo de rechazo. A diferencia del embudo de conversión
 * (cohorte por fecha de creación), aquí cuenta cuándo se perdieron.
 */
class PerdidasCategoriaService
{
    public const NOMBRE_SIN_CATEGORIA = 'Sin categoría';

    /**
     * @return array{aplica: bool, total: int, monto: float, categorias: array<int, array{categoriaId: ?int, nombre: string, cantidad: int, monto: float, motivos: array<int, array{motivoId: int, nombre: string, cantidad: int, monto: float}>}>}
     */
    public function resumen(int $empresaId, ?int $vendedorId, RangoDashboard $rango): array
    {
        $filas = $this->perdidas($empresaId, $vendedorId, $rango)
            ->selectRaw('motivo_rechazo_id, COUNT(*) as cantidad, COALESCE(SUM(monto_esperado), 0) as monto')
            ->groupBy('motivo_rechazo_id')
            ->get();

        // La categoría se toma del motivo aunque hoy esté inactivo: lo histórico conserva su categoría.
        $motivos = CrmMotivoRechazo::with('categoria:id,nombre')
            ->whereIn('id', $filas->pluck('motivo_rechazo_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $categorias = [];
        $sinCategoria = ['cantidad' => 0, 'monto' => 0.0];
        foreach ($filas as $fila) {
            $motivo = $fila->motivo_rechazo_id !== null ? $motivos->get($fila->motivo_rechazo_id) : null;
            $cantidad = (int) $fila->cantidad;
            $monto = (float) $fila->monto;

            if ($motivo === null || $motivo->categoria === null) {
                $sinCategoria['cantidad'] += $cantidad;
                $sinCategoria['monto'] += $monto;

                continue;
            }

            $id = $motivo->categoria->id;
            $categorias[$id] ??= [
                'categoriaId' => $id,
                'nombre' => $motivo->categoria->nombre,
                'cantidad' => 0,
                'monto' => 0.0,
                'motivos' => [],
            ];
            $categorias[$id]['cantidad'] += $cantidad;
            $categorias[$id]['monto'] += $monto;
            $categorias[$id]['motivos'][] = [
                'motivoId' => $motivo->id,
                'nombre' => $motivo->nombre,
                'cantidad' => $cantidad,
                'monto' => round($monto, 2),
            ];
        }

        $porCantidadYNombre = fn (array $a, array $b) => [$b['cantidad'], $a['nombre']] <=> [$a['cantidad'], $b['nombre']];

        $categorias = array_values($categorias);
        foreach ($categorias as &$categoria) {
            $categoria['monto'] = round($categoria['monto'], 2);
            usort($categoria['motivos'], $porCantidadYNombre);
        }
        unset($categoria);
        usort($categorias, $porCantidadYNombre);

        // «Sin categoría» siempre al final, y solo si hay algo.
        if ($sinCategoria['cantidad'] > 0) {
            $categorias[] = [
                'categoriaId' => null,
                'nombre' => self::NOMBRE_SIN_CATEGORIA,
                'cantidad' => $sinCategoria['cantidad'],
                'monto' => round($sinCategoria['monto'], 2),
                'motivos' => [],
            ];
        }

        return [
            'aplica' => $this->aplica($empresaId),
            'total' => (int) $filas->sum('cantidad'),
            'monto' => round((float) $filas->sum('monto'), 2),
            'categorias' => $categorias,
        ];
    }

    /**
     * Oportunidades perdidas del periodo de una categoría ($categoriaId null =
     * sin categoría). Misma forma que EmbudoService::detalle.
     *
     * @return array{total: int, items: array<int, array>}
     */
    public function detalle(int $empresaId, ?int $vendedorId, RangoDashboard $rango, ?int $categoriaId): array
    {
        $query = $this->perdidas($empresaId, $vendedorId, $rango);

        if ($categoriaId === null) {
            $query->whereNull('motivo_rechazo_id');
        } else {
            $query->whereHas('motivoRechazo', fn ($m) => $m->where('categoria_id', $categoriaId));
        }

        $total = (clone $query)->count();
        $items = $query->with(EmbudoService::RELACIONES_DETALLE)
            ->orderByDesc('monto_esperado')
            ->orderBy('id')
            ->limit(EmbudoService::LIMITE_DETALLE)
            ->get()
            ->map(fn (CrmOportunidad $o) => EmbudoService::itemDetalle($o))
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    /** Perdidas cerradas en el rango (las borradas no cuentan; vendedor 0 = sin vendedor, no coincide con nada). */
    private function perdidas(int $empresaId, ?int $vendedorId, RangoDashboard $rango): Builder
    {
        return CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_perdido')
            ->whereBetween('fecha_cierre_real', [$rango->inicio, $rango->fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));
    }

    /** Hay catálogo utilizable, o la empresa ya tiene pérdidas con motivo de catálogo (aunque hoy esté desactivado). */
    private function aplica(int $empresaId): bool
    {
        return CrmCategoriaRechazo::empresaTieneCatalogo($empresaId)
            || CrmOportunidad::where('empresa_id', $empresaId)
                ->where('etapa', 'cerrado_perdido')
                ->whereNotNull('motivo_rechazo_id')
                ->exists();
    }
}
