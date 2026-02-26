<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmodulePermissionType extends Model
{
    use HasFactory;

    protected $table = 'submodule_permission_types';

    protected $fillable = [
        'submodule_id',
        'slug',
        'name',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    public function submodule()
    {
        return $this->belongsTo(Submodule::class);
    }

    public function userPermissions()
    {
        return $this->hasMany(UserSubmodulePermission::class, 'permission_type_id');
    }
}
