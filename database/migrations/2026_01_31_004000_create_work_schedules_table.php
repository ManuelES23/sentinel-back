<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            $table->text('description')->nullable();
            
            // Horarios por día (null = día libre)
            $table->time('monday_start')->nullable();
            $table->time('monday_end')->nullable();
            $table->time('tuesday_start')->nullable();
            $table->time('tuesday_end')->nullable();
            $table->time('wednesday_start')->nullable();
            $table->time('wednesday_end')->nullable();
            $table->time('thursday_start')->nullable();
            $table->time('thursday_end')->nullable();
            $table->time('friday_start')->nullable();
            $table->time('friday_end')->nullable();
            $table->time('saturday_start')->nullable();
            $table->time('saturday_end')->nullable();
            $table->time('sunday_start')->nullable();
            $table->time('sunday_end')->nullable();
            
            // Tolerancias
            $table->integer('late_tolerance_minutes')->default(15); // Minutos de tolerancia para entrada
            $table->integer('early_departure_tolerance')->default(0); // Minutos antes de poder salir
            
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'is_active']);
        });

        // Agregar schedule a employees
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('work_shift')->constrained()->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['work_schedule_id']);
            $table->dropColumn('work_schedule_id');
        });
        
        Schema::dropIfExists('work_schedules');
    }
};
