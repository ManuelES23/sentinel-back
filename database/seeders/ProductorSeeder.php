<?php

namespace Database\Seeders;

use App\Models\Productor;
use Illuminate\Database\Seeder;

class ProductorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear productor interno (SplendidFarms - producción propia)
        Productor::firstOrCreate(
            ['nombre' => 'SplendidFarms', 'tipo' => Productor::TIPO_INTERNO],
            [
                'tipo' => Productor::TIPO_INTERNO,
                'nombre' => 'SplendidFarms',
                'apellido' => '(Producción Propia)',
                'telefono' => null,
                'email' => 'produccion@splendidfarms.com',
                'direccion' => 'Oficinas centrales',
                'rfc' => null,
                'notas' => 'Productor interno para registro de producción propia de la empresa.',
                'is_active' => true,
            ]
        );

        $this->command->info('✅ Productor interno SplendidFarms creado.');
    }
}
