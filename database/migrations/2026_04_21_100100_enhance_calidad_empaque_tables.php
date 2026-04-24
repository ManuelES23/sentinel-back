<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cabecera: amplia calidad_empaque con campos de resumen y hace polimorfico opcional.
        Schema::table('calidad_empaque', function (Blueprint $table) {
            $table->string('evaluable_type', 100)->nullable()->change();
            $table->unsignedBigInteger('evaluable_id')->nullable()->change();

            $table->string('responsable', 150)->nullable()->after('fecha_evaluacion');
            $table->integer('tamano_muestra_total')->nullable()->after('responsable');
            $table->decimal('cumple_total', 12, 2)->nullable()->after('tamano_muestra_total');
            $table->decimal('no_cumple_total', 12, 2)->nullable()->after('cumple_total');
            $table->decimal('porcentaje_cumple', 5, 2)->nullable()->after('no_cumple_total');
            $table->integer('piezas_por_caja')->nullable()->after('porcentaje_cumple');
        });

        // Amplia enum tipo_evaluacion para soportar 'empacadores' (mantiene valores previos).
        DB::statement("ALTER TABLE calidad_empaque MODIFY COLUMN tipo_evaluacion ENUM('recepcion','empacado','empacadores') NOT NULL");

        // Muestras dinamicas por evaluacion (una fila por folio/empacador).
        Schema::create('calidad_empaque_muestras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calidad_id')->constrained('calidad_empaque')->cascadeOnDelete();
            $table->foreignId('recepcion_id')->nullable()->constrained('recepciones_empaque')->nullOnDelete();
            $table->foreignId('empleado_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('empacador_nombre', 150)->nullable();
            $table->time('hora')->nullable();
            $table->integer('muestra')->default(0);
            $table->decimal('conteo', 10, 2)->nullable();
            $table->decimal('cumple', 10, 2)->default(0);
            $table->decimal('no_cumple', 10, 2)->default(0);
            $table->decimal('porcentaje_cumple', 5, 2)->nullable();
            $table->string('calificacion', 30)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['calidad_id']);
            $table->index(['recepcion_id']);
            $table->index(['empleado_id']);
        });

        // Detalle de plagas por muestra (cantidades).
        Schema::create('calidad_empaque_muestra_plagas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muestra_id')->constrained('calidad_empaque_muestras')->cascadeOnDelete();
            $table->foreignId('plaga_id')->constrained('plagas')->cascadeOnDelete();
            $table->decimal('cantidad', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['muestra_id', 'plaga_id']);
            $table->index(['plaga_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calidad_empaque_muestra_plagas');
        Schema::dropIfExists('calidad_empaque_muestras');

        Schema::table('calidad_empaque', function (Blueprint $table) {
            $table->dropColumn([
                'responsable',
                'tamano_muestra_total',
                'cumple_total',
                'no_cumple_total',
                'porcentaje_cumple',
                'piezas_por_caja',
            ]);
        });

        DB::statement("ALTER TABLE calidad_empaque MODIFY COLUMN tipo_evaluacion ENUM('recepcion','empacado') NOT NULL");
    }
};
