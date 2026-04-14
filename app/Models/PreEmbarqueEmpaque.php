<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreEmbarqueEmpaque extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'pre_embarques_empaque';

    protected $fillable = [
        'temporada_id',
        'entity_id',
        'folio_pre_embarque',
        'espacios_caja',
        'status',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'espacios_caja' => 'integer',
    ];

    // ── Relaciones ──

    public function temporada()
    {
        return $this->belongsTo(Temporada::class);
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function detalles()
    {
        return $this->hasMany(PreEmbarqueEmpaqueDetalle::class, 'pre_embarque_id');
    }

    // ── Scopes ──

    public function scopeAbiertos($query)
    {
        return $query->where('status', 'abierto');
    }

    // ── Helpers ──

    public function nextPosition(): int
    {
        $maxPos = $this->detalles()->max('posicion_carga');
        return ($maxPos ?? 0) + 1;
    }

    public function isFull(): bool
    {
        return $this->detalles()->count() >= $this->espacios_caja;
    }
}
