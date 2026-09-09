<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_processes')->insertOrIgnore([
            'code' => 'recipe_approval',
            'name' => 'Aprobación de recetas',
            'description' => 'Aprobación de recetas (BOM) antes de pasar a estado activo',
            'module' => 'inventario',
            'entity_type' => 'Recipe',
            'requires_approval' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('approval_processes')->where('code', 'recipe_approval')->delete();
    }
};
