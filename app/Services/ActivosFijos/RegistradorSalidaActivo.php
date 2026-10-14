<?php

namespace App\Services\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\DepreciationClose;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salidas de activos fijos. Toda escritura bloquea la fila del activo dentro de una transacción:
 * así no pueden existir dos salidas vigentes del mismo activo ni una asignación devuelta a medias.
 * El activo queda en `baja` y las cifras (acumulada, libro, resultado) se congelan a la fecha.
 */
class RegistradorSalidaActivo
{
    private const CAMPOS_VENTA = ['buyer_name', 'buyer_address', 'buyer_identification', 'payment_method', 'place'];

    public function __construct(
        private CalculadorSalida $calculador,
        private AsignadorActivos $asignador,
    ) {
    }

    /**
     * Vista previa de una salida: cifras a la fecha, sin escribir nada. Aplica las mismas reglas de
     * fecha que `registrar`, para que el formulario avise antes de confirmar.
     *
     * @return array{purchase_value: ?string, accumulated_depreciation: string, book_value: string, result_amount: ?string, warnings: list<array{code: string, message: string}>, has_active_assignment: bool}
     */
    public function previa(FixedAsset $activo, string $tipo, CarbonImmutable $fecha, ?string $precio = null): array
    {
        $this->exigirSinSalidaVigente($activo);
        $this->validarFecha($activo, $fecha);

        $valores = $this->calculador->valores($activo, $fecha);
        $resultado = $tipo === 'venta' && $precio === null
            ? null
            : $this->calculador->resultado($tipo, $valores['book_value'], $precio);

        return [
            'purchase_value' => $valores['purchase_value'],
            'accumulated_depreciation' => $valores['accumulated_depreciation'],
            'book_value' => $valores['book_value'],
            'result_amount' => $resultado,
            'warnings' => $valores['warnings'],
            'has_active_assignment' => $activo->asignacionActiva()->exists(),
        ];
    }

    public function registrar(FixedAsset $asset, array $datos, User $por): FixedAssetDisposal
    {
        $tipo = $datos['type'] ?? null;
        if (! in_array($tipo, FixedAssetDisposal::TIPOS, true) || $tipo === 'traspaso') {
            throw ValidationException::withMessages(['type' => 'Tipo de salida no válido.']);
        }
        if ($tipo === 'venta' && ! isset($datos['sale_price'])) {
            throw ValidationException::withMessages(['sale_price' => 'Indica el precio de venta.']);
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $datos['disposal_date']);

        return DB::transaction(function () use ($asset, $datos, $por, $tipo, $fecha) {
            $activo = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $this->exigirSinSalidaVigente($activo);
            $this->validarFecha($activo, $fecha);
            $activa = $this->asignacionActivaValida($activo, $fecha);

            $valores = $this->calculador->valores($activo, $fecha);
            $precio = $tipo === 'venta' ? number_format((float) $datos['sale_price'], 2, '.', '') : null;

            $salida = FixedAssetDisposal::create(array_merge([
                'enterprise_id' => $activo->enterprise_id,
                'fixed_asset_id' => $activo->id,
                'type' => $tipo,
                'disposal_date' => $fecha->toDateString(),
                'reason' => $datos['reason'] ?? null,
                'authorized_by_name' => $datos['authorized_by_name'],
                // Un baja antiguo (sin salida) no sabe a qué estado volver: disponible.
                'previous_status' => $activo->status === 'baja' ? 'disponible' : $activo->status,
                'asset_snapshot' => $this->copiaDelActivo($activo),
                'purchase_value' => $valores['purchase_value'],
                'accumulated_depreciation' => $valores['accumulated_depreciation'],
                'book_value' => $valores['book_value'],
                'sale_price' => $precio,
                'result_amount' => $this->calculador->resultado($tipo, $valores['book_value'], $precio),
                'created_by' => $por->id,
                'created_by_name' => $por->name,
            ], $this->camposDelTipo($tipo, $datos)));

            if ($activa) {
                $this->devolverAsignacion($activa, $salida, $fecha, $por);
            }

            // Se relee: la devolución de la asignación cambió el estado del activo.
            $activo->refresh();
            $activo->update(['status' => 'baja']);

            ActivityLog::log(
                action: 'dispose',
                model: 'FixedAssetDisposal',
                modelId: $salida->id,
                oldValues: ['status' => $salida->previous_status],
                newValues: [
                    'type' => $tipo,
                    'disposal_date' => $fecha->toDateString(),
                    'book_value' => $valores['book_value'],
                    'result_amount' => $salida->result_amount,
                ],
            );

            return $salida->refresh()->load('enterprise:id,name,slug');
        });
    }

    /** @return array<string, mixed> Solo los campos propios del tipo; el resto se ignora. */
    private function camposDelTipo(string $tipo, array $datos): array
    {
        return match ($tipo) {
            'venta' => array_intersect_key($datos, array_flip(self::CAMPOS_VENTA)),
            'siniestro' => ['claim_reference' => $datos['claim_reference'] ?? null],
            default => [],
        };
    }

    private function exigirSinSalidaVigente(FixedAsset $activo): void
    {
        if ($activo->salidaVigente()->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'El activo ya tiene una salida registrada. Deshazla antes de registrar otra.',
            ]);
        }
    }

    /** No futura, no anterior a la compra y en un mes posterior al último cierre de depreciación. */
    private function validarFecha(FixedAsset $activo, CarbonImmutable $fecha): void
    {
        if ($fecha->greaterThan(CarbonImmutable::instance(now())->startOfDay())) {
            throw ValidationException::withMessages(['disposal_date' => 'La fecha de la salida no puede ser futura.']);
        }

        if ($activo->purchase_date && $fecha->lessThan(CarbonImmutable::instance($activo->purchase_date)->startOfDay())) {
            throw ValidationException::withMessages([
                'disposal_date' => 'La fecha de la salida no puede ser anterior a la fecha de compra del activo.',
            ]);
        }

        $ultimo = DepreciationClose::where('enterprise_id', $activo->enterprise_id)->max('period');
        if ($ultimo && $fecha->startOfMonth()->lessThanOrEqualTo(CarbonImmutable::parse(substr((string) $ultimo, 0, 10)))) {
            throw ValidationException::withMessages([
                'disposal_date' => 'El mes de depreciación de esa fecha ya está cerrado (último cierre: '.substr((string) $ultimo, 0, 7).'). Reabre el último cierre antes de registrar la salida.',
            ]);
        }
    }

    /** La asignación activa (si hay) debe haberse entregado en o antes de la fecha de la salida. */
    private function asignacionActivaValida(FixedAsset $activo, CarbonImmutable $fecha): ?FixedAssetAssignment
    {
        $activa = $activo->asignacionActiva()->first();

        if ($activa && $fecha->lessThan(CarbonImmutable::instance($activa->assigned_at)->startOfDay())) {
            throw ValidationException::withMessages([
                'disposal_date' => 'La fecha de la salida no puede ser anterior a la entrega vigente del activo ('.$activa->assigned_at->format('d/m/Y').').',
            ]);
        }

        return $activa;
    }

    private function devolverAsignacion(FixedAssetAssignment $activa, FixedAssetDisposal $salida, CarbonImmutable $fecha, User $por): void
    {
        $this->asignador->devolver($activa, [
            'returned_at' => $fecha->toDateString(),
            'condition_in' => $activa->condition_out,
            'return_reason' => 'otro',
            'return_notes' => "Devuelta por baja del activo (salida #{$salida->id}).",
        ], $por);
    }

    /** Copia del activo al registrar la salida: la carta se reimprime idéntica aunque el activo cambie. */
    private function copiaDelActivo(FixedAsset $activo): array
    {
        $activo->loadMissing(['category:id,name', 'subcategory:id,name', 'brand:id,name', 'branch:id,name']);

        return [
            'code' => $activo->code,
            'name' => $activo->name,
            'category' => $activo->category?->name,
            'subcategory' => $activo->subcategory?->name,
            'brand' => $activo->brand?->name,
            'model' => $activo->model,
            'serial_number' => $activo->serial_number,
            'year' => $activo->year,
            'status' => $activo->status,
            'status_label' => $activo->status_label,
            'branch' => $activo->branch?->name,
        ];
    }
}
