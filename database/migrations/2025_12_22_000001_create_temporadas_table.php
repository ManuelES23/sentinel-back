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
        Schema::create('temporadas', function (Blueprint $table) {
            $table->id();
            
            // Relación con cultivo
            $table->foreignId('cultivo_id')->constrained('cultivos')->onDelete('cascade');
            
            // Información de la temporada
            $table->string('nombre'); // Auto-generado: Cultivo + Locación + Año
            $table->string('locacion');
            $table->string('folio_temporada')->unique(); // Cultivo_ID + Consecutivo (ej: 3-001)
            
            // Años extraídos de las fechas
            $table->integer('año_inicio');
            $table->integer('año_fin');
            
            // Fechas
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            
            // Usuario que registró
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['cultivo_id', 'año_inicio']);
            $table->index('folio_temporada');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporadas');
    }
};
