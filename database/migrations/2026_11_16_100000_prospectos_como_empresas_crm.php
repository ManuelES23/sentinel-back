<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prospectos como empresas (CRM SBP): bandera por empresa (apagada por defecto,
 * esta migración NUNCA la enciende), datos de empresa en prospectos y clientes,
 * y contacto_id en actividades y agenda.
 */
return new class extends Migration
{
    private const TABLAS_EMPRESA = ['crm_prospectos', 'crm_clientes'];

    private const TABLAS_CONTACTO = ['crm_actividades', 'crm_agenda'];

    public function up(): void
    {
        if (! Schema::hasColumn('crm_configuraciones_comerciales', 'prospecto_como_empresa')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->boolean('prospecto_como_empresa')->default(false);
            });
        }

        foreach (self::TABLAS_EMPRESA as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'giro')) {
                    $table->string('giro', 150)->nullable();
                }
                if (! Schema::hasColumn($tabla, 'sitio_web')) {
                    $table->string('sitio_web', 255)->nullable();
                }
                if (! Schema::hasColumn($tabla, 'ciudad')) {
                    $table->string('ciudad', 100)->nullable();
                }
                if (! Schema::hasColumn($tabla, 'estado')) {
                    $table->string('estado', 100)->nullable();
                }
            });
        }

        foreach (self::TABLAS_CONTACTO as $tabla) {
            if (Schema::hasColumn($tabla, 'contacto_id')) {
                continue;
            }

            if (DB::getDriverName() === 'sqlite') {
                // En SQLite, agregar una FK con Blueprint reconstruye la tabla y pierde
                // los CHECK de los enum (p. ej. crm_agenda.tipo). ADD COLUMN con
                // REFERENCES conserva la tabla intacta y la FK nullOnDelete.
                DB::statement("ALTER TABLE {$tabla} ADD COLUMN contacto_id integer REFERENCES crm_contactos(id) ON DELETE SET NULL");

                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('contacto_id')->nullable()
                    ->constrained('crm_contactos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS_CONTACTO as $tabla) {
            if (Schema::hasColumn($tabla, 'contacto_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropForeign(['contacto_id']);
                });
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('contacto_id');
                });
            }
        }

        foreach (self::TABLAS_EMPRESA as $tabla) {
            $columnas = array_values(array_filter(
                ['giro', 'sitio_web', 'ciudad', 'estado'],
                fn ($c) => Schema::hasColumn($tabla, $c),
            ));
            if ($columnas) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn($columnas));
            }
        }

        if (Schema::hasColumn('crm_configuraciones_comerciales', 'prospecto_como_empresa')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->dropColumn('prospecto_como_empresa');
            });
        }
    }
};
