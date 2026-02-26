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
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('entity_type_id')->constrained('entity_types')->onDelete('restrict');
            $table->string('code', 50)->unique()->comment('Código único de entidad');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable()->comment('Ubicación dentro de la sucursal');
            $table->string('responsible')->nullable()->comment('Responsable de la entidad');
            $table->decimal('area_m2', 10, 2)->nullable()->comment('Área en metros cuadrados');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable()->comment('Capacidad, equipamiento, etc.');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'entity_type_id']);
            $table->index(['branch_id', 'is_active']);
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
