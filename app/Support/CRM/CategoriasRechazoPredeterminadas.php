<?php

namespace App\Support\CRM;

use Illuminate\Support\Facades\DB;

/**
 * Catálogo de rechazo con el que arranca Splendid by Porvenir (spec
 * 2026-10-23 §4.5): 6 categorías y 14 motivos (el encabezado del spec dice 15,
 * pero su tabla suma 14; se respeta la tabla). Lo usa la migración del
 * submódulo. Es editable: después de sembrarlo nadie lo vuelve a tocar.
 */
final class CategoriasRechazoPredeterminadas
{
    public const LISTA = [
        ['nombre' => 'Precio', 'orden' => 1, 'motivos' => [
            'Precio alto',
        ]],
        ['nombre' => 'Cliente', 'orden' => 2, 'motivos' => [
            'Cliente canceló',
        ]],
        ['nombre' => 'Producto', 'orden' => 3, 'motivos' => [
            'Etiqueta no aceptada',
            'Problemas de calidad',
            'Problemas con presentación de producto',
            'Diferencia en label',
            'Diferencia en variedad',
            'Diferencia en cantidades',
        ]],
        ['nombre' => 'Disponibilidad', 'orden' => 4, 'motivos' => [
            'Volumen insuficiente',
            'Fecha de carga no corresponde',
        ]],
        ['nombre' => 'Crédito', 'orden' => 5, 'motivos' => [
            'Crédito no autorizado',
            'Sin Bluebook',
        ]],
        ['nombre' => 'Logística', 'orden' => 6, 'motivos' => [
            'Carga ladeada',
            'Truck claim',
        ]],
    ];

    /**
     * Siembra las categorías y sus motivos si la empresa no tiene ninguna
     * categoría (un catálogo ya editado no se rehace ni se completa).
     * Devuelve cuántas categorías insertó.
     */
    public static function sembrar(int $empresaId): int
    {
        if (DB::table('crm_categorias_rechazo')->where('empresa_id', $empresaId)->exists()) {
            return 0;
        }

        $ahora = now();

        DB::transaction(function () use ($empresaId, $ahora) {
            foreach (self::LISTA as $categoria) {
                $categoriaId = DB::table('crm_categorias_rechazo')->insertGetId([
                    'empresa_id' => $empresaId,
                    'nombre' => $categoria['nombre'],
                    'orden' => $categoria['orden'],
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                DB::table('crm_motivos_rechazo')->insert(array_map(fn (string $nombre, int $i) => [
                    'categoria_id' => $categoriaId,
                    'nombre' => $nombre,
                    'orden' => $i + 1,
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], $categoria['motivos'], array_keys($categoria['motivos'])));
            }
        });

        return count(self::LISTA);
    }
}
