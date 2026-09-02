<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El unique(recipe_id, product_id) original (2026_02_28) nunca se ajustó
     * cuando se agregó group_key (2026_03_25). Eso bloquea a nivel de BD que
     * el mismo producto se use como alternativa en dos grupos intercambiables
     * distintos (ej. una etiqueta genérica en "Caja" y en "PLU"), aunque la
     * aplicación sí lo permite — causaba un error de constraint duplicado al
     * guardar. La unicidad real es por (recipe_id, product_id, group_key).
     */
    public function up(): void
    {
        // MySQL usa el unique(recipe_id, product_id) como índice de respaldo
        // para la FK de recipe_id (no hay otro índice que lo cubra) — hay
        // que darle un índice simple antes de poder soltar ese unique.
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->index('recipe_id');
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropUnique(['recipe_id', 'product_id']);
            $table->unique(['recipe_id', 'product_id', 'group_key'], 'recipe_items_recipe_product_group_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropUnique('recipe_items_recipe_product_group_unique');
            $table->unique(['recipe_id', 'product_id']);
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropIndex(['recipe_id']);
        });
    }
};
