<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marcas, líneas y sublíneas del catálogo por líneas (spec 2026-11-10). Re-ejecutable. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['crm_marcas', 'crm_lineas'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) use ($tabla) {
                    $table->id();
                    $table->foreignId('empresa_id')->constrained('enterprises')->cascadeOnDelete();
                    $table->string('nombre', 150);
                    $table->string('nombre_clave', 150);
                    $table->boolean('activo')->default(true);
                    $table->timestamps();
                    $table->unique(['empresa_id', 'nombre_clave'], $tabla.'_empresa_nombre_clave_unique');
                });
            }
        }

        if (! Schema::hasTable('crm_sublineas')) {
            Schema::create('crm_sublineas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('linea_id')->constrained('crm_lineas')->cascadeOnDelete();
                $table->string('nombre', 150);
                $table->string('nombre_clave', 150);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->unique(['linea_id', 'nombre_clave'], 'crm_sublineas_linea_nombre_clave_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sublineas');
        Schema::dropIfExists('crm_lineas');
        Schema::dropIfExists('crm_marcas');
    }
};
