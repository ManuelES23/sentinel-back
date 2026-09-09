<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_brand', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'brand_id'], 'uq_ent_brand');
        });

        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $brandIds = DB::table('brands')->whereNull('deleted_at')->pluck('id');
            $records = $brandIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'brand_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_brand')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_brand');
    }
};
