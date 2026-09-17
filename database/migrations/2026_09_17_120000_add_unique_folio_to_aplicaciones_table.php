<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El folio de aplicaciones se generaba leyendo el último y sumando 1, sin
 * bloqueo ni restricción en la base: dos altas simultáneas guardaban el mismo
 * folio en silencio. Se agrega el índice único, renombrando antes cualquier
 * duplicado que ya exista (folio-2, folio-3, ...) para no romper la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicados = DB::table('aplicaciones')
            ->select('temporada_id', 'folio')
            ->whereNotNull('folio')
            ->groupBy('temporada_id', 'folio')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicados as $dup) {
            $ids = DB::table('aplicaciones')
                ->where('temporada_id', $dup->temporada_id)
                ->where('folio', $dup->folio)
                ->orderBy('id')
                ->pluck('id');

            // El primero conserva el folio original
            foreach ($ids->skip(1)->values() as $i => $id) {
                DB::table('aplicaciones')
                    ->where('id', $id)
                    ->update(['folio' => substr($dup->folio, 0, 45) . '-' . ($i + 2)]);
            }
        }

        Schema::table('aplicaciones', function (Blueprint $table) {
            $table->unique(['temporada_id', 'folio'], 'aplicaciones_temporada_folio_unique');
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table) {
            $table->dropUnique('aplicaciones_temporada_folio_unique');
        });
    }
};
