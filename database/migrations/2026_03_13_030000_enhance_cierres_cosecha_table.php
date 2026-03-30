<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cierres_cosecha', function (Blueprint $table) {
            $table->foreignId('productor_id')->nullable()->after('lote_id')
                  ->constrained('productores')->nullOnDelete();
            $table->foreignId('zona_cultivo_id')->nullable()->after('productor_id')
                  ->constrained('zonas_cultivo')->nullOnDelete();

            $table->integer('total_bultos')->nullable()->after('total_cajas');
            $table->integer('total_batangas')->nullable()->after('total_bultos');
            $table->integer('total_salidas')->nullable()->after('total_batangas');
            $table->decimal('rendimiento_kg_ha', 10, 2)->nullable()->after('rendimiento_hectarea');

            $table->foreignId('created_by')->nullable()->after('cerrado_por')
                  ->constrained('users')->nullOnDelete();

            $table->index(['fecha_inicio', 'productor_id']);
            $table->index('productor_id');
            $table->index('zona_cultivo_id');
        });
    }

    public function down(): void
    {
        Schema::table('cierres_cosecha', function (Blueprint $table) {
            $table->dropForeign(['productor_id']);
            $table->dropForeign(['zona_cultivo_id']);
            $table->dropForeign(['created_by']);

            $table->dropIndex(['fecha_inicio', 'productor_id']);
            $table->dropIndex(['productor_id']);
            $table->dropIndex(['zona_cultivo_id']);

            $table->dropColumn([
                'productor_id', 'zona_cultivo_id',
                'total_bultos', 'total_batangas', 'total_salidas',
                'rendimiento_kg_ha', 'created_by',
            ]);
        });
    }
};
