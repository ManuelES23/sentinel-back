<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los catálogos iniciales del Programa semanal: bodegas de destino,
 * tipos de empaque y «cajas por carga» (globales), y, si existe el cultivo
 * «Elote Dulce», sus presentaciones y grupos de balance. Es idempotente y no
 * pisa lo que ya se haya editado.
 */
return new class extends Migration
{
    private const BODEGAS = ['Texas', 'Nogales', 'Mexicali', 'Nacional'];

    private const TIPOS_EMPAQUE = ['RPC', 'Cartón', 'DPC'];

    private const PRESENTACIONES_ELOTE = ['Bulk', 'Tray', 'Minicobs', 'Tray 5 pz'];

    /** Nombre del grupo => variedades que lo forman. */
    private const GRUPOS_ELOTE = [
        'Amarillo / Bicolor' => ['Amarillo', 'Bicolor'],
        'Blanco' => ['Blanco'],
    ];

    public function up(): void
    {
        $this->sembrarLista('plan_bodegas_destino', self::BODEGAS);
        $this->sembrarLista('plan_tipos_empaque', self::TIPOS_EMPAQUE);

        if (! DB::table('plan_configuracion')->where('clave', 'cajas_por_carga')->exists()) {
            DB::table('plan_configuracion')->insert([
                'clave' => 'cajas_por_carga',
                'valor' => '1040',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cultivoId = DB::table('cultivos')
            ->where('nombre', 'Elote Dulce')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $cultivoId) {
            return;
        }

        foreach (self::PRESENTACIONES_ELOTE as $orden => $nombre) {
            if (! DB::table('plan_presentaciones')->where('cultivo_id', $cultivoId)->where('nombre', $nombre)->exists()) {
                DB::table('plan_presentaciones')->insert([
                    'cultivo_id' => $cultivoId,
                    'nombre' => $nombre,
                    'orden' => $orden + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $orden = 0;
        foreach (self::GRUPOS_ELOTE as $nombreGrupo => $nombresVariedad) {
            $orden++;

            $variedadIds = DB::table('variedades')
                ->where('cultivo_id', $cultivoId)
                ->whereIn('nombre', $nombresVariedad)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($variedadIds->isEmpty()) {
                continue;
            }

            $grupoId = DB::table('plan_grupos_balance')
                ->where('cultivo_id', $cultivoId)
                ->where('nombre', $nombreGrupo)
                ->value('id');

            if (! $grupoId) {
                $grupoId = DB::table('plan_grupos_balance')->insertGetId([
                    'cultivo_id' => $cultivoId,
                    'nombre' => $nombreGrupo,
                    'orden' => $orden,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($variedadIds as $variedadId) {
                // Una variedad va en un solo grupo: si ya está en alguno, se respeta.
                if (! DB::table('plan_grupo_variedad')->where('variedad_id', $variedadId)->exists()) {
                    DB::table('plan_grupo_variedad')->insert([
                        'grupo_id' => $grupoId,
                        'variedad_id' => $variedadId,
                    ]);
                }
            }
        }
    }

    /**
     * Quita solo el parámetro sembrado; los catálogos pudieron tener uso y se dejan.
     */
    public function down(): void
    {
        DB::table('plan_configuracion')->where('clave', 'cajas_por_carga')->delete();
    }

    private function sembrarLista(string $tabla, array $nombres): void
    {
        foreach ($nombres as $orden => $nombre) {
            if (! DB::table($tabla)->where('nombre', $nombre)->exists()) {
                DB::table($tabla)->insert([
                    'nombre' => $nombre,
                    'orden' => $orden + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
