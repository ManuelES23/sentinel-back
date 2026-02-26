<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('is_active')
                ->comment('Indica si es una instalación externa de terceros');
            $table->string('owner_company')->nullable()->after('is_external')
                ->comment('Nombre de la empresa dueña de la instalación externa');
            $table->string('contact_person')->nullable()->after('owner_company')
                ->comment('Persona de contacto en la instalación externa');
            $table->string('contact_phone')->nullable()->after('contact_person')
                ->comment('Teléfono de contacto');
            $table->string('contact_email')->nullable()->after('contact_phone')
                ->comment('Email de contacto');
            $table->string('contract_number')->nullable()->after('contact_email')
                ->comment('Número de contrato o convenio');
            $table->date('contract_start_date')->nullable()->after('contract_number')
                ->comment('Fecha de inicio del contrato');
            $table->date('contract_end_date')->nullable()->after('contract_start_date')
                ->comment('Fecha de finalización del contrato');
            $table->text('contract_notes')->nullable()->after('contract_end_date')
                ->comment('Notas adicionales sobre el contrato o acuerdo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn([
                'is_external',
                'owner_company',
                'contact_person',
                'contact_phone',
                'contact_email',
                'contract_number',
                'contract_start_date',
                'contract_end_date',
                'contract_notes',
            ]);
        });
    }
};
