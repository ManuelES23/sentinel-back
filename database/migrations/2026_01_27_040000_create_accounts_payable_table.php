<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tablas de Cuentas por Pagar - En accounting/cuentas-por-pagar
     * Se generan automáticamente al completar recepciones de mercancía
     */
    public function up(): void
    {
        // Cuentas por Pagar (documentos)
        Schema::create('accounts_payable', function (Blueprint $table) {
            $table->id();
            
            // Identificación
            $table->string('document_number', 50)->unique();
            $table->enum('document_type', [
                'invoice',          // Factura de proveedor
                'credit_note',      // Nota de crédito (reduce deuda)
                'debit_note',       // Nota de débito (aumenta deuda)
                'other'
            ])->default('invoice');
            
            // Proveedor
            $table->foreignId('supplier_id')->constrained('suppliers');
            
            // Documento externo del proveedor
            $table->string('supplier_invoice', 100)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            
            // Origen (puede venir de una recepción)
            $table->foreignId('purchase_receipt_id')->nullable()
                ->constrained('purchase_receipts')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('purchase_orders')->nullOnDelete();
            
            // Fechas
            $table->date('document_date');
            $table->date('due_date');
            $table->integer('payment_terms_days')->default(0);
            
            // Montos
            $table->string('currency_code', 3)->default('MXN');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->decimal('subtotal', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->decimal('balance', 15, 4);  // total_amount - amount_paid
            
            // Estado
            $table->enum('status', [
                'pending',      // Pendiente de pago
                'partial',      // Parcialmente pagado
                'paid',         // Pagado totalmente
                'cancelled',    // Cancelado
                'disputed'      // En disputa
            ])->default('pending');
            
            // Clasificación contable (para integración futura)
            $table->string('accounting_account', 20)->nullable();
            $table->string('cost_center', 20)->nullable();
            
            // Notas
            $table->text('notes')->nullable();
            
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['supplier_id', 'status']);
            $table->index(['due_date', 'status']);
            $table->index(['document_date', 'status']);
            $table->index('status');
        });

        // Pagos a Proveedores
        Schema::create('account_payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->cascadeOnDelete();
            
            // Identificación del pago
            $table->string('payment_number', 50)->unique();
            $table->date('payment_date');
            
            // Monto
            $table->decimal('amount', 15, 4);
            $table->string('currency_code', 3)->default('MXN');
            $table->decimal('exchange_rate', 10, 6)->default(1);
            
            // Método de pago
            $table->enum('payment_method', [
                'cash',             // Efectivo
                'bank_transfer',    // Transferencia bancaria
                'check',            // Cheque
                'credit_card',      // Tarjeta de crédito
                'direct_debit',     // Domiciliación
                'other'
            ])->default('bank_transfer');
            
            // Referencia bancaria
            $table->string('bank_reference', 100)->nullable();
            $table->string('check_number', 50)->nullable();
            $table->string('bank_account', 50)->nullable();  // Cuenta desde donde se pagó
            
            // Estado
            $table->enum('status', [
                'pending',      // Pendiente de procesar
                'processed',    // Procesado
                'cancelled',    // Cancelado
                'bounced'       // Rechazado (cheque sin fondos, etc)
            ])->default('pending');
            
            // Notas
            $table->text('notes')->nullable();
            
            // Auditoría
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['payment_date', 'status']);
            $table->index('status');
        });

        // Tabla de relación N:N para pagos que cubren múltiples facturas
        // (un pago puede aplicarse a varias facturas)
        Schema::create('payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('account_payable_payments')->cascadeOnDelete();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->cascadeOnDelete();
            $table->decimal('amount_applied', 15, 4);
            $table->timestamps();
            
            // Índice único para evitar duplicados
            $table->unique(['payment_id', 'account_payable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_applications');
        Schema::dropIfExists('account_payable_payments');
        Schema::dropIfExists('accounts_payable');
    }
};
