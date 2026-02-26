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
        Schema::create('temporada_lote', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('lote_id')->constrained('lotes')->onDelete('cascade');
            $table->foreignId('cultivo_id')->nullable()->constrained('cultivos')->onDelete('set null')->comment('Cultivo sembrado en este lote durante la temporada');
            $table->decimal('superficie_sembrada', 10, 2)->nullable()->comment('Superficie real sembrada en hectáreas');
            $table->date('fecha_siembra')->nullable();
            $table->date('fecha_cosecha_estimada')->nullable();
            $table->text('notas')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Índices
            $table->unique(['temporada_id', 'lote_id']);
            $table->index('temporada_id');
            $table->index('lote_id');
            $table->index('cultivo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporada_lote');
    }
};
