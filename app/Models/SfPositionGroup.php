<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SfPositionGroup extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'sf_position_groups';

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'salary',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = ['display_name'];

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function positions()
    {
        return $this->hasMany(SfPosition::class, 'sf_position_group_id');
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->name ?: 'Grupo') . ' ' . $this->code);
    }
}
