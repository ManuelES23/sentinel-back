<?php

namespace App\Models\Abastecimiento;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte de un pago que le toca a una recepción. */
class AbonoRecepcion extends Model
{
    use HasFactory;

    protected $table = 'abs_abono_recepciones';

    protected $fillable = [
        'abono_id',
        'recepcion_id',
        'monto',
    ];

    protected $casts = [
        'abono_id' => 'integer',
        'recepcion_id' => 'integer',
        'monto' => 'decimal:2',
    ];

    public function abono(): BelongsTo
    {
        return $this->belongsTo(Abono::class, 'abono_id');
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(Recepcion::class, 'recepcion_id');
    }
}
