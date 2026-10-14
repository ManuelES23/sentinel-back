<?php

namespace App\Services\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\DepreciationClose;
use App\Models\DepreciationCloseLine;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
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
        private CreadorActivo $creador,
        private ValidadorRelacionesActivo $validador,
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
        $activa = $this->asignacionActivaValida($activo, $fecha);

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
            'has_active_assignment' => $activa !== null,
        ];
    }

    /**
     * @param  array<int, int>  $destinosPermitidos  Ids de empresa que el controlador ya filtró para un traspaso
     *                                               (visibles para el usuario, distintas del origen y con `activos.create`).
     */
    public function registrar(FixedAsset $asset, array $datos, User $por, array $destinosPermitidos = []): FixedAssetDisposal
    {
        $tipo = $datos['type'] ?? null;
        if (! in_array($tipo, FixedAssetDisposal::TIPOS, true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de salida no válido.']);
        }
        if ($tipo === 'venta' && ! isset($datos['sale_price'])) {
            throw ValidationException::withMessages(['sale_price' => 'Indica el precio de venta.']);
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $datos['disposal_date']);

        return DB::transaction(function () use ($asset, $datos, $por, $tipo, $fecha, $destinosPermitidos) {
            $activo = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            $this->exigirSinSalidaVigente($activo);
            $this->validarFecha($activo, $fecha);
            $activa = $this->asignacionActivaValida($activo, $fecha);
            $destino = $tipo === 'traspaso' ? $this->destinoValido($activo, $datos, $destinosPermitidos) : null;

            $valores = $this->calculador->valores($activo, $fecha);
            $precio = $tipo === 'venta' ? number_format((float) $datos['sale_price'], 2, '.', '') : null;

            $salida = FixedAssetDisposal::create(array_merge([
                'enterprise_id' => $activo->enterprise_id,
                'fixed_asset_id' => $activo->id,
                'type' => $tipo,
                'disposal_date' => $fecha->toDateString(),
                'reason' => $datos['reason'] ?? null,
                'authorized_by_name' => $datos['authorized_by_name'],
                // Un baja antiguo (sin salida) conserva `baja`: sigue fuera de los cierres de depreciación
                // (no se le carga un acumulado que nunca apareció) y deshacer lo regresa a baja.
                'previous_status' => $activo->status,
                'asset_snapshot' => $this->copiaDelActivo($activo),
                'purchase_value' => $valores['purchase_value'],
                'accumulated_depreciation' => $valores['accumulated_depreciation'],
                'book_value' => $valores['book_value'],
                'sale_price' => $precio,
                'result_amount' => $this->calculador->resultado($tipo, $valores['book_value'], $precio),
                'destination_enterprise_id' => $destino?->id,
                'created_by' => $por->id,
                'created_by_name' => $por->name,
            ], $this->camposDelTipo($tipo, $datos)));

            if ($activa) {
                $this->devolverAsignacion($activa, $salida, $fecha, $por);
            }

            // Se relee: la devolución de la asignación cambió el estado del activo.
            $activo->refresh();
            $activo->update(['status' => 'baja']);

            if ($destino) {
                $nuevo = $this->crearActivoDestino($activo, $salida, $destino, $datos, $fecha);
                $salida->update(['destination_asset_id' => $nuevo->id]);
            }

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
                    'destination_asset_id' => $salida->destination_asset_id,
                ],
            );

            return $salida->refresh()->load('enterprise:id,name,slug');
        });
    }

    /**
     * Marca la salida como revertida y restaura el estado previo del activo. No se puede si hay
     * un cierre de depreciación posterior al mes de la salida (el cierre del mismo mes sí incluye
     * al activo). En un traspaso, además, retira el activo recibido si todavía no se usó.
     */
    public function deshacer(FixedAssetDisposal $salida, string $motivo, User $por): FixedAssetDisposal
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo para deshacer la salida.']);
        }

        return DB::transaction(function () use ($salida, $motivo, $por) {
            // Mismo orden de bloqueo que registrar: primero el activo y luego la salida.
            $activo = FixedAsset::withTrashed()->whereKey($salida->fixed_asset_id)->lockForUpdate()->firstOrFail();
            $actual = FixedAssetDisposal::query()->whereKey($salida->getKey())->lockForUpdate()->firstOrFail();

            if ($actual->reversed_at !== null) {
                throw ValidationException::withMessages(['salida' => 'Esta salida ya fue deshecha.']);
            }

            $this->exigirSinCierresPosteriores($actual);
            if ($actual->type === 'traspaso') {
                $this->retirarActivoDestino($actual);
            }

            $actual->update([
                'reversed_at' => now(),
                'reversed_by' => $por->id,
                'reversal_reason' => $motivo,
            ]);
            $activo->update(['status' => $actual->previous_status]);

            ActivityLog::log(
                action: 'reverse',
                model: 'FixedAssetDisposal',
                modelId: $actual->id,
                oldValues: ['type' => $actual->type, 'disposal_date' => $actual->disposal_date->toDateString(), 'book_value' => $actual->book_value],
                newValues: ['reason' => $motivo, 'reversed_by' => $por->id, 'status' => $actual->previous_status],
            );

            return $actual->refresh();
        });
    }

    private function exigirSinCierresPosteriores(FixedAssetDisposal $salida): void
    {
        $mes = CarbonImmutable::parse($salida->disposal_date->toDateString())->startOfMonth();
        $ultimo = DepreciationClose::where('enterprise_id', $salida->enterprise_id)
            ->where('period', '>', $mes->toDateString())
            ->max('period');

        if ($ultimo) {
            throw ValidationException::withMessages([
                'salida' => 'Hay cierres de depreciación posteriores al mes de la salida (último: '.substr((string) $ultimo, 0, 7).'). Reábrelos antes de deshacerla.',
            ]);
        }
    }

    /** Borra (lógicamente) el activo recibido en un traspaso, solo si todavía no se usó. */
    private function retirarActivoDestino(FixedAssetDisposal $salida): void
    {
        $destino = FixedAsset::withTrashed()->whereKey($salida->destination_asset_id)->lockForUpdate()->first();
        if (! $destino || $destino->trashed()) {
            return; // ya no existe: no hay nada que retirar
        }

        $motivo = match (true) {
            $destino->status === 'baja' || $destino->salidas()->exists() => 'El activo recibido ya tiene una salida registrada.',
            $destino->asignaciones()->exists() => 'El activo recibido ya tiene asignaciones.',
            DepreciationCloseLine::where('fixed_asset_id', $destino->id)->exists() => 'El activo recibido ya forma parte de un cierre de depreciación.',
            // Se recibió `disponible`: cualquier otro estado indica que ya se usó o se editó.
            $destino->status !== 'disponible' => 'El activo recibido ya cambió de estado.',
            default => null,
        };

        if ($motivo) {
            throw ValidationException::withMessages([
                'salida' => "{$motivo} Corrígelo en la empresa receptora ({$destino->code}).",
            ]);
        }

        $destino->delete();
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

    /** La empresa destino debe estar entre las permitidas; sucursal, entidad y área, ser de esa empresa. */
    private function destinoValido(FixedAsset $origen, array $datos, array $destinosPermitidos): Enterprise
    {
        $destinoId = (int) ($datos['destination_enterprise_id'] ?? 0);
        $destino = $destinoId !== $origen->enterprise_id && in_array($destinoId, $destinosPermitidos, true)
            ? Enterprise::find($destinoId)
            : null;

        if (! $destino) {
            throw ValidationException::withMessages([
                'destination_enterprise_id' => 'Elige una empresa destino distinta de la del activo y en la que puedas dar de alta activos.',
            ]);
        }

        $errores = [];
        foreach (['destination_branch_id' => 'sucursal', 'destination_entity_id' => 'entidad'] as $campo => $nombre) {
            if (empty($datos[$campo])) {
                $errores[$campo] = "Indica la {$nombre} de la empresa que recibe el activo.";
            }
        }

        $relaciones = $this->validador->errores($destino, [
            'branch_id' => $datos['destination_branch_id'] ?? null,
            'entity_id' => $datos['destination_entity_id'] ?? null,
            'area_id' => $datos['destination_area_id'] ?? null,
        ]);
        foreach ($relaciones as $campo => $mensaje) {
            $errores['destination_'.$campo] = $mensaje;
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $destino;
    }

    /**
     * Activo nuevo en la receptora: valor de compra = valor en libros, depreciación desde el mes
     * siguiente, tasa y residual del origen. La marca solo se copia si existe en la receptora.
     */
    private function crearActivoDestino(FixedAsset $origen, FixedAssetDisposal $salida, Enterprise $destino, array $datos, CarbonImmutable $fecha): FixedAsset
    {
        [$tasa, $residual] = $this->calculador->tasaYResidual($origen);
        $origen->loadMissing('enterprise:id,name');

        $marcaId = $origen->brand_id && Brand::whereKey($origen->brand_id)->forEnterprise($destino->id)->exists()
            ? $origen->brand_id
            : null;

        return $this->creador->crear($destino, array_merge(
            Arr::only($origen->getAttributes(), [
                'name', 'category_id', 'subcategory_id', 'model', 'serial_number', 'year', 'description', 'useful_life_years',
            ]),
            [
                'brand_id' => $marcaId,
                'branch_id' => $datos['destination_branch_id'],
                'entity_id' => $datos['destination_entity_id'],
                'area_id' => $datos['destination_area_id'] ?? null,
                'status' => 'disponible',
                'is_active' => true,
                'purchase_date' => $fecha->toDateString(),
                'purchase_value' => $salida->book_value,
                'depreciation_start' => $fecha->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'depreciation_rate' => $tasa,
                'salvage_percent' => $residual,
                'observations' => "Traspaso desde {$origen->code} ({$origen->enterprise->name}) el {$fecha->format('d/m/Y')}.",
            ],
        ));
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
