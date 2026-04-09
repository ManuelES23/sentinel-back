<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enterprise_entity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->enum('access_level', ['read', 'write'])->default('write');
            $table->timestamps();

            $table->unique(['enterprise_id', 'entity_id']);
        });

        // Auto-asignar entidades propias a su empresa
        $entities = \App\Models\Entity::with('branch')->get();
        foreach ($entities as $entity) {
            if ($entity->branch && $entity->branch->enterprise_id) {
                \DB::table('enterprise_entity')->insertOrIgnore([
                    'enterprise_id' => $entity->branch->enterprise_id,
                    'entity_id' => $entity->id,
                    'access_level' => 'write',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enterprise_entity');
    }
};
