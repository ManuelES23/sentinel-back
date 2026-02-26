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
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->onDelete('cascade');
            $table->string('code', 50)->unique()->comment('Código único de área');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('responsible')->nullable()->comment('Responsable del área');
            $table->decimal('area_m2', 10, 2)->nullable()->comment('Área en metros cuadrados');
            $table->boolean('is_active')->default(true);
            $table->boolean('allows_inventory')->default(true)->comment('¿Permite manejo de inventario?');
            $table->json('metadata')->nullable()->comment('Equipos, características, etc.');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'is_active']);
            $table->index(['entity_id', 'allows_inventory']);
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
