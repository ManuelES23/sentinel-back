<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('razon_social', 200)->nullable()->after('name');
            $table->string('rfc', 20)->nullable()->after('razon_social');
            $table->string('direccion', 300)->nullable()->after('rfc');
            $table->string('ciudad', 100)->nullable()->after('direccion');
            $table->string('pais', 100)->nullable()->after('ciudad');
            $table->string('agente_aduana_mx', 200)->nullable()->after('pais');
            $table->string('telefono', 30)->nullable()->after('agente_aduana_mx');
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn([
                'razon_social', 'rfc', 'direccion', 'ciudad', 'pais',
                'agente_aduana_mx', 'telefono',
            ]);
        });
    }
};
