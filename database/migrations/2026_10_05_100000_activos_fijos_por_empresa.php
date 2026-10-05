<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activos Fijos pasa a ser por empresa (fase 1):
 * - fixed_assets.enterprise_id, tomado de la empresa de la sucursal.
 * - enterprises.asset_code_prefix para los códigos {prefijo}-AF-000001.
 * - Consecutivos con bloqueo de fila: uno por empresa para activos y uno
 *   global para el catálogo de tipos (TAC-###), que es central.
 *
 * Estados vigentes de un activo: en_uso, en_mantenimiento, disponible,
 * resguardo, fuera_de_servicio, baja.
 */
return new class extends Migration
{
    private const PREFIJOS = [
        'splendidfarms' => 'SF',
        'splendidbyporvenir' => 'SP',
        'grupoesplendido' => 'GE',
    ];

    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('asset_code_prefix', 10)->nullable()->after('slug');
        });

        foreach (self::PREFIJOS as $slug => $prefijo) {
            DB::table('enterprises')->where('slug', $slug)->update(['asset_code_prefix' => $prefijo]);
        }

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained('enterprises')->restrictOnDelete();
        });

        // Subconsulta correlacionada: funciona igual en MySQL y en SQLite (pruebas).
        DB::statement('UPDATE fixed_assets SET enterprise_id = (SELECT branches.enterprise_id FROM branches WHERE branches.id = fixed_assets.branch_id)');

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->index(['enterprise_id', 'status']);
        });

        Schema::create('fixed_asset_sequences', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->primary()->constrained('enterprises')->cascadeOnDelete();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('asset_category_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        $ultimoTipo = DB::table('asset_categories')->where('code', 'like', 'TAC-%')->pluck('code')
            ->map(fn ($code) => (int) substr($code, 4))
            ->max() ?? 0;

        DB::table('asset_category_sequences')->insert([
            'id' => 1, 'last_number' => $ultimoTipo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_category_sequences');
        Schema::dropIfExists('fixed_asset_sequences');

        Schema::table('fixed_assets', function (Blueprint $table) {
            // En MySQL el índice compuesto sirve a la llave foránea: primero se suelta la FK.
            $table->dropForeign(['enterprise_id']);
            $table->dropIndex(['enterprise_id', 'status']);
            $table->dropColumn('enterprise_id');
        });

        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn('asset_code_prefix');
        });
    }
};
