<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paso 1: columna nullable
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Paso 2: backfill a Splendid Farms (única empresa real con recetas hoy)
        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            DB::table('recipes')->whereNull('enterprise_id')->update(['enterprise_id' => $splendidFarms->id]);

            DB::statement('
                UPDATE recipe_items
                SET enterprise_id = (
                    SELECT recipes.enterprise_id FROM recipes WHERE recipes.id = recipe_items.recipe_id
                )
                WHERE enterprise_id IS NULL
            ');
        }

        // Paso 3: NOT NULL
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
