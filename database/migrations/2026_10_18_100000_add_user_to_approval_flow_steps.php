<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasos de aprobación de vacaciones por «Usuario específico» (approver_type = 'user'):
 * approval_flow_steps.user_id apunta al usuario de Sentinel que aprueba el paso.
 * «Jefe del departamento» ('department_manager') no necesita columna: usa
 * departments.manager_id. approver_type ya es string desde la migración de la cadena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('position_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('approval_flow_steps')->whereIn('approver_type', ['user', 'department_manager'])->delete();

        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
