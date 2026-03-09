<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('visita_campo_id')->nullable()->constrained('visitas_campo')->nullOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();

            // Imagen
            $table->string('imagen_path', 500);
            $table->string('imagen_url', 500)->nullable();

            // Contexto agrícola enviado al AI
            $table->json('contexto_agricola')->nullable();

            // Respuesta del AI
            $table->text('diagnostico')->nullable();
            $table->json('plagas_detectadas')->nullable();
            $table->json('enfermedades_detectadas')->nullable();
            $table->string('estado_fenologico', 200)->nullable();
            $table->json('recomendaciones')->nullable();
            $table->enum('nivel_urgencia', ['bajo', 'medio', 'alto', 'critico'])->nullable();
            $table->decimal('confianza', 5, 2)->nullable(); // 0-100%

            // Metadata
            $table->string('modelo_ia', 100)->default('gpt-4o');
            $table->integer('tokens_usados')->nullable();
            $table->enum('status', ['procesando', 'completado', 'error'])->default('procesando');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['temporada_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos_ia');
    }
};
