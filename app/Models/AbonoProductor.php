<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AbonoProductor extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abonos_productores';

    protected $fillable = [
        'folio_abono',
        'productor_id',
        'temporada_id',
        'convenio_compra_id',
        'fecha',
        'monto',
        'metodo_pago',
        'referencia',
        'notas',
        'status',
        'motivo_cancelacion',
        'created_by',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'monto' => 'decimal:2',
    ];

    const STATUS_ACTIVO = 'activo';
    const STATUS_CANCELADO = 'cancelado';

    // ── Relaciones ──────────────────────────────────────

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function convenioCompra(): BelongsTo
    {
        return $this->belongsTo(ConvenioCompra::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeActivo($query)
    {
        return $query->where('status', self::STATUS_ACTIVO);
    }

    public function scopePorProductor($query, $productorId)
    {
        return $query->where('productor_id', $productorId);
    }

    public function scopePorTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopePorConvenio($query, $convenioId)
    {
        return $query->where('convenio_compra_id', $convenioId);
    }
}
