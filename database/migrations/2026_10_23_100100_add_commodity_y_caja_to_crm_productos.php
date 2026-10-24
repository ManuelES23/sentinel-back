<?php

use App\Models\CRM\CrmProducto;
use App\Support\CRM\EmpresasCrm;
use App\Support\CRM\ProductosSbpPredeterminados;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Productos del CRM: agrega el commodity (texto libre, indexado por empresa) y la
 * unidad de medida «caja», y siembra el catálogo de Splendid by Porvenir
 * (spec 2026-10-23 §4.3). SBP se localiza por slug; si no existe no se siembra nada.
 * Re-ejecutable sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_productos', 'commodity')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->string('commodity', 100)->nullable()->after('nombre');
                $table->index(['empresa_id', 'commodity'], 'crm_productos_empresa_commodity_index');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE crm_productos MODIFY unidad_medida ENUM('pieza','kg','litro','servicio','hora','caja') NOT NULL DEFAULT 'pieza'");
        } else {
            // SQLite (tests): el enum es un CHECK; ->change() reconstruye la tabla conservando las filas.
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->enum('unidad_medida', ['pieza', 'kg', 'litro', 'servicio', 'hora', 'caja'])
                    ->default('pieza')
                    ->change();
            });
        }

        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId !== null) {
            ProductosSbpPredeterminados::sembrar($sbpId);
        }
    }

    public function down(): void
    {
        // Los productos sembrados no se borran; sólo se revierte la estructura.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE crm_productos SET unidad_medida = 'pieza' WHERE unidad_medida = 'caja'");
            DB::statement("ALTER TABLE crm_productos MODIFY unidad_medida ENUM('pieza','kg','litro','servicio','hora') NOT NULL DEFAULT 'pieza'");
        }
        // SQLite: no se revierte el CHECK (la BD de test se reconstruye desde cero).

        if (Schema::hasColumn('crm_productos', 'commodity')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->dropIndex('crm_productos_empresa_commodity_index');
                $table->dropColumn('commodity');
            });
        }
    }
};
