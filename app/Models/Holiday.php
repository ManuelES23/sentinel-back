<?php

namespace App\Models;

use App\Traits\Loggable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use Loggable;

    protected $fillable = ['date', 'name', 'enterprise_id', 'is_official'];

    protected $casts = ['is_official' => 'boolean'];

    /**
     * Siempre 'Y-m-d'. Sin cast 'date' a propósito: en SQLite ese cast guarda
     * la hora y rompe las comparaciones por fecha.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($valor) => $valor ? substr((string) $valor, 0, 10) : null,
            set: fn ($valor) => Carbon::parse($valor)->format('Y-m-d'),
        );
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** Festivos generales más los de la empresa dada. */
    public function scopeParaEmpresa($query, ?int $empresaId)
    {
        return $query->where(fn ($q) => $q->whereNull('enterprise_id')
            ->when($empresaId, fn ($w) => $w->orWhere('enterprise_id', $empresaId)));
    }
}
