<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand status enum to include lavado pipeline states
        DB::statement("ALTER TABLE proceso_empaque MODIFY COLUMN status ENUM('en_piso','lavando','lavado','hidrotermico','enfriando','listo_produccion','en_proceso','procesado','agotado') DEFAULT 'en_piso'");

        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->date('fecha_lavado')->nullable()->after('fecha_proceso');
            $table->date('fecha_hidrotermico')->nullable()->after('fecha_lavado');
            $table->date('fecha_enfriamiento')->nullable()->after('fecha_hidrotermico');
            $table->date('fecha_listo_produccion')->nullable()->after('fecha_enfriamiento');
            $table->decimal('rezaga_lavado_kg', 12, 2)->default(0)->after('fecha_listo_produccion');
            $table->integer('rezaga_lavado_cantidad')->default(0)->after('rezaga_lavado_kg');
            $table->decimal('rezaga_hidrotermico_kg', 12, 2)->default(0)->after('rezaga_lavado_cantidad');
            $table->integer('rezaga_hidrotermico_cantidad')->default(0)->after('rezaga_hidrotermico_kg');
        });
    }

    public function down(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_lavado', 'fecha_hidrotermico', 'fecha_enfriamiento', 'fecha_listo_produccion',
                'rezaga_lavado_kg', 'rezaga_lavado_cantidad', 'rezaga_hidrotermico_kg', 'rezaga_hidrotermico_cantidad',
            ]);
        });

        DB::statement("ALTER TABLE proceso_empaque MODIFY COLUMN status ENUM('en_piso','en_proceso','procesado','agotado') DEFAULT 'en_piso'");
    }
};
