<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programa de empaque (Planeación): renglones de sobrante de la semana, cajas
 * por día de lunes (cajas_0) a domingo (cajas_6) que se empacan para una bodega
 * con una o más variedades del mismo grupo de balance. Misma forma que las
 * necesidades de venta, sin cliente y con el tipo de empaque obligatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programa_sobrantes')) {
            Schema::create('programa_sobrantes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
                $table->foreignId('bodega_id')->constrained('plan_bodegas_destino')->restrictOnDelete();
                $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
                $table->foreignId('presentacion_id')->constrained('plan_presentaciones')->restrictOnDelete();
                $table->foreignId('tipo_empaque_id')->constrained('plan_tipos_empaque')->restrictOnDelete();
                for ($dia = 0; $dia <= 6; $dia++) {
                    $table->unsignedInteger("cajas_{$dia}")->default(0);
                }
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('programa_id');
            });
        }

        if (! Schema::hasTable('programa_sobrante_variedad')) {
            Schema::create('programa_sobrante_variedad', function (Blueprint $table) {
                $table->foreignId('sobrante_id')->constrained('programa_sobrantes')->cascadeOnDelete();
                $table->foreignId('variedad_id')->constrained('variedades')->cascadeOnDelete();

                $table->primary(['sobrante_id', 'variedad_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('programa_sobrante_variedad');
        Schema::dropIfExists('programa_sobrantes');
    }
};
