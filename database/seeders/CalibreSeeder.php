<?php

namespace Database\Seeders;

use App\Models\Calibre;
use App\Models\Variedad;
use Illuminate\Database\Seeder;

class CalibreSeeder extends Seeder
{
    public function run(): void
    {
        // cultivo_id = 2 (Mango)
        $cultivoId = 2;
        $userId = 2;

        // Crear variedad NAM DOK MAI si no existe
        $namDokMai = Variedad::firstOrCreate(
            ['cultivo_id' => $cultivoId, 'nombre' => 'Nam Dok Mai'],
            ['user_id' => $userId]
        );

        // Mapeo variedad nombre => id
        $variedades = Variedad::where('cultivo_id', $cultivoId)->pluck('id', 'nombre');

        $data = [
            'Tommy Atkins' => [
                '7 Bolsas', 'Calibre 10', 'Calibre 12', 'Calibre 14',
                'Calibre 24/28', 'Calibre 24/30', 'Calibre 31/42',
                'Calibre 6', 'Calibre 7', 'Calibre 7/10',
                'Calibre 8', 'Calibre 9',
            ],
            'Ataulfo' => [
                '10 Bolsas', '4 Cajas de 5lbs', '8x13', 'Baby',
                'Calibre 12', 'Calibre 14', 'Calibre 16', 'Calibre 16/18/20',
                'Calibre 18', 'Calibre 20', 'Calibre 20/22', 'Calibre 22',
                'Calibre 24', 'Calibre 31/42', 'Calibre 54/66', 'Calibre 7/10',
                'Granel 18/22', 'Malla 20/22', 'Malla 20/40',
                'Primera 12/16', 'Primera 18/22', 'Regular 16', 'Super 10/14',
            ],
            'Kent' => [
                '6-3.3', '8x6',
                'Calibre 10', 'Calibre 12', 'Calibre 14', 'Calibre 24',
                'Calibre 24/28', 'Calibre 24/30', 'Calibre 31/42',
                'Calibre 5', 'Calibre 6', 'Calibre 7', 'Calibre 7/10',
                'Calibre 8', 'Calibre 8, 9, 10', 'Calibre 9',
                'Granel', 'Malla 7/10', 'Primera 6/8', 'Primera 9/12',
                'Size 10/12',
            ],
            'Keitt' => [
                '4/7', '8/12',
                'Calibre 10', 'Calibre 12', 'Calibre 14',
                'Calibre 15/17', 'Calibre 18/23', 'Calibre 24/30',
                'Calibre 31/42', 'Calibre 4', 'Calibre 5', 'Calibre 6',
                'Calibre 7', 'Calibre 7/10', 'Calibre 8', 'Calibre 8,9,10',
                'Calibre 9', 'Primera 7/12',
            ],
            'Nam Dok Mai' => [
                'Calibre 12', 'Calibre 14', 'Calibre 16',
                'Calibre 18', 'Calibre 20', 'Calibre 22',
            ],
        ];

        $order = 0;

        foreach ($data as $variedadNombre => $calibres) {
            $variedadId = $variedades[$variedadNombre] ?? null;

            if (!$variedadId) {
                $this->command->warn("Variedad '{$variedadNombre}' no encontrada, omitiendo...");
                continue;
            }

            foreach ($calibres as $nombre) {
                $valor = $this->extractValor($nombre);

                Calibre::withTrashed()->firstOrCreate(
                    [
                        'cultivo_id'  => $cultivoId,
                        'variedad_id' => $variedadId,
                        'valor'       => $valor,
                    ],
                    [
                        'nombre'    => $nombre,
                        'is_active' => true,
                        'order'     => $order++,
                    ]
                );
            }
        }

        $total = Calibre::where('cultivo_id', $cultivoId)->count();
        $this->command->info("Calibres de mango: {$total} registros.");
    }

    private function extractValor(string $nombre): string
    {
        // "Calibre 10" → "10", "Calibre 24/28" → "24/28"
        if (preg_match('/^Calibre\s+(.+)$/i', $nombre, $m)) {
            return trim($m[1]);
        }

        // Para nombres especiales devolver tal cual como valor
        return $nombre;
    }
}
