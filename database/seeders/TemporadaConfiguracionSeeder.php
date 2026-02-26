<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Temporada;
use App\Models\Cultivo;
use App\Models\Productor;
use App\Models\ZonaCultivo;
use App\Models\Lote;

class TemporadaConfiguracionSeeder extends Seeder
{
    /**
     * Ejemplo de configuración de temporadas con asignaciones temporales.
     * 
     * Este seeder demuestra cómo:
     * 1. Crear temporadas
     * 2. Asignar productores
     * 3. Asignar zonas de cultivo
     * 4. Asignar lotes con cultivos específicos
     */
    public function run(): void
    {
        // Verificar que existan los datos necesarios
        /** @phpstan-ignore-next-line */
        $cultivo = Cultivo::first();
        if (!$cultivo) {
            $this->command->warn('No hay cultivos. Ejecuta CultivoSeeder primero.');
            return;
        }

        /** @phpstan-ignore-next-line */
        $productor = Productor::first();
        if (!$productor) {
            $this->command->warn('No hay productores. Ejecuta ProductorSeeder primero.');
            return;
        }

        // TEMPORADA 2024
        $this->command->info('Creando Temporada 2024...');
        
        $temporada2024 = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => $cultivo->nombre . ' 2024',
            'locacion' => 'Región Norte',
            'folio_temporada' => Temporada::generarFolio($cultivo->id),
            'año_inicio' => 2024,
            'año_fin' => 2024,
            'fecha_inicio' => '2024-03-01',
            'fecha_fin' => '2024-08-31',
            'estado' => 'cerrada',
            'user_id' => 1,
        ]);

        // Asignar 3 productores a 2024
        $productores2024 = Productor::take(3)->get();
        foreach ($productores2024 as $index => $prod) {
            $temporada2024->asignarProductor(
                $prod->id,
                $index === 0 ? 'Productor principal' : null
            );
        }
        $this->command->info("  - {$productores2024->count()} productores asignados");

        // Asignar zonas de cultivo
        /** @phpstan-ignore-next-line */
        $zonas2024 = ZonaCultivo::whereIn('productor_id', $productores2024->pluck('id'))->get();
        foreach ($zonas2024 as $zona) {
            $temporada2024->asignarZonaCultivo(
                $zona->id,
                $zona->superficie_total * 0.8, // 80% de la superficie
                'Asignación temporada 2024'
            );
        }
        $this->command->info("  - {$zonas2024->count()} zonas asignadas");

        // Asignar lotes
        /** @phpstan-ignore-next-line */
        $lotes2024 = Lote::whereIn('zona_cultivo_id', $zonas2024->pluck('id'))->take(5)->get();
        foreach ($lotes2024 as $lote) {
            $temporada2024->asignarLote(
                $lote->id,
                $cultivo->id,
                $lote->superficie_total,
                '2024-03-15',
                'Siembra completada',
                '2024-08-15'
            );
        }
        $this->command->info("  - {$lotes2024->count()} lotes asignados");

        // TEMPORADA 2025
        $this->command->info('Creando Temporada 2025...');
        
        $temporada2025 = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => $cultivo->nombre . ' 2025',
            'locacion' => 'Región Norte y Sur',
            'folio_temporada' => Temporada::generarFolio($cultivo->id),
            'año_inicio' => 2025,
            'año_fin' => 2025,
            'fecha_inicio' => '2025-03-01',
            'fecha_fin' => '2025-08-31',
            'estado' => 'cerrada',
            'user_id' => 1,
        ]);

        // En 2025, algunos productores se repiten y otros son nuevos
        $productores2025 = Productor::take(4)->get(); // Un productor más que en 2024
        foreach ($productores2025 as $index => $prod) {
            $temporada2025->asignarProductor(
                $prod->id,
                $index >= 3 ? 'Nuevo productor' : null
            );
        }
        $this->command->info("  - {$productores2025->count()} productores asignados (1 nuevo)");

        // Algunas zonas son las mismas, otras son nuevas
        /** @phpstan-ignore-next-line */
        $zonas2025 = ZonaCultivo::whereIn('productor_id', $productores2025->pluck('id'))->get();
        foreach ($zonas2025 as $zona) {
            $temporada2025->asignarZonaCultivo(
                $zona->id,
                $zona->superficie_total * 0.9, // 90% de la superficie (mejor rendimiento)
                'Asignación temporada 2025'
            );
        }
        $this->command->info("  - {$zonas2025->count()} zonas asignadas");

        // Asignar lotes
        /** @phpstan-ignore-next-line */
        $lotes2025 = Lote::whereIn('zona_cultivo_id', $zonas2025->pluck('id'))->take(7)->get();
        foreach ($lotes2025 as $lote) {
            $temporada2025->asignarLote(
                $lote->id,
                $cultivo->id,
                $lote->superficie_total,
                '2025-03-10',
                'Siembra adelantada por buen clima',
                '2025-08-10'
            );
        }
        $this->command->info("  - {$lotes2025->count()} lotes asignados");

        // TEMPORADA 2026 (ACTUAL - PROGRAMADA)
        $this->command->info('Creando Temporada 2026 (actual)...');
        
        $temporada2026 = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => $cultivo->nombre . ' 2026',
            'locacion' => 'Región Norte',
            'folio_temporada' => Temporada::generarFolio($cultivo->id),
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-03-01',
            'fecha_fin' => '2026-08-31',
            'estado' => 'abierta', // Cambiado de 'programada' a 'abierta'
            'user_id' => 1,
        ]);

        // Solo 2 productores confirmados para 2026 hasta ahora
        $productores2026 = Productor::take(2)->get();
        foreach ($productores2026 as $prod) {
            $temporada2026->asignarProductor($prod->id, 'Confirmado para 2026');
        }
        $this->command->info("  - {$productores2026->count()} productores confirmados");

        // Zonas y lotes aún en planificación
        /** @phpstan-ignore-next-line */
        $zonas2026 = ZonaCultivo::whereIn('productor_id', $productores2026->pluck('id'))->take(3)->get();
        foreach ($zonas2026 as $zona) {
            $temporada2026->asignarZonaCultivo(
                $zona->id,
                $zona->superficie_total * 0.85,
                'En planificación'
            );
        }
        $this->command->info("  - {$zonas2026->count()} zonas en planificación");

        // RESUMEN
        $this->command->info('');
        $this->command->info('=== RESUMEN DE CONFIGURACIÓN ===');
        
        $resumen2024 = $temporada2024->resumen();
        $this->command->info("Temporada 2024 (cerrada):");
        $this->command->info("  - Productores activos: {$resumen2024['productores_activos']}");
        $this->command->info("  - Zonas activas: {$resumen2024['zonas_activas']}");
        $this->command->info("  - Lotes activos: {$resumen2024['lotes_activos']}");
        $this->command->info("  - Superficie total: {$resumen2024['superficie_total_sembrada']} ha");

        $resumen2025 = $temporada2025->resumen();
        $this->command->info("Temporada 2025 (cerrada):");
        $this->command->info("  - Productores activos: {$resumen2025['productores_activos']}");
        $this->command->info("  - Zonas activas: {$resumen2025['zonas_activas']}");
        $this->command->info("  - Lotes activos: {$resumen2025['lotes_activos']}");
        $this->command->info("  - Superficie total: {$resumen2025['superficie_total_sembrada']} ha");

        $resumen2026 = $temporada2026->resumen();
        $this->command->info("Temporada 2026 (abierta):");
        $this->command->info("  - Productores activos: {$resumen2026['productores_activos']}");
        $this->command->info("  - Zonas activas: {$resumen2026['zonas_activas']}");
        $this->command->info("  - Lotes activos: {$resumen2026['lotes_activos']}");
    }
}

