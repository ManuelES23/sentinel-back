<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_carga', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->onDelete('cascade');
            $table->string('nombre', 100);
            $table->decimal('peso_estimado_kg', 10, 2);
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['cultivo_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_carga');
    }
};
