<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cultivo extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cultivos';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nombre',
        'imagen',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'imagen_url',
    ];

    /**
     * URL pública de la imagen del cultivo.
     *
     * Vive en el modelo y no en cada controlador: el front pinta la miniatura
     * del cultivo en variedades, tipos de variedad, ciclos y temporadas, y ahí
     * nadie armaba la URL, así que la imagen nunca aparecía.
     */
    public function getImagenUrlAttribute(): ?string
    {
        // Se lee de los atributos cargados y no con $this->imagen: muchas
        // consultas traen el cultivo con select parcial (cultivo:id,nombre) y
        // con el modo estricto de Eloquent eso lanzaría MissingAttribute.
        $imagen = $this->attributes['imagen'] ?? null;

        if (! $imagen) {
            return null;
        }

        return asset('storage/'.$imagen);
    }

    /**
     * Productores que manejan este cultivo
     */
    public function productores()
    {
        return $this->belongsToMany(Productor::class, 'cultivo_productor')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Variedades de este cultivo
     */
    public function variedades()
    {
        return $this->hasMany(Variedad::class);
    }

    /**
     * Etapas fenológicas de este cultivo
     */
    public function etapasFenologicas()
    {
        return $this->hasMany(EtapaFenologica::class);
    }
}
