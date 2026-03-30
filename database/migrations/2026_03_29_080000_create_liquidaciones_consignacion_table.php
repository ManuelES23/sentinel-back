<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_consignacion', function (Blueprint $table) {
            $table->id();
            $table->string('folio_liquidacion', 20)->unique();

            $table->foreignId('convenio_compra_id')->constrained('convenios_compra')->restrictOnDelete();
            $table->foreignId('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignId('productor_id')->constrained('productores')->restrictOnDelete();

            $table->date('periodo_inicio');
            $table->date('periodo_fin');

            // Totales de las salidas en el período
            $table->integer('total_salidas')->default(0);
            $table->decimal('total_kilos', 12, 2)->default(0);
            $table->integer('total_cantidad')->default(0)->comment('Cantidad total (unidades de carga)');

            // Datos del precio utilizado
            $table->decimal('precio_unitario_utilizado', 12, 4)->default(0);
            $table->decimal('porcentaje_productor', 5, 2)->nullable()->comment('Para consignación');

            // Montos
            $table->decimal('monto_bruto', 14, 2)->default(0)->comment('total_cantidad × precio_unitario');
            $table->decimal('monto_productor_calculado', 14, 2)->default(0)->comment('monto_bruto × porcentaje / 100');
            $table->decimal('monto_ajustado', 14, 2)->nullable()->comment('Monto manual (override)');
            $table->decimal('monto_final', 14, 2)->default(0)->comment('ajustado ?? productor_calculado');
            $table->text('motivo_ajuste')->nullable();

            $table->text('notas')->nullable();
            $table->enum('status', ['borrador', 'revisada', 'aprobada', 'pagada', 'cancelada'])->default('borrador');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_consignacion');
    }
};
