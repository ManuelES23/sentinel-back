<?php
// sentinel-back/app/Services/ActivosFijos/AsignadorActivos.php

namespace App\Services\ActivosFijos;

use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio de las asignaciones. Toda escritura bloquea la fila del activo
 * (o de la asignación) dentro de una transacción: así no pueden existir dos
 * asignaciones activas del mismo activo ni una devolución duplicada.
 */
class AsignadorActivos
{
    public const ESTADOS_BLOQUEADOS = ['en_mantenimiento', 'fuera_de_servicio', 'baja'];

    public function __construct(private ResponsablesActivo $responsables)
    {
    }

    /** Motivo por el que el activo no admite una asignación nueva; null si es asignable. */
    public function motivoBloqueo(FixedAsset $asset): ?string
    {
        if (! $asset->is_active) {
            return 'El activo está inactivo.';
        }

        if (in_array($asset->status, self::ESTADOS_BLOQUEADOS, true)) {
            return 'Un activo con estado «'.(FixedAsset::STATUSES[$asset->status] ?? $asset->status).'» no se puede asignar.';
        }

        return null;
    }

    public function asignar(FixedAsset $asset, array $datos, User $por): FixedAssetAssignment
    {
        return DB::transaction(function () use ($asset, $datos, $por) {
            $activo = $this->bloquearActivo($asset);

            if ($this->activaDe($activo)) {
                throw ValidationException::withMessages([
                    'asset' => 'El activo ya tiene una asignación activa. Devuélvelo o usa «Reasignar».',
                ]);
            }

            return $this->crear($activo, $datos, $por);
        });
    }

    public function devolver(FixedAssetAssignment $asignacion, array $datos, User $por): FixedAssetAssignment
    {
        return DB::transaction(function () use ($asignacion, $datos, $por) {
            [$activo, $actual] = $this->bloquearActivoYAsignacion($asignacion);
            $this->cerrar($actual, $activo, $datos, $por);

            return $actual->refresh();
        });
    }

    /** Devolución y nueva entrega en una sola transacción: si algo falla, no cambia nada. */
    public function reasignar(FixedAsset $asset, array $devolucion, array $nueva, User $por): FixedAssetAssignment
    {
        return DB::transaction(function () use ($asset, $devolucion, $nueva, $por) {
            $activo = $this->bloquearActivo($asset);
            $actual = $this->activaDe($activo);

            if (! $actual) {
                throw ValidationException::withMessages([
                    'asset' => 'El activo no tiene una asignación activa; usa «Asignar».',
                ]);
            }

            $this->cerrar($actual, $activo, $devolucion, $por);

            return $this->crear($activo->refresh(), $nueva, $por);
        });
    }

    /** Solo área, accesorios, notas y condición de entrega, y solo mientras está activa. */
    public function corregir(FixedAssetAssignment $asignacion, array $datos): FixedAssetAssignment
    {
        return DB::transaction(function () use ($asignacion, $datos) {
            [$activo, $actual] = $this->bloquearActivoYAsignacion($asignacion);

            if ($actual->returned_at !== null) {
                throw ValidationException::withMessages([
                    'asignacion' => 'Una asignación devuelta ya no se puede corregir.',
                ]);
            }

            $actual->update(Arr::only($datos, ['area_id', 'accessories', 'notes', 'condition_out']));

            if (! empty($datos['area_id'])) {
                // Con la instancia (no query builder) para que Loggable registre el cambio.
                $activo->update(['area_id' => $datos['area_id']]);
            }

            return $actual->refresh();
        });
    }

    private function bloquearActivo(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Mismo orden de bloqueo que asignar/reasignar (activo y luego asignación) para evitar
     * deadlocks: se lee fixed_asset_id sin bloquear, se bloquea el activo y se relee la asignación.
     *
     * @return array{0: FixedAsset, 1: FixedAssetAssignment}
     */
    private function bloquearActivoYAsignacion(FixedAssetAssignment $asignacion): array
    {
        $activoId = FixedAssetAssignment::query()->whereKey($asignacion->getKey())->firstOrFail()->fixed_asset_id;

        $activo = FixedAsset::withTrashed()->whereKey($activoId)->lockForUpdate()->firstOrFail();
        $actual = FixedAssetAssignment::query()->whereKey($asignacion->getKey())->lockForUpdate()->firstOrFail();

        return [$activo, $actual];
    }

    private function activaDe(FixedAsset $activo): ?FixedAssetAssignment
    {
        return FixedAssetAssignment::query()
            ->where('fixed_asset_id', $activo->id)
            ->whereNull('returned_at')
            ->lockForUpdate()
            ->first();
    }

    private function crear(FixedAsset $activo, array $datos, User $por): FixedAssetAssignment
    {
        if ($motivo = $this->motivoBloqueo($activo)) {
            throw ValidationException::withMessages(['asset' => $motivo]);
        }

        $persona = $this->responsables->resolver($datos['assignee_type'], (int) $datos['assignee_id'], $activo->enterprise);

        $asignacion = FixedAssetAssignment::create([
            'enterprise_id' => $activo->enterprise_id,
            'fixed_asset_id' => $activo->id,
            'assignee_type' => $persona['tipo'],
            'assignee_id' => $persona['id'],
            'assignee_name' => $persona['nombre'],
            'assignee_position' => $persona['puesto'],
            'assignee_department' => $persona['departamento'],
            'area_id' => $datos['area_id'] ?? null,
            'assigned_at' => $datos['assigned_at'] ?? now()->toDateString(),
            'assigned_by' => $por->id,
            'condition_out' => $datos['condition_out'] ?? 'bueno',
            'accessories' => $datos['accessories'] ?? null,
            'notes' => $datos['notes'] ?? null,
            'asset_snapshot' => $this->copiaDelActivo($activo),
        ]);

        $activo->update(array_filter(
            ['status' => 'en_uso', 'area_id' => $datos['area_id'] ?? null],
            fn ($valor) => $valor !== null,
        ));

        return $asignacion;
    }

    private function cerrar(FixedAssetAssignment $actual, FixedAsset $activo, array $datos, User $por): void
    {
        if ($actual->returned_at !== null) {
            throw ValidationException::withMessages(['asignacion' => 'La asignación ya fue devuelta.']);
        }

        $fecha = $datos['returned_at'] ?? now()->toDateString();
        if (Carbon::parse($fecha)->startOfDay()->lt($actual->assigned_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'returned_at' => 'La fecha de devolución no puede ser anterior a la de entrega.',
            ]);
        }

        $actual->update([
            'returned_at' => $fecha,
            'returned_by' => $por->id,
            'condition_in' => $datos['condition_in'],
            'return_reason' => $datos['return_reason'],
            'return_notes' => $datos['return_notes'] ?? null,
        ]);

        // Un estado bloqueado (mantenimiento, fuera de servicio, baja) se respeta.
        if (! in_array($activo->status, self::ESTADOS_BLOQUEADOS, true)) {
            $activo->update(['status' => 'disponible']);
        }
    }

    /** Copia del activo al entregar: la carta se reimprime idéntica aunque el activo cambie. */
    private function copiaDelActivo(FixedAsset $activo): array
    {
        $activo->loadMissing(['category:id,name', 'subcategory:id,name', 'brand:id,name']);

        return [
            'code' => $activo->code,
            'name' => $activo->name,
            'category' => $activo->category?->name,
            'subcategory' => $activo->subcategory?->name,
            'brand' => $activo->brand?->name,
            'model' => $activo->model,
            'serial_number' => $activo->serial_number,
            'year' => $activo->year,
            'purchase_value' => $activo->purchase_value,
        ];
    }
}
