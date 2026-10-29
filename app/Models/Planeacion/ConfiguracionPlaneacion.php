<?php

namespace App\Models\Planeacion;

use Illuminate\Database\Eloquent\Model;

/**
 * Parámetros del Programa semanal en pares clave/valor (p. ej. cajas_por_carga).
 */
class ConfiguracionPlaneacion extends Model
{
    protected $table = 'plan_configuracion';

    protected $fillable = ['clave', 'valor'];

    /**
     * Valor de un parámetro, o $porDefecto si no existe. El valor se guarda
     * como texto; si el valor por defecto es numérico o booleano, se devuelve
     * del mismo tipo.
     */
    public static function valor(string $clave, mixed $porDefecto = null): mixed
    {
        $fila = static::query()->where('clave', $clave)->first();
        if (! $fila || $fila->valor === null) {
            return $porDefecto;
        }

        return match (true) {
            is_int($porDefecto) => (int) $fila->valor,
            is_float($porDefecto) => (float) $fila->valor,
            is_bool($porDefecto) => filter_var($fila->valor, FILTER_VALIDATE_BOOLEAN),
            default => $fila->valor,
        };
    }
}
