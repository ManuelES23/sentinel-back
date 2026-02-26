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
        Schema::create('temporada_productor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('productor_id')->constrained('productores')->onDelete('cascade');
            $table->text('notas')->nullable()->comment('Notas específicas para esta temporada');
            $table->boolean('is_active')->default(true)->comment('Si está activo en esta temporada');
            $table->timestamps();
            
            // Índices
            $table->unique(['temporada_id', 'productor_id']);
            $table->index('temporada_id');
            $table->index('productor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporada_productor');
    }
};
