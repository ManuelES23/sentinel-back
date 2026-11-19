<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta por convenio (plan 1 back): el pago se liga a las recepciones, el abono gana
 * `tipo` y `liquidacion_id`, y la liquidación pasa a ser el cierre final del convenio
 * (periodos nulos, saldo inicial y estado `liquidada`). Aditiva y re-ejecutable (guarda
 * con hasTable/hasColumn). Se detiene si quedan datos del flujo anterior de liquidaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viejas = DB::table('abs_liquidaciones')
            ->whereIn('status', ['borrador', 'en_revision', 'aprobada', 'pagada'])
            ->count();
        $aplicaciones = Schema::hasTable('abs_abono_aplicaciones')
            ? DB::table('abs_abono_aplicaciones')->count()
            : 0;

        if ($viejas > 0 || $aplicaciones > 0) {
            throw new RuntimeException(
                'Hay liquidaciones o abonos del flujo anterior (borrador, en revisión, aprobada, pagada o con aplicaciones): límpialos antes de migrar.'
            );
        }

        // Un abono del plan anterior sin aplicaciones (un anticipo suelto, o uno cuya liquidación se canceló) sigue
        // `aplicado` y, al estrenar `tipo` con `abono` por omisión, contaría como pagado del convenio. Solo se revisa
        // la primera vez: después los abonos ya son del flujo nuevo.
        if (! Schema::hasColumn('abs_abonos', 'tipo') && DB::table('abs_abonos')->whereNull('deleted_at')->exists()) {
            throw new RuntimeException('Hay abonos del flujo anterior: límpialos antes de migrar.');
        }

        // Liquidación: estado `liquidada`, periodos opcionales, saldo inicial y quién/cuándo la cerró.
        if (! Schema::hasColumn('abs_liquidaciones', 'liquidada_at')) {
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->enum('status', ['borrador', 'en_revision', 'aprobada', 'pagada', 'liquidada', 'cancelada'])
                    ->default('borrador')->change();
                $table->date('periodo_inicio')->nullable()->change();
                $table->date('periodo_fin')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('abs_liquidaciones', 'saldo_inicial')) {
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->decimal('saldo_inicial', 14, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('abs_liquidaciones', 'liquidada_at')) {
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->timestamp('liquidada_at')->nullable();
                $table->foreignId('liquidada_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        // Abono: tipo (de dónde nace el pago) y liquidación a la que pertenece, si es de cierre.
        if (! Schema::hasColumn('abs_abonos', 'tipo')) {
            Schema::table('abs_abonos', function (Blueprint $table) {
                $table->enum('tipo', ['recepcion', 'abono', 'adelanto', 'liquidacion', 'saldo_inicial'])->default('abono');
            });
        }

        if (! Schema::hasColumn('abs_abonos', 'liquidacion_id')) {
            Schema::table('abs_abonos', function (Blueprint $table) {
                $table->foreignId('liquidacion_id')->nullable()->constrained('abs_liquidaciones');
            });
        }

        // Cuánto de un pago le toca a cada recepción.
        if (! Schema::hasTable('abs_abono_recepciones')) {
            Schema::create('abs_abono_recepciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('abono_id')->constrained('abs_abonos')->cascadeOnDelete();
                $table->foreignId('recepcion_id')->constrained('abs_recepciones');
                $table->decimal('monto', 14, 2);
                $table->timestamps();
                $table->unique(['abono_id', 'recepcion_id'], 'abs_abono_recepciones_abono_recepcion_unique');
                $table->index('recepcion_id', 'abs_abono_recepciones_recepcion_index');
            });
        }
    }

    public function down(): void
    {
        // Cualquier abono vivo es del flujo nuevo (el viejo no se admite al subir). Una liquidación `liquidada` o una
        // con periodo nulo (por ejemplo, liquidada y luego cancelada) no cabe en el esquema anterior: sin esta revisión
        // `down()` borraría las columnas y fallaría después en MySQL al volver a exigir los periodos.
        $hayDatos = (Schema::hasTable('abs_abono_recepciones') && DB::table('abs_abono_recepciones')->exists())
            || DB::table('abs_abonos')->whereNull('deleted_at')->exists()
            || DB::table('abs_liquidaciones')->where(fn ($q) => $q->where('status', 'liquidada')
                ->orWhereNull('periodo_inicio')->orWhereNull('periodo_fin'))->exists();

        if ($hayDatos) {
            throw new RuntimeException('No se puede revertir: ya hay cuentas por convenio con datos.');
        }

        Schema::dropIfExists('abs_abono_recepciones');

        if (Schema::hasColumn('abs_abonos', 'liquidacion_id')) {
            Schema::table('abs_abonos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('liquidacion_id');
            });
        }
        if (Schema::hasColumn('abs_abonos', 'tipo')) {
            Schema::table('abs_abonos', function (Blueprint $table) {
                $table->dropColumn('tipo');
            });
        }

        if (Schema::hasColumn('abs_liquidaciones', 'liquidada_at')) {
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->dropConstrainedForeignId('liquidada_by');
                $table->dropColumn(['liquidada_at', 'saldo_inicial']);
            });
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->enum('status', ['borrador', 'en_revision', 'aprobada', 'pagada', 'cancelada'])
                    ->default('borrador')->change();
                $table->date('periodo_inicio')->nullable(false)->change();
                $table->date('periodo_fin')->nullable(false)->change();
            });
        }
    }
};
