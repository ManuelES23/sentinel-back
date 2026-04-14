<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->boolean('en_cuarto_frio')->default(true)->after('is_cola')
                ->comment('Indica si el pallet está físicamente en cuarto frío');
        });

        // Pallets no embarcados → marcar en cuarto frío por defecto
        DB::table('produccion_empaque')
            ->whereIn('status', ['empacado', 'en_almacen'])
            ->update(['en_cuarto_frio' => true]);
        DB::table('produccion_empaque')
            ->where('status', 'embarcado')
            ->update(['en_cuarto_frio' => false]);
    }

    public function down(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn('en_cuarto_frio');
        });
    }
};
