<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deja vacaciones en cero para cargar el historial real: borra solicitudes (también las
 * ya eliminadas), su cadena, sus avisos, los saldos con sus ajustes (incluida la
 * conciliación) y el historial. Conserva empleados, PINs, festivos y la configuración
 * de la cadena. Sin --confirmar solo muestra lo que borraría.
 */
class ReiniciarVacacionesCommand extends Command
{
    protected $signature = 'vacaciones:reiniciar
        {--confirmar : Borra de verdad (pide confirmación)}';

    protected $description = 'Borra solicitudes, avisos, ajustes e historial de vacaciones de todos los empleados';

    /** En orden de borrado: primero lo que depende de las solicitudes. */
    private const TABLAS = [
        'vacation_notifications' => 'Avisos (correo y WhatsApp)',
        'vacation_request_approvals' => 'Pasos de aprobación',
        'vacation_requests' => 'Solicitudes (incluye eliminadas)',
        'vacation_balances' => 'Saldos y ajustes (incluye la conciliación)',
        'vacation_history' => 'Historial capturado por RH',
    ];

    public function handle(): int
    {
        $faltan = array_filter(array_keys(self::TABLAS), fn (string $tabla) => ! Schema::hasTable($tabla));
        if ($faltan) {
            $this->error('Faltan tablas ('.implode(', ', $faltan).'). Corre primero php artisan migrate.');

            return self::FAILURE;
        }

        $conteos = [];
        foreach (self::TABLAS as $tabla => $etiqueta) {
            $conteos[$tabla] = DB::table($tabla)->count();
        }
        $this->table(['Qué', 'Registros'], array_map(
            fn (string $tabla) => [self::TABLAS[$tabla], $conteos[$tabla]],
            array_keys(self::TABLAS),
        ));
        $this->line('Se conservan empleados, PINs, festivos y la cadena de aprobación.');

        if (! $this->option('confirmar')) {
            $this->warn('Nada se borró. Para borrar: php artisan vacaciones:reiniciar --confirmar');

            return self::SUCCESS;
        }

        if (! $this->confirm('¿Borrar todo lo de vacaciones de todos los empleados? No se puede deshacer.')) {
            $this->warn('Nada se borró.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            foreach (array_keys(self::TABLAS) as $tabla) {
                DB::table($tabla)->delete();
            }
        });

        Log::warning('Reinicio de vacaciones', ['borrados' => $conteos]);
        $this->info('Listo: vacaciones en cero. Ya puedes importar el historial desde RH → Vacaciones.');

        return self::SUCCESS;
    }
}
