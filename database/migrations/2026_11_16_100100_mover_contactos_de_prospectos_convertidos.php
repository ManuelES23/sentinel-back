<?php

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmProspecto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prospectos como empresas (CRM SBP): los contactos de un prospecto convertido
 * viven en su cliente (ContactoActividadService::empresaDe). La conversión ya
 * los mueve desde esta versión, pero los prospectos convertidos ANTES del
 * despliegue conservan sus contactos en el prospecto: el selector no los ofrece,
 * `resolver` no los acepta y la pestaña Contactos del cliente no los muestra.
 *
 * Esta migración los pasa al cliente. Reglas:
 * - Solo clientes vigentes (no eliminados) de la MISMA empresa que el contacto;
 *   si el prospecto tiene varios, el de menor id (igual que `empresaDe`).
 * - Si el cliente ya tiene un contacto principal, los movidos dejan de serlo
 *   (nunca dos principales).
 * - Idempotente: una segunda corrida no encuentra contactos en el prospecto.
 * - Los contactos de un prospecto sin cliente, o de otra empresa, no se tocan.
 *
 * Se corre por ruta en el mismo despliegue de la migración de la bandera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $clientes = DB::table('crm_clientes')
            ->whereNotNull('prospecto_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'empresa_id', 'prospecto_id']);

        // Un solo cliente por (empresa, prospecto): el de menor id.
        $destinos = [];
        foreach ($clientes as $cliente) {
            $destinos[$cliente->empresa_id.':'.$cliente->prospecto_id] ??= $cliente;
        }

        DB::transaction(function () use ($destinos) {
            foreach ($destinos as $cliente) {
                $pendientes = DB::table('crm_contactos')
                    ->where('empresa_id', $cliente->empresa_id)
                    ->where('entidad_type', CrmProspecto::class)
                    ->where('entidad_id', $cliente->prospecto_id)
                    ->pluck('id');

                if ($pendientes->isEmpty()) {
                    continue;
                }

                $clienteTienePrincipal = DB::table('crm_contactos')
                    ->where('empresa_id', $cliente->empresa_id)
                    ->where('entidad_type', CrmCliente::class)
                    ->where('entidad_id', $cliente->id)
                    ->where('es_principal', true)
                    ->exists();

                $cambios = [
                    'entidad_type' => CrmCliente::class,
                    'entidad_id' => $cliente->id,
                    'updated_at' => now(),
                ];
                if ($clienteTienePrincipal) {
                    $cambios['es_principal'] = false;
                }

                DB::table('crm_contactos')->whereIn('id', $pendientes)->update($cambios);
            }
        });
    }

    /**
     * Sin marcha atrás: ya no se sabe qué contactos estaban en el prospecto
     * antes de moverlos, y devolverlos dejaría huérfanos a los del cliente.
     */
    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
