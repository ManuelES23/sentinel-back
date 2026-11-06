<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte de un abono aplicada a una liquidación. */
class AbonoAplicacion extends Model
{
    use HasFactory;

    protected $table = 'abs_abono_aplicaciones';

    protected $fillable = [
        'abono_id',
        'liquidacion_id',
        'monto',
    ];

    protected $casts = [
        'abono_id' => 'integer',
        'liquidacion_id' => 'integer',
        'monto' => 'decimal:2',
    ];

    public function abono(): BelongsTo
    {
        return $this->belongsTo(Abono::class, 'abono_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }
}
