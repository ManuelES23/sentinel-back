<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_unit_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'unit_of_measure_id'], 'uq_ent_uom');
        });

        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $unitIds = DB::table('units_of_measure')->whereNull('deleted_at')->pluck('id');
            $records = $unitIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'unit_of_measure_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_unit_of_measure')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_unit_of_measure');
    }
};
