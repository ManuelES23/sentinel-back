<?php

namespace App\Console\Commands;

use App\Services\RH\FestivosOficiales;
use Illuminate\Console\Command;

class CargarFestivosOficialesCommand extends Command
{
    protected $signature = 'vacaciones:festivos {anio : Año a cargar, por ejemplo 2026}';

    protected $description = 'Carga los días de descanso obligatorio de la LFT (art. 74) para un año';

    public function handle(FestivosOficiales $festivos): int
    {
        $anio = (int) $this->argument('anio');
        if ($anio < 2000 || $anio > 2100) {
            $this->error('Año inválido: usa un año entre 2000 y 2100.');

            return self::FAILURE;
        }

        $creados = $festivos->cargar($anio);
        $existentes = count($festivos->paraAnio($anio)) - $creados;
        $this->info("Festivos oficiales {$anio}: {$creados} nuevos, {$existentes} ya existían.");
        $this->line('Las jornadas electorales no se cargan solas: agrégalas a mano si aplican.');

        return self::SUCCESS;
    }
}
