<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla de detalles/entradas de un pallet (para colas con múltiples adiciones)
        Schema::create('produccion_empaque_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id')->constrained('produccion_empaque')->cascadeOnDelete();
            $table->unsignedInteger('numero_entrada')->default(1);
            $table->foreignId('proceso_id')->nullable()->constrained('proceso_empaque')->nullOnDelete();
            $table->date('fecha_produccion');
            $table->integer('total_cajas');
            $table->decimal('peso_neto_kg', 12, 2)->nullable();
            $table->string('turno', 50)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('produccion_id');
            $table->unique(['produccion_id', 'numero_entrada'], 'prod_det_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_empaque_detalles');

        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn('cajas_objetivo');
        });
    }
};
