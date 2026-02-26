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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('slug'); // agricultural, administration, sales
            $table->string('name'); // Gestión Agrícola, Administración
            $table->text('description');
            $table->string('icon')->default('Package'); // Nombre del icono de Lucide React
            $table->string('path'); // /splendidfarms/agricultural
            $table->json('config')->nullable(); // Configuraciones específicas
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['enterprise_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
