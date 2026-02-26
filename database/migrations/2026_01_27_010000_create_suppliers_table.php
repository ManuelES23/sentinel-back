<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabla de Proveedores - Catálogo maestro en administration/organizacion
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('code', 50)->unique();
            $table->string('business_name', 255);           // Razón social
            $table->string('trade_name', 255)->nullable();  // Nombre comercial
            $table->string('tax_id', 50)->nullable();       // RFC/NIT/Tax ID

            // Clasificación
            $table->enum('supplier_type', ['national', 'international'])->default('national');
            $table->string('category', 100)->nullable();    // Categoría del proveedor

            // Condiciones comerciales
            $table->integer('payment_terms')->default(30);           // Días de crédito
            $table->decimal('credit_limit', 15, 2)->nullable();      // Límite de crédito
            $table->string('currency_code', 3)->default('MXN');      // Moneda preferida
            $table->decimal('discount_percent', 5, 2)->default(0);   // Descuento acordado

            // Dirección
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('México');
            $table->string('postal_code', 20)->nullable();

            // Contacto principal
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();

            // Información bancaria
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 50)->nullable();
            $table->string('bank_clabe', 50)->nullable();   // CLABE interbancaria (México)
            $table->string('bank_swift', 20)->nullable();   // Para internacionales

            // Documentos y certificaciones
            $table->string('legal_representative', 255)->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();

            // Control
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('business_name');
            $table->index('supplier_type');
            $table->index('is_active');
        });

        // Tabla de contactos del proveedor
        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            $table->string('name', 255);
            $table->string('position', 100)->nullable();     // Puesto
            $table->string('department', 100)->nullable();   // Departamento
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_primary')->default(false);   // Contacto principal
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_contacts');
        Schema::dropIfExists('suppliers');
    }
};
