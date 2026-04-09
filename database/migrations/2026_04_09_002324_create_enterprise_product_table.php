<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enterprise_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['enterprise_id', 'product_id']);
        });

        // Vincular productos existentes a Splendid Farms
        $sfEnterprise = DB::table('enterprises')->where('slug', 'splendidfarms')->first();
        if ($sfEnterprise) {
            $productIds = DB::table('products')->whereNull('deleted_at')->pluck('id');
            $records = $productIds->map(fn($id) => [
                'enterprise_id' => $sfEnterprise->id,
                'product_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            if (!empty($records)) {
                DB::table('enterprise_product')->insert($records);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enterprise_product');
    }
};
