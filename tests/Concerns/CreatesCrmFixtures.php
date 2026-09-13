<?php

namespace Tests\Concerns;

use App\Models\CRM\CrmBodega;
use App\Models\CRM\CrmRegion;
use App\Models\CRM\CrmVendedor;
use App\Models\CRM\CrmZona;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;

/**
 * Fixtures mínimos para probar el módulo CRM. A diferencia de Activos Fijos
 * (que resuelve la empresa por la URL), el CRM resuelve el contexto de
 * empresa vía el header X-Enterprise-Id (ver FiltraPorEmpresa::getEmpresaId).
 * Por eso los tests deben enviar ese header explícitamente en cada request.
 */
trait CreatesCrmFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected CrmVendedor $vendedor;
    protected CrmRegion $region;
    protected CrmZona $zona;
    protected CrmBodega $bodega;

    protected function setUpCrmFixtures(): void
    {
        $this->actingUser = User::factory()->create();

        $this->enterprise = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms-crm',
            'description' => 'Empresa de prueba para CRM',
            'is_active' => true,
        ]);

        // getEmpresaId() valida que el usuario tenga acceso activo a la
        // empresa del header X-Enterprise-Id antes de confiar en él.
        UserEnterpriseAccess::create([
            'user_id' => $this->actingUser->id,
            'enterprise_id' => $this->enterprise->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        $vendedorUser = User::factory()->create();

        $this->vendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $vendedorUser->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan.perez@example.com',
            'activo' => true,
        ]);

        $this->region = CrmRegion::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Noroeste',
        ]);

        $this->zona = CrmZona::create([
            'empresa_id' => $this->enterprise->id,
            'region_id' => $this->region->id,
            'nombre' => 'Sinaloa',
        ]);

        $this->bodega = CrmBodega::create([
            'empresa_id' => $this->enterprise->id,
            'zona_id' => $this->zona->id,
            'nombre' => 'Bodega Los Mochis',
        ]);
    }

    /**
     * Header de contexto de empresa que todos los endpoints CRM requieren.
     */
    protected function crmHeaders(?int $empresaId = null): array
    {
        return ['X-Enterprise-Id' => $empresaId ?? $this->enterprise->id];
    }

    /**
     * Crea una segunda empresa con su propio vendedor, para probar
     * aislamiento multi-tenant.
     */
    protected function crearOtraEmpresa(): Enterprise
    {
        return Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa-crm-'.uniqid(),
            'description' => 'Segunda empresa de prueba (aislamiento)',
            'is_active' => true,
        ]);
    }

    /**
     * Crea la estructura de permisos del CRM (CrmPermisosSeeder) y otorga a
     * $this->actingUser TODOS los permisos de submódulo del CRM de la empresa
     * dada (por defecto $this->enterprise). Para tests cuyo foco no es la
     * autorización: los controladores exigen permisos en las escrituras.
     */
    protected function otorgarTodosLosPermisosCrm(?Enterprise $empresa = null): void
    {
        $empresa ??= $this->enterprise;

        (new \Database\Seeders\CrmPermisosSeeder())->run();

        $tipos = \App\Models\SubmodulePermissionType::whereHas('submodule.module.application', function ($q) use ($empresa) {
            $q->where('enterprise_id', $empresa->id)->where('slug', 'crm');
        })->get();

        foreach ($tipos as $tipo) {
            \App\Models\UserSubmodulePermission::firstOrCreate(
                [
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $tipo->submodule_id,
                    'permission_type_id' => $tipo->id,
                ],
                ['is_granted' => true],
            );
        }
    }

    /**
     * Otorga a $this->actingUser solo los permisos indicados de un submódulo
     * del CRM de $this->enterprise (crea la estructura si no existe).
     */
    protected function otorgarPermisosCrm(string $modulo, string $submodulo, array $permisos): void
    {
        (new \Database\Seeders\CrmPermisosSeeder())->run();

        $tipos = \App\Models\SubmodulePermissionType::whereIn('slug', $permisos)
            ->whereHas('submodule', function ($q) use ($modulo, $submodulo) {
                $q->where('slug', $submodulo)->whereHas('module', function ($mq) use ($modulo) {
                    $mq->where('slug', $modulo)->whereHas('application', function ($aq) {
                        $aq->where('enterprise_id', $this->enterprise->id)->where('slug', 'crm');
                    });
                });
            })->get();

        foreach ($tipos as $tipo) {
            \App\Models\UserSubmodulePermission::firstOrCreate(
                [
                    'user_id' => $this->actingUser->id,
                    'submodule_id' => $tipo->submodule_id,
                    'permission_type_id' => $tipo->id,
                ],
                ['is_granted' => true],
            );
        }
    }

    /**
     * Otorga a $this->actingUser acceso activo a una empresa adicional,
     * para probar el camino "con acceso" de getEmpresaId().
     */
    protected function otorgarAccesoA(Enterprise $empresa): void
    {
        UserEnterpriseAccess::create([
            'user_id' => $this->actingUser->id,
            'enterprise_id' => $empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
    }
}
