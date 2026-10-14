<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            // Sin cascada: los activos usan borrado lógico y el historial de salidas se conserva.
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();

            $table->enum('type', ['venta', 'desecho', 'siniestro', 'donacion', 'traspaso']);
            $table->date('disposal_date')->comment('Fecha de la salida; el activo se deprecia hasta este mes inclusive');
            $table->text('reason')->nullable();
            $table->string('authorized_by_name')->comment('Quién autorizó (texto libre)');
            $table->string('previous_status', 30)->comment('Estado del activo antes de la salida; se restaura al deshacer');
            $table->json('asset_snapshot')->comment('Copia del activo al registrar la salida; la carta se imprime de aquí');

            // Cifras congeladas a la fecha de la salida
            $table->decimal('purchase_value', 15, 2)->nullable();
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->decimal('book_value', 15, 2)->default(0);
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->decimal('result_amount', 15, 2)->default(0)->comment('Utilidad (+) o pérdida (-)');

            // Venta
            $table->string('buyer_name')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('buyer_identification')->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->string('place')->nullable()->comment('Lugar que lleva la carta de venta');

            // Siniestro
            $table->string('claim_reference')->nullable();

            // Traspaso
            $table->foreignId('destination_enterprise_id')->nullable()->constrained('enterprises')->restrictOnDelete();
            $table->foreignId('destination_asset_id')->nullable()->constrained('fixed_assets')->restrictOnDelete();

            // Carta firmada escaneada (disco privado)
            $table->string('signed_document_path')->nullable();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Copia del nombre: los usuarios se borran de verdad y el documento debe reimprimirse igual.
            $table->string('created_by_name');

            // Reversa (reversed_at nulo = salida vigente)
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->index(['fixed_asset_id', 'reversed_at']);
            $table->index(['enterprise_id', 'disposal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
