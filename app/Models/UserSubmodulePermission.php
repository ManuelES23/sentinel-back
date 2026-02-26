<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSubmodulePermission extends Model
{
    use HasFactory;

    protected $table = 'user_submodule_permissions';

    protected $fillable = [
        'user_id',
        'submodule_id',
        'permission_type_id',
        'is_granted',
    ];

    protected $casts = [
        'is_granted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submodule()
    {
        return $this->belongsTo(Submodule::class);
    }

    public function permissionType()
    {
        return $this->belongsTo(SubmodulePermissionType::class, 'permission_type_id');
    }
}
