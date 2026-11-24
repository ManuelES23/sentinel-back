<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalidaRezagaEmpaqueDetalle extends Model
{
    use HasFactory;

    protected $table = 'venta_rezaga_empaque_detalles';

    protected $fillable = ['venta_rezaga_id', 'rezaga_id', 'peso_kg', 'precio_kg', 'monto', 'bins', 'precio_bin'];

    protected $casts = [
        'peso_kg' => 'decimal:2',
        'precio_kg' => 'decimal:2',
        'monto' => 'decimal:2',
        'bins' => 'integer',
        'precio_bin' => 'decimal:2',
    ];

    /**
     * `bins` y `precio_bin` solo viajan en el JSON cuando el renglón se vendió en
     * bins; los renglones en kg (los de siempre) conservan exactamente el mismo cuerpo.
     */
    public function attributesToArray(): array
    {
        $atributos = parent::attributesToArray();

        foreach (['bins', 'precio_bin'] as $clave) {
            if (array_key_exists($clave, $atributos) && $atributos[$clave] === null) {
                unset($atributos[$clave]);
            }
        }

        return $atributos;
    }

    /**
     * La clase de la rezaga (`subtipo_rezaga`, `tipo_carga_id`) solo viaja dentro del detalle de
     * un renglón vendido en bins, que es donde el front distingue Hoja de Fresco; en los renglones
     * en kg la rezaga conserva exactamente las claves de siempre.
     */
    public function relationsToArray(): array
    {
        $relaciones = parent::relationsToArray();

        if ($this->getAttribute('bins') === null && isset($relaciones['rezaga']) && is_array($relaciones['rezaga'])) {
            unset($relaciones['rezaga']['subtipo_rezaga'], $relaciones['rezaga']['tipo_carga_id']);
        }

        return $relaciones;
    }

    public function salidaRezaga() { return $this->belongsTo(SalidaRezagaEmpaque::class, 'venta_rezaga_id'); }
    public function rezaga() { return $this->belongsTo(RezagaEmpaque::class, 'rezaga_id'); }
}
