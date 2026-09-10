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

        // Paso 2.5: verificar que el backfill no dejó huérfanos antes de
        // forzar NOT NULL — si Splendid by Porvenir (u otra fuente) generó
        // recetas/items propios sin que el paso 2 los alcanzara, es mejor
        // fallar la migración con un mensaje claro que correr NOT NULL sobre
        // filas que se quedarían sin poder guardarse nunca.
        $missingRecipes = DB::table('recipes')->whereNull('enterprise_id')->count();
        if ($missingRecipes > 0) {
            throw new \RuntimeException("No se pudo asignar enterprise_id a {$missingRecipes} receta(s) — revisar antes de continuar.");
        }

        $missingRecipeItems = DB::table('recipe_items')->whereNull('enterprise_id')->count();
        if ($missingRecipeItems > 0) {
            throw new \RuntimeException("No se pudo asignar enterprise_id a {$missingRecipeItems} recipe_item(s) — revisar antes de continuar.");
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
