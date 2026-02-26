<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSubmoduleAccess extends Model
{
    use HasFactory;

    protected $table = 'user_submodule_access';

    protected $fillable = [
        'user_id',
        'submodule_id',
        'is_active',
        'granted_at',
        'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submodule()
    {
        return $this->belongsTo(Submodule::class);
    }
}
