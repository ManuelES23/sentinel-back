<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'product_category_id'], 'uq_ent_prod_cat');
        });

        // Mismo criterio que enterprise_product (2026_04_09_002324): todo lo
        // existente hoy se vincula a Splendid Farms.
        $splendidFarms = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($splendidFarms) {
            $categoryIds = DB::table('product_categories')->whereNull('deleted_at')->pluck('id');
            $records = $categoryIds->map(fn ($id) => [
                'enterprise_id' => $splendidFarms->id,
                'product_category_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (! empty($records)) {
                DB::table('enterprise_product_category')->insert($records);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_product_category');
    }
};
