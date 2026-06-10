<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('recepciones_empaque', 'clasificacion')) {
            Schema::table('recepciones_empaque', function (Blueprint $table) {
                $table->enum('clasificacion', ['convencional', 'organico'])
                    ->nullable()
                    ->after('lote_producto_terminado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('recepciones_empaque', 'clasificacion')) {
            Schema::table('recepciones_empaque', function (Blueprint $table) {
                $table->dropColumn('clasificacion');
            });
        }
    }
};
