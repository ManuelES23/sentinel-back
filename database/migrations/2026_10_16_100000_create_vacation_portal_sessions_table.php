<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones del portal de vacaciones (spec §3.3). dateTime y no timestamp en las
 * dos fechas obligatorias: en MySQL sin explicit_defaults_for_timestamp un
 * TIMESTAMP NOT NULL recibe ON UPDATE CURRENT_TIMESTAMP o un default inválido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('last_activity_at');
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_portal_sessions');
    }
};
