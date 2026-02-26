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
        Schema::create('tipos_variedad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variedad_id')->constrained('variedades')->onDelete('cascade');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('variedad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_variedad');
    }
};
