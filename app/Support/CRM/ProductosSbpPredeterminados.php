<?php

namespace App\Support\CRM;

use App\Models\CRM\CrmProducto;

/**
 * Catálogo inicial de productos de Splendid by Porvenir (spec 2026-10-23 §4.3).
 * Cada producto se vende por caja; el precio de referencia es 0.
 * Los commodities sin agrupación (Broccoli, Tomato, etc.) llevan su propio nombre.
 */
class ProductosSbpPredeterminados
{
    /** @var array<string, list<string>> commodity => productos */
    public const LISTA = [
        'Corn' => [
            'White Corn Bulk', 'Yellow Corn Bulk', 'Bicolor Corn Bulk',
            'White Corn Tray', 'Yellow Corn Tray', 'Bicolor Corn Tray',
            'Mini Cob Yellow', 'Mini Cob Bicolor',
        ],
        'Mango' => [
            'Tommy Atkins Mango', 'Haden Mango', 'Kent Mango', 'Keitt Mango', 'Ataulfo Mango',
            'Palmer Mango', 'Nam Doc Mai Mango', 'Criollo Mango', 'Kent Organic Mango', 'Ataulfo Organic Mango',
        ],
        'Pepper' => [
            'Green Bell Pepper', 'Red Bell Pepper', 'Habanero Pepper', 'Hungarian Pepper',
            'Jalapeno Pepper', 'Poblano Pepper', 'Serrano Pepper',
        ],
        'Squash' => ['Acorn Squash', 'Butternut Squash', 'Kabocha Squash', 'Spaghetti Squash'],
        'Lettuce' => ['Lettuce Iceberg', 'Lettuce Romaine'],
        'Melon' => ['Honeydew Melon'],
        'Broccoli' => ['Broccoli'],
        'Cauliflower' => ['Cauliflower'],
        'Celery Apium' => ['Celery Apium'],
        'Cucumber' => ['Cucumber'],
        'Dragon Fruit' => ['Dragon Fruit'],
        'Green Beans' => ['Green Beans'],
        'Lemon Yellow' => ['Lemon Yellow'],
        'Limes' => ['Limes'],
        'Pineapple' => ['Pineapple'],
        'Tomato' => ['Tomato'],
        'Prickly Pears' => ['Prickly Pears'],
        'Papaya' => ['Papaya'],
        'Jack Fruit' => ['Jack Fruit'],
        'Carrot' => ['Carrot'],
        'Grapefruit' => ['Grapefruit'],
    ];

    /**
     * Inserta los productos que aún no existan (por empresa y nombre).
     * Idempotente. Devuelve cuántos insertó.
     */
    public static function sembrar(int $empresaId): int
    {
        $existentes = CrmProducto::where('empresa_id', $empresaId)->pluck('nombre')->all();
        $insertados = 0;

        foreach (self::LISTA as $commodity => $nombres) {
            foreach ($nombres as $nombre) {
                if (in_array($nombre, $existentes, true)) {
                    continue;
                }

                CrmProducto::create([
                    'empresa_id'    => $empresaId,
                    'nombre'        => $nombre,
                    'precio'        => 0,
                    'unidad_medida' => 'caja',
                    'commodity'     => $commodity,
                    'activo'        => true,
                ]);
                $insertados++;
            }
        }

        return $insertados;
    }
}
