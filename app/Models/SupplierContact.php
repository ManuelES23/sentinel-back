<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de Contactos de Proveedor
 */
class SupplierContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'position',
        'department',
        'phone',
        'mobile',
        'email',
        'is_primary',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // ==================== SCOPES ====================

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        // Al crear/actualizar, asegurar que solo haya un contacto primario
        static::saving(function ($contact) {
            if ($contact->is_primary) {
                static::where('supplier_id', $contact->supplier_id)
                    ->where('id', '!=', $contact->id ?? 0)
                    ->update(['is_primary' => false]);
            }
        });
    }
}
