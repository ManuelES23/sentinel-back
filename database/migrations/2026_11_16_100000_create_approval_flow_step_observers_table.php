<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Personas informadas (copia, sin aprobar) de un paso «Usuario específico» del proceso de vacaciones. */
    public function up(): void
    {
        if (Schema::hasTable('approval_flow_step_observers')) {
            return;
        }

        Schema::create('approval_flow_step_observers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_step_id')->constrained('approval_flow_steps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['approval_flow_step_id', 'user_id'], 'afs_observers_step_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flow_step_observers');
    }
};
