<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->tinyInteger('hierarchy_level')->default(7)->after('department_id')
                ->comment('1=Director General, 2=Director, 3=Gerente, 4=Jefe, 5=Coordinador, 6=Supervisor, 7=Operativo');
            $table->boolean('can_approve')->default(false)->after('hierarchy_level')
                ->comment('Si este puesto puede aprobar solicitudes');
            $table->string('approval_scope', 30)->default('own_department')->after('can_approve')
                ->comment('own_department: solo su depto | child_departments: incluye subdeptos | enterprise: toda la empresa');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['hierarchy_level', 'can_approve', 'approval_scope']);
        });
    }
};
