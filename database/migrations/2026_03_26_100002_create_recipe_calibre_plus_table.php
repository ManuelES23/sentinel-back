<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_calibre_plus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_calibre_id')->constrained('recipe_calibres')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('is_organic')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['recipe_calibre_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_calibre_plus');
    }
};
