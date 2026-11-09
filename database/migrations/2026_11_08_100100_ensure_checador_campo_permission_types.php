<?php

use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use Illuminate\Database\Migrations\Migration;

/**
 * El submódulo Administración → Personal → «Checador de campo» existía sin
 * tipos de permiso (view/create/edit/delete), así que no se podía conceder
 * «edit» desde el panel. Esta migración crea los que falten en cada empresa,
 * con los mismos textos que MasterStructureSeeder::ensureSubmodulePermissionTypes.
 * No toca concesiones ni tipos existentes. Es idempotente.
 */
return new class extends Migration
{
    private const TIPOS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
        ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
        ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
        ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
    ];

    public function up(): void
    {
        $submodulos = Submodule::where('slug', 'checador-campo')
            ->whereHas('module', fn ($m) => $m->where('slug', 'personal')
                ->whereHas('application', fn ($a) => $a->where('slug', 'administration')))
            ->get();

        foreach ($submodulos as $submodulo) {
            $orden = (int) (SubmodulePermissionType::where('submodule_id', $submodulo->id)->max('order') ?? 0);

            foreach (self::TIPOS as $tipo) {
                $existe = SubmodulePermissionType::where('submodule_id', $submodulo->id)
                    ->where('slug', $tipo['slug'])
                    ->exists();

                if ($existe) {
                    continue;
                }

                $orden++;

                SubmodulePermissionType::create([
                    'submodule_id' => $submodulo->id,
                    'slug' => $tipo['slug'],
                    'name' => $tipo['name'],
                    'description' => $tipo['description'],
                    'order' => $orden,
                    'is_active' => true,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Sin reversa: los tipos de permiso pueden tener concesiones asociadas.
    }
};
