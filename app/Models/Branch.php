<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'slug',
        'description',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'phone',
        'email',
        'manager',
        'is_active',
        'is_main',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($branch) {
            if (empty($branch->slug)) {
                $branch->slug = static::generateUniqueSlug($branch->name);
            }
        });

        static::updating(function ($branch) {
            if (empty($branch->slug)) {
                $branch->slug = static::generateUniqueSlug($branch->name, $branch->id);
            }
        });
    }

    /**
     * El índice único de `slug` incluye las sucursales borradas (borrado lógico),
     * así que las colisiones se buscan también entre ellas.
     */
    private static function generateUniqueSlug(?string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $name);
        if ($base === '') {
            $base = 'sucursal';
        }

        $slug = $base;
        $counter = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    // Relaciones
    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function entities()
    {
        return $this->hasMany(Entity::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }
}
