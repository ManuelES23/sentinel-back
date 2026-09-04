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
        Schema::table('attendance_records', function (Blueprint $table) {
            // Misma precisión que time_clock_checks.latitude/longitude — el
            // dato ya se captura ahí en cada chequeo biométrico, pero se
            // perdía al consolidar en el registro diario de asistencia.
            $table->decimal('check_in_latitude', 10, 7)->nullable()->after('check_in_device');
            $table->decimal('check_in_longitude', 10, 7)->nullable()->after('check_in_latitude');
            $table->decimal('check_out_latitude', 10, 7)->nullable()->after('check_out_device');
            $table->decimal('check_out_longitude', 10, 7)->nullable()->after('check_out_latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_latitude',
                'check_in_longitude',
                'check_out_latitude',
                'check_out_longitude',
            ]);
        });
    }
};
