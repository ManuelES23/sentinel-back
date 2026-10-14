<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación en cadena para vacaciones (spec §5):
 * - approver_type pasa a string para admitir 'direct_supervisor'.
 * - Las reglas EXISTENTES de vacaciones se juntan en el paso 1: hasta hoy
 *   cualquiera de ellas aprobaba sola, y con la cadena un step_order
 *   distinto significa un paso en serie. Así nada cambia el día del
 *   despliegue; RH arma la cadena después.
 * - vacation_requests: source, current_step, resolved_by_rh.
 * - vacation_request_approvals: la cadena congelada de cada solicitud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->string('approver_type', 30)->change();
        });

        $procesoId = DB::table('approval_processes')->where('code', 'vacation_requests')->value('id');
        if ($procesoId) {
            DB::table('approval_flow_steps')->where('approval_process_id', $procesoId)->update(['step_order' => 1]);
        }

        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->string('source', 10)->default('rh')->after('status');
            $table->unsignedSmallInteger('current_step')->nullable()->after('source');
            $table->boolean('resolved_by_rh')->default(false)->after('current_step');
        });

        // Origen de las existentes: 'perfil' si las creó el usuario ligado al mismo empleado.
        $delPerfil = DB::table('vacation_requests as vr')
            ->join('employees as e', 'e.id', '=', 'vr.employee_id')
            ->whereColumn('e.user_id', 'vr.created_by')
            ->pluck('vr.id');
        foreach ($delPerfil->chunk(500) as $ids) {
            DB::table('vacation_requests')->whereIn('id', $ids->all())->update(['source' => 'perfil']);
        }

        Schema::create('vacation_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacation_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('description', 255);
            $table->json('rules')->nullable();
            $table->boolean('is_rh_final')->default(false);
            $table->string('decision', 10)->default('waiting');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['vacation_request_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_request_approvals');

        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->dropColumn(['source', 'current_step', 'resolved_by_rh']);
        });

        DB::table('approval_flow_steps')->where('approver_type', 'direct_supervisor')->delete();
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approval_flow_steps MODIFY COLUMN approver_type ENUM('hierarchy_level','position') NOT NULL");
        }
    }
};
