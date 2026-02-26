<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class UnitOfMeasureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            // Unidades de cantidad
            [
                'code' => 'UND',
                'name' => 'Unidad',
                'abbreviation' => 'und',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'DOC',
                'name' => 'Docena',
                'abbreviation' => 'doc',
                'type' => 'unit',
                'conversion_factor' => 12,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'CEN',
                'name' => 'Ciento',
                'abbreviation' => 'cen',
                'type' => 'unit',
                'conversion_factor' => 100,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'MIL',
                'name' => 'Millar',
                'abbreviation' => 'mil',
                'type' => 'unit',
                'conversion_factor' => 1000,
                'precision' => 0,
                'is_active' => true,
            ],
            
            // Peso
            [
                'code' => 'GR',
                'name' => 'Gramo',
                'abbreviation' => 'g',
                'type' => 'weight',
                'conversion_factor' => 1,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'KG',
                'name' => 'Kilogramo',
                'abbreviation' => 'kg',
                'type' => 'weight',
                'conversion_factor' => 1000,
                'precision' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'LB',
                'name' => 'Libra',
                'abbreviation' => 'lb',
                'type' => 'weight',
                'conversion_factor' => 453.592,
                'precision' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'TON',
                'name' => 'Tonelada',
                'abbreviation' => 't',
                'type' => 'weight',
                'conversion_factor' => 1000000,
                'precision' => 4,
                'is_active' => true,
            ],
            [
                'code' => 'OZ',
                'name' => 'Onza',
                'abbreviation' => 'oz',
                'type' => 'weight',
                'conversion_factor' => 28.3495,
                'precision' => 2,
                'is_active' => true,
            ],
            
            // Volumen
            [
                'code' => 'ML',
                'name' => 'Mililitro',
                'abbreviation' => 'ml',
                'type' => 'volume',
                'conversion_factor' => 1,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'LT',
                'name' => 'Litro',
                'abbreviation' => 'lt',
                'type' => 'volume',
                'conversion_factor' => 1000,
                'precision' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'GAL',
                'name' => 'Galón',
                'abbreviation' => 'gal',
                'type' => 'volume',
                'conversion_factor' => 3785.41,
                'precision' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'M3',
                'name' => 'Metro cúbico',
                'abbreviation' => 'm³',
                'type' => 'volume',
                'conversion_factor' => 1000000,
                'precision' => 4,
                'is_active' => true,
            ],
            
            // Longitud
            [
                'code' => 'MM',
                'name' => 'Milímetro',
                'abbreviation' => 'mm',
                'type' => 'length',
                'conversion_factor' => 1,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'CM',
                'name' => 'Centímetro',
                'abbreviation' => 'cm',
                'type' => 'length',
                'conversion_factor' => 10,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'M',
                'name' => 'Metro',
                'abbreviation' => 'm',
                'type' => 'length',
                'conversion_factor' => 1000,
                'precision' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'IN',
                'name' => 'Pulgada',
                'abbreviation' => 'in',
                'type' => 'length',
                'conversion_factor' => 25.4,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'FT',
                'name' => 'Pie',
                'abbreviation' => 'ft',
                'type' => 'length',
                'conversion_factor' => 304.8,
                'precision' => 2,
                'is_active' => true,
            ],
            
            // Área
            [
                'code' => 'M2',
                'name' => 'Metro cuadrado',
                'abbreviation' => 'm²',
                'type' => 'area',
                'conversion_factor' => 1,
                'precision' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'HA',
                'name' => 'Hectárea',
                'abbreviation' => 'ha',
                'type' => 'area',
                'conversion_factor' => 10000,
                'precision' => 4,
                'is_active' => true,
            ],
            [
                'code' => 'MZ',
                'name' => 'Manzana',
                'abbreviation' => 'mz',
                'type' => 'area',
                'conversion_factor' => 6988.96,
                'precision' => 4,
                'is_active' => true,
            ],
            
            // Empaque/Embalaje
            [
                'code' => 'PAQ',
                'name' => 'Paquete',
                'abbreviation' => 'paq',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'CAJA',
                'name' => 'Caja',
                'abbreviation' => 'caja',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'SACO',
                'name' => 'Saco',
                'abbreviation' => 'saco',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'BOLSA',
                'name' => 'Bolsa',
                'abbreviation' => 'bolsa',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'ROLLO',
                'name' => 'Rollo',
                'abbreviation' => 'rollo',
                'type' => 'unit',
                'conversion_factor' => 1,
                'precision' => 0,
                'is_active' => true,
            ],
        ];

        foreach ($units as $unit) {
            UnitOfMeasure::updateOrCreate(
                ['code' => $unit['code']],
                $unit
            );
        }

        // Establecer relaciones de unidades base
        $this->setBaseUnits();

        $this->command->info('✓ Unidades de medida creadas');
    }

    /**
     * Establece las unidades base para conversiones.
     */
    private function setBaseUnits(): void
    {
        // Las unidades derivadas deben apuntar a sus unidades base
        $baseRelations = [
            // Peso: gramo es la base
            'KG' => 'GR',
            'LB' => 'GR',
            'TON' => 'GR',
            'OZ' => 'GR',
            // Volumen: mililitro es la base
            'LT' => 'ML',
            'GAL' => 'ML',
            'M3' => 'ML',
            // Longitud: milímetro es la base
            'CM' => 'MM',
            'M' => 'MM',
            'IN' => 'MM',
            'FT' => 'MM',
            // Área: metro cuadrado es la base
            'HA' => 'M2',
            'MZ' => 'M2',
            // Unidades: unidad es la base
            'DOC' => 'UND',
            'CEN' => 'UND',
            'MIL' => 'UND',
        ];

        foreach ($baseRelations as $derivedCode => $baseCode) {
            $derived = UnitOfMeasure::where('code', $derivedCode)->first();
            $base = UnitOfMeasure::where('code', $baseCode)->first();
            
            if ($derived && $base) {
                $derived->update(['base_unit_id' => $base->id]);
            }
        }
    }
}
