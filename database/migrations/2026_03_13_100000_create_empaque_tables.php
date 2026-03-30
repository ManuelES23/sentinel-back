<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Recepciones — recibe salidas de campo en la planta de empaque
        Schema::create('recepciones_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities')->comment('Planta empaque destino');
            $table->foreignId('salida_campo_id')->nullable()->constrained('salidas_campo_cosecha');
            $table->string('folio_recepcion', 30)->unique();
            $table->date('fecha_recepcion');
            $table->time('hora_recepcion')->nullable();
            $table->foreignId('productor_id')->nullable()->constrained('productores');
            $table->foreignId('lote_id')->nullable()->constrained('lotes');
            $table->foreignId('etapa_id')->nullable()->constrained('etapas');
            $table->foreignId('zona_cultivo_id')->nullable()->constrained('zonas_cultivo');
            $table->foreignId('tipo_carga_id')->nullable()->constrained('tipos_carga');
            $table->integer('cantidad_recibida')->default(0);
            $table->decimal('peso_recibido_kg', 12, 2)->default(0);
            $table->decimal('temperatura', 5, 2)->nullable();
            $table->string('transportista', 150)->nullable();
            $table->string('vehiculo', 50)->nullable();
            $table->string('chofer', 150)->nullable();
            $table->enum('status', ['pendiente', 'recibida', 'en_proceso', 'rechazada'])->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'fecha_recepcion']);
            $table->index(['entity_id', 'status']);
        });

        // 2. Proceso — existencias en piso, asignación de folios para producción
        Schema::create('proceso_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('recepcion_id')->nullable()->constrained('recepciones_empaque');
            $table->string('folio_proceso', 30)->unique();
            $table->foreignId('tipo_carga_id')->nullable()->constrained('tipos_carga');
            $table->foreignId('productor_id')->nullable()->constrained('productores');
            $table->foreignId('lote_id')->nullable()->constrained('lotes');
            $table->foreignId('etapa_id')->nullable()->constrained('etapas');
            $table->integer('cantidad_entrada')->default(0);
            $table->decimal('peso_entrada_kg', 12, 2)->default(0);
            $table->integer('cantidad_disponible')->default(0);
            $table->decimal('peso_disponible_kg', 12, 2)->default(0);
            $table->date('fecha_entrada');
            $table->date('fecha_proceso')->nullable();
            $table->string('linea_proceso', 50)->nullable();
            $table->enum('status', ['en_piso', 'en_proceso', 'procesado', 'agotado'])->default('en_piso');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'status']);
            $table->index(['entity_id', 'fecha_entrada']);
        });

        // 3. Producción — cajas empacadas en pallets
        Schema::create('produccion_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('proceso_id')->nullable()->constrained('proceso_empaque');
            $table->string('folio_produccion', 30)->unique();
            $table->date('fecha_produccion');
            $table->string('turno', 20)->nullable();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades');
            $table->string('linea_empaque', 50)->nullable();
            $table->string('numero_pallet', 30)->nullable();
            $table->integer('total_cajas')->default(0);
            $table->decimal('peso_neto_kg', 12, 2)->default(0);
            $table->string('tipo_empaque', 80)->nullable();
            $table->string('etiqueta', 100)->nullable()->comment('Marca/etiqueta');
            $table->string('calibre', 30)->nullable();
            $table->string('categoria', 50)->nullable()->comment('Grado de calidad');
            $table->enum('status', ['empacado', 'en_almacen', 'embarcado'])->default('empacado');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'fecha_produccion']);
            $table->index(['entity_id', 'status']);
        });

        // 4. Rezaga — mermas y descartes del procesamiento
        Schema::create('rezaga_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('proceso_id')->nullable()->constrained('proceso_empaque');
            $table->string('folio_rezaga', 30)->unique();
            $table->enum('tipo_rezaga', ['descarte', 'merma', 'segunda', 'basura'])->default('descarte');
            $table->date('fecha');
            $table->decimal('cantidad_kg', 12, 2)->default(0);
            $table->string('motivo', 200)->nullable();
            $table->enum('status', ['pendiente', 'vendida', 'destruida'])->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'fecha']);
            $table->index(['status', 'tipo_rezaga']);
        });

        // 5. Embarques — salida de producto empacado
        Schema::create('embarques_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->string('folio_embarque', 30)->unique();
            $table->enum('tipo_venta', ['exportacion', 'nacional'])->default('nacional');
            $table->string('cliente', 200);
            $table->string('destino', 200)->nullable();
            $table->date('fecha_embarque');
            $table->integer('total_pallets')->default(0);
            $table->integer('total_cajas')->default(0);
            $table->decimal('peso_total_kg', 12, 2)->default(0);
            $table->string('transportista', 150)->nullable();
            $table->string('vehiculo', 50)->nullable();
            $table->string('chofer', 150)->nullable();
            $table->string('numero_contenedor', 50)->nullable();
            $table->string('sello', 50)->nullable();
            $table->decimal('temperatura', 5, 2)->nullable();
            $table->enum('status', ['programado', 'cargando', 'en_transito', 'entregado', 'cancelado'])->default('programado');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'fecha_embarque']);
            $table->index(['status', 'tipo_venta']);
        });

        // 5b. Detalles de embarque (pallets en el embarque)
        Schema::create('embarque_empaque_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embarque_id')->constrained('embarques_empaque')->cascadeOnDelete();
            $table->foreignId('produccion_id')->constrained('produccion_empaque');
            $table->integer('cajas')->default(0);
            $table->decimal('peso_kg', 12, 2)->default(0);
            $table->timestamps();
        });

        // 6. Venta de rezaga
        Schema::create('venta_rezaga_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->string('folio_venta', 30)->unique();
            $table->string('comprador', 200);
            $table->date('fecha_venta');
            $table->decimal('total_peso_kg', 12, 2)->default(0);
            $table->decimal('precio_kg', 10, 2)->default(0);
            $table->decimal('monto_total', 14, 2)->default(0);
            $table->enum('status', ['pendiente', 'pagada', 'cancelada'])->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['temporada_id', 'fecha_venta']);
        });

        // 6b. Detalles de venta de rezaga
        Schema::create('venta_rezaga_empaque_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_rezaga_id')->constrained('venta_rezaga_empaque')->cascadeOnDelete();
            $table->foreignId('rezaga_id')->constrained('rezaga_empaque');
            $table->decimal('peso_kg', 12, 2)->default(0);
            $table->decimal('precio_kg', 10, 2)->default(0);
            $table->decimal('monto', 14, 2)->default(0);
            $table->timestamps();
        });

        // 7. Calidad — evaluaciones de recepción y empacado
        Schema::create('calidad_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->enum('tipo_evaluacion', ['recepcion', 'empacado']);
            $table->string('evaluable_type', 100);
            $table->unsignedBigInteger('evaluable_id');
            $table->string('folio_evaluacion', 30)->unique();
            $table->date('fecha_evaluacion');
            $table->enum('resultado', ['aprobada', 'condicionada', 'rechazada'])->default('aprobada');
            $table->decimal('porcentaje_defectos', 5, 2)->default(0);
            $table->text('defectos_encontrados')->nullable();
            $table->decimal('temperatura', 5, 2)->nullable();
            $table->decimal('humedad', 5, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('evaluado_por')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['evaluable_type', 'evaluable_id']);
            $table->index(['temporada_id', 'tipo_evaluacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calidad_empaque');
        Schema::dropIfExists('venta_rezaga_empaque_detalles');
        Schema::dropIfExists('venta_rezaga_empaque');
        Schema::dropIfExists('embarque_empaque_detalles');
        Schema::dropIfExists('embarques_empaque');
        Schema::dropIfExists('rezaga_empaque');
        Schema::dropIfExists('produccion_empaque');
        Schema::dropIfExists('proceso_empaque');
        Schema::dropIfExists('recepciones_empaque');
    }
};
