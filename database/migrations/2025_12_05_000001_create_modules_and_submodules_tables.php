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
        // Tabla de módulos (pertenecen a una aplicación)
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->string('slug'); // ventas, inventario, reportes
            $table->string('name'); // Ventas, Inventario, Reportes
            $table->text('description')->nullable();
            $table->string('icon')->default('Package'); // Icono de Lucide
            $table->string('path')->nullable(); // /modulo
            $table->integer('order')->default(0); // Orden de visualización
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['application_id', 'slug']);
        });

        // Tabla de submódulos (pertenecen a un módulo)
        Schema::create('submodules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('slug'); // crear-venta, listar-ventas
            $table->string('name'); // Crear Venta, Listar Ventas
            $table->text('description')->nullable();
            $table->string('icon')->default('File'); // Icono de Lucide
            $table->string('path')->nullable(); // /crear, /listar
            $table->integer('order')->default(0); // Orden de visualización
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['module_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submodules');
        Schema::dropIfExists('modules');
    }
};
