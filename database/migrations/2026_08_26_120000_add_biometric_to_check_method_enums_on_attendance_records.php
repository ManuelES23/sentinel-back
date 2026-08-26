<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El checador biométrico self-service (este plan) registra entradas/salidas con
 * AttendanceRecord::checkIn()/checkOut() usando method = 'biometric', pero el
 * enum de check_in_method/check_out_method solo contemplaba ['qr', 'pin', 'manual',
 * 'auto'] (checador QR/PIN legado). Spec: "se agrega el valor 'biometric'"
 * (docs/superpowers/specs/2026-08-26-checador-biometrico-grupoesplendido-rh-design.md
 * sección 4). Columnas sin FK/índice — seguro soltarlas y recrearlas en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendance_records MODIFY check_in_method ENUM('qr', 'pin', 'manual', 'auto', 'biometric') NULL");
            DB::statement("ALTER TABLE attendance_records MODIFY check_out_method ENUM('qr', 'pin', 'manual', 'auto', 'biometric') NULL");

            return;
        }

        // SQLite (tests): recrear las columnas con el enum ampliado.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['check_in_method', 'check_out_method']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->enum('check_in_method', ['qr', 'pin', 'manual', 'auto', 'biometric'])->nullable()->after('early_leave_minutes');
            $table->enum('check_out_method', ['qr', 'pin', 'manual', 'auto', 'biometric'])->nullable()->after('check_in_method');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Los registros ya marcados 'biometric' se degradan a 'manual' para
            // poder revertir el enum sin perder la fila.
            DB::statement("UPDATE attendance_records SET check_in_method = 'manual' WHERE check_in_method = 'biometric'");
            DB::statement("UPDATE attendance_records SET check_out_method = 'manual' WHERE check_out_method = 'biometric'");
            DB::statement("ALTER TABLE attendance_records MODIFY check_in_method ENUM('qr', 'pin', 'manual', 'auto') NULL");
            DB::statement("ALTER TABLE attendance_records MODIFY check_out_method ENUM('qr', 'pin', 'manual', 'auto') NULL");
        }
        // No se revierte en SQLite: la BD de test se reconstruye desde cero en cada suite.
    }
};
