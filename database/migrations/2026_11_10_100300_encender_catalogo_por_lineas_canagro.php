<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enciende el catálogo por líneas para CanAgro (se localiza por slug; si no
 * existe no hace nada). Solo datos, con DB::table. Re-ejecutable.
 */
return new class extends Migration
{
    private const SLUG = 'canagro';

    public function up(): void
    {
        $empresaId = DB::table('enterprises')->where('slug', self::SLUG)->value('id');
        if ($empresaId === null) {
            return;
        }

        $existe = DB::table('crm_configuraciones_comerciales')->where('empresa_id', $empresaId)->exists();
        if ($existe) {
            DB::table('crm_configuraciones_comerciales')->where('empresa_id', $empresaId)
                ->update(['catalogo_por_lineas' => true, 'updated_at' => now()]);
        } else {
            DB::table('crm_configuraciones_comerciales')->insert([
                'empresa_id' => $empresaId,
                'moneda' => 'MXN',
                'catalogo_por_lineas' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $empresaId = DB::table('enterprises')->where('slug', self::SLUG)->value('id');
        if ($empresaId !== null) {
            DB::table('crm_configuraciones_comerciales')->where('empresa_id', $empresaId)
                ->update(['catalogo_por_lineas' => false, 'updated_at' => now()]);
        }
    }
};
