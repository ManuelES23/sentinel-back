<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $submoduleId = DB::table('submodules')
            ->where('slug', 'lavado')
            ->value('id');

        if (!$submoduleId) {
            return;
        }

        $exists = DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'reiniciar_recorrido')
            ->exists();

        if ($exists) {
            return;
        }

        $maxOrder = (int) (DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->max('order') ?? 0);

        DB::table('submodule_permission_types')->insert([
            'submodule_id' => $submoduleId,
            'slug' => 'reiniciar_recorrido',
            'name' => 'Reiniciar recorrido',
            'description' => 'Permite reiniciar el folio y regresarlo a pendiente de lavar',
            'order' => $maxOrder + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $submoduleId = DB::table('submodules')
            ->where('slug', 'lavado')
            ->value('id');

        if (!$submoduleId) {
            return;
        }

        DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->where('slug', 'reiniciar_recorrido')
            ->delete();
    }
};
