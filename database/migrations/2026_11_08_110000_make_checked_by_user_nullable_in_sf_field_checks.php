<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rescate de checadas del checador de campo: si el ingeniero que tenía el
 * celular revocado ya fue borrado (device_pairings.paired_by_user_id en null),
 * la checada se guarda igual, sin ingeniero y con motivo de revisión. Por eso
 * checked_by_user_id deja de ser obligatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->foreignId('checked_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las filas rescatadas sin ingeniero no pueden volver a NOT NULL sin perder datos.
        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->foreignId('checked_by_user_id')->nullable(false)->change();
        });
    }
};
