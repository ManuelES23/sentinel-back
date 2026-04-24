<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SfPosition extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'sf_positions';

    protected $fillable = [
        'enterprise_id',
        'code',
        'name',
        'sf_position_group_id',
        'department',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function group()
    {
        return $this->belongsTo(SfPositionGroup::class, 'sf_position_group_id');
    }

    public function scopeForEnterprise($query, $enterpriseId)
    {
        return $query->where('enterprise_id', $enterpriseId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function generateCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'SFPOS-%')
            ->orderByRaw('CAST(SUBSTRING(code, 7) AS UNSIGNED) DESC')
            ->value('code');

        $next = $last ? ((int) substr($last, 6)) + 1 : 1;

        return 'SFPOS-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
