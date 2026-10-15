<?php

use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Services\RH\AprobadorVacaciones;
use Illuminate\Database\Migrations\Migration;

/**
 * Submódulo RH → Gestión → Festivos, solo en la empresa corporativa (la única que usa RH).
 * Quien ya administra Vacaciones (acceso activo y permiso `edit` concedido) recibe acceso y
 * los cuatro permisos de Festivos; quien solo consulta Vacaciones no recibe nada.
 * Es re-ejecutable: todo va con firstOrCreate.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'view' => ['Ver', 1],
        'create' => ['Crear', 2],
        'edit' => ['Editar', 3],
        'delete' => ['Eliminar', 4],
    ];

    public function up(): void
    {
        $modulo = $this->modulo();
        if (! $modulo) {
            return;
        }

        $festivos = Submodule::firstOrCreate(
            ['module_id' => $modulo->id, 'slug' => 'festivos'],
            ['name' => 'Festivos', 'icon' => 'CalendarX', 'order' => 3, 'is_active' => true],
        );

        $tipos = collect(self::PERMISOS)->map(fn ($datos, $slug) => SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $festivos->id, 'slug' => $slug],
            ['name' => $datos[0], 'order' => $datos[1], 'is_active' => true],
        ));

        $vacaciones = Submodule::where('module_id', $modulo->id)->where('slug', 'vacaciones')->first();
        $tipoEditar = $vacaciones
            ? SubmodulePermissionType::where('submodule_id', $vacaciones->id)->where('slug', 'edit')->first()
            : null;
        if (! $tipoEditar) {
            return;
        }

        $administradores = UserSubmoduleAccess::where('submodule_id', $vacaciones->id)->where('is_active', true)->pluck('user_id')
            ->filter(fn ($usuarioId) => UserSubmodulePermission::where('user_id', $usuarioId)
                ->where('submodule_id', $vacaciones->id)
                ->where('permission_type_id', $tipoEditar->id)
                ->where('is_granted', true)
                ->exists());

        foreach ($administradores as $usuarioId) {
            UserSubmoduleAccess::firstOrCreate(
                ['user_id' => $usuarioId, 'submodule_id' => $festivos->id],
                ['is_active' => true, 'granted_at' => now()],
            );

            foreach ($tipos as $tipo) {
                UserSubmodulePermission::firstOrCreate(
                    ['user_id' => $usuarioId, 'submodule_id' => $festivos->id, 'permission_type_id' => $tipo->id],
                    ['is_granted' => true],
                );
            }
        }
    }

    public function down(): void
    {
        $modulo = $this->modulo();
        $festivos = $modulo ? Submodule::where('module_id', $modulo->id)->where('slug', 'festivos')->first() : null;
        if (! $festivos) {
            return;
        }

        UserSubmodulePermission::where('submodule_id', $festivos->id)->delete();
        UserSubmoduleAccess::where('submodule_id', $festivos->id)->delete();
        SubmodulePermissionType::where('submodule_id', $festivos->id)->delete();
        $festivos->delete();
    }

    private function modulo(): ?Module
    {
        return Module::where('slug', 'gestion')
            ->whereHas('application', fn ($a) => $a->where('slug', 'rh')
                ->whereHas('enterprise', fn ($e) => $e->where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)))
            ->first();
    }
};
