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
        Schema::create('variedades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->onDelete('cascade');
            $table->string('nombre'); // Nombre de la variedad (ej: Amarillo, Tommy Atkins)
            $table->string('tipo_variedad')->nullable(); // Subtipo (ej: Overlan, Coppa)
            $table->enum('clasificacion', ['organico', 'convencional'])->nullable(); // Clasificación
            $table->text('descripcion')->nullable(); // Descripción adicional
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('cultivo_id');
            $table->index('clasificacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variedades');
    }
};
