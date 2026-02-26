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
}
