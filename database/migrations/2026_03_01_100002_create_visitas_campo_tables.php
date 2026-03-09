<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Cabecera de la visita ──
        Schema::create('visitas_campo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->date('fecha_visita');
            $table->text('observaciones_generales')->nullable();
            $table->enum('status', ['borrador', 'completada'])->default('borrador');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'fecha_visita']);
        });

        // ── Detalle por etapa visitada ──
        Schema::create('visita_campo_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_campo_id')->constrained('visitas_campo')->cascadeOnDelete();
            $table->foreignId('etapa_id')->constrained('etapas')->cascadeOnDelete();
            $table->foreignId('etapa_fenologica_id')->nullable()->constrained('etapas_fenologicas')->nullOnDelete();
            $table->integer('poblacion_plantas_ha')->nullable();
            $table->date('fecha_siembra_real')->nullable();
            $table->date('fecha_cosecha_proyectada')->nullable();
            $table->text('observaciones')->nullable();
            $table->text('recomendaciones_generales')->nullable();
            $table->timestamps();
        });

        // ── Plagas encontradas por detalle ──
        Schema::create('visita_campo_plagas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_campo_detalle_id')->constrained('visita_campo_detalles')->cascadeOnDelete();
            $table->foreignId('plaga_id')->constrained('plagas')->cascadeOnDelete();
            $table->enum('severidad', ['baja', 'media', 'alta', 'critica'])->default('baja');
            $table->decimal('area_afectada_porcentaje', 5, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        // ── Recomendaciones de aplicación por detalle ──
        Schema::create('visita_campo_recomendaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_campo_detalle_id')->constrained('visita_campo_detalles')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('nombre_producto'); // snapshot o producto libre
            $table->decimal('dosis', 10, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->string('metodo_aplicacion', 100)->nullable();
            $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        // ── Fotos por detalle ──
        Schema::create('visita_campo_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_campo_detalle_id')->constrained('visita_campo_detalles')->cascadeOnDelete();
            $table->string('foto_path');
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visita_campo_fotos');
        Schema::dropIfExists('visita_campo_recomendaciones');
        Schema::dropIfExists('visita_campo_plagas');
        Schema::dropIfExists('visita_campo_detalles');
        Schema::dropIfExists('visitas_campo');
    }
};
