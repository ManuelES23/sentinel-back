<?php

namespace App\Console\Commands;

use App\Models\Enterprise;
use App\Services\RH\FestivosOficiales;
use App\Support\RH\FestivosGeneralesAEmpresa;
use Illuminate\Console\Command;

class CargarFestivosOficialesCommand extends Command
{
    protected $signature = 'vacaciones:festivos
        {anio : Año a cargar, por ejemplo 2026}
        {--empresa='.FestivosGeneralesAEmpresa::MEXICO.' : Slug de la empresa que recibe los festivos}
        {--pais=mx : mx (LFT art. 74) o us (los 6 comunes de Estados Unidos)}';

    protected $description = 'Carga los festivos oficiales de un año en una empresa (México o Estados Unidos)';

    public function handle(FestivosOficiales $festivos): int
    {
        $anio = (int) $this->argument('anio');
        if ($anio < 2000 || $anio > 2100) {
            $this->error('Año inválido: usa un año entre 2000 y 2100.');

            return self::FAILURE;
        }

        $pais = strtolower((string) $this->option('pais'));
        if (! in_array($pais, FestivosOficiales::PAISES, true)) {
            $this->error('País inválido: usa mx o us.');

            return self::FAILURE;
        }

        $empresa = Enterprise::where('slug', (string) $this->option('empresa'))->first();
        if (! $empresa) {
            $this->error("No existe la empresa «{$this->option('empresa')}».");

            return self::FAILURE;
        }

        $creados = $festivos->cargar($anio, $empresa->id, $pais);
        $existentes = count($festivos->paraAnio($anio, $pais)) - $creados;
        $this->info("Festivos oficiales {$anio} ({$pais}) en {$empresa->name}: {$creados} nuevos, {$existentes} ya existían.");
        if ($pais === FestivosOficiales::MEXICO) {
            $this->line('Las jornadas electorales no se cargan solas: agrégalas a mano si aplican.');
        }

        return self::SUCCESS;
    }
}
