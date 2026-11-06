<?php

namespace App\Models\Abastecimiento;

use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paso congelado de la cadena de aprobación de una liquidación, por ronda de envío. */
class LiquidacionAprobacion extends Model
{
    use HasFactory, Loggable;

    public const DECISIONES = ['waiting', 'approved', 'rejected', 'skipped'];

    protected $table = 'abs_liquidacion_aprobaciones';

    protected $fillable = [
        'liquidacion_id',
        'ronda',
        'step_order',
        'description',
        'rules',
        'decision',
        'decided_by',
        'comment',
        'decided_at',
    ];

    protected $attributes = [
        'decision' => 'waiting',
    ];

    protected $casts = [
        'liquidacion_id' => 'integer',
        'ronda' => 'integer',
        'step_order' => 'integer',
        'rules' => 'array',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
