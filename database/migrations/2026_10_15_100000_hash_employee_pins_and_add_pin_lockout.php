<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Portal de vacaciones (plan 1): el PIN del empleado pasa a guardarse cifrado
 * y se agrega el bloqueo por intentos fallidos. El cifrado NO se puede
 * deshacer: los empleados conservan su PIN, pero ya nadie puede leerlo.
 * Respaldar la base de datos antes de correrla en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('pin', 255)->nullable()->change();
        });

        if (! Schema::hasColumn('employees', 'pin_failed_attempts')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('pin');
                $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');
            });
        }

        $this->cifrarPines();
    }

    /** Re-ejecutable: no vuelve a cifrar un PIN que ya está cifrado. */
    public function cifrarPines(): void
    {
        DB::table('employees')->whereNotNull('pin')->orderBy('id')
            ->chunkById(200, function ($empleados) {
                foreach ($empleados as $empleado) {
                    if ($empleado->pin === '' || Hash::isHashed($empleado->pin)) {
                        continue;
                    }
                    DB::table('employees')->where('id', $empleado->id)
                        ->update(['pin' => Hash::make($empleado->pin)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['pin_failed_attempts', 'pin_locked_until']);
        });
        // El cifrado es irreversible: la columna pin se queda en 255.
    }
};
