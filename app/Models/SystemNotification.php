<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class SystemNotification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'audience_type',
        'user_id',
        'enterprise_id',
        'role',
        'title',
        'message',
        'category',
        'icon',
        'icon_color',
        'action_url',
        'action_label',
        'data',
        'priority',
        'is_active',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'data' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    // Constantes para tipos de audiencia
    const AUDIENCE_PERSONAL = 'personal';
    const AUDIENCE_ENTERPRISE = 'enterprise';
    const AUDIENCE_ROLE = 'role';
    const AUDIENCE_GLOBAL = 'global';

    // Constantes para categorías
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_VACATION = 'vacation';
    const CATEGORY_ATTENDANCE = 'attendance';
    const CATEGORY_RH = 'rh';
    const CATEGORY_ALERT = 'alert';
    const CATEGORY_INFO = 'info';

    // Constantes para prioridades
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // ==================== RELACIONES ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuarios que han leído esta notificación
     */
    public function readByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_reads', 'notification_id', 'user_id')
            ->withPivot(['read_at', 'dismissed_at'])
            ->withTimestamps();
    }

    // ==================== SCOPES ====================

    /**
     * Notificaciones activas y no expiradas
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Notificaciones para un usuario específico
     * Incluye: personales, de su empresa, de su rol, y globales
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            // Personales para este usuario
            $q->where(function ($personal) use ($user) {
                $personal->where('audience_type', self::AUDIENCE_PERSONAL)
                    ->where('user_id', $user->id);
            });

            // De sus empresas
            $enterpriseIds = $user->activeEnterprises()->pluck('enterprises.id');
            if ($enterpriseIds->isNotEmpty()) {
                $q->orWhere(function ($enterprise) use ($enterpriseIds) {
                    $enterprise->where('audience_type', self::AUDIENCE_ENTERPRISE)
                        ->whereIn('enterprise_id', $enterpriseIds);
                });
            }

            // De su rol
            if ($user->role) {
                $q->orWhere(function ($role) use ($user) {
                    $role->where('audience_type', self::AUDIENCE_ROLE)
                        ->where('role', $user->role);
                });
            }

            // Globales
            $q->orWhere('audience_type', self::AUDIENCE_GLOBAL);
        });
    }

    /**
     * Notificaciones no leídas por un usuario
     */
    public function scopeUnreadBy(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('readByUsers', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Notificaciones no descartadas por un usuario
     */
    public function scopeNotDismissedBy(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->whereDoesntHave('readByUsers', function ($subQ) use ($user) {
                $subQ->where('user_id', $user->id)
                    ->whereNotNull('dismissed_at');
            });
        });
    }

    /**
     * Filtrar por categoría
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Filtrar por prioridad mínima
     */
    public function scopeMinPriority(Builder $query, string $priority): Builder
    {
        $priorities = [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT];
        $minIndex = array_search($priority, $priorities);

        return $query->whereIn('priority', array_slice($priorities, $minIndex));
    }

    // ==================== MÉTODOS ====================

    /**
     * Marcar como leída por un usuario
     */
    public function markAsReadBy(User $user): void
    {
        $this->readByUsers()->syncWithoutDetaching([
            $user->id => ['read_at' => now()],
        ]);
    }

    /**
     * Marcar como descartada por un usuario
     */
    public function dismissBy(User $user): void
    {
        $this->readByUsers()->syncWithoutDetaching([
            $user->id => ['read_at' => now(), 'dismissed_at' => now()],
        ]);
    }

    /**
     * Verificar si fue leída por un usuario
     */
    public function isReadBy(User $user): bool
    {
        return $this->readByUsers()
            ->where('user_id', $user->id)
            ->whereNotNull('notification_reads.read_at')
            ->exists();
    }

    /**
     * Verificar si fue descartada por un usuario
     */
    public function isDismissedBy(User $user): bool
    {
        return $this->readByUsers()
            ->where('user_id', $user->id)
            ->whereNotNull('notification_reads.dismissed_at')
            ->exists();
    }

    // ==================== ACCESSORS ====================

    /**
     * Color del icono por defecto según categoría
     */
    public function getIconColorAttribute($value): string
    {
        if ($value) return $value;

        return match ($this->category) {
            self::CATEGORY_SYSTEM => 'gray',
            self::CATEGORY_VACATION => 'green',
            self::CATEGORY_ATTENDANCE => 'blue',
            self::CATEGORY_RH => 'purple',
            self::CATEGORY_ALERT => 'red',
            self::CATEGORY_INFO => 'blue',
            default => 'gray',
        };
    }

    /**
     * Icono por defecto según categoría
     */
    public function getIconAttribute($value): string
    {
        if ($value) return $value;

        return match ($this->category) {
            self::CATEGORY_SYSTEM => 'Settings',
            self::CATEGORY_VACATION => 'Palmtree',
            self::CATEGORY_ATTENDANCE => 'Clock',
            self::CATEGORY_RH => 'Users',
            self::CATEGORY_ALERT => 'AlertTriangle',
            self::CATEGORY_INFO => 'Info',
            default => 'Bell',
        };
    }

    /**
     * Etiqueta de prioridad
     */
    public function getPriorityLabelAttribute(): string
    {
        return match ($this->priority) {
            self::PRIORITY_LOW => 'Baja',
            self::PRIORITY_NORMAL => 'Normal',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_URGENT => 'Urgente',
            default => 'Normal',
        };
    }
}
