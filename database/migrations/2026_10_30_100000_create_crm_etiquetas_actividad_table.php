<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de etiquetas de actividad por empresa (spec 2026-10-30 §6) y la
 * referencia `etiqueta_id` en la agenda y en las actividades. Siembra las seis
 * etiquetas predeterminadas en cada empresa que tiene CRM y todavía no tiene
 * ninguna. La lista va escrita aquí (y no se lee de la clase de la aplicación)
 * para que la migración no cambie si esa lista se edita más adelante.
 * Re-ejecutable sin error.
 */
return new class extends Migration
{
    private const NOMBRES = ['Primer contacto', 'Seguimiento', 'Cotización', 'Negociación', 'Cobranza', 'Rechazo'];

    public function up(): void
    {
        if (! Schema::hasTable('crm_etiquetas_actividad')) {
            Schema::create('crm_etiquetas_actividad', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
                $table->string('nombre', 100);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['empresa_id', 'nombre']);
                $table->index(['empresa_id', 'activo']);
            });
        }

        foreach (['crm_agenda', 'crm_actividades'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'etiqueta_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->foreignId('etiqueta_id')
                        ->nullable()
                        ->constrained('crm_etiquetas_actividad')
                        ->nullOnDelete();
                });
            }
        }

        $this->sembrar();
    }

    public function down(): void
    {
        foreach (['crm_agenda', 'crm_actividades'] as $tabla) {
            if (Schema::hasColumn($tabla, 'etiqueta_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropForeign(['etiqueta_id']);
                    $table->dropColumn('etiqueta_id');
                });
            }
        }

        Schema::dropIfExists('crm_etiquetas_actividad');
    }

    private function sembrar(): void
    {
        $ahora = now();
        $empresaIds = DB::table('applications')->where('slug', 'crm')->pluck('enterprise_id')->unique();

        foreach ($empresaIds as $empresaId) {
            if (DB::table('crm_etiquetas_actividad')->where('empresa_id', $empresaId)->exists()) {
                continue;
            }

            DB::table('crm_etiquetas_actividad')->insert(array_map(fn (string $nombre, int $i) => [
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'orden' => $i + 1,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], self::NOMBRES, array_keys(self::NOMBRES)));
        }
    }
};
