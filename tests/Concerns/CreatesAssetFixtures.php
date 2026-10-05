<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Str;

/**
 * Fixtures del módulo Activos Fijos: Splendid Farms (empresa operativa) y
 * Grupo Espléndido (corporativa, dueña del catálogo de tipos), cada una con
 * Administración → Activos Fijos, más sucursal → entidad y catálogos.
 */
trait CreatesAssetFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected Enterprise $corporativo;
    protected Branch $branch;
    protected Entity $entity;
    protected AssetCategory $assetCategory;
    protected AssetCategory $assetSubcategory;
    protected Brand $brand;
    protected UnitOfMeasure $unit;

    protected const PERMISOS_CRUD = ['view', 'create', 'edit', 'delete'];

    protected function setUpAssetFixtures(): void
    {
        $this->actingUser = User::factory()->create(['role' => 'user']);

        $this->enterprise = $this->crearEmpresaActivos('splendidfarms', 'Splendid Farms', 'SF');
        $this->corporativo = $this->crearEmpresaActivos('grupoesplendido', 'Grupo Espléndido', 'GE');

        $this->otorgarActivos($this->actingUser, $this->enterprise, 'activos', self::PERMISOS_CRUD);
        $this->otorgarActivos($this->actingUser, $this->corporativo, 'activos', self::PERMISOS_CRUD);
        $this->otorgarActivos($this->actingUser, $this->corporativo, 'tipos-activo', self::PERMISOS_CRUD);

        [$this->branch, $this->entity] = $this->crearUbicacion($this->enterprise, 'SF');

        $this->assetCategory = AssetCategory::create([
            'code' => 'TAC-001', 'name' => 'Equipos de cómputo', 'icon' => 'Laptop', 'is_active' => true,
        ]);
        $this->assetSubcategory = AssetCategory::create([
            'code' => 'TAC-002', 'name' => 'Laptops', 'parent_id' => $this->assetCategory->id, 'icon' => 'Laptop', 'is_active' => true,
        ]);

        $this->brand = Brand::create(['code' => 'MRC-001', 'name' => 'Dell', 'is_active' => true]);
        $this->brand->enterprises()->attach($this->enterprise->id);

        $this->unit = UnitOfMeasure::create(['code' => 'HRS', 'name' => 'Horas', 'abbreviation' => 'hrs']);
    }

    protected function crearEmpresaActivos(string $slug, string $nombre, string $prefijo): Enterprise
    {
        $empresa = Enterprise::create([
            'name' => $nombre, 'slug' => $slug, 'description' => $nombre,
            'is_active' => true, 'asset_code_prefix' => $prefijo,
        ]);
        $this->moduloActivos($empresa);

        return $empresa;
    }

    protected function moduloActivos(Enterprise $empresa): Module
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
            ['name' => 'Administración', 'path' => "/{$empresa->slug}/administration", 'description' => 'administration'],
        );

        return Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'activos-fijos'], ['name' => 'Activos Fijos']);
    }

    protected function otorgarActivos(User $user, Enterprise $empresa, string $submodulo, array $permisos): void
    {
        UserEnterpriseAccess::firstOrCreate(
            ['user_id' => $user->id, 'enterprise_id' => $empresa->id],
            ['is_active' => true, 'granted_at' => now()],
        );

        $sub = Submodule::firstOrCreate(
            ['module_id' => $this->moduloActivos($empresa)->id, 'slug' => $submodulo],
            ['name' => Str::headline($submodulo)],
        );

        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $sub->id, 'slug' => $slug],
                ['name' => Str::headline($slug), 'is_active' => true],
            );
            UserSubmodulePermission::firstOrCreate(
                ['user_id' => $user->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }

    /** @return array{0: Branch, 1: Entity} */
    protected function crearUbicacion(Enterprise $empresa, string $sufijo): array
    {
        $sucursal = Branch::create([
            'enterprise_id' => $empresa->id, 'code' => "SUC-{$sufijo}", 'name' => "Matriz {$sufijo}",
            'slug' => Str::slug("matriz-{$sufijo}"), 'is_active' => true, 'is_main' => true,
        ]);
        $tipoEntidad = EntityType::firstOrCreate(['code' => 'OFICINA'], ['name' => 'Oficina', 'slug' => 'oficina', 'is_active' => true]);
        $entidad = Entity::create([
            'branch_id' => $sucursal->id, 'entity_type_id' => $tipoEntidad->id, 'code' => "OFC-{$sufijo}",
            'name' => "Oficinas {$sufijo}", 'slug' => Str::slug("oficinas-{$sufijo}"), 'is_active' => true,
        ]);

        return [$sucursal, $entidad];
    }

    /** Payload mínimo válido; sirve para el POST y para FixedAsset::create. */
    protected function validFixedAssetPayload(array $overrides = []): array
    {
        return array_merge([
            'enterprise_id' => $this->enterprise->id,
            'name' => 'Laptop Dell Latitude 5530',
            'category_id' => $this->assetCategory->id,
            'subcategory_id' => $this->assetSubcategory->id,
            'branch_id' => $this->branch->id,
            'entity_id' => $this->entity->id,
            'brand_id' => $this->brand->id,
        ], $overrides);
    }
}
