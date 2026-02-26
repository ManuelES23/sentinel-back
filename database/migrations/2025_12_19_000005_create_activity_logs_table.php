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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            
            // Usuario que realizó la acción
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Descripción de la acción
            $table->string('action'); // create, update, delete, login, logout, etc.
            
            // Modelo/Entidad afectada
            $table->string('model')->nullable(); // Cultivo, CicloAgricola, User, etc.
            $table->unsignedBigInteger('model_id')->nullable(); // ID del registro
            
            // Valores anteriores y nuevos (JSON)
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            // Información de la petición
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            
            // Índices para búsquedas rápidas
            $table->index(['user_id', 'created_at']);
            $table->index(['model', 'model_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
