<?php

namespace Database\Seeders;

use App\Models\Cultivo;
use App\Models\Plaga;
use Illuminate\Database\Seeder;

class PlagasEloteDulceSeeder extends Seeder
{
    /**
     * Seeder de plagas/defectos para el cultivo Elote Dulce.
     */
    public function run(): void
    {
        $cultivo = Cultivo::firstOrCreate([
            'nombre' => 'Elote Dulce',
        ]);

        $plagas = [
            ['nombre' => 'GUSANO V', 'abreviatura' => 'GSV', 'tipo' => 'insecto'],
            ['nombre' => 'GUSANO NV', 'abreviatura' => 'GSNV', 'tipo' => 'insecto'],
            ['nombre' => 'CHICO', 'abreviatura' => 'CHI', 'tipo' => 'otro'],
            ['nombre' => 'SIN PLAGA', 'abreviatura' => 'SP', 'tipo' => 'otro'],
            ['nombre' => 'PICADA DE PAJARO', 'abreviatura' => 'PDP', 'tipo' => 'otro'],
            ['nombre' => 'TIERNO', 'abreviatura' => 'TIR', 'tipo' => 'otro'],
            ['nombre' => 'VANO', 'abreviatura' => 'VAN', 'tipo' => 'otro'],
            ['nombre' => 'HOJA SECA', 'abreviatura' => 'HS', 'tipo' => 'otro'],
            ['nombre' => 'HONGO', 'abreviatura' => 'HNG', 'tipo' => 'hongo'],
            ['nombre' => 'PUNTA VANA', 'abreviatura' => 'PV', 'tipo' => 'otro'],
            ['nombre' => 'MAL DESARROLLO', 'abreviatura' => 'MD', 'tipo' => 'otro'],
            ['nombre' => 'DAÑO MECANICO', 'abreviatura' => 'DM', 'tipo' => 'otro'],
            ['nombre' => 'PULGON', 'abreviatura' => 'PUL', 'tipo' => 'insecto'],
            ['nombre' => 'DESHIDRATADO', 'abreviatura' => 'DES', 'tipo' => 'otro'],
        ];

        foreach ($plagas as $plaga) {
            Plaga::updateOrCreate(
                [
                    'cultivo_id' => $cultivo->id,
                    'nombre' => $plaga['nombre'],
                ],
                [
                    'abreviatura' => $plaga['abreviatura'],
                    'tipo' => $plaga['tipo'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Plagas de Elote Dulce sembradas correctamente.');
    }
}
