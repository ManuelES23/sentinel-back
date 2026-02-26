<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear tabla pivot para relacionar horarios con empresas
        Schema::create('enterprise_work_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->foreignId('work_schedule_id')->constrained()->onDelete('cascade');
            $table->boolean('is_default')->default(false); // Horario por defecto para esta empresa
            $table->timestamps();

            $table->unique(['enterprise_id', 'work_schedule_id']);
            $table->index('enterprise_id');
            $table->index('work_schedule_id');
        });

        // 2. Migrar datos existentes a la tabla pivot
        $schedules = DB::table('work_schedules')->whereNotNull('enterprise_id')->get();
        foreach ($schedules as $schedule) {
            DB::table('enterprise_work_schedule')->insert([
                'enterprise_id' => $schedule->enterprise_id,
                'work_schedule_id' => $schedule->id,
                'is_default' => $schedule->is_default,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Hacer enterprise_id nullable y quitar is_default (ahora está en pivot)
        Schema::table('work_schedules', function (Blueprint $table) {
            // Eliminar la foreign key existente
            $table->dropForeign(['enterprise_id']);
            
            // Hacer nullable
            $table->foreignId('enterprise_id')->nullable()->change();
            
            // Quitar is_default (se movió a tabla pivot)
            $table->dropColumn('is_default');
        });

        // 4. Poner enterprise_id en null (ya migrados a pivot)
        DB::table('work_schedules')->update(['enterprise_id' => null]);
    }

    public function down(): void
    {
        // 1. Restaurar is_default en work_schedules
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // 2. Restaurar datos desde pivot
        $pivotData = DB::table('enterprise_work_schedule')->get();
        foreach ($pivotData as $pivot) {
            DB::table('work_schedules')
                ->where('id', $pivot->work_schedule_id)
                ->update([
                    'enterprise_id' => $pivot->enterprise_id,
                    'is_default' => $pivot->is_default,
                ]);
        }

        // 3. Hacer enterprise_id NOT NULL y restaurar foreign key
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->foreign('enterprise_id')->references('id')->on('enterprises')->onDelete('cascade');
        });

        // 4. Eliminar tabla pivot
        Schema::dropIfExists('enterprise_work_schedule');
    }
};
