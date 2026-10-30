<?php

use App\Support\RH\FestivosGeneralesAEmpresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festivos por empresa: cada empresa usa los suyos o los de otra (`enterprises.holiday_source_enterprise_id`)
 * y cada empleado puede seguir los de otra empresa (`employees.holiday_source_enterprise_id`).
 * Los festivos «de todas las empresas» pasan a Splendid Farms y las demás empresas (menos Splendid
 * by Porvenir) quedan siguiéndola (ver FestivosGeneralesAEmpresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->foreignId('holiday_source_enterprise_id')->nullable()->constrained('enterprises')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('holiday_source_enterprise_id')->nullable()->constrained('enterprises')->nullOnDelete();
        });

        FestivosGeneralesAEmpresa::aplicar();
    }

    public function down(): void
    {
        // Los festivos ya movidos a Splendid Farms se quedan ahí.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_source_enterprise_id');
        });

        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_source_enterprise_id');
        });
    }
};
