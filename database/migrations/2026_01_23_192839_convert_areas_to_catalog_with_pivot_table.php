<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Crear la tabla pivote entity_area
        Schema::create('entity_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->onDelete('cascade');
            $table->foreignId('area_id')->constrained()->onDelete('cascade');
            $table->string('location')->nullable()->comment('Ubicación específica del área en esta entidad');
            $table->decimal('area_m2', 10, 2)->nullable()->comment('Metros cuadrados en esta entidad específica');
            $table->string('responsible')->nullable()->comment('Responsable del área en esta entidad');
            $table->boolean('is_active')->default(true);
            $table->boolean('allows_inventory')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Índice único para evitar duplicados
            $table->unique(['entity_id', 'area_id']);
        });

        // 2. Migrar datos existentes de areas a la tabla pivote
        $areas = DB::table('areas')->whereNotNull('entity_id')->get();
        foreach ($areas as $area) {
            DB::table('entity_area')->insert([
                'entity_id' => $area->entity_id,
                'area_id' => $area->id,
                'location' => null,
                'area_m2' => $area->area_m2,
                'responsible' => $area->responsible,
                'is_active' => $area->is_active,
                'allows_inventory' => $area->allows_inventory,
                'metadata' => $area->metadata,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Eliminar columnas que ahora están en la tabla pivote de la tabla areas
        Schema::table('areas', function (Blueprint $table) {
            // Eliminar foreign key y columnas
            $table->dropForeign(['entity_id']);
            $table->dropColumn(['entity_id', 'responsible', 'area_m2', 'allows_inventory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Restaurar columnas en areas
        Schema::table('areas', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->string('responsible')->nullable()->after('description');
            $table->decimal('area_m2', 10, 2)->nullable()->after('responsible');
            $table->boolean('allows_inventory')->default(true)->after('is_active');
        });

        // 2. Restaurar datos desde la tabla pivote
        $pivotData = DB::table('entity_area')->get();
        foreach ($pivotData as $pivot) {
            DB::table('areas')
                ->where('id', $pivot->area_id)
                ->update([
                    'entity_id' => $pivot->entity_id,
                    'responsible' => $pivot->responsible,
                    'area_m2' => $pivot->area_m2,
                    'allows_inventory' => $pivot->allows_inventory,
                ]);
        }

        // 3. Eliminar tabla pivote
        Schema::dropIfExists('entity_area');
    }
};
