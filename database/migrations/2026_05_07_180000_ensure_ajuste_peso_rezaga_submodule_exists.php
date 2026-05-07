<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $enterpriseId = DB::table('enterprises')
            ->where('slug', 'splendidfarms')
            ->value('id');

        if (! $enterpriseId) {
            return;
        }

        $applicationId = DB::table('applications')
            ->where('enterprise_id', $enterpriseId)
            ->where('slug', 'operacion-agricola')
            ->value('id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'empaque')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $existingId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'ajuste-peso-rezaga')
            ->value('id');

        if ($existingId) {
            DB::table('submodules')
                ->where('id', $existingId)
                ->update([
                    'name' => 'Ajuste de Peso Rezaga',
                    'icon' => 'TrendingDown',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            return;
        }

        $nextOrder = ((int) DB::table('submodules')
            ->where('module_id', $moduleId)
            ->max('order')) + 1;

        DB::table('submodules')->insert([
            'module_id' => $moduleId,
            'slug' => 'ajuste-peso-rezaga',
            'name' => 'Ajuste de Peso Rezaga',
            'description' => 'Control de perdida de peso en rezaga por deshidratacion o putrefaccion',
            'icon' => 'TrendingDown',
            'path' => null,
            'order' => $nextOrder,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $enterpriseId = DB::table('enterprises')
            ->where('slug', 'splendidfarms')
            ->value('id');

        if (! $enterpriseId) {
            return;
        }

        $applicationId = DB::table('applications')
            ->where('enterprise_id', $enterpriseId)
            ->where('slug', 'operacion-agricola')
            ->value('id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'empaque')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'ajuste-peso-rezaga')
            ->delete();
    }
};
