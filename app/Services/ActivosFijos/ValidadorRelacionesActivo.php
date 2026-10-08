<?php

namespace App\Services\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\Entity;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de relación entre los datos de un activo (las usan la captura manual y
 * el alta desde compras). Recibe los valores efectivos y devuelve los errores
 * por campo; no lanza nada.
 */
class ValidadorRelacionesActivo
{
    /**
     * @param  array{branch_id?: mixed, entity_id?: mixed, area_id?: mixed, brand_id?: mixed, category_id?: mixed, subcategory_id?: mixed}  $v
     * @return array<string, string>
     */
    public function errores(Enterprise $empresa, array $v): array
    {
        $errores = [];

        $branchId = $v['branch_id'] ?? null;
        if ($branchId && ! Branch::where('id', $branchId)->where('enterprise_id', $empresa->id)->exists()) {
            $errores['branch_id'] = 'La sucursal seleccionada no pertenece a la empresa del activo';
        }

        $entityId = $v['entity_id'] ?? null;
        if ($entityId && $branchId && ! Entity::where('id', $entityId)->where('branch_id', $branchId)->exists()) {
            $errores['entity_id'] = 'La entidad seleccionada no pertenece a la sucursal indicada';
        }

        $areaId = $v['area_id'] ?? null;
        if ($areaId && $entityId && ! DB::table('entity_area')->where('entity_id', $entityId)->where('area_id', $areaId)->exists()) {
            $errores['area_id'] = 'El área seleccionada no pertenece a la entidad indicada';
        }

        $brandId = $v['brand_id'] ?? null;
        if ($brandId && ! Brand::whereKey($brandId)->forEnterprise($empresa->id)->exists()) {
            $errores['brand_id'] = 'La marca seleccionada no está dada de alta en la empresa del activo';
        }

        $categoryId = $v['category_id'] ?? null;
        if ($categoryId && AssetCategory::whereKey($categoryId)->whereNotNull('parent_id')->exists()) {
            $errores['category_id'] = 'El tipo de activo debe ser un tipo principal, no un subtipo';
        }

        $subcategoryId = $v['subcategory_id'] ?? null;
        if ($subcategoryId && ! AssetCategory::whereKey($subcategoryId)->where('parent_id', $categoryId)->exists()) {
            $errores['subcategory_id'] = 'El subtipo seleccionado no pertenece al tipo de activo';
        }

        return $errores;
    }
}
