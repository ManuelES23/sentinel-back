<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 - Requisiciones de Campo
 *
 * Flujo: VisitaCampoRecomendación → RequisiciónCampo → PurchaseOrder
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═════════════════════════════════════════════
        // REQUISICIONES DE CAMPO (cabecera)
        // ═════════════════════════════════════════════
        Schema::create('requisiciones_campo', function (Blueprint $table) {
            $table->id();
            $table->string('numero_requisicion', 30)->unique();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('visita_campo_id')->nullable()->constrained('visitas_campo')->nullOnDelete();
            $table->foreignId('solicitante_user_id')->constrained('users');
            $table->date('fecha_solicitud');

            $table->enum('status', [
                'borrador',        // Recién creada, editable
                'pendiente',       // Enviada a aprobación
                'aprobada',        // Aprobada por supervisor
                'rechazada',       // Rechazada con motivo
                'orden_generada',  // Se generó OC vinculada
                'completada',      // OC completada
                'cancelada',       // Cancelada
            ])->default('borrador');

            $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
            $table->text('justificacion')->nullable();
            $table->text('notas_rechazo')->nullable();

            // Aprobación
            $table->foreignId('aprobado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();

            // Vínculo con Orden de Compra generada
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();

            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'status']);
            $table->index('solicitante_user_id');
        });

        // ═════════════════════════════════════════════
        // DETALLES DE REQUISICIÓN (líneas de producto)
        // ═════════════════════════════════════════════
        Schema::create('requisicion_campo_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisicion_campo_id')->constrained('requisiciones_campo')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('nombre_producto', 200);
            $table->decimal('cantidad', 10, 2);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('precio_estimado', 12, 2)->nullable();
            $table->decimal('subtotal_estimado', 14, 2)->nullable();

            // Asignación agrícola (para costeo)
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            // Trazabilidad: de qué recomendación de visita viene
            $table->foreignId('visita_campo_recomendacion_id')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicion_campo_detalles');
        Schema::dropIfExists('requisiciones_campo');
    }
};
