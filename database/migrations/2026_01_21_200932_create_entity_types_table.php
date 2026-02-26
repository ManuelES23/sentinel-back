<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entity_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Código único del tipo');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable()->comment('Icono lucide-react');
            $table->string('color', 7)->nullable()->comment('Color hex para UI');
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0)->comment('Orden de visualización');
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index(['is_active', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_types');
    }
};
