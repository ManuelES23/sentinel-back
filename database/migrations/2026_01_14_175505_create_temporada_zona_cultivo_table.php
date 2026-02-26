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
        Schema::create('temporada_zona_cultivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('zona_cultivo_id')->constrained('zonas_cultivo')->onDelete('cascade');
            $table->decimal('superficie_asignada', 10, 2)->nullable()->comment('Superficie asignada en hectáreas para esta temporada');
            $table->text('notas')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Índices
            $table->unique(['temporada_id', 'zona_cultivo_id']);
            $table->index('temporada_id');
            $table->index('zona_cultivo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporada_zona_cultivo');
    }
};
