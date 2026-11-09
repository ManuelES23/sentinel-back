<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checador de campo SF como app directa: el dispositivo emparejado (modo
 * sf_field) queda ligado a la empresa, y los chequeos guardan de qué
 * dispositivo vinieron, quién los rescató si el dispositivo fue revocado
 * y el motivo de revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairings', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('paired_by_user_id')->constrained('enterprises')->nullOnDelete();
        });

        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->foreignId('device_pairing_id')->nullable()->constrained('device_pairings')->nullOnDelete();
            $table->foreignId('rescued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_pairing_id');
            $table->dropConstrainedForeignId('rescued_by_user_id');
            $table->dropColumn('review_reason');
        });

        Schema::table('device_pairings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
