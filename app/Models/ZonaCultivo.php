<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZonaCultivo extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'zonas_cultivo';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nombre',
        'ubicacion',
        'descripcion',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the lotes for the zona.
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'zona_cultivo_id');
    }

    /**
     * Temporadas en las que esta zona ha sido utilizada
     */
    public function temporadas()
    {
        return $this->belongsToMany(Temporada::class, 'temporada_zona_cultivo')
            ->withPivot('superficie_asignada', 'notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Scope para zonas activas.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get lotes count attribute.
     */
    public function getLotesCountAttribute(): int
    {
        return $this->lotes()->count();
    }
}
