<?php

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * En Splendid Farms, Compras deja de estar repartido entre Inventario y
 * Operación Agrícola y pasa a ser su propio módulo de Administración.
 *
 * Mueve las filas existentes en vez de recrearlas (los permisos cuelgan del
 * submodule_id) y además arrastra el acceso al menú, que vive en tablas
 * aparte y NO viaja con el submódulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $administration = $this->aplicacion('administration');
        if (! $administration) {
            return; // base sin la estructura de Splendid Farms (p. ej. pruebas en limpio)
        }
        $inventario = $this->aplicacion('inventario');
        $operacion = $this->aplicacion('operacion-agricola');

        // 1. Compras Agrícolas → Abastecimiento
        Module::where('application_id', $administration->id)
            ->where('slug', 'compras-agricolas')
            ->update(['slug' => 'abastecimiento', 'name' => 'Abastecimiento']);

        // 2. Módulo Compras
        $compras = Module::firstOrCreate(
            ['application_id' => $administration->id, 'slug' => 'compras'],
            [
                'name' => 'Compras',
                'path' => '/compras',
                'icon' => 'ShoppingCart',
                'order' => ((int) Module::where('application_id', $administration->id)->max('order')) + 1,
                'is_active' => true,
            ],
        );

        // 3. Submódulo Requisiciones con sus tipos de permiso
        $requisiciones = Submodule::firstOrCreate(
            ['module_id' => $compras->id, 'slug' => 'requisiciones'],
            ['name' => 'Requisiciones', 'path' => '/requisiciones', 'icon' => 'ClipboardList', 'order' => 1, 'is_active' => true],
        );
        $tipoView = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $requisiciones->id, 'slug' => 'view'],
            ['name' => 'Ver', 'order' => 1, 'is_active' => true],
        );
        $tipoCotizar = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $requisiciones->id, 'slug' => 'cotizar'],
            [
                'name' => 'Cotizar',
                'description' => 'Recibe requisiciones, registra cotizaciones y genera la orden de compra',
                'order' => 80,
                'is_active' => true,
            ],
        );
        // `color` no está en $fillable del modelo.
        DB::table('submodule_permission_types')->where('id', $tipoCotizar->id)->update(['color' => 'purple']);

        // 4. Re-parentar órdenes de compra y recepciones
        $comprasInventario = $inventario
            ? Module::where('application_id', $inventario->id)->where('slug', 'compras')->first()
            : null;

        if ($comprasInventario) {
            Submodule::where('module_id', $comprasInventario->id)->where('slug', 'ordenes-compra')
                ->update(['module_id' => $compras->id, 'order' => 2]);
            Submodule::where('module_id', $comprasInventario->id)->where('slug', 'recepciones')
                ->update(['module_id' => $compras->id, 'order' => 3]);
        }

        // 5. Migrar el permiso `cotizar` desde Operación Agrícola
        $usuariosCotizan = collect();
        $submoduloViejo = $operacion ? $this->submodulo($operacion, 'agricola', 'requisiciones') : null;
        $tipoViejo = $submoduloViejo
            ? SubmodulePermissionType::where('submodule_id', $submoduloViejo->id)->where('slug', 'cotizar')->first()
            : null;

        if ($tipoViejo) {
            $usuariosCotizan = UserSubmodulePermission::where('permission_type_id', $tipoViejo->id)
                ->where('is_granted', true)->pluck('user_id')->unique();

            foreach ($usuariosCotizan as $userId) {
                foreach ([$tipoCotizar, $tipoView] as $tipo) {
                    UserSubmodulePermission::firstOrCreate(
                        ['user_id' => $userId, 'submodule_id' => $requisiciones->id, 'permission_type_id' => $tipo->id],
                        ['is_granted' => true],
                    );
                }
            }

            UserSubmodulePermission::where('permission_type_id', $tipoViejo->id)->delete();
            $tipoViejo->delete();
        }

        // 6. Preservar el acceso al menú. firstOrCreate a propósito: si alguien
        // tiene un acceso desactivado a mano, no se resucita aquí.
        $usuarios = $usuariosCotizan->merge(
            $comprasInventario
                ? UserModuleAccess::where('module_id', $comprasInventario->id)->where('is_active', true)->pluck('user_id')
                : collect(),
        )->unique();

        foreach ($usuarios as $userId) {
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $userId, 'application_id' => $administration->id],
                ['is_active' => true, 'granted_at' => now()],
            );
            UserModuleAccess::firstOrCreate(
                ['user_id' => $userId, 'module_id' => $compras->id],
                ['is_active' => true, 'granted_at' => now()],
            );
            UserSubmoduleAccess::firstOrCreate(
                ['user_id' => $userId, 'submodule_id' => $requisiciones->id],
                ['is_active' => true, 'granted_at' => now()],
            );
        }

        // 7. El módulo viejo queda vacío: se desactiva, no se borra.
        $comprasInventario?->update(['is_active' => false]);
    }

    public function down(): void
    {
        $administration = $this->aplicacion('administration');
        if (! $administration) {
            return;
        }
        $inventario = $this->aplicacion('inventario');
        $operacion = $this->aplicacion('operacion-agricola');

        $compras = Module::where('application_id', $administration->id)->where('slug', 'compras')->first();
        $comprasInventario = $inventario
            ? Module::where('application_id', $inventario->id)->where('slug', 'compras')->first()
            : null;

        if ($compras && $comprasInventario) {
            $comprasInventario->update(['is_active' => true]);
            Submodule::where('module_id', $compras->id)->whereIn('slug', ['ordenes-compra', 'recepciones'])
                ->update(['module_id' => $comprasInventario->id]);
        }

        $requisiciones = $compras
            ? Submodule::where('module_id', $compras->id)->where('slug', 'requisiciones')->first()
            : null;

        if ($requisiciones) {
            $submoduloViejo = $operacion ? $this->submodulo($operacion, 'agricola', 'requisiciones') : null;

            if ($submoduloViejo) {
                $tipoViejo = SubmodulePermissionType::firstOrCreate(
                    ['submodule_id' => $submoduloViejo->id, 'slug' => 'cotizar'],
                    ['name' => 'Cotizar', 'order' => 80, 'is_active' => true],
                );
                $tipoCotizar = SubmodulePermissionType::where('submodule_id', $requisiciones->id)->where('slug', 'cotizar')->first();

                if ($tipoCotizar) {
                    foreach (UserSubmodulePermission::where('permission_type_id', $tipoCotizar->id)->where('is_granted', true)->pluck('user_id')->unique() as $userId) {
                        UserSubmodulePermission::firstOrCreate(
                            ['user_id' => $userId, 'submodule_id' => $submoduloViejo->id, 'permission_type_id' => $tipoViejo->id],
                            ['is_granted' => true],
                        );
                    }
                }
            }

            UserSubmodulePermission::where('submodule_id', $requisiciones->id)->delete();
            UserSubmoduleAccess::where('submodule_id', $requisiciones->id)->delete();
            SubmodulePermissionType::where('submodule_id', $requisiciones->id)->delete();
            $requisiciones->delete();
        }

        if ($compras) {
            UserModuleAccess::where('module_id', $compras->id)->delete();
            $compras->delete();
        }

        Module::where('application_id', $administration->id)
            ->where('slug', 'abastecimiento')
            ->update(['slug' => 'compras-agricolas', 'name' => 'Compras Agrícolas']);
    }

    private function aplicacion(string $slug): ?Application
    {
        $empresaId = DB::table('enterprises')->where('slug', 'splendidfarms')->value('id');

        return $empresaId
            ? Application::where('enterprise_id', $empresaId)->where('slug', $slug)->first()
            : null;
    }

    private function submodulo(Application $app, string $modulo, string $sub): ?Submodule
    {
        $moduloId = Module::where('application_id', $app->id)->where('slug', $modulo)->value('id');

        return $moduloId ? Submodule::where('module_id', $moduloId)->where('slug', $sub)->first() : null;
    }
};
