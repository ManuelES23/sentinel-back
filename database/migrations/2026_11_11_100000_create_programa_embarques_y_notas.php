<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embarques por cargas (una celda = programa · cultivo · bodega · tipo · día)
 * y las notas libres de la semana. Los viajes por caja de tráiler siguen en
 * `programa_viajes`; esta tabla es la captura de cargas de Logística.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programa_embarques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('plan_bodegas_destino')->restrictOnDelete();
            $table->enum('tipo', ['full', 'sencillo']);
            $table->unsignedTinyInteger('dia');
            $table->unsignedSmallInteger('cargas');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programa_id', 'cultivo_id', 'bodega_id', 'tipo', 'dia'], 'programa_embarques_celda_unique');
        });

        Schema::table('programas_semanales', function (Blueprint $table) {
            $table->text('notas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('programas_semanales', function (Blueprint $table) {
            $table->dropColumn('notas');
        });

        Schema::dropIfExists('programa_embarques');
    }
};
