<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Productor extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'productores';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tipo',
        'nombre',
        'apellido',
        'telefono',
        'email',
        'direccion',
        'rfc',
        'notas',
        'is_active',
    ];

    /**
     * Tipos de productor
     */
    const TIPO_INTERNO = 'interno';
    const TIPO_EXTERNO = 'externo';

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
     * Get the zonas de cultivo for the productor.
     */
    public function zonasCultivo(): HasMany
    {
        return $this->hasMany(ZonaCultivo::class);
    }

    /**
     * Get the lotes for the productor.
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'productor_id');
    }

    /**
     * Get the active lotes for the productor.
     */
    public function lotesActivos(): HasMany
    {
        return $this->lotes()->where('is_active', true);
    }

    /**
     * Temporadas en las que este productor ha participado
     */
    public function temporadas()
    {
        return $this->belongsToMany(Temporada::class, 'temporada_productor')
            ->withPivot('notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Cultivos que maneja este productor
     */
    public function cultivos()
    {
        return $this->belongsToMany(Cultivo::class, 'cultivo_productor')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Verificar si el productor está activo en una temporada específica
     */
    public function estaActivoEnTemporada($temporadaId): bool
    {
        return $this->temporadas()
            ->wherePivot('temporada_id', $temporadaId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    /**
     * Scope para productores activos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para productores internos (producción propia).
     */
    public function scopeInternos($query)
    {
        return $query->where('tipo', self::TIPO_INTERNO);
    }

    /**
     * Scope para productores externos (proveedores).
     */
    public function scopeExternos($query)
    {
        return $query->where('tipo', self::TIPO_EXTERNO);
    }

    /**
     * Verificar si es productor interno.
     */
    public function esInterno(): bool
    {
        return $this->tipo === self::TIPO_INTERNO;
    }

    /**
     * Verificar si es productor externo.
     */
    public function esExterno(): bool
    {
        return $this->tipo === self::TIPO_EXTERNO;
    }

    /**
     * Obtener el nombre completo del productor.
     */
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombre} {$this->apellido}");
    }

    /**
     * Obtener etiqueta del tipo.
     */
    public function getTipoLabelAttribute(): string
    {
        return $this->tipo === self::TIPO_INTERNO ? 'Producción Propia' : 'Proveedor Externo';
    }
}
