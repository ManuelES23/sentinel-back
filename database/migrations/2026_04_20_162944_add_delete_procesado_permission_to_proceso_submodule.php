<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $submodule = DB::table('submodules')->where('slug', 'proceso')->first();
        if (!$submodule) return;

        $exists = DB::table('submodule_permission_types')
            ->where('submodule_id', $submodule->id)
            ->where('slug', 'delete_procesado')
            ->exists();

        if ($exists) return;

        $maxOrder = DB::table('submodule_permission_types')
            ->where('submodule_id', $submodule->id)
            ->max('order') ?? 0;

        DB::table('submodule_permission_types')->insert([
            'submodule_id' => $submodule->id,
            'slug' => 'delete_procesado',
            'name' => 'Eliminar Consumidos',
            'description' => 'Permite eliminar folios de proceso ya consumidos (status procesado)',
            'icon' => 'Trash2',
            'color' => 'red',
            'order' => $maxOrder + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $submodule = DB::table('submodules')->where('slug', 'proceso')->first();
        if (!$submodule) return;

        DB::table('submodule_permission_types')
            ->where('submodule_id', $submodule->id)
            ->where('slug', 'delete_procesado')
            ->delete();
    }
};
