<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\Respaldos\CicloRespaldos;
use Illuminate\Console\Command;

class EjecutarRespaldosCommand extends Command
{
    protected $signature = 'respaldos:ejecutar {--ahora : Crea un respaldo manual y lo procesa de inmediato}';

    protected $description = 'Crea el respaldo programado del día, procesa los pendientes y aplica la retención';

    public function handle(CicloRespaldos $ciclo): int
    {
        if ($this->option('ahora')) {
            DatabaseBackup::create([
                'type' => DatabaseBackup::TIPO_MANUAL,
                'status' => DatabaseBackup::ESTADO_PENDIENTE,
            ]);
        }

        $ciclo->ejecutar();

        if ($this->option('ahora')) {
            $ultimo = DatabaseBackup::latest('id')->first();
            $this->info("Respaldo #{$ultimo->id}: {$ultimo->status}".($ultimo->filename ? " ({$ultimo->filename})" : ''));
        }

        return self::SUCCESS;
    }
}
