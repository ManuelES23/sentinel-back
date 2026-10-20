<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El jefe de un departamento sin usuario de Sentinel decide su paso desde el portal de
 * vacaciones con su número y PIN. decided_by solo guarda usuarios, así que la decisión
 * queda también ligada al empleado que la tomó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_request_approvals', function (Blueprint $table) {
            $table->foreignId('decided_by_employee_id')->nullable()->after('decided_by')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vacation_request_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_employee_id');
        });
    }
};
