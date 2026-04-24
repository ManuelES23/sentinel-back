<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonos_productores', function (Blueprint $table) {
            $table->id();
            $table->string('folio_abono', 30)->unique();

            $table->foreignId('productor_id')->constrained('productores')->cascadeOnDelete();
            $table->foreignId('temporada_id')->nullable()
                ->constrained('temporadas')->nullOnDelete();
            // Convenio opcional: si el abono es específico a un convenio
            $table->foreignId('convenio_compra_id')->nullable()
                ->constrained('convenios_compra')->nullOnDelete();

            $table->date('fecha');
            $table->decimal('monto', 14, 2);
            $table->enum('metodo_pago', [
                'efectivo',
                'transferencia',
                'cheque',
                'deposito',
                'otro',
            ])->default('transferencia');
            $table->string('referencia', 100)->nullable()
                ->comment('Folio, número de cheque, referencia bancaria, etc.');
            $table->text('notas')->nullable();

            $table->enum('status', ['activo', 'cancelado'])->default('activo');
            $table->text('motivo_cancelacion')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('productor_id');
            $table->index('temporada_id');
            $table->index('convenio_compra_id');
            $table->index('fecha');
            $table->index(['productor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonos_productores');
    }
};
