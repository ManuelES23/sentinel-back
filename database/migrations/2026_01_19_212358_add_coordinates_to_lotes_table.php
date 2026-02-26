<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega campos para almacenar la ubicación geográfica del lote:
     * - coordenadas: Array de puntos [lat, lng] que forman el polígono del lote
     * - centro_lat/centro_lng: Punto central para ubicar el lote en el mapa
     * - superficie_calculada: Superficie en hectáreas calculada del polígono
     */
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            // Polígono del lote (array de coordenadas [[lat, lng], ...])
            $table->json('coordenadas')->nullable()->after('superficie');
            
            // Centro del lote para marcador en mapa
            $table->decimal('centro_lat', 10, 7)->nullable()->after('coordenadas');
            $table->decimal('centro_lng', 10, 7)->nullable()->after('centro_lat');
            
            // Superficie calculada automáticamente del polígono (en hectáreas)
            $table->decimal('superficie_calculada', 10, 4)->nullable()->after('centro_lng');
            
            // Índice para búsquedas geográficas
            $table->index(['centro_lat', 'centro_lng']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex(['centro_lat', 'centro_lng']);
            $table->dropColumn(['coordenadas', 'centro_lat', 'centro_lng', 'superficie_calculada']);
        });
    }
};
