<?php

namespace App\Services\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\AssetCharacteristicDefinition;
use App\Models\Enterprise;
use App\Models\FixedAsset;

/**
 * Alta de un activo fijo. Lo usan la captura manual (FixedAssetController) y el
 * alta desde compras (AltaActivosDesdeCompra). Debe llamarse DENTRO de la
 * transacción del llamador: el código se toma con GeneradorCodigoActivo, que
 * bloquea la fila del consecutivo.
 */
class CreadorActivo
{
    public function __construct(private GeneradorCodigoActivo $codigos)
    {
    }

    /**
     * @param  array<string, mixed>  $datos  Ya validados; la empresa se fuerza a la indicada.
     * @param  array<int, array<string, mixed>>|null  $caracteristicas  null = no tocar las características.
     */
    public function crear(Enterprise $empresa, array $datos, ?array $caracteristicas = null): FixedAsset
    {
        $datos['enterprise_id'] = $empresa->id;
        $datos['code'] = ($datos['code'] ?? null) ?: $this->codigos->siguiente($empresa);
        $datos = $this->heredarDepreciacion($datos);

        $asset = FixedAsset::create($datos);

        if ($caracteristicas !== null) {
            $this->sincronizarCaracteristicas($asset, $caracteristicas);
        }

        return $asset;
    }

    /**
     * La tasa y el residual que no vengan se copian del subtipo o, si no tiene, del tipo.
     * Lo que captura el usuario siempre gana. El inicio de depreciación no se copia.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function heredarDepreciacion(array $datos): array
    {
        $ids = array_filter([$datos['subcategory_id'] ?? null, $datos['category_id'] ?? null]);
        if (! $ids) {
            return $datos;
        }

        $tipos = AssetCategory::whereIn('id', $ids)->get()->keyBy('id');
        $subtipo = $tipos[$datos['subcategory_id'] ?? 0] ?? null;
        $tipo = $tipos[$datos['category_id'] ?? 0] ?? null;

        foreach (['depreciation_rate', 'salvage_percent'] as $campo) {
            if (! isset($datos[$campo]) || $datos[$campo] === '') {
                $datos[$campo] = $subtipo?->{$campo} ?? $tipo?->{$campo};
            }
        }

        return $datos;
    }

    /**
     * Reemplaza las características capturadas de un activo. Las filas sin
     * nombre o sin valor se ignoran. Cuando una fila no viene ligada a una
     * definición existente, se registra en el catálogo de la categoría del
     * activo para poder reutilizarla después.
     */
    public function sincronizarCaracteristicas(FixedAsset $asset, array $caracteristicas): void
    {
        $asset->characteristics()->delete();

        $categoryId = $asset->subcategory_id ?: $asset->category_id;
        $order = 0;

        foreach ($caracteristicas as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $definitionId = $item['definition_id'] ?? null;

            if (! $definitionId && $categoryId) {
                // withTrashed(): si el nombre existía pero se borró del
                // catálogo, se recicla en vez de chocar con el único category_id+name.
                $definition = AssetCharacteristicDefinition::withTrashed()
                    ->where('category_id', $categoryId)
                    ->where('name', $name)
                    ->first();

                if ($definition) {
                    if ($definition->trashed()) {
                        $definition->restore();
                    }
                } else {
                    $definition = AssetCharacteristicDefinition::create([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'order' => 0,
                    ]);
                }

                $definitionId = $definition->id;
            }

            $asset->characteristics()->create([
                'definition_id' => $definitionId,
                'name' => $name,
                'value' => $value,
                'order' => $order++,
            ]);
        }
    }
}
