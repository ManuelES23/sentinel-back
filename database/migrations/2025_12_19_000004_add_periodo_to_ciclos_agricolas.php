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
        Schema::table('ciclos_agricolas', function (Blueprint $table) {
            $table->enum('periodo', ['primavera-verano', 'otoño-invierno', 'todo-el-año'])->after('cultivo_id');
            $table->date('fecha_fin')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ciclos_agricolas', function (Blueprint $table) {
            $table->dropColumn('periodo');
            $table->date('fecha_fin')->nullable(false)->change();
        });
    }
};
