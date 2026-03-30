<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Eliminar columnas obsoletas
            $table->dropColumn([
                'producto',
                'unidad_medida',
                'cajas',
                'guia_remision',
                'destino',
            ]);

            // Eliminar softDeletes (deleted_at) — se reemplaza con 'eliminado'
            $table->dropSoftDeletes();
        });

        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Nuevas columnas
            $table->time('hora_salida')->nullable()->after('fecha');
            $table->foreignId('tipo_carga_id')->nullable()->after('lote_id')
                  ->constrained('tipos_carga')->nullOnDelete();
            $table->foreignId('productor_id')->nullable()->after('tipo_carga_id')
                  ->constrained('productores')->nullOnDelete();
            $table->foreignId('zona_cultivo_id')->nullable()->after('productor_id')
                  ->constrained('zonas_cultivo')->nullOnDelete();
            $table->foreignId('destino_entity_id')->nullable()->after('zona_cultivo_id')
                  ->constrained('entities')->nullOnDelete();
            $table->string('folio_salida', 30)->nullable()->unique()->after('destino_entity_id');
            $table->boolean('es_batanga')->default(false)->after('observaciones');
            $table->boolean('eliminado')->default(false)->after('status');

            // Cambiar cantidad a integer unsigned
            $table->unsignedInteger('cantidad')->change();

            // Nuevos índices
            $table->index(['productor_id', 'lote_id']);
            $table->index('eliminado');
        });
    }

    public function down(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Quitar nuevos índices
            $table->dropIndex(['productor_id', 'lote_id']);
            $table->dropIndex(['eliminado']);

            // Quitar folio_salida unique
            $table->dropUnique(['folio_salida']);

            // Quitar FKs
            $table->dropConstrainedForeignId('tipo_carga_id');
            $table->dropConstrainedForeignId('productor_id');
            $table->dropConstrainedForeignId('zona_cultivo_id');
            $table->dropConstrainedForeignId('destino_entity_id');

            // Quitar columnas nuevas
            $table->dropColumn([
                'hora_salida',
                'folio_salida',
                'es_batanga',
                'eliminado',
            ]);

            // Restaurar cantidad a decimal
            $table->decimal('cantidad', 12, 2)->change();
        });

        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Restaurar columnas eliminadas
            $table->string('producto', 150)->after('fecha');
            $table->string('unidad_medida', 50)->after('cantidad');
            $table->integer('cajas')->nullable()->after('unidad_medida');
            $table->string('destino', 255)->nullable()->after('peso_neto_kg');
            $table->string('guia_remision', 100)->nullable()->after('chofer');
            $table->softDeletes();
        });
    }
};
