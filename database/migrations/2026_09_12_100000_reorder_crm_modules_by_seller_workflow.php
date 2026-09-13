<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 de la reorganización del CRM Comercial: el menú deja de seguir el
 * modelo de datos (Catálogos primero, Dashboard en décimo lugar) y pasa a
 * seguir la jornada del vendedor: qué tengo hoy, en qué va cada venta, con
 * quién hablo, y al final la configuración.
 *
 * Solo toca `modules.order` de la aplicación `crm` de cada empresa. No crea,
 * borra ni renombra módulos, y no toca permisos. CrmPermisosSeeder usa el
 * mismo orden, así que volver a correr el seeder no lo deshace.
 */
return new class extends Migration
{
    public const ORDEN_NUEVO = [
        'agenda' => 1,
        'oportunidades' => 2,
        'clientes' => 3,
        'prospectos' => 4,
        'cotizaciones' => 5,
        'actividades' => 6,
        'empresas-externas' => 7,
        'dashboard' => 8,
        'presupuestos' => 9,
        'catalogos' => 10,
        'integraciones' => 11,
    ];

    private const ORDEN_ANTERIOR = [
        'catalogos' => 1,
        'prospectos' => 2,
        'clientes' => 3,
        'empresas-externas' => 4,
        'actividades' => 5,
        'oportunidades' => 6,
        'cotizaciones' => 7,
        'presupuestos' => 8,
        'agenda' => 9,
        'dashboard' => 10,
        'integraciones' => 11,
    ];

    public function up(): void
    {
        $this->aplicarOrden(self::ORDEN_NUEVO);
    }

    public function down(): void
    {
        $this->aplicarOrden(self::ORDEN_ANTERIOR);
    }

    private function aplicarOrden(array $orden): void
    {
        $appIds = DB::table('applications')->where('slug', 'crm')->pluck('id');
        if ($appIds->isEmpty()) {
            return;
        }

        foreach ($orden as $slug => $posicion) {
            DB::table('modules')
                ->whereIn('application_id', $appIds)
                ->where('slug', $slug)
                ->update(['order' => $posicion]);
        }
    }
};
