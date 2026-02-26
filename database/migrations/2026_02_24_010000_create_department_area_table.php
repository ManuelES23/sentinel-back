<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_type', 30)->default('operates_in')
                ->comment('manages: el depto gestiona el área | operates_in: el depto opera en el área | supports: el depto da soporte al área');
            $table->boolean('is_primary')->default(false)
                ->comment('Si es el área principal del departamento');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'area_id']);
            $table->index('relationship_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_area');
    }
};
