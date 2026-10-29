<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operación Agrícola → Planeación → Programa semanal: catálogos configurables
 * (bodegas de destino, presentaciones, tipos de empaque, grupos de balance,
 * receta por combinación, rendimiento por variedad y temporada, y parámetros).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_bodegas_destino', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->string('nombre');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cultivo_id', 'nombre']);
        });

        Schema::create('plan_tipos_empaque', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_grupos_balance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->string('nombre');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Una variedad pertenece a lo más a un grupo (variedad_id único).
        Schema::create('plan_grupo_variedad', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('plan_grupos_balance')->cascadeOnDelete();
            $table->foreignId('variedad_id')->unique()->constrained('variedades')->cascadeOnDelete();

            $table->primary(['grupo_id', 'variedad_id']);
        });

        // variedad_id nulo = receta para cualquier variedad (en BD los nulos no
        // chocan en el índice único; el controlador evita duplicados).
        Schema::create('plan_recetas_combinacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentacion_id')->constrained('plan_presentaciones')->cascadeOnDelete();
            $table->foreignId('tipo_empaque_id')->constrained('plan_tipos_empaque')->cascadeOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['presentacion_id', 'tipo_empaque_id', 'variedad_id'], 'plan_recetas_combinacion_unica');
        });

        Schema::create('plan_rendimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('variedad_id')->constrained('variedades')->cascadeOnDelete();
            $table->decimal('minimo', 8, 2);
            $table->decimal('maximo', 8, 2);
            $table->timestamps();

            $table->unique(['temporada_id', 'variedad_id']);
        });

        Schema::create('plan_configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_configuracion');
        Schema::dropIfExists('plan_rendimientos');
        Schema::dropIfExists('plan_recetas_combinacion');
        Schema::dropIfExists('plan_grupo_variedad');
        Schema::dropIfExists('plan_grupos_balance');
        Schema::dropIfExists('plan_tipos_empaque');
        Schema::dropIfExists('plan_presentaciones');
        Schema::dropIfExists('plan_bodegas_destino');
    }
};
