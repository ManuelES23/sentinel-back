<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folio de las oportunidades (OPP-0001): consecutivo POR EMPRESA, asignado al
 * crearlas y que nunca cambia. Las existentes (incluidas las borradas, para no
 * reutilizar un número) lo reciben por orden de creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_oportunidades', 'folio')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->string('folio', 20)->nullable()->after('empresa_id');
            });
        }

        DB::table('crm_oportunidades')->whereNull('folio')->distinct()->pluck('empresa_id')
            ->each(function ($empresaId) {
                $ultimo = DB::table('crm_oportunidades')->where('empresa_id', $empresaId)
                    ->whereNotNull('folio')->where('folio', 'like', 'OPP-%')->get(['folio'])
                    ->map(fn ($fila) => (int) substr($fila->folio, 4))->max() ?? 0;

                DB::table('crm_oportunidades')->where('empresa_id', $empresaId)->whereNull('folio')
                    ->orderBy('id')->select('id')->chunkById(500, function ($filas) use (&$ultimo) {
                        foreach ($filas as $fila) {
                            DB::table('crm_oportunidades')->where('id', $fila->id)
                                ->update(['folio' => 'OPP-'.str_pad((string) ++$ultimo, 4, '0', STR_PAD_LEFT)]);
                        }
                    });
            });

        Schema::table('crm_oportunidades', function (Blueprint $table) {
            $table->unique(['empresa_id', 'folio'], 'crm_oportunidades_empresa_folio_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_oportunidades', function (Blueprint $table) {
            $table->dropUnique('crm_oportunidades_empresa_folio_unique');
            $table->dropColumn('folio');
        });
    }
};
