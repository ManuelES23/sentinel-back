<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->string('pallet_qr_id', 36)->nullable()->unique()->after('numero_pallet')
                ->comment('UUID único para QR del pallet');
            $table->boolean('is_cola')->default(false)->after('status')
                ->comment('Pallet incompleto (cola) para completar otro día');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn(['pallet_qr_id', 'is_cola']);
        });
    }
};
