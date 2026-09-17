<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Almacén (entidad) asignado a un usuario dentro de una empresa.
 * Lo usa AlmacenAccessService para limitar qué inventario ve cada quien.
 */
class UserEntityAccess extends Model
{
    protected $table = 'user_entity_access';

    protected $fillable = ['user_id', 'entity_id', 'enterprise_id', 'granted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
