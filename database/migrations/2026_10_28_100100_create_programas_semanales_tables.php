<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programa semanal: un programa por semana (se identifica por su lunes) con
 * sus rendimientos elegidos, cortes, necesidades, viajes, acuerdos de la junta,
 * inventarios iniciales manuales, juntas registradas e historial de cambios.
 * Los días van de 0 (lunes) a 5 (sábado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programas_semanales', function (Blueprint $table) {
            $table->id();
            $table->date('lunes')->unique();
            $table->timestamp('junta_registrada_at')->nullable();
            $table->foreignId('junta_registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('programa_rendimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('variedad_id')->constrained('variedades')->cascadeOnDelete();
            $table->enum('modo', ['minimo', 'medio', 'maximo', 'exacto'])->default('medio');
            $table->decimal('valor_exacto', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['programa_id', 'temporada_id', 'variedad_id'], 'programa_rendimientos_unico');
        });

        Schema::create('programa_cortes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('variedad_id')->constrained('variedades')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia');
            $table->decimal('hectareas', 8, 2);
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['programa_id', 'dia']);
            $table->index('etapa_id');
        });

        Schema::create('programa_necesidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('plan_bodegas_destino')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('crm_clientes')->nullOnDelete();
            $table->string('cliente_nombre');
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->foreignId('presentacion_id')->constrained('plan_presentaciones')->restrictOnDelete();
            $table->foreignId('tipo_empaque_id')->nullable()->constrained('plan_tipos_empaque')->nullOnDelete();
            for ($dia = 0; $dia <= 5; $dia++) {
                $table->unsignedInteger("cajas_{$dia}")->default(0);
            }
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('programa_id');
        });

        Schema::create('programa_necesidad_variedad', function (Blueprint $table) {
            $table->foreignId('necesidad_id')->constrained('programa_necesidades')->cascadeOnDelete();
            $table->foreignId('variedad_id')->constrained('variedades')->cascadeOnDelete();

            $table->primary(['necesidad_id', 'variedad_id']);
        });

        Schema::create('programa_viajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia');
            $table->foreignId('bodega_id')->constrained('plan_bodegas_destino')->restrictOnDelete();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->enum('tipo', ['full', 'sencillo']);
            $table->unsignedInteger('cajas_caja_1');
            $table->unsignedInteger('cajas_caja_2')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['programa_id', 'dia']);
        });

        // Los campos propios de cada tipo de acuerdo son nulos en los demás tipos.
        Schema::create('programa_acuerdos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->enum('tipo', ['adelantar_etapa', 'mover_pedido', 'conseguir_producto']);
            $table->boolean('activo')->default(true);
            $table->text('descripcion')->nullable();
            // adelantar_etapa
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('temporada_id')->nullable()->constrained('temporadas')->nullOnDelete();
            // adelantar_etapa y conseguir_producto
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->unsignedTinyInteger('dia')->nullable();
            $table->decimal('hectareas', 8, 2)->nullable();
            // mover_pedido
            $table->foreignId('necesidad_id')->nullable()->constrained('programa_necesidades')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_origen')->nullable();
            $table->date('destino_lunes')->nullable();
            $table->unsignedTinyInteger('dia_destino')->nullable();
            // mover_pedido y conseguir_producto
            $table->unsignedInteger('cajas')->nullable();
            // conseguir_producto
            $table->enum('origen_tipo', ['productor', 'empresa_grupo', 'otro'])->nullable();
            $table->string('origen_texto')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['programa_id', 'activo']);
            $table->index('destino_lunes');
        });

        // grupo_clave: id del grupo de balance, o "v{variedadId}" si es implícito.
        Schema::create('programa_inventarios_iniciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->string('grupo_clave', 40);
            $table->integer('cajas');
            $table->timestamps();

            $table->unique(['programa_id', 'grupo_clave'], 'programa_inventarios_unico');
        });

        Schema::create('programa_juntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('foto')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('programa_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->string('entidad', 60);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->enum('accion', ['creado', 'actualizado', 'borrado', 'activado', 'desactivado']);
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['programa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programa_cambios');
        Schema::dropIfExists('programa_juntas');
        Schema::dropIfExists('programa_inventarios_iniciales');
        Schema::dropIfExists('programa_acuerdos');
        Schema::dropIfExists('programa_viajes');
        Schema::dropIfExists('programa_necesidad_variedad');
        Schema::dropIfExists('programa_necesidades');
        Schema::dropIfExists('programa_cortes');
        Schema::dropIfExists('programa_rendimientos');
        Schema::dropIfExists('programas_semanales');
    }
};
