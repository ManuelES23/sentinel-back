<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ingrediente_activo', 255)->nullable()->after('description');
            $table->boolean('requiere_revision')->default(false)->after('is_active');
            $table->unsignedSmallInteger('dias_alerta_caducidad')->default(30)->after('track_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ingrediente_activo', 'requiere_revision', 'dias_alerta_caducidad']);
        });
    }
};
