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
        Schema::create('ciclos_agricolas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->year('año');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->enum('estado', ['planificado', 'activo', 'finalizado', 'cancelado'])->default('planificado');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para mejorar búsquedas
            $table->index('año');
            $table->index('estado');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ciclos_agricolas');
    }
};
