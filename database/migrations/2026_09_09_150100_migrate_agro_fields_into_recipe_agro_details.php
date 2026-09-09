<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo migrar recetas que de verdad tienen algo agrícola — una
        // receta sin cultivo/variedad/peso_pieza no gana fila en la
        // extensión (es exactamente el caso de Canes Agro).
        $rows = DB::table('recipes')
            ->whereNotNull('cultivo_id')
            ->orWhereNotNull('variedad_id')
            ->orWhereNotNull('peso_pieza')
            ->get(['id', 'cultivo_id', 'variedad_id', 'peso_pieza']);

        $records = $rows->map(fn ($r) => [
            'recipe_id' => $r->id,
            'cultivo_id' => $r->cultivo_id,
            'variedad_id' => $r->variedad_id,
            'peso_pieza' => $r->peso_pieza,
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        if (! empty($records)) {
            DB::table('recipe_agro_details')->insert($records);
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cultivo_id');
            $table->dropConstrainedForeignId('variedad_id');
            $table->dropColumn('peso_pieza');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('cultivo_id')->nullable()->constrained('cultivos')->nullOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->decimal('peso_pieza', 10, 4)->nullable();
        });

        DB::table('recipe_agro_details')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('recipes')->where('id', $row->recipe_id)->update([
                    'cultivo_id' => $row->cultivo_id,
                    'variedad_id' => $row->variedad_id,
                    'peso_pieza' => $row->peso_pieza,
                ]);
            }
        });

        Schema::dropIfExists('recipe_agro_details');
    }
};
