<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de consignatarios (clientes destino)
        Schema::create('consignatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises');
            $table->string('nombre', 200);
            $table->string('rfc_tax_id', 50)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('pais', 100)->nullable();
            $table->string('agente_aduana', 200)->nullable();
            $table->string('bodega', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('enterprise_id');
        });

        // Campos adicionales para embarques con manifiesto
        Schema::table('embarques_empaque', function (Blueprint $table) {
            // Datos empresa (snapshot)
            $table->string('empresa_razon_social', 200)->nullable()->after('genera_manifiesto');
            $table->string('empresa_rfc', 50)->nullable()->after('empresa_razon_social');
            $table->string('empresa_direccion', 300)->nullable()->after('empresa_rfc');
            $table->string('empresa_ciudad', 100)->nullable()->after('empresa_direccion');
            $table->string('empresa_pais', 100)->default('MEXICO')->after('empresa_ciudad');
            $table->string('empresa_agente_aduana_mx', 200)->nullable()->after('empresa_pais');

            // Consignatario
            $table->foreignId('consignatario_id')->nullable()->after('empresa_agente_aduana_mx')
                ->constrained('consignatarios')->nullOnDelete();
            // Snapshot consignatario
            $table->string('consigne_nombre', 200)->nullable()->after('consignatario_id');
            $table->string('consigne_rfc', 50)->nullable()->after('consigne_nombre');
            $table->string('consigne_direccion', 300)->nullable()->after('consigne_rfc');
            $table->string('consigne_ciudad', 100)->nullable()->after('consigne_direccion');
            $table->string('consigne_pais', 100)->nullable()->after('consigne_ciudad');
            $table->string('consigne_agente_aduana_eua', 200)->nullable()->after('consigne_pais');
            $table->string('consigne_bodega', 200)->nullable()->after('consigne_agente_aduana_eua');

            // Destino (FK a consignatario)
            $table->foreignId('destino_consignatario_id')->nullable()->after('destino')
                ->constrained('consignatarios')->nullOnDelete();

            // Factura
            $table->string('factura', 100)->nullable()->after('empresa_agente_aduana_mx');

            // Transporte ampliado
            $table->string('rfc_chofer', 50)->nullable()->after('chofer');
            $table->string('marca_caja', 100)->nullable()->after('numero_contenedor');
            $table->string('placa_caja', 30)->nullable()->after('marca_caja');
            $table->string('placa_tracto', 30)->nullable()->after('placa_caja');
            $table->string('marca_tracto', 100)->nullable()->after('placa_tracto');
            $table->string('scac', 20)->nullable()->after('marca_tracto');
            $table->string('capacidad_volumen', 50)->nullable()->after('temperatura');

            // Carga
            $table->string('codigo_rastreo', 100)->nullable()->after('peso_total_kg');
            $table->unsignedTinyInteger('espacios_caja')->default(22)->after('codigo_rastreo');
        });

        // Posición de carga en la caja del camión
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->unsignedTinyInteger('posicion_carga')->nullable()->after('is_cola');
        });
    }

    public function down(): void
    {
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->dropColumn('posicion_carga');
        });

        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->dropForeign(['consignatario_id']);
            $table->dropForeign(['destino_consignatario_id']);
            $table->dropColumn([
                'empresa_razon_social', 'empresa_rfc', 'empresa_direccion',
                'empresa_ciudad', 'empresa_pais', 'empresa_agente_aduana_mx',
                'consignatario_id', 'consigne_nombre', 'consigne_rfc',
                'consigne_direccion', 'consigne_ciudad', 'consigne_pais',
                'consigne_agente_aduana_eua', 'consigne_bodega',
                'destino_consignatario_id', 'factura',
                'rfc_chofer', 'marca_caja', 'placa_caja',
                'placa_tracto', 'marca_tracto', 'scac', 'capacidad_volumen',
                'codigo_rastreo', 'espacios_caja',
            ]);
        });

        Schema::dropIfExists('consignatarios');
    }
};
